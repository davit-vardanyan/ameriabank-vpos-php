<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Http;

use function array_flip;
use function array_intersect_key;
use function array_keys;

use Exception;

use function is_array;
use function is_int;
use function json_decode;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Makes a third-party throwable safe to attach to one of this package's
 * exceptions as its `previous`.
 *
 * ## The leak this closes
 *
 * The transport merges the merchant's ClientID, Username and Password into every
 * request body (CONVENTIONS.md §5), so the bytes handed to the PSR-18 client
 * are a credential. When that client fails it throws a PSR-18 exception that,
 * by the interface's own design, hands back the request it was sent —
 * `getRequest()->getBody()` is the merged payload, password included. Attaching
 * that exception unchanged puts the credential one hop from every exception
 * this transport throws, and an exception graph is walked wholesale by
 * print_r(), var_dump(), var_export(), serialize(), Monolog with
 * `includeStacktraces`, Sentry's serialiser and Symfony's error page. The
 * package spends SensitiveParameterValue, __debugInfo(), __serialize(),
 * Redactor and a nine-character canary closing exactly that channel
 * everywhere else.
 *
 * The same applies to the JsonException the engine throws out of json_encode()
 * and json_decode(): the payload it choked on is a stack-trace argument of the
 * internal frame, and a response body may carry a PAN, an ExpDate and an
 * ApprovalCode — all three on §6's never-log list.
 *
 * ## Two treatments, because the payload sits in two different places
 *
 * - **A PSR-18 failure is replaced by a stand-in.** Its payload is reachable
 *   through getRequest(), and a PSR-7 request is immutable-with-withers, so
 *   scrubbing it in place is not possible; and a client may also hold the same
 *   bytes in private properties under names this package has never heard of, so
 *   what can be inspected is not all there is. The stand-in therefore copies out
 *   what is certainly safe — class name, message, code, file, line, an
 *   argument-free trace, and a request reduced to its method, URI and redacted
 *   body — and the original object is dropped rather than referenced, because a
 *   reference is exactly what the printers follow.
 * - **A JsonException keeps its identity and loses its trace arguments.** Here
 *   the payload sits only in the trace, which is writable, so stripping it in
 *   place preserves the real class, message, file and line with nothing
 *   reconstructed. Its message is safe to keep: json_last_error_msg() returns a
 *   fixed phrase — `Syntax error`, `Malformed UTF-8 characters` — and never
 *   quotes the input.
 *
 * ## Why the request body goes through Redactor
 *
 * Because there is one place in this package that knows what is sensitive, and a
 * second scrubbing path would be a second place to forget a field. The body is a
 * JSON string, so it is decoded, redacted as the array it is, and re-encoded.
 * The redactor keys on the key, which is the whole reason the round trip is
 * worth it — `Password` becomes the marker, a card number keeps first-six /
 * last-four, and the §4.8 misspellings survive untouched.
 *
 * A body that is not decodable JSON is replaced wholesale by the marker. Nothing
 * can be said about which part of it is sensitive, and the two ways to be wrong
 * are not symmetric: publishing an unparsed body may publish a credential, while
 * redacting a parseable-by-someone-else body costs one line of a diagnostic that
 * still names the operation, the URI and the method.
 *
 * ## Why the headers are dropped rather than redacted
 *
 * Because Redactor is keyed on the key, and a header name is a namespace it was
 * never derived against. Its stems come from the manifest's field names plus
 * the credential stems, so `X-Api-Password` would be caught while
 * `Authorization`, `Proxy-Authorization`, `Cookie` and `X-Vendor-Token` would
 * all sail through as values — the same class of miss as a `Cvv2`, in a
 * namespace with no manifest to enumerate it. Running header values through the
 * redactor would therefore publish exactly the headers most worth hiding, and
 * this was measured rather than reasoned about: a `Proxy-Authorization` and an
 * `X-Api-Password` set on the request reached print_r() on the stand-in
 * verbatim. Unlike the body, which is a stream and so a resource handle a
 * graph-walking printer stops at, headers are a plain array property and are
 * walked like any other.
 *
 * The headers are also not this package's to vouch for. The transport sets two
 * — `Content-Type` and `Accept`, both constants of HttpTransport — and
 * everything else on that request was put there by the consumer's PSR-18 stack:
 * a Guzzle handler stack, a corporate proxy, a vendor middleware. So dropping
 * them costs nothing a diagnostic needs. `Host` is the URI's host and the URI
 * is kept; the two the SDK sets are fixed strings a reader can look up here.
 * What is left is a request that still answers the three questions a merchant
 * chasing a failure actually asks: which method, which endpoint, and what was
 * in the body.
 *
 * A PSR-7 implementation that regenerates `Host` from the URI it was given is
 * not a leak for the same reason: that value is already in the URI beside it.
 *
 * ## What is deliberately not preserved
 *
 * The original's own `previous`. This class can vouch for what it copied out of
 * one exception it inspected; it cannot vouch for an arbitrary Throwable further
 * down a chain it did not build.
 *
 * @internal
 */
