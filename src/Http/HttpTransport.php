<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Http;

use function array_key_exists;
use function array_keys;
use function array_merge;

use Closure;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;

use function get_debug_type;
use function hrtime;

use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use SensitiveParameter;

use function sprintf;

use Throwable;

use function usleep;

/**
 * Sends a request object to the REST gateway and returns the decoded body.
 *
 * The single place where every empirical finding about this gateway is either
 * honoured or quietly lost. Four of them shape the whole class:
 *
 * ## HTTP status carries no business meaning (CONVENTIONS.md §4.1, §4.2)
 *
 * An authentication failure arrives as HTTP 200 with `ResponseCode` 20. An
 * unattempted payment queried through `GetPaymentDetails` arrives as HTTP 500
 * with a body that is valid JSON and perfectly deliberate. A wrong endpoint name
 * arrives as HTTP 404 carrying the same envelope as the 500. So the status is
 * read exactly once in this file, on the line that builds a log record, and is
 * carried onward only as diagnostic data. No branch anywhere in this class reads
 * it. Success, failure, fault and retry are all decided by the shape of the
 * decoded body:
 *
 * - a `ResponseCode` key — a business answer, handed to ResponseCode;
 * - a `Message` key and no `ResponseCode` — the ASP.NET fault envelope;
 * - neither — no evidence of failure, so the body is returned as decoded.
 *
 * That last case is not a loophole. `GetPendingTransactions` answers with a
 * top-level JSON array of rows and no response code at all — the manifest's own
 * sample body is `[ { "OrderId": 1, … } ]` — so a transport that demanded a
 * `ResponseCode` would make that endpoint unreachable. Shape validation belongs
 * to the hydrator, which fails loudly on a body it cannot map; this class
 * throws on *evidence of failure*, not on the absence of evidence of success.
 *
 * ## InitPayment is idempotent but not immutable (CONVENTIONS.md §4.4)
 *
 * A repeat call returns the same PaymentID but overwrites the earlier call's
 * parameters, verified through the Opaque value echoed in the callback. A retry
 * is therefore safe only if it resends the bytes already sent. The body is
 * encoded once, before the first attempt, and dispatch() takes that string —
 * never the request object, never the field array — so there is nothing inside
 * the retry loop that could re-encode anything.
 *
 * ## Four operations must never be retried (CONVENTIONS.md §4.5)
 *
 * ConfirmPayment, RefundPayment, CancelPayment and MakeBindingPayment move
 * money. A request is retried only if its own isIdempotent() says so *and* its
 * operation is absent from NEVER_RETRY below — see that constant for why the
 * request's word is not taken alone. There is no option, argument or setter that
 * can override either half. A transport failure on one of them raises
 * IndeterminateStateException, which is deliberately not a subtype of
 * TransportException so that a caller retrying transport failures cannot swallow
 * the one case that may double-charge.
 *
 * ## A retry is never an answer to an HTTP response
 *
 * Only a PSR-18 NetworkExceptionInterface — a request that did not complete —
 * is retried. An HTTP 500 from this gateway is a semantic refusal, not a
 * transient fault, and is indistinguishable from a client input error (§4.2), so
 * retrying one repeats a refusal.
 *
 * ## Nothing thrown from here carries the payload it failed on
 *
 * The bytes sent to the gateway are the caller's fields plus the merchant's
 * credentials, and a response body may carry a PAN, an ExpDate and an
 * ApprovalCode — §6 forbids all of it in anything that reaches a log. Two
 * channels used to carry it anyway, and both were measured rather than guessed:
 *
 * - **The cause.** A PSR-18 exception hands back the request it was sent, so
 *   `previous->getRequest()->getBody()` was the credential payload. Every cause
 *   attached below is a scrubbed stand-in built by FailureRedactor, which keeps
 *   the interface, the class name, the message, the call site and the request —
 *   redacted body, no headers — and drops the original object. Building one
 *   means calling into the failed client, so the call is guarded; see
 *   redactedFailure() for what a client that refuses to be inspected gets
 *   instead.
 * - **The stack frame.** With `zend.exception_ignore_args` off, which is the
 *   php.ini-development default, a frame keeps its arguments, and print_r() on
 *   an exception prints them. Every parameter here that carries the encoded
 *   request body or a raw response body is marked #[SensitiveParameter], so the
 *   frame renders an empty SensitiveParameterValue instead.
 *
 * @internal
 */
