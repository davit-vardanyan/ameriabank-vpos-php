<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use function array_map;

use DavitVardanyan\AmeriabankVpos\Http\FailureRedactor;
use DavitVardanyan\AmeriabankVpos\Http\RedactedClientException;
use DavitVardanyan\AmeriabankVpos\Http\RedactedNetworkException;
use DavitVardanyan\AmeriabankVpos\Http\RedactedRequestException;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;
use Http\Client\Exception\NetworkException;
use Http\Client\Exception\RequestException;
use Http\Client\Exception\TransferException;

use function json_encode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function print_r;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface as PsrRequest;
use RuntimeException;

use function str_contains;

/**
 * What a third-party throwable looks like after it has been made safe to attach
 * to one of this package's exceptions.
 *
 * ## The defect being defended against
 *
 * A PSR-18 exception hands back the request it was sent, and this transport's
 * request body is the merchant's credentials merged into the payload
 * (CONVENTIONS.md §5). Attached unchanged as `previous`, that put the password
 * one hop from every exception the transport throws — measurably: `print_r()`
 * on the thrown IndeterminateStateException printed it, as did var_dump(),
 * var_export() and serialize(), while the message and getTraceAsString()
 * stayed clean. Every error reporter a merchant is likely to have installed
 * walks that graph.
 *
 * ## Canaries
 *
 * Nine characters, for a recorded reason: PHP truncates a string argument in a
 * rendered stack trace at fifteen, so a longer canary makes an assertion look
 * at a prefix that never matches and the test passes vacuously. None of these
 * values is a credential of any kind.
 */
#[CoversClass(FailureRedactor::class)]
#[CoversClass(RedactedClientException::class)]
#[CoversClass(RedactedNetworkException::class)]
#[CoversClass(RedactedRequestException::class)]
#[UsesClass(Redactor::class)]
final class FailureRedactorTest extends TestCase
{
    private const string PASSWORD = 'pw-canary';

    private const string URL = 'https://servicestest.ameriabank.am/VPOS/api/VPOS/InitPayment';

    private const string MARKER = '[redacted]';

    /**
     * Two header canaries, both nine characters, for the two shapes a consumer's
     * PSR-18 stack adds: one the redactor's key set would catch by name, one it
     * would not. Neither is a credential of any kind.
     */
    private const string PROXY_CANARY = 'px-canary';

    private const string HEADER_CANARY = 'hx-canary';

    private Psr17Factory $psr17;