final readonly class FailureRedactor
{
    /**
     * The trace keys a stand-in keeps.
     *
     * A positive filter, not a blacklist: `args` is the key that carries the
     * payload today, but a frame is a structure this package does not own, and
     * the cost of an unknown key surviving is a leak while the cost of one being
     * dropped is a slightly thinner diagnostic. What is left is the call path —
     * which file, which line, which function — which is the diagnostic half of a
     * trace and holds none of the data.
     *
     * @var list<string>
     */
    private const array SAFE_FRAME_KEYS = ['file', 'line', 'function', 'class', 'type'];

    /**
     * The flags HttpTransport encodes with, for the same reasons
     * (CONVENTIONS.md §4.7). Re-encoding a redacted body with different flags
     * would render an Armenian Description as escape sequences and make the
     * redacted copy harder to read than the original it replaced.
     */
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private Redactor $redactor,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * A stand-in for $failure that carries no credential.
     *
     * The interface the original implemented is preserved, because the mapping
     * from a PSR-18 failure to this package's exceptions is built on that
     * distinction and a caller inspecting getPrevious() reads it the same way.
     * NetworkExceptionInterface is tested first, mirroring the catch order in
     * HttpTransport::dispatch(), so an exception implementing both is
     * classified there and here identically.
     *
     * The stand-in assembles its own message, out of its own named factory,
     * because every string this package can emit as an exception message is
     * kept behind one: the message is the one string in this exchange that this
     * package did not write, and the audit that proves no emittable string
     * carries a credential is worth nothing if the foreign one is assembled
     * outside it. What this method contributes is the two judgements that are
     * its own — which interface the original claimed, and how much of its code
     * and its request may safely be carried.
     */
    public function redactClientFailure(#[SensitiveParameter] ClientExceptionInterface $failure): ClientExceptionInterface
    {
        $standIn = match (true) {
            $failure instanceof NetworkExceptionInterface => RedactedNetworkException::standingInFor(
                $failure,
                $this->codeOf($failure),
                $this->redactedRequest($failure->getRequest()),
            ),
            $failure instanceof RequestExceptionInterface => RedactedRequestException::standingInFor(
                $failure,
                $this->codeOf($failure),
                $this->redactedRequest($failure->getRequest()),
            ),
            default => RedactedClientException::standingInFor($failure, $this->codeOf($failure)),
        };

        $this->copyOrigin($standIn, $failure);

        return $standIn;
    }

    /**
     * $failure with the arguments stripped out of its stack trace, in place.
     *
     * Returns the same object it was given: the identity is the point. A
     * JsonException carries its payload only in the trace, so there is nothing
     * to reconstruct and no reason to hand back something that is not the
     * exception the engine threw.
     *
     * Typed Exception rather than Throwable because the write below reaches for
     * a property Exception declares. An Error declares its own, and this package
     * has no Error to hand here.
     *
     * @template T of Exception
     *
     * @param T $failure
     *
     * @return T
     */
    public function withoutTraceArguments(Exception $failure): Exception
    {
        $this->write($failure, 'trace', $this->safeFrames($failure->getTrace()));

        return $failure;
    }

    /**
     * $request with every header dropped and its body replaced by a redacted
     * copy.
     *
     * The headers go rather than get scrubbed, for the reason recorded on the
     * class: the redactor is keyed on the key and header names are a namespace
     * its key set was never derived against, so redacting them would pass
     * `Authorization` and `Cookie` through untouched. What survives — method,
     * URI, redacted body — is the diagnostic half.
     *
     * PSR-7 requests are immutable-with-withers, so each call hands back a new
     * object and the one the client holds is untouched.
     */
    private function redactedRequest(#[SensitiveParameter] RequestInterface $request): RequestInterface
    {
        $stripped = $request;

        foreach (array_keys($request->getHeaders()) as $name) {
            $stripped = $stripped->withoutHeader($name);
        }

        return $stripped->withBody(
            $this->streamFactory->createStream($this->redactedBody((string) $request->getBody())),
        );
    }

    /**
     * The body with every sensitive field masked, or the marker when it cannot
     * be read as JSON.
     *
     * json_encode() cannot fail on the way back out: its input is what
     * json_decode() just produced, so it is valid UTF-8 by construction and
     * holds no resource, and the redactor replaces values with strings.
     */
    private function redactedBody(#[SensitiveParameter] string $body): string
    {
        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Redactor::REDACTED;
        }

        if (!is_array($decoded)) {
            return Redactor::REDACTED;
        }

        return json_encode($this->redactor->redact($decoded), self::JSON_FLAGS);
    }

    /**
     * The original's code, when it is the int a stand-in can carry.
     *
     * Throwable::getCode() is not typed: Exception declares it int, Error
     * declares it int, and a subclass is free to overwrite the property with
     * something else — PDOException famously holds an SQLSTATE string there.
     * A stand-in reports 0 rather than refuse to be built over a code it cannot
     * pass to a constructor.
     */
    private function codeOf(Throwable $failure): int
    {
        $code = $failure->getCode();

        return is_int($code) ? $code : 0;
    }

    /**
     * Points the stand-in at the place the original was thrown.
     *
     * Exception::getFile(), getLine() and getTrace() are final, so a decorator
     * cannot delegate them the way it delegates getMessage() and getCode() —
     * they answer from properties the constructor fills in with the stand-in's
     * own birthplace, which is a line in this package rather than the line in
     * the client that failed. Writing the original's values into those
     * properties is the only way to keep the answer true, and it is a copy of
     * three scalars and a filtered array, not a reimplementation of anything.
     *
     * Without this, a merchant chasing a real cURL failure would be handed the
     * inside of a redactor.
     */
    private function copyOrigin(RuntimeException $standIn, Throwable $failure): void
    {
        $this->write($standIn, 'file', $failure->getFile());
        $this->write($standIn, 'line', $failure->getLine());
        $this->write($standIn, 'trace', $this->safeFrames($failure->getTrace()));
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    private function safeFrames(array $trace): array
    {
        $safe = [];
        $keep = array_flip(self::SAFE_FRAME_KEYS);

        foreach ($trace as $frame) {
            $safe[] = array_intersect_key($frame, $keep);
        }

        return $safe;
    }

    /**
     * Writes one of Exception's own properties on $target.
     *
     * Reflection because there is no other door: `file`, `line` and `trace` are
     * private to Exception, filled in by the engine at construction, and exposed
     * only through final getters. This is the same class of surgery every error
     * reporter performs to rewrite a frame, and it is confined to this method.
     */
    private function write(Exception $target, string $property, mixed $value): void
    {
        (new ReflectionProperty(Exception::class, $property))->setValue($target, $value);
    }
}