final class HttpTransport
{
    /**
     * §4.12 confirms `Accept: application/xml` is honoured too, but one
     * representation is enough and JSON is what every probe exercised.
     */
    private const string CONTENT_TYPE = 'application/json; charset=utf-8';

    private const string ACCEPT = 'application/json';

    /**
     * The four operations CONVENTIONS.md §4.5's retry table marks **Never**:
     * ConfirmPayment captures funds, RefundPayment moves money, CancelPayment
     * changes state, MakeBindingPayment charges a card. The list is that table
     * and nothing else — it is transcribed from a source, not assembled from
     * judgement, so a reader can check it against §4.5 line by line rather than
     * wonder who picked it.
     *
     * It duplicates what the DTOs already answer, deliberately. The transport
     * does not trust a request object's word on this. Contracts\RequestInterface
     * is public surface — a caller holds an implementation and passes it in — so
     * isIdempotent() is, in the end, a claim made by code this package does not
     * own; and even in-house, one wrong `return true` on a capture is a silent
     * defect that no type checker and no gateway response would report.
     *
     * The asymmetry this corrects: withCredentials() below already refuses to
     * trust a third-party body that declares a `Password`, which costs a
     * credential in a body we control. Trusting the same stranger about whether
     * a capture may be repeated costs the customer up to five charges. The
     * cheaper failure had the guard and the expensive one had none.
     *
     * Applied as a conjunction with isIdempotent() rather than as a replacement
     * for it, which is what makes the duplication safe: two sources must agree
     * before anything is retried, so drift on either side can only ever make
     * this transport more conservative, never less. An operation absent from
     * this list is still governed by its DTO.
     *
     * ActivateBinding and DeactivateBinding are absent, and deliberately so.
     * Both change state, and both are already refused a retry by their own
     * DTOs, which answer isIdempotent() false — the conjunction needs only one
     * honest false. Adding them would buy nothing against the threat this
     * constant models, a third-party ConfirmPayment that lies about being
     * repeatable, because a third-party ActivateBinding is not a lie anyone has
     * an incentive to tell; and it would cost the property that makes the list
     * worth auditing, since the entries would no longer all come from one
     * place. Nor is §4.5 widened to match: that table records what the gateway
     * was observed to do, and nothing has been observed about retrying a
     * binding call — §13 records that bindings cannot be exercised on this
     * sandbox at all.
     *
     * @var list<string>
     */
    private const array NEVER_RETRY = [
        'ConfirmPayment',
        'RefundPayment',
        'CancelPayment',
        'MakeBindingPayment',
    ];

    private const int MINIMUM_ATTEMPTS = 1;

    /**
     * The ceiling is low deliberately. This is a payment switch behind a
     * merchant's checkout, not a fan-out to a CDN: past a handful of attempts
     * the customer has already left, and the only retryable state-changing
     * operation is InitPayment, whose repeat overwrites an order (§4.4).
     */
    private const int MAXIMUM_ATTEMPTS = 5;

    /**
     * The first pause, doubled per attempt: 100 ms, then 200 ms, then 400 ms.
     *
     * No jitter. Jitter exists to de-synchronise a herd of clients hammering one
     * service; a single merchant integration retrying its own payment twice is
     * not a herd, and the randomness would make the delay untestable in exchange
     * for nothing.
     */
    private const int BASE_BACKOFF_MICROSECONDS = 100_000;

    /**
     * CONVENTIONS.md §4.7. JSON_UNESCAPED_UNICODE is load-bearing rather than
     * cosmetic: Armenian Description values round-trip correctly and must not
     * be escaped. JSON_PRESERVE_ZERO_FRACTION matters for the same reason a
     * float must never reach the wire — it keeps a decimal that arrived as one
     * from being re-rendered as an integer.
     */
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    private readonly ClientInterface $client;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly LoggerInterface $logger;

    private readonly int $maxAttempts;

    /**
     * CONVENTIONS.md §6 requires a redactor on every log record. It is
     * constructed here rather than injected so that there is no argument a
     * caller could pass to switch it off.
     */
    private readonly Redactor $redactor;

    /**
     * Scrubs a third-party throwable before it is attached to one of this
     * package's exceptions as its `previous`.
     *
     * Constructed here for the same reason the redactor is: there is no argument
     * a caller could pass to switch it off. A PSR-18 exception hands back the
     * request it was sent, whose body is the merged credential payload, and an
     * exception graph is walked wholesale by print_r(), var_dump(), serialize()
     * and every error reporter a merchant is likely to have installed.
     */
    private readonly FailureRedactor $failureRedactor;