    private FailureRedactor $redactor;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->redactor = new FailureRedactor(new Redactor(), $this->psr17);
    }

    public function testTheCanaryIsShortEnoughToSurviveATraceTruncation(): void
    {
        self::assertLessThan(15, mb_strlen(self::PASSWORD));
        self::assertLessThan(15, mb_strlen(self::PROXY_CANARY));
        self::assertLessThan(15, mb_strlen(self::HEADER_CANARY));
    }

    /**
     * The body is scrubbed and everything a diagnostic needs survives.
     */
    public function testANetworkFailureKeepsItsShapeAndLosesItsCredential(): void
    {
        $failure = new NetworkException('Connection timed out', $this->request());

        $standIn = $this->redactor->redactClientFailure($failure);

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertSame('Connection timed out', $standIn->getMessage());
        self::assertSame(NetworkException::class, $standIn->originalClass());

        $request = $standIn->getRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertStringNotContainsString(self::PASSWORD, (string) $request->getBody());
        self::assertStringContainsString('"Password":"' . self::MARKER . '"', (string) $request->getBody());
    }

    /**
     * The interface the client chose is the interface the caller gets back.
     *
     * The transport maps the two to opposite outcomes — a network failure may
     * be retried, a malformed request never is — so a stand-in that answered
     * `true` to both instanceof checks would make the transport's own decision
     * unreadable from getPrevious().
     */
    public function testARequestFailureKeepsItsOwnInterfaceAndNotTheOtherOne(): void
    {
        $failure = new RequestException('Invalid header', $this->request());

        $standIn = $this->redactor->redactClientFailure($failure);

        self::assertInstanceOf(RedactedRequestException::class, $standIn);
        self::assertSame('Invalid header', $standIn->getMessage());
        self::assertSame(RequestException::class, $standIn->originalClass());

        // Through class_implements() rather than instanceof: the stand-in's
        // declared interfaces make both instanceof forms constant, and PHPStan
        // rejects an assertion it can already answer. The claim being made is
        // about the class, so it is asserted about the class.
        $interfaces = class_implements($standIn);

        self::assertContains(RequestExceptionInterface::class, $interfaces);
        self::assertNotContains(NetworkExceptionInterface::class, $interfaces);
        self::assertStringNotContainsString(self::PASSWORD, (string) $standIn->getRequest()->getBody());
    }

    /**
     * A network failure must not come back as a request failure either.
     */
    public function testANetworkFailureIsNotClassifiedAsARequestFailure(): void
    {
        $standIn = $this->redactor->redactClientFailure(
            new NetworkException('Connection timed out', $this->request()),
        );

        $interfaces = class_implements($standIn);

        self::assertContains(NetworkExceptionInterface::class, $interfaces);
        self::assertNotContains(RequestExceptionInterface::class, $interfaces);
    }

    /**
     * Both interfaces at once: network wins, matching the catch order in
     * HttpTransport::dispatch().
     */
    public function testAFailureImplementingBothInterfacesIsTreatedAsNetworkShaped(): void
    {
        $standIn = $this->redactor->redactClientFailure(
            new AmbiguousClientException('Ambiguous', $this->request()),
        );

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertSame(AmbiguousClientException::class, $standIn->originalClass());
    }

    /**
     * Plain ClientExceptionInterface exposes no getRequest(), which the PSR
     * makes optional. There is nothing to redact and nothing to hand back, and
     * the stand-in is built anyway.
     */
    public function testAFailureCarryingNoRequestIsHandledWithoutError(): void
    {
        $failure = new TransferException('Something else went wrong', 42);

        $standIn = $this->redactor->redactClientFailure($failure);

        self::assertInstanceOf(RedactedClientException::class, $standIn);
        self::assertSame('Something else went wrong', $standIn->getMessage());

        $interfaces = class_implements($standIn);

        self::assertContains(ClientExceptionInterface::class, $interfaces);
        self::assertNotContains(NetworkExceptionInterface::class, $interfaces);
        self::assertNotContains(RequestExceptionInterface::class, $interfaces);
        self::assertSame(42, $standIn->getCode());
        self::assertSame(TransferException::class, $standIn->originalClass());
    }

    /**
     * The other door into a stand-in: the one HttpTransport uses when this
     * class could not build one at all.
     *
     * HttpTransport::redactedFailure() reaches
     * RedactedClientException::forUninspectableFailure() from inside a catch
     * block, after the ordinary path threw — a client whose getRequest(), or
     * whose request's own accessors, raised. Its behaviour is asserted at the
     * transport level too, but only there, and that left the factory itself
     * described rather than pinned: nothing said what it must do when handed a
     * failure that *does* carry a readable code.
     *
     * It must report 0 anyway. Throwable::getCode() is untyped and this object
     * has already demonstrated that its accessors are not safe to call, so the
     * fallback asks it exactly two questions — its class and its message — and
     * a third would be one more chance to be thrown out of. The 42 below is
     * there so that 0 cannot be the fixture's own value read back: a factory
     * that quietly started copying the code would fail this line.
     */
    public function testTheUninspectableFallbackReportsNoCodeEvenWhenTheOriginalCarriesOne(): void
    {
        $standIn = RedactedClientException::forUninspectableFailure(
            new TransferException('Something else went wrong', 42),
        );

        self::assertSame(0, $standIn->getCode());
        self::assertSame('Something else went wrong', $standIn->getMessage());
        self::assertSame(TransferException::class, $standIn->originalClass());
    }

    /**
     * The same door, given the shape it exists for.
     *
     * A network-shaped failure whose getRequest() throws comes back as a plain
     * RedactedClientException: the network interface is deliberately not
     * claimed, because a failure that cannot be inspected cannot be vouched
     * for, and nothing depends on the claim — the catch clause that decided the
     * original was retryable read the *original*'s type. The withheld request
     * still holds the merged credential payload, and none of it reaches a
     * printer.
     */
    public function testTheUninspectableFallbackClaimsNoInterfaceItCannotVouchFor(): void
    {
        $failure = new UninspectableNetworkException('Connection timed out', $this->request());

        $standIn = RedactedClientException::forUninspectableFailure($failure);

        $interfaces = class_implements($standIn);

        self::assertContains(ClientExceptionInterface::class, $interfaces);
        self::assertNotContains(NetworkExceptionInterface::class, $interfaces);
        self::assertSame(UninspectableNetworkException::class, $standIn->originalClass());
        self::assertFalse(str_contains(print_r($standIn, true), self::PASSWORD));
    }

    /**
     * The stand-in holds no reference to the exception it replaces.
     *
     * The strongest assertion in this file. A client may keep the payload in a
     * private property of its own — nothing in PSR-18 forbids it — under a name
     * this package cannot guess, so a stand-in that merely wrapped the original
     * would republish it the moment anything printed the graph.
     */
    public function testTheOriginalIsDroppedRatherThanReferenced(): void
    {
        $failure = new PayloadHoldingNetworkException(
            'Connection timed out',
            $this->request(),
            $this->body(),
            previous: new RuntimeException('the socket layer, which nothing here can vouch for'),
        );

        $standIn = $this->redactor->redactClientFailure($failure);

        self::assertFalse(
            str_contains(print_r($standIn, true), self::PASSWORD),
            'A printed stand-in must not reach the credential through any reference it kept.',
        );
        self::assertNull(
            $standIn->getPrevious(),
            'A chain this package did not build is dropped, not re-attached.',
        );
    }

    /**
     * A code that is not an integer becomes zero rather than an error.
     *
     * PDOException holds an SQLSTATE string in exactly that property, and
     * Throwable::getCode() is untyped, so this is a shape a real client can
     * present.
     */
    public function testACodeThatIsNotAnIntegerBecomesZero(): void
    {
        $standIn = $this->redactor->redactClientFailure(new PayloadHoldingNetworkException(
            'Connection timed out',
            $this->request(),
            $this->body(),
            code: 'HY000',
        ));

        self::assertSame(0, $standIn->getCode());
    }

    public function testAnIntegerCodeIsCarriedThrough(): void
    {
        $standIn = $this->redactor->redactClientFailure(new PayloadHoldingNetworkException(
            'Connection timed out',
            $this->request(),
            $this->body(),
            code: 28,
        ));

        self::assertSame(28, $standIn->getCode());
    }

    /**
     * The stand-in answers with the original's call site, not with the inside of
     * this redactor.
     *
     * Exception::getFile(), getLine() and getTrace() are final, so this is the
     * only way to delegate them. Without it a merchant chasing a real cURL
     * failure would be handed a line number in src/Http.
     */
    public function testTheStandInPointsAtTheOriginalCallSite(): void
    {
        $failure = $this->failureFromAKnownLine();

        $standIn = $this->redactor->redactClientFailure($failure);

        self::assertSame($failure->getFile(), $standIn->getFile());
        self::assertSame($failure->getLine(), $standIn->getLine());
        self::assertSame($this->withoutArguments($failure->getTrace()), $standIn->getTrace());
        self::assertNotSame([], $standIn->getTrace(), 'A vacuous pass would prove nothing.');
    }

    /**
     * The trace is the other half of the leak, and it is measurable: with
     * `zend.exception_ignore_args` off — the php.ini-development default — every
     * argument of every live frame is kept, so the frame that carried the
     * encoded body carried the password with it.
     */
    public function testTheCopiedTraceCarriesNoArguments(): void
    {
        $failure = $this->failureFromAKnownLine();

        $standIn = $this->redactor->redactClientFailure($failure);

        foreach ($standIn->getTrace() as $frame) {
            self::assertArrayNotHasKey('args', $frame);
        }

        self::assertFalse(str_contains(print_r($standIn, true), self::PASSWORD));
    }

    /**
     * A body that is not JSON is replaced wholesale.
     *
     * The two ways of being wrong are not symmetric: publishing an unparsed body
     * may publish a credential, while redacting one costs a line of a diagnostic
     * that still names the operation, the URI and the method.
     */
    public function testABodyThatIsNotJsonIsReplacedWholesale(): void
    {
        $standIn = $this->redactor->redactClientFailure(new NetworkException(
            'Connection timed out',
            $this->request('Password=' . self::PASSWORD . '&ClientID=client-x'),
        ));

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertSame(self::MARKER, (string) $standIn->getRequest()->getBody());
    }

    /**
     * Valid JSON that is not an object or an array: nothing to key redaction on,
     * so the same wholesale treatment.
     */
    public function testABodyThatDecodesToAScalarIsReplacedWholesale(): void
    {
        $standIn = $this->redactor->redactClientFailure(new NetworkException(
            'Connection timed out',
            $this->request('"' . self::PASSWORD . '"'),
        ));

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertSame(self::MARKER, (string) $standIn->getRequest()->getBody());
    }

    /**
     * The scrubbing is Redactor's, not a second implementation of it.
     *
     * Asserted through a rule only Redactor has: a card number keeps
     * first-six/last-four (CONVENTIONS.md §6) rather than disappearing behind
     * the marker. A body scrubbed by anything else would fail this.
     */
    public function testTheBodyIsScrubbedByTheRedactorAndNotBySomethingElse(): void
    {
        $standIn = $this->redactor->redactClientFailure(new NetworkException(
            'Connection timed out',
            $this->request(json_encode([
                'CardNumber' => '4111111111111111',
                'Password' => self::PASSWORD,
                'OrderID' => 1234,
            ], JSON_THROW_ON_ERROR)),
        ));

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertSame(
            '{"CardNumber":"411111******1111","Password":"' . self::MARKER . '","OrderID":1234}',
            (string) $standIn->getRequest()->getBody(),
        );
    }

    /**
     * The request the client still holds is not modified.
     *
     * PSR-7 is immutable-with-withers, so withBody() hands back a new object.
     * This pins that the redaction happens on the copy: a caller inspecting its
     * own request after catching would otherwise find it silently rewritten.
     */
    public function testTheOriginalRequestIsLeftAlone(): void
    {
        $request = $this->request();
        $failure = new NetworkException('Connection timed out', $request);

        $standIn = $this->redactor->redactClientFailure($failure);

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertNotSame($request, $standIn->getRequest());
        self::assertStringContainsString(self::PASSWORD, (string) $request->getBody());
    }

    /**
     * No header survives onto the stand-in's request.
     *
     * Measured before it was fixed: a `Proxy-Authorization` and an
     * `X-Api-Password` set on the failed request were copied verbatim onto the
     * stand-in and printed by print_r() on it. Headers are a plain array
     * property, so unlike the body — a stream, which a graph-walking printer
     * reaches as a resource handle and stops at — they are walked like any other
     * value.
     *
     * They are dropped rather than run through Redactor because the redactor is
     * keyed on the key and its key set was derived from the manifest's field
     * names, not from header names: `X-Api-Password` would be caught by the
     * `password` stem while `Authorization`, `Proxy-Authorization` and `Cookie`
     * would sail through as values. Both shapes are asserted here, so a future
     * change to redact-instead-of-drop fails on the second one.
     *
     * What is dropped costs no diagnostic. The transport sets two headers, both
     * constants of HttpTransport, and `Host` is the URI's host — which the
     * stand-in still carries, along with the method and the redacted body.
     */
    public function testEveryHeaderIsDroppedFromTheStandInsRequest(): void
    {
        $request = $this->requestWithConsumerHeaders();

        self::assertTrue(
            str_contains(print_r($request->getHeaders(), true), self::PROXY_CANARY),
            'Headers are a plain array a printer walks; if they were not, this would prove nothing.',
        );

        $standIn = $this->redactor->redactClientFailure(new NetworkException('Connection timed out', $request));

        self::assertInstanceOf(RedactedNetworkException::class, $standIn);
        self::assertSame([], $standIn->getRequest()->getHeaders());

        $printed = print_r($standIn, true);

        self::assertStringNotContainsString(self::PROXY_CANARY, $printed);
        self::assertStringNotContainsString(self::HEADER_CANARY, $printed);
        self::assertStringNotContainsString(self::PASSWORD, $printed);

        self::assertSame('POST', $standIn->getRequest()->getMethod());
        self::assertSame(self::URL, (string) $standIn->getRequest()->getUri());
    }

    /**
     * The other stand-in that carries a request gets the same treatment. The two
     * are built by separate constructor calls, so a header left on either one is
     * a leak on its own.
     */
    public function testARequestFailuresStandInAlsoCarriesNoHeader(): void
    {
        $standIn = $this->redactor->redactClientFailure(
            new RequestException('Invalid header', $this->requestWithConsumerHeaders()),
        );

        self::assertInstanceOf(RedactedRequestException::class, $standIn);
        self::assertSame([], $standIn->getRequest()->getHeaders());
        self::assertStringNotContainsString(self::PROXY_CANARY, print_r($standIn, true));
    }

    /**
     * The request the client still holds keeps its headers.
     *
     * Same reasoning as the body: withoutHeader() is a wither, so the stripping
     * happens on a copy. A caller inspecting its own request after catching must
     * not find it silently rewritten.
     */
    public function testTheClientsOwnRequestKeepsItsHeaders(): void
    {
        $request = $this->requestWithConsumerHeaders();

        $this->redactor->redactClientFailure(new NetworkException('Connection timed out', $request));

        self::assertSame(['Basic ' . self::PROXY_CANARY], $request->getHeader('Proxy-Authorization'));
        self::assertSame([self::HEADER_CANARY], $request->getHeader('X-Api-Password'));
    }

    /**
     * A JsonException keeps its identity and loses its arguments.
     *
     * json_encode() and json_decode() throw with the payload they choked on as a
     * stack-trace argument of their own internal frame — the request fields on
     * the way out, the response body on the way in. The exception itself is the
     * engine's, its message is a fixed phrase from json_last_error_msg() that
     * never quotes the input, and its trace is writable, so there is nothing to
     * reconstruct: the arguments are stripped in place.
     */
    public function testAJsonFailureIsScrubbedInPlaceAndStaysItself(): void
    {
        $failure = $this->jsonFailure();
        $file = $failure->getFile();
        $line = $failure->getLine();

        self::assertTrue(
            str_contains(print_r($failure, true), self::PASSWORD),
            'The defect must be present before it is fixed, or this proves nothing.',
        );

        $scrubbed = $this->redactor->withoutTraceArguments($failure);

        self::assertSame($failure, $scrubbed);
        self::assertSame(JsonException::class, $scrubbed::class);
        self::assertSame('Malformed UTF-8 characters, possibly incorrectly encoded', $scrubbed->getMessage());
        self::assertSame($file, $scrubbed->getFile());
        self::assertSame($line, $scrubbed->getLine());
        self::assertNotSame([], $scrubbed->getTrace());
        self::assertFalse(str_contains(print_r($scrubbed, true), self::PASSWORD));
    }

    /**
     * A PSR-7 request carrying the body the transport would have sent.
     */
    private function request(?string $body = null): PsrRequest
    {
        return $this->psr17->createRequest('POST', self::URL)
            ->withBody($this->psr17->createStream($body ?? $this->body()));
    }

    /**
     * The request as a consumer's PSR-18 stack hands it over: the two headers
     * the transport set, plus whatever a Guzzle handler stack, a corporate proxy
     * or a vendor middleware added on the way out.
     *
     * `X-Api-Password` matches the redactor's `password` stem; the other three
     * match nothing in a key set derived from the manifest's field names. That
     * asymmetry is the point.
     */
    private function requestWithConsumerHeaders(): PsrRequest
    {
        return $this->request()
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Proxy-Authorization', 'Basic ' . self::PROXY_CANARY)
            ->withHeader('X-Api-Password', self::HEADER_CANARY)
            ->withHeader('Authorization', 'Bearer ' . self::HEADER_CANARY)
            ->withHeader('Cookie', 'session=' . self::HEADER_CANARY);
    }

    /**
     * The merged payload: the caller's fields plus the credentials the
     * transport adds (CONVENTIONS.md §5), which is what makes a request body a
     * secret.
     */
    private function body(): string
    {
        return json_encode([
            'Amount' => '10.00',
            'OrderID' => 1234,
            'ClientID' => 'client-x',
            'Username' => 'user-x',
            'Password' => self::PASSWORD,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A failure thrown from a line that is not in the redactor, with the payload
     * on the stack as a frame argument.
     */
    private function failureFromAKnownLine(): ClientExceptionInterface
    {
        return $this->throwingFrame($this->body());
    }

    private function throwingFrame(string $payload): ClientExceptionInterface
    {
        return new NetworkException('Connection timed out', $this->request($payload));
    }

    private function jsonFailure(): JsonException
    {
        try {
            json_encode(
                ['Password' => self::PASSWORD, 'Description' => "\xB1\x31"],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $failure) {
            return $failure;
        }

        self::fail('json_encode() must refuse malformed UTF-8.');
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    private function withoutArguments(array $trace): array
    {
        return array_map(
            static function (array $frame): array {
                unset($frame['args']);

                return $frame;
            },
            $trace,
        );
    }
}