    /**
     * How this class waits between attempts, isolated behind a property so a
     * test can observe the delay instead of spending it.
     *
     * Null until the first pause, then the real usleep(). Deliberately not a
     * constructor parameter: a consumer-facing seam here would be a switch for
     * turning backoff off, and backoff is not a preference. A test replaces it
     * by reflection, which no consumer's static analyser will suggest and no
     * documented API offers.
     *
     * It exists because the alternative is worse in both directions. Sleeping
     * for real in the suite would spend seconds per mutation run, and — the
     * decisive half — a mutant that alters the backoff arithmetic or deletes the
     * pause outright is invisible to any assertion that does not observe the
     * delay, so the retry timing would be the one part of this class no test
     * could hold.
     *
     * @var (Closure(int): void)|null
     */
    private ?Closure $sleeper = null;

    /**
     * @param int $maxAttempts Total attempts for a retryable operation, 1..5. Not a switch for *which* operations retry — see CONVENTIONS.md §4.5.
     *
     * @throws ConfigurationException when no PSR-18 client or PSR-17 factory can be discovered
     * @throws ValidationException    when $maxAttempts is out of range
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Environment $environment,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        int $maxAttempts = 3,
    ) {
        if ($maxAttempts < self::MINIMUM_ATTEMPTS || $maxAttempts > self::MAXIMUM_ATTEMPTS) {
            throw ValidationException::maxAttemptsOutOfRange($maxAttempts);
        }

        try {
            $this->client = $httpClient ?? Psr18ClientDiscovery::find();
        } catch (DiscoveryException $failure) {
            throw ConfigurationException::noHttpClientFound($failure);
        }

        try {
            $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        } catch (DiscoveryException $failure) {
            throw ConfigurationException::noRequestFactoryFound($failure);
        }

        try {
            $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        } catch (DiscoveryException $failure) {
            throw ConfigurationException::noStreamFactoryFound($failure);
        }

        $this->logger = $logger ?? new NullLogger();
        $this->maxAttempts = $maxAttempts;
        $this->redactor = new Redactor();
        $this->failureRedactor = new FailureRedactor($this->redactor, $this->streamFactory);
    }

    /**
     * Sends $request and returns its decoded body, having found no evidence of
     * failure in it.
     *
     * Order of operations, and it is the order the findings demand: merge the
     * credentials the request asked for, encode once, decide whether a failed
     * attempt may be repeated, attempt, decode, look for a fault envelope, then
     * branch on the response code.
     *
     * That decision is a conjunction of the request's own isIdempotent() and
     * NEVER_RETRY — never either one alone, and never anything a caller passed
     * (CONVENTIONS.md §4.5).
     *
     * No hydration happens here. The decoded array goes to the hydrator through
     * the client that called this, which keeps this class free of any knowledge
     * of which DTO belongs to which operation.
     *
     * The array-key type is `array-key` rather than `string` on purpose: a
     * GetPendingTransactions body is a top-level JSON array, whose keys decode
     * as integers. Narrowing the return type would either exclude that endpoint
     * or force an assertion this class cannot honestly make.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationException       when the client rejects the request as malformed
     * @throws GatewayFaultException        on the ASP.NET fault envelope
     * @throws IndeterminateStateException  when a non-idempotent operation fails in transport
     * @throws SerializationException       when the body cannot be encoded or decoded
     * @throws TransportException           when a retryable operation exhausts its attempts
     * @throws ValidationException          when the request body carries a credential field
     * @throws \DavitVardanyan\AmeriabankVpos\Exception\ApiException on a non-success response code
     */
    public function send(RequestInterface $request): array
    {
        $operation = $request->operation();
        $fields = $this->withCredentials($request);
        $isRetryable = $request->isIdempotent() && !in_array($operation, self::NEVER_RETRY, true);

        $exchange = $this->dispatch(
            $operation,
            $this->encode($operation, $fields),
            $isRetryable,
            $this->paymentIdOf($fields),
        );

        return $this->interpret($operation, $exchange['status'], $exchange['body']);
    }

    /**
     * The request's own fields plus the credential set it declared.
     *
     * requiresClientId() picks between merchantFields() and userFields(), and
     * the answer is the manifest's, not this class's.
     *
     * The guard is not ceremony. Contracts\RequestInterface is public surface —
     * it has to be, since a caller holds an implementation and passes it in —
     * so a third-party implementation could put `Password` in its own body, and
     * §4.12 records that the gateway ignores unknown request fields silently.
     * The credential names are read back from Credentials::merchantFields()
     * rather than transcribed, so the guard covers all three fields whichever
     * set this request uses, and cannot drift from the class that defines them.
     *
     * Credentials are merged last, so that even if this guard were ever weakened
     * the configured credential still wins over anything a body declared.
     *
     * @return array<string, scalar>
     *
     * @throws ValidationException
     */
    private function withCredentials(RequestInterface $request): array
    {
        $fields = $request->toArray();
        $credentials = $this->credentials->merchantFields();

        foreach (array_keys($credentials) as $field) {
            if (array_key_exists($field, $fields)) {
                throw ValidationException::credentialFieldInRequestBody($request->operation(), $field);
            }
        }

        return array_merge(
            $fields,
            $request->requiresClientId() ? $credentials : $this->credentials->userFields(),
        );
    }

    /**
     * The PaymentID this request addresses, when it has one.
     *
     * Only used to make IndeterminateStateException's reconcile instruction
     * specific: three of the four non-idempotent operations carry a PaymentID,
     * and telling the caller *which* payment to reconcile is the difference
     * between an actionable message and a homework assignment. A PaymentID is a
     * GUID the gateway itself puts in a redirect URL, not a datum §6 protects.
     *
     * @param array<string, scalar> $fields
     */
    private function paymentIdOf(#[SensitiveParameter] array $fields): ?string
    {
        $paymentId = $fields['PaymentID'] ?? null;

        return is_string($paymentId) ? $paymentId : null;
    }

    /**
     * The request body, serialised exactly once.
     *
     * @param array<string, scalar> $fields
     *
     * @throws SerializationException
     */
    private function encode(string $operation, #[SensitiveParameter] array $fields): string
    {
        try {
            // JSON_THROW_ON_ERROR turns json_encode()'s false return into the
            // exception caught below, which is why nothing here narrows the
            // result: PHPStan already types it as a string under that flag.
            return json_encode($fields, self::JSON_FLAGS);
        } catch (JsonException $failure) {
            throw SerializationException::requestNotEncodable(
                $operation,
                $this->failureRedactor->withoutTraceArguments($failure),
            );
        }
    }

    /**
     * Attempts the exchange until one completes, and reports what came back.
     *
     * Takes the body as a string. That is the whole of the identical-body
     * guarantee: the request object and its field array stay in send(), so no
     * amount of editing inside this loop can re-serialise a body between
     * attempts. A fresh PSR-7 request is built per attempt because a consumed
     * stream is not necessarily rewindable — but it is built from the
     * same bytes.
     *
     * Retry is applied here and only here, and only a PSR-18 transport failure
     * reaches the decision. Note the catch order: the two specific interfaces
     * come before the base one, and RequestExceptionInterface — the request is
     * malformed — is our defect, not the network's, so it is not retried.
     *
     * Eligibility, though, is settled before the call. $isRetryable is a
     * conjunction — the request's own isIdempotent() *and* the operation's
     * absence from NEVER_RETRY — and the parameter is named for the conjunction
     * rather than for either half on purpose. A `!$isIdempotent` on the line
     * below would read as this class taking a third-party DTO's word on whether
     * a capture may be repeated, which is the one thing NEVER_RETRY exists to
     * refuse.
     *
     * @param bool $isRetryable isIdempotent() and not NEVER_RETRY, as decided in send(). Never a caller's option.
     *
     * @return array{status: int, body: string}
     *
     * @throws ConfigurationException
     * @throws IndeterminateStateException
     * @throws TransportException
     */
    private function dispatch(
        string $operation,
        #[SensitiveParameter]
        string $encodedBody,
        bool $isRetryable,
        ?string $paymentId,
    ): array {
        $url = $this->environment->apiUrl($operation);
        $attempt = 1;

        while (true) {
            $httpRequest = $this->requestFactory->createRequest('POST', $url)
                ->withHeader('Content-Type', self::CONTENT_TYPE)
                ->withHeader('Accept', self::ACCEPT)
                ->withBody($this->streamFactory->createStream($encodedBody));

            $startedAt = hrtime(true);

            try {
                $response = $this->client->sendRequest($httpRequest);
            } catch (NetworkExceptionInterface $failure) {
                if (!$isRetryable) {
                    throw IndeterminateStateException::afterTransportFailure(
                        $operation,
                        $paymentId,
                        $this->redactedFailure($failure),
                    );
                }

                if ($attempt >= $this->maxAttempts) {
                    throw TransportException::requestFailed(
                        $operation,
                        $failure::class,
                        $this->redactedFailure($failure),
                    );
                }

                $this->log(LogLevel::WARNING, 'Ameriabank vPOS request failed in transport; retrying.', [
                    'operation' => $operation,
                    'url' => $url,
                    'attempt' => $attempt,
                    'duration_ms' => $this->elapsedMilliseconds($startedAt),
                    'exception' => $failure::class,
                ]);

                $this->pause($this->backoffMicroseconds($attempt));
                $attempt++;

                continue;
            } catch (RequestExceptionInterface $failure) {
                throw ConfigurationException::requestRejectedByClient(
                    $operation,
                    $failure::class,
                    $this->redactedFailure($failure),
                );
            } catch (ClientExceptionInterface $failure) {
                throw TransportException::requestFailed(
                    $operation,
                    $failure::class,
                    $this->redactedFailure($failure),
                );
            }

            // The only read of the status in this package's REST path, and the
            // next statement is what it was read for. Everything downstream
            // receives it as data.
            $statusCode = $response->getStatusCode();

            $this->log(LogLevel::DEBUG, 'Ameriabank vPOS exchange completed.', [
                'operation' => $operation,
                'url' => $url,
                'status' => $statusCode,
                'attempt' => $attempt,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

            return ['status' => $statusCode, 'body' => (string) $response->getBody()];
        }
    }

    /**
     * The scrubbed stand-in for $failure, or a bare one when the failure refuses
     * to be inspected.
     *
     * FailureRedactor builds a stand-in by reaching into a third party: it calls
     * getRequest() on the failure and then reads that request's headers and
     * body. Every one of those calls runs code this package does not own, from
     * inside a catch block — and a throw out of any of them leaves the catch by
     * a route that maps nothing. The original PSR-18 exception is discarded on
     * the way out and whatever the client's accessor raised takes its place, so
     * `catch (VposExceptionInterface)` catches nothing (CONVENTIONS.md §5) and,
     * worse, a non-idempotent operation loses the IndeterminateStateException
     * it was owed — the one case where retrying may double-charge (§4.5).
     *
     * Measured rather than supposed: a client whose getRequest() throws sent a
     * bare LogicException out of send() for an idempotent and a non-idempotent
     * request alike.
     *
     * The fallback asks the failure for exactly two things, and the engine
     * guarantees both. `::class` is read off the object by the VM and runs no
     * userland code at all. getMessage() is declared final on Exception and
     * final on Error, and PHP refuses to compile a userland class that
     * implements Throwable without extending one of those two — so there is no
     * third case in which it could be overridden into throwing. Nothing else is
     * asked of the object, its code is not read (Throwable::getCode() is
     * untyped, and 0 is what a stand-in reports for one it cannot carry), and
     * the object is dropped rather than referenced. Both reads happen inside
     * RedactedClientException::forUninspectableFailure(), which is where that
     * count of two and that deliberate 0 are recorded: the message is a string
     * this package did not write, and every string this package can emit as an
     * exception message is kept behind a named factory in an audited file.
     *
     * Its trace loses its arguments, like every other stand-in's. A trace built
     * here runs up through the caller's own frames, and the shape this fallback
     * exists for — a client holding the payload in a property of its own — is
     * exactly the shape a frame argument hands to a printer. Stripping them
     * touches only an object this method just constructed.
     *
     * The bare stand-in is a RedactedClientException whatever the original's
     * interface, and that loses the network/request distinction on
     * getPrevious(). It costs nothing that decides anything: the mapping from a
     * PSR-18 failure to this package's exceptions is made by the catch clauses
     * above from the original's own type, before this is called. A failure that
     * cannot be inspected cannot be vouched for, and claiming an interface for
     * it would be the one claim this method is in no position to make.
     */
    private function redactedFailure(#[SensitiveParameter] ClientExceptionInterface $failure): ClientExceptionInterface
    {
        try {
            return $this->failureRedactor->redactClientFailure($failure);
        } catch (Throwable) {
            return $this->failureRedactor->withoutTraceArguments(
                RedactedClientException::forUninspectableFailure($failure),
            );
        }
    }

    /**
     * Decides what the gateway said, from the body alone.
     *
     * $statusCode arrives as data and is used for two things: a log record and
     * the diagnostic status carried by GatewayFaultException. Nothing branches
     * on it — the same fault envelope has been observed at 404 and at 500, so a
     * status-driven branch would be wrong in both directions.
     *
     * A body carrying both `Message` and `ResponseCode` takes the response-code
     * path. `Message` alone is the fault envelope; the response code is what a
     * business answer always carries.
     *
     * GetPendingTransactions gets no special case. Its failure shape has never
     * been observed, so guessing at one would be inventing a contract.
     *
     * @return array<array-key, mixed>
     *
     * @throws GatewayFaultException
     * @throws SerializationException
     * @throws \DavitVardanyan\AmeriabankVpos\Exception\ApiException
     *
     * @todo unverified — see CONVENTIONS.md §13 (GetPendingTransactions failure shape)
     */
    private function interpret(string $operation, int $statusCode, #[SensitiveParameter] string $body): array
    {
        $decoded = $this->decode($operation, $body);

        if (!array_key_exists('ResponseCode', $decoded)) {
            return $this->faultOrBody($operation, $statusCode, $decoded);
        }

        $raw = $decoded['ResponseCode'];

        if (!is_int($raw) && !is_string($raw)) {
            throw SerializationException::unexpectedPayload($operation, sprintf(
                'ResponseCode was %s, expected an integer or a string',
                get_debug_type($raw),
            ));
        }

        $code = ResponseCode::fromWire($raw);

        if ($code->isSuccess()) {
            return $decoded;
        }

        $responseMessage = $decoded['ResponseMessage'] ?? null;

        throw $code->toException($operation, is_string($responseMessage) ? $responseMessage : '');
    }

    /**
     * A body with no response code: the ASP.NET fault envelope, or something
     * this class has no evidence against.
     *
     * The envelope is `{"Message":"An error has occurred."}` and its Message is a
     * string. A non-string under that key is not the envelope, and inventing a
     * fault out of it would report a refusal the gateway never made — so it
     * falls through to the body, where the hydrator will refuse it by name.
     *
     * Only the Message text reaches the exception, never the body: a body may
     * carry card data and an exception message reaches logs (CONVENTIONS.md
     * §5, §6).
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return array<array-key, mixed>
     *
     * @throws GatewayFaultException
     */
    private function faultOrBody(string $operation, int $statusCode, #[SensitiveParameter] array $decoded): array
    {
        $faultMessage = $decoded['Message'] ?? null;

        if (!is_string($faultMessage)) {
            return $decoded;
        }

        $this->log(LogLevel::WARNING, 'Ameriabank vPOS returned a gateway fault.', [
            'operation' => $operation,
            'status' => $statusCode,
        ]);

        throw GatewayFaultException::fromFaultEnvelope($operation, $statusCode, $faultMessage);
    }

    /**
     * Named arguments carry the depth default rather than repeating 512,
     * because a magic number nothing here chose is a magic number nothing here
     * should own.
     *
     * Neither failure message carries the body (CONVENTIONS.md §6): the
     * decoder's own exception quotes the payload, so only its class name is
     * passed on, and the unexpected-payload message reports the decoded *type*,
     * never the value.
     *
     * @return array<array-key, mixed>
     *
     * @throws SerializationException
     */
    private function decode(string $operation, #[SensitiveParameter] string $body): array
    {
        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw SerializationException::malformedJson(
                $operation,
                $this->failureRedactor->withoutTraceArguments($failure),
            );
        }

        if (!is_array($decoded)) {
            throw SerializationException::unexpectedPayload($operation, sprintf(
                'the body decoded to %s, expected a JSON object or array',
                get_debug_type($decoded),
            ));
        }

        return $decoded;
    }

    /**
     * 100 ms × 2^(attempt−1), in microseconds.
     */
    private function backoffMicroseconds(int $attempt): int
    {
        return self::BASE_BACKOFF_MICROSECONDS * 2 ** ($attempt - 1);
    }

    /**
     * Waits, through the seam described on $sleeper.
     */
    private function pause(int $microseconds): void
    {
        $this->sleeper ??= usleep(...);

        ($this->sleeper)($microseconds);
    }

    /**
     * Milliseconds since $startedAt, as measured by the monotonic clock.
     */
    private function elapsedMilliseconds(int|float $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e6;
    }

    /**
     * The one way a record leaves this class, so that the redactor cannot be
     * bypassed by adding a log call that forgets it.
     *
     * The context is kept to metadata — operation, URL, status, attempt,
     * duration — so there is nothing here for the redactor to mask today. That
     * is the point of routing every record through it anyway: a body added to a
     * context later is masked rather than published.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        $this->logger->log($level, $message, $this->redactor->redact($context));
    }
}
