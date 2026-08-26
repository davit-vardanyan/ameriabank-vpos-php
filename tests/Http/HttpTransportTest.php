<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use function array_map;
use function class_implements;

use DateTimeImmutable;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Http\FailureRedactor;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Http\RedactedClientException;
use DavitVardanyan\AmeriabankVpos\Http\RedactedNetworkException;
use DavitVardanyan\AmeriabankVpos\Http\RedactedRequestException;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\ConfirmPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetPendingTransactionsRequest;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\MakeBindingPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\PaymentDetailsRequest;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;

use function hrtime;

use Http\Client\Exception\NetworkException;
use Http\Client\Exception\RequestException;
use Http\Client\Exception\TransferException;
use Http\Discovery\ClassDiscovery;
use Http\Mock\Client;

use function json_encode;

use const JSON_THROW_ON_ERROR;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use SensitiveParameter;

use function sprintf;
use function str_contains;

use Throwable;

/**
 * The transport's behaviour against a mock PSR-18 client.
 *
 * Four properties are being defended here, and each one is a finding that cost a
 * probe run to establish:
 *
 * 1. **The HTTP status decides nothing.** Several tests below pair a status with
 *    a body that contradicts it — a 500 carrying a success code, a 200 carrying
 *    a rejection — and assert the body wins. Every one of them fails if any
 *    branch in the transport starts reading the status.
 * 2. **A fault is recognised by shape.** The ASP.NET envelope has been observed
 *    at 404 and at 500, so the same body is fed at both statuses and both must
 *    throw. A body carrying `Message` *and* `ResponseCode` must not.
 * 3. **A retry resends the same bytes.** §4.4: a repeat InitPayment overwrites
 *    the earlier call's parameters, so the two sent bodies are compared as
 *    strings rather than as decoded arrays.
 * 4. **Nothing that moves money is retried.** A network failure on a
 *    non-idempotent operation sends exactly one request.
 *
 * ## Time
 *
 * The transport's pause is replaced by a recorder in every test but one, so the
 * suite observes the backoff instead of spending it. The exception is
 * testTheDefaultPauseIsARealSleep(), which exists because a seam nothing ever
 * exercises in its default state is a seam that could be wired to nothing at
 * all.
 *
 * ## Canaries
 *
 * The password below is nine characters, for a recorded reason: a real
 * credential leak was once hidden by a passing test, because PHP truncates a
 * string argument in a stack trace at fifteen characters and a longer canary
 * made the assertion look at a prefix that never matched. A test asserts the
 * length stays under that limit.
 *
 * None of these values is a credential of any kind. The sandbox's own
 * credentials live outside the repository (CONVENTIONS.md §8).
 */
#[CoversClass(HttpTransport::class)]
#[UsesClass(Amount::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(AuthenticationException::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(ConfirmPaymentRequest::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(Currency::class)]
#[UsesClass(Environment::class)]
#[UsesClass(ExceptionState::class)]
#[UsesClass(FailureRedactor::class)]
#[UsesClass(GatewayFaultException::class)]
#[UsesClass(GetPendingTransactionsRequest::class)]
#[UsesClass(IndeterminateStateException::class)]
#[UsesClass(InitPaymentRequest::class)]
#[UsesClass(MakeBindingPaymentRequest::class)]
#[UsesClass(PaymentDetailsRequest::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(RedactedClientException::class)]
#[UsesClass(RedactedNetworkException::class)]
#[UsesClass(RedactedRequestException::class)]
#[UsesClass(Redactor::class)]
#[UsesClass(ResponseCode::class)]
#[UsesClass(SerializationException::class)]
#[UsesClass(TransportException::class)]
#[UsesClass(ValidationException::class)]
final class HttpTransportTest extends TestCase
{
    private const string CLIENT_ID = 'client-x';

    private const string USERNAME = 'user-x';

    /**
     * Nine characters, under PHP's fifteen-character trace truncation.
     */
    private const string PASSWORD = 'pw-canary';

    /**
     * Nine characters again, standing in for the card data a response body
     * carries — CONVENTIONS.md §6's never-log list is PAN, ExpDate,
     * ApprovalCode and SSN, and a PaymentDetails body carries the first three.
     */
    private const string CARD_CANARY = 'ed-canary';

    private const string INIT_URL = 'https://servicestest.ameriabank.am/VPOS/api/VPOS/InitPayment';

    /**
     * A success body as InitPayment answers it: ResponseCode is an *integer* 1
     * here and a string "00" everywhere else (CONVENTIONS.md §4.3).
     */
    private const string INIT_SUCCESS_BODY = '{"PaymentID":"7A2B0C1D-0000-0000-0000-000000000001",'
        . '"ResponseCode":1,"ResponseMessage":"OK","Opaque":null}';

    private const string FAULT_BODY = '{"Message":"An error has occurred."}';

    private Client $client;

    private Psr17Factory $psr17;

    private RecordingLogger $logger;

    /**
     * Every pause the transport asked for, in microseconds.
     *
     * @var list<int>
     */
    private array $pauses = [];

    protected function setUp(): void
    {
        $this->client = new Client();
        $this->psr17 = new Psr17Factory();
        $this->logger = new RecordingLogger();
        $this->pauses = [];
    }

    public function testTheCanaryIsShortEnoughToSurviveATraceTruncation(): void
    {
        self::assertLessThan(15, mb_strlen(self::PASSWORD));
    }

    public function testASuccessfulExchangeReturnsTheDecodedBody(): void
    {
        $this->respondWith(200, self::INIT_SUCCESS_BODY);

        self::assertSame(
            [
                'PaymentID' => '7A2B0C1D-0000-0000-0000-000000000001',
                'ResponseCode' => 1,
                'ResponseMessage' => 'OK',
                'Opaque' => null,
            ],
            $this->transport()->send($this->initRequest()),
            'The decoded body is returned whole and unhydrated: the clients map it.',
        );
    }

    public function testTheRequestIsPostedAsJsonToTheOperationUrl(): void
    {
        $this->respondWith(200, self::INIT_SUCCESS_BODY);
        $this->transport()->send($this->initRequest());

        $sent = $this->sentRequests()[0];

        self::assertSame('POST', $sent->getMethod());
        self::assertSame(self::INIT_URL, (string) $sent->getUri());
        self::assertSame('application/json; charset=utf-8', $sent->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
    }

    /**
     * The status is contradicted by the body in both directions here, and the
     * body wins both times.
     *
     * HTTP 500 is a semantic answer from this gateway, not a fault (§4.2), and
     * HTTP 200 is what an authentication failure arrives as (§4.1). A transport
     * that shortcut either way would be wrong on live traffic within a day.
     */
    public function testAFiveHundredCarryingASuccessBodySucceeds(): void
    {
        $this->respondWith(500, self::INIT_SUCCESS_BODY);

        self::assertArrayHasKey('PaymentID', $this->transport()->send($this->initRequest()));
    }

    public function testATwoHundredCarryingAFailureCodeThrows(): void
    {
        $this->respondWith(200, '{"PaymentID":"","ResponseCode":20,"ResponseMessage":"Incorrect Username and Password"}');

        try {
            $this->transport()->send($this->initRequest());
            self::fail('A ResponseCode of 20 must not be reported as success.');
        } catch (AuthenticationException $exception) {
            self::assertSame(20, $exception->responseCode());
            self::assertSame('InitPayment', $exception->operation());
            self::assertSame('Incorrect Username and Password', $exception->responseMessage());
        }
    }

    /**
     * Integer 20 and string "20" are different wire values with different
     * meanings, and the mapping between a code and an exception belongs to
     * ResponseCode (CONVENTIONS.md §5). The transport must not second-guess
     * it.
     */
    public function testANonAuthenticationFailureBecomesAPlainApiException(): void
    {
        $this->respondWith(200, '{"ResponseCode":"20","ResponseMessage":"Client payment type BindingMainRest is not available"}');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A failure code must throw.');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(AuthenticationException::class, $exception);
            self::assertSame('20', $exception->responseCode());
        }
    }

    /**
     * A response code the SDK has never seen must still throw, and must still
     * carry its raw value: §4.3 records roughly sixty codes in incompatible
     * shapes, with more added without notice.
     */
    public function testAnUnknownResponseCodeThrowsWithItsRawValueIntact(): void
    {
        $this->respondWith(200, '{"ResponseCode":"0151017","ResponseMessage":"Something new"}');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('An unknown code is not a success code.');
        } catch (ApiException $exception) {
            self::assertSame('0151017', $exception->responseCode(), 'The leading zeros are the value.');
        }
    }

    /**
     * A failure body with no ResponseMessage still throws, with the code intact.
     *
     * ApiException renders the empty message as "(no message)"; inventing text
     * or refusing the body would both be worse than saying nothing.
     */
    public function testAFailureWithoutAResponseMessageStillThrows(): void
    {
        $this->respondWith(200, '{"ResponseCode":"05"}');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A failure code must throw even with no message beside it.');
        } catch (ApiException $exception) {
            self::assertSame('05', $exception->responseCode());
            self::assertSame('', $exception->responseMessage());
        }
    }

    /**
     * The same envelope, at the two statuses it has actually been observed at.
     *
     * 500 is GetPaymentDetails on an unattempted payment and GetBindings with an
     * out-of-range PaymentType; 404 is a wrong endpoint name. Detecting a fault
     * by status would have to be wrong at one of them.
     *
     * @param non-empty-string $body
     */
    #[DataProvider('faultEnvelopes')]
    public function testTheFaultEnvelopeThrowsWhateverTheStatus(int $status, string $body, string $expectedText): void
    {
        $this->respondWith($status, $body);

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A fault envelope must not be returned as a body.');
        } catch (GatewayFaultException $exception) {
            self::assertSame('GetPaymentDetails', $exception->operation());
            self::assertSame($status, $exception->statusCode());
            self::assertSame($expectedText, $exception->faultMessage());

            // That a fault is not an ApiException is asserted in
            // tests/Exception/ExceptionHierarchyTest.php, not here: written as
            // assertNotInstanceOf() at this point PHPStan reports it as always
            // true, which is the stronger result — the type checker proves at
            // level 10 what the assertion could only observe.
        }

        self::assertCount(1, $this->sentRequests(), 'A fault is a deliberate answer and is never retried.');
    }

    /**
     * @return array<string, array{int, non-empty-string, string}>
     */
    public static function faultEnvelopes(): array
    {
        return [
            'unhandled exception page at 500' => [
                500,
                self::FAULT_BODY,
                'An error has occurred.',
            ],
            'routing failure at 404' => [
                404,
                '{"Message":"No HTTP resource was found that matches the request URI."}',
                'No HTTP resource was found that matches the request URI.',
            ],
        ];
    }

    /**
     * `Message` is only a fault marker in the absence of a response code.
     *
     * A body carrying both is a business answer that happens to have a Message
     * field, and treating it as a fault would throw away the response code — the
     * one piece of information the caller can act on.
     */
    public function testABodyCarryingBothMessageAndAResponseCodeTakesTheResponseCodePath(): void
    {
        $this->respondWith(500, '{"Message":"An error has occurred.","ResponseCode":"05","ResponseMessage":"Declined"}');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A response code must be reported as one.');
        } catch (ApiException $exception) {
            self::assertSame('05', $exception->responseCode());
        }
    }

    /**
     * A top-level JSON array carries no response code and is not a fault.
     *
     * This is GetPendingTransactions' documented success shape — the manifest's
     * own sample body is a two-element array of row objects — so a transport
     * that demanded a ResponseCode would make the endpoint unreachable. The
     * transport throws on evidence of failure, not on the absence of evidence of
     * success; the hydrator is what refuses a body it cannot map.
     */
    public function testATopLevelArrayIsReturnedAsDecoded(): void
    {
        $this->respondWith(200, '[{"OrderId":1,"ClientName":"A","Amount":4.0},{"OrderId":2,"ClientName":"B","Amount":8.0}]');

        self::assertSame(
            [
                ['OrderId' => 1, 'ClientName' => 'A', 'Amount' => 4.0],
                ['OrderId' => 2, 'ClientName' => 'B', 'Amount' => 8.0],
            ],
            $this->transport()->send($this->pendingTransactionsRequest()),
            'Both rows, in order: a list that arrives truncated is worse than one that is refused.',
        );
    }

    /**
     * The fault envelope's Message is a string. A body whose Message is
     * something else is not that envelope, and reporting a refusal the gateway
     * never made would be an invention.
     */
    public function testANonStringMessageIsNotAFault(): void
    {
        $this->respondWith(200, '{"Message":42,"OrderID":"4815"}');

        self::assertSame(
            ['Message' => 42, 'OrderID' => '4815'],
            $this->transport()->send($this->detailsRequest()),
            'The body comes back whole: nothing here is entitled to drop a field.',
        );
    }

    public function testANonJsonBodyIsReportedWithoutQuotingIt(): void
    {
        $this->respondWith(200, '<html><body>Gateway timeout for order 4815162342</body></html>');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A body that is not JSON cannot be a response.');
        } catch (SerializationException $exception) {
            self::assertTrue($exception->causedByJson());
            self::assertSame('GetPaymentDetails', $exception->operation());
            self::assertStringNotContainsString('4815162342', $exception->getMessage());
            self::assertStringNotContainsString('<html>', $exception->getMessage());
        }
    }

    /**
     * Valid JSON that is not a structure at all — the gateway has never sent
     * one, which is exactly why it must not be guessed at.
     */
    public function testAScalarBodyIsReportedByTypeNotByValue(): void
    {
        $this->respondWith(200, '"4111111111111111"');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A bare string is not a response body.');
        } catch (SerializationException $exception) {
            self::assertFalse($exception->causedByJson());
            self::assertStringContainsString('string', $exception->getMessage());
            self::assertStringNotContainsString('4111111111111111', $exception->getMessage());
        }
    }

    /**
     * §4.3 allows ResponseCode to be an int or a string, and nothing else. A
     * float is a shape no endpoint has ever produced, so it is reported rather
     * than coerced — coercion is how "0.0" would become a success.
     */
    public function testAResponseCodeOfTheWrongTypeIsReportedByTypeNotByValue(): void
    {
        $this->respondWith(200, '{"ResponseCode":1.5}');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A float response code is not a response code.');
        } catch (SerializationException $exception) {
            self::assertStringContainsString('ResponseCode', $exception->getMessage());
            self::assertStringContainsString('float', $exception->getMessage());
            self::assertStringNotContainsString('1.5', $exception->getMessage());
        }
    }

    /**
     * The two attempts carry byte-identical bodies.
     *
     * Compared as strings, not as decoded arrays. §4.4: a repeat InitPayment
     * returns the same PaymentID but overwrites the earlier call's parameters,
     * so a retry that re-serialised — picking up a new timestamp, a reordered
     * key, a differently rendered decimal — would silently mutate a registered
     * order.
     */
    public function testARetryResendsTheIdenticalBytes(): void
    {
        $this->client->addException($this->networkFailure());
        $this->respondWith(200, self::INIT_SUCCESS_BODY);

        $this->transport()->send($this->initRequest('Օրինակ 10.00'));

        $bodies = array_map(
            static fn(PsrRequest $sent): string => (string) $sent->getBody(),
            $this->sentRequests(),
        );

        self::assertCount(2, $bodies);
        self::assertStringContainsString('"OrderID":1234', $bodies[0]);
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame([100_000], $this->pauses);
    }

    /**
     * The retry does not go back to the request object.
     *
     * The test above compares two bodies produced by a real DTO, which is
     * deterministic — so it would still pass against a transport that
     * re-serialised on every attempt. This one cannot: the request counts its
     * own toArray() calls and puts the count in the body, so re-encoding
     * produces a *different* second body and a second call. That is the failure
     * §4.4 describes, made observable.
     */
    public function testARetryNeverAsksTheRequestObjectForItsBodyAgain(): void
    {
        $counting = new class implements RequestInterface {
            public int $calls = 0;

            public function operation(): string
            {
                return 'InitPayment';
            }

            public function isIdempotent(): bool
            {
                return true;
            }

            public function requiresClientId(): bool
            {
                return true;
            }

            /**
             * @return array<string, scalar>
             */
            public function toArray(): array
            {
                ++$this->calls;

                return ['OrderID' => 1234, 'Serialisations' => $this->calls];
            }
        };

        $this->client->addException($this->networkFailure());
        $this->respondWith(200, self::INIT_SUCCESS_BODY);

        $this->transport()->send($counting);

        $bodies = array_map(
            static fn(PsrRequest $sent): string => (string) $sent->getBody(),
            $this->sentRequests(),
        );

        self::assertSame(1, $counting->calls, 'The body is built once, before the first attempt.');
        self::assertCount(2, $bodies);
        self::assertStringContainsString('"Serialisations":1', $bodies[1]);
        self::assertSame($bodies[0], $bodies[1]);
    }

    /**
     * Exhaustion: three attempts, two pauses, then a TransportException.
     *
     * The pause values are asserted exactly because they are the only place the
     * backoff arithmetic is visible. 100 ms then 200 ms — every mutation of
     * `100_000 * 2 ** ($attempt - 1)` changes at least one of the two.
     */
    public function testARetryableFailureIsAttemptedUpToTheLimit(): void
    {
        $this->client->setDefaultException($this->networkFailure());

        try {
            $this->transport(3)->send($this->initRequest());
            self::fail('An exhausted retry must throw.');
        } catch (TransportException $exception) {
            self::assertSame('InitPayment', $exception->operation());

            // The cause is a redacted stand-in rather than the client's own
            // exception — that exception hands back the request it was sent,
            // whose body is the merged credential payload. The interface
            // survives, which is what a caller inspects, and the class it stands
            // in for is named in both the message and originalClass().
            $previous = $exception->getPrevious();

            self::assertInstanceOf(RedactedNetworkException::class, $previous);
            self::assertSame(NetworkException::class, $previous->originalClass());
            self::assertStringContainsString(NetworkException::class, $exception->getMessage());
        }

        self::assertCount(3, $this->sentRequests());
        self::assertSame([100_000, 200_000], $this->pauses);
    }

    /**
     * The default is three attempts, exercised through the constructor's own
     * default rather than through an argument that happens to equal it.
     *
     * Worth its own test because the two are indistinguishable from outside:
     * every other test here passes the count explicitly, so a default changed to
     * two or four would leave all of them green.
     */
    public function testTheDefaultIsThreeAttempts(): void
    {
        $this->client->setDefaultException($this->networkFailure());

        $transport = $this->recordPausesOn(new HttpTransport(
            credentials: $this->credentials(),
            environment: Environment::Test,
            httpClient: $this->client,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: $this->logger,
        ));

        try {
            $transport->send($this->initRequest());
            self::fail('An exhausted retry must throw.');
        } catch (TransportException) {
            self::assertCount(3, $this->sentRequests());
            self::assertSame([100_000, 200_000], $this->pauses);
        }
    }

    /**
     * @param positive-int $maxAttempts
     */
    #[DataProvider('attemptLimits')]
    public function testTheAttemptLimitIsHonouredExactly(int $maxAttempts, int $expectedPauses): void
    {
        $this->client->setDefaultException($this->networkFailure());

        $this->expectException(TransportException::class);

        try {
            $this->transport($maxAttempts)->send($this->initRequest());
        } finally {
            self::assertCount($maxAttempts, $this->sentRequests());
            self::assertCount($expectedPauses, $this->pauses);
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function attemptLimits(): array
    {
        return [
            'one attempt, no pause' => [1, 0],
            'two attempts' => [2, 1],
            'three attempts' => [3, 2],
            'four attempts' => [4, 3],
            'five attempts' => [5, 4],
        ];
    }

    #[DataProvider('rejectedAttemptLimits')]
    public function testTheAttemptLimitIsBounded(int $maxAttempts): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('between 1 and 5');

        $this->transport($maxAttempts);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function rejectedAttemptLimits(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'six' => [6],
        ];
    }

    /**
     * A network failure on an operation that moves money is not retried, and the
     * caller is told what to reconcile.
     *
     * IndeterminateStateException rather than TransportException, and
     * deliberately not a subtype of it (CONVENTIONS.md §5): a caller who
     * catches transport failures and retries them must not be able to swallow
     * the one case that may capture twice.
     */
    public function testANonIdempotentOperationIsNotRetried(): void
    {
        $this->client->setDefaultException($this->networkFailure());

        try {
            $this->transport()->send(new ConfirmPaymentRequest('PID-CONFIRM-1', $this->amount()));
            self::fail('A capture that failed in transport must not be reported as a plain transport error.');
        } catch (IndeterminateStateException $exception) {
            self::assertSame('ConfirmPayment', $exception->operation());
            self::assertSame('PID-CONFIRM-1', $exception->paymentId());
            self::assertStringContainsString('ConfirmPayment', $exception->getMessage());
            self::assertStringContainsString('GetPaymentDetails', $exception->getMessage());
            self::assertStringContainsString('PID-CONFIRM-1', $exception->getMessage());

            // IndeterminateStateException is not a TransportException, so a
            // caller retrying transport failures cannot swallow it
            // (CONVENTIONS.md §5). Asserted structurally in
            // ExceptionHierarchyTest; PHPStan reports the instanceof form here
            // as always true.
        }

        self::assertCount(1, $this->sentRequests());
        self::assertSame([], $this->pauses);
    }

    /**
     * MakeBindingPayment charges a card and carries no PaymentID, so the
     * reconcile instruction has nothing to name. It still must not retry.
     */
    public function testANonIdempotentOperationWithoutAPaymentIdStillRefusesToRetry(): void
    {
        $this->client->setDefaultException($this->networkFailure());

        try {
            $this->transport()->send(new MakeBindingPaymentRequest(
                cardHolderId: 'holder-1',
                amount: $this->amount(),
                orderId: 4242,
                backUrl: 'https://merchant.example/return',
                paymentType: PaymentType::BindingMainRest,
            ));
            self::fail('A binding payment that failed in transport must not be reported as retryable.');
        } catch (IndeterminateStateException $exception) {
            self::assertNull($exception->paymentId());
            self::assertStringNotContainsString('for payment', $exception->getMessage());
        }

        self::assertCount(1, $this->sentRequests());
    }

    /**
     * A request object that claims to be idempotent while naming an operation
     * that moves money is refused a retry anyway.
     *
     * The eleven DTOs in src/Request answer isIdempotent() correctly, so nothing
     * in this package reaches this guard — which is the reason to test it
     * through a request that does. Contracts\RequestInterface is public surface:
     * a caller holds an implementation and passes it in, so the honest answer is
     * ultimately a claim made by code this package does not own, and even
     * in-house a single wrong `return true` on a capture is a defect that no
     * type checker and no gateway response would ever report. The transport ANDs
     * the claim with its own NEVER_RETRY list, so the liar gets exactly one
     * attempt and the caller is told to reconcile.
     *
     * maxAttempts is 5 — the ceiling — rather than the default, so that a
     * transport which believed the request would send five charges here and the
     * failure would be as loud as the bug it stands for.
     *
     * All four operations from CONVENTIONS.md §4.5's table are exercised
     * rather than one representative, and the names are transcribed here from
     * the table independently of the constant. A list guard is only as strong
     * as its weakest entry, and a test naming one operation would pass against
     * a NEVER_RETRY missing the other three.
     */
    #[DataProvider('operationsThatMoveMoney')]
    public function testAnOperationThatMovesMoneyIsNotRetriedEvenIfTheRequestClaimsItIsIdempotent(string $operation): void
    {
        $liar = new readonly class ($operation) implements RequestInterface {
            public function __construct(private string $operation) {}

            public function operation(): string
            {
                return $this->operation;
            }

            public function isIdempotent(): bool
            {
                return true;
            }

            public function requiresClientId(): bool
            {
                return false;
            }

            /**
             * @return array<string, scalar>
             */
            public function toArray(): array
            {
                return ['PaymentID' => 'PID-LIAR-1'];
            }
        };

        $this->client->setDefaultException($this->networkFailure());

        try {
            $this->transport(5)->send($liar);
            self::fail('An operation that moves money must not be retried, whatever the request claims.');
        } catch (IndeterminateStateException $exception) {
            self::assertSame($operation, $exception->operation());
            self::assertSame('PID-LIAR-1', $exception->paymentId());
            self::assertStringContainsString('GetPaymentDetails', $exception->getMessage());
        }

        self::assertCount(1, $this->sentRequests(), 'Exactly one attempt, whatever the request claimed.');
        self::assertSame([], $this->pauses);
    }

    /**
     * CONVENTIONS.md §4.5's four "Never" rows, transcribed.
     *
     * ActivateBinding and DeactivateBinding are isIdempotent() === false in
     * their DTOs but are absent from that table, so they are absent here too:
     * this provider is a copy of a source, not a judgement about which
     * operations are dangerous.
     *
     * @return array<string, array{string}>
     */
    public static function operationsThatMoveMoney(): array
    {
        return [
            'a capture' => ['ConfirmPayment'],
            'a refund' => ['RefundPayment'],
            'a cancellation' => ['CancelPayment'],
            'a binding charge' => ['MakeBindingPayment'],
        ];
    }

    /**
     * A malformed request is our defect, not the network's, so repeating it
     * would only repeat the defect.
     */
    public function testAMalformedRequestIsAConfigurationErrorAndIsNotRetried(): void
    {
        $this->client->setDefaultException(new RequestException('Invalid URI', $this->psr17->createRequest('POST', self::INIT_URL)));

        try {
            $this->transport()->send($this->initRequest());
            self::fail('A rejected request must not be reported as a network failure.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('InitPayment', $exception->getMessage());
            self::assertStringContainsString(RequestException::class, $exception->getMessage());
        }

        self::assertCount(1, $this->sentRequests());
        self::assertSame([], $this->pauses);
    }

    /**
     * A PSR-18 failure that is neither of the two specific interfaces. It could
     * be anything, including a request that reached the gateway, so it is not
     * retried either.
     */
    public function testAnUnclassifiedClientFailureIsATransportErrorAndIsNotRetried(): void
    {
        $this->client->setDefaultException(new TransferException('Something else went wrong'));

        try {
            $this->transport()->send($this->initRequest());
            self::fail('An unclassified client failure must surface.');
        } catch (TransportException $exception) {
            self::assertStringContainsString(TransferException::class, $exception->getMessage());
        }

        self::assertCount(1, $this->sentRequests());
        self::assertSame([], $this->pauses);
    }

    /**
     * The leak this section exists for, reproduced end to end.
     *
     * A PSR-18 exception hands back the request it was sent, and this
     * transport's request body is the merchant's credentials merged into the
     * payload. Attached unchanged as `previous`, the password was one hop from
     * every exception thrown here, and the measurement was unambiguous: the
     * message was clean, getTraceAsString() was clean, and print_r(), var_dump(),
     * var_export() and serialize() all printed it — which is what Monolog with
     * includeStacktraces, Sentry's serialiser and Symfony's error page walk.
     *
     * Two channels had to close for this to pass. The `previous` is now a
     * redacted stand-in, and the encoded body — which the transport carries as a
     * frame argument, kept by any runtime with zend.exception_ignore_args off —
     * is marked #[SensitiveParameter], so the frame renders it as an empty
     * SensitiveParameterValue.
     */
    public function testNoPrinterReachesTheCredentialThroughAnIndeterminateState(): void
    {
        $this->client->setDefaultException($this->failureCarryingTheSentBody());

        try {
            $this->transport()->send(new ConfirmPaymentRequest('PID-CONFIRM-1', $this->amount()));
            self::fail('A capture that failed in transport must throw.');
        } catch (IndeterminateStateException $exception) {
            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
        }
    }

    /**
     * The same for the retryable path, which throws a different class through a
     * different factory.
     */
    public function testNoPrinterReachesTheCredentialThroughAnExhaustedRetry(): void
    {
        $this->client->setDefaultException($this->failureCarryingTheSentBody());

        try {
            $this->transport()->send($this->initRequest());
            self::fail('An exhausted retry must throw.');
        } catch (TransportException $exception) {
            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
        }
    }

    /**
     * And for the malformed-request path, where the client hands back the same
     * request through the other optional interface.
     */
    public function testNoPrinterReachesTheCredentialThroughARejectedRequest(): void
    {
        $this->client->setDefaultException(new RequestException(
            'Invalid header',
            $this->psr17->createRequest('POST', self::INIT_URL)
                ->withBody($this->psr17->createStream($this->sentBody())),
        ));

        try {
            $this->transport()->send($this->initRequest());
            self::fail('A rejected request must throw.');
        } catch (ConfigurationException $exception) {
            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
            self::assertInstanceOf(RedactedRequestException::class, $exception->getPrevious());
        }
    }

    /**
     * An unclassified client failure carries no request, so there is nothing to
     * scrub — and the stand-in is built anyway, because the client is free to
     * keep the payload in a private property this package cannot see.
     */
    public function testAnUnclassifiedClientFailureAlsoArrivesAsAStandIn(): void
    {
        $this->client->setDefaultException(new TransferException('Something else went wrong'));

        try {
            $this->transport()->send($this->initRequest());
            self::fail('An unclassified client failure must surface.');
        } catch (TransportException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(RedactedClientException::class, $previous);
            self::assertSame(TransferException::class, $previous->originalClass());
            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
        }
    }

    /**
     * A client that will not be inspected still leaves through the same
     * mapping.
     *
     * Building a stand-in means calling getRequest() on the failure and then
     * reading that request — third-party code, called from inside a catch
     * block. A throw from any of it used to escape unmapped: measured, this
     * shape sent a bare LogicException out of send(), which `catch
     * (VposExceptionInterface)` does not catch (CONVENTIONS.md §5).
     *
     * What comes back instead is the bare stand-in: class name and message, code
     * 0, and no reference to the object those came from. The network shape is
     * deliberately not claimed — a failure that cannot be inspected cannot be
     * vouched for — and nothing depends on it here, because the catch clause that
     * decided this was a retryable network failure read the *original*'s type
     * before the stand-in existed. That decision is visible in the request count.
     */
    public function testAClientThatRefusesInspectionStillExhaustsItsRetriesAndThrowsTransport(): void
    {
        $this->client->setDefaultException($this->uninspectableFailure());

        try {
            $this->transport()->send($this->initRequest());
            self::fail('An exhausted retry must throw.');
        } catch (TransportException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(RedactedClientException::class, $previous);
            self::assertNotContains(NetworkExceptionInterface::class, class_implements($previous));
            self::assertSame(UninspectableNetworkException::class, $previous->originalClass());
            self::assertSame('Connection timed out', $previous->getMessage());
            self::assertSame(0, $previous->getCode());

            foreach ($previous->getTrace() as $frame) {
                self::assertArrayNotHasKey('args', $frame);
            }

            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
        }

        self::assertCount(3, $this->sentRequests());
    }

    /**
     * The same client on a capture, which is the half that costs money.
     *
     * §4.5: a timeout on a non-idempotent operation must raise
     * IndeterminateStateException naming the payment to reconcile — never a
     * retry, never a guess. An unmapped throw out of the catch block took that
     * exception away from the caller and replaced it with one carrying no
     * instruction at all, on the one path where the wrong response may
     * double-charge.
     */
    public function testAClientThatRefusesInspectionStillReachesTheIndeterminateState(): void
    {
        $this->client->setDefaultException($this->uninspectableFailure());

        try {
            $this->transport()->send(new ConfirmPaymentRequest('PID-CONFIRM-1', $this->amount()));
            self::fail('A capture that failed in transport must throw.');
        } catch (IndeterminateStateException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(RedactedClientException::class, $previous);
            self::assertSame(UninspectableNetworkException::class, $previous->originalClass());
            self::assertStringContainsString('ConfirmPayment', $exception->getMessage());
            self::assertStringContainsString('PID-CONFIRM-1', $exception->getMessage());
            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
        }

        self::assertCount(1, $this->sentRequests());
        self::assertSame([], $this->pauses);
    }

    /**
     * The diagnostic chain survives the scrubbing. That is the whole reason the
     * cause is replaced rather than dropped: a merchant debugging a real cURL
     * failure needs to know which client failed, with what message, against
     * which URL.
     */
    public function testTheChainSurvivesWithTheRequestItFailedOn(): void
    {
        $this->client->setDefaultException($this->failureCarryingTheSentBody());

        try {
            $this->transport()->send(new ConfirmPaymentRequest('PID-CONFIRM-1', $this->amount()));
            self::fail('A capture that failed in transport must throw.');
        } catch (IndeterminateStateException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(RedactedNetworkException::class, $previous);
            self::assertSame(NetworkException::class, $previous->originalClass());
            self::assertSame('Connection timed out', $previous->getMessage());

            $request = $previous->getRequest();

            self::assertSame('POST', $request->getMethod());
            self::assertSame(self::INIT_URL, (string) $request->getUri());
            self::assertStringContainsString('"Password":"[redacted]"', (string) $request->getBody());
        }
    }

    /**
     * The other half of the request body's journey: a body that cannot be
     * encoded at all.
     *
     * json_encode() throws with the array it choked on as an argument of its own
     * internal frame, and that array is the merged payload. The JsonException
     * keeps its identity — the engine's class, its fixed message — and loses its
     * trace arguments.
     */
    public function testNoPrinterReachesTheCredentialThroughAnUnencodableBody(): void
    {
        try {
            $this->transport()->send($this->initRequest("\xB1\x31"));
            self::fail('An unencodable body must throw.');
        } catch (SerializationException $exception) {
            self::assertTrue($exception->causedByJson());
            $this->assertNothingPrintsTheCanary($exception, self::PASSWORD);
        }
    }

    /**
     * A response body is sensitive too, and for a different reason: §6 forbids a
     * PAN, an ExpDate or an ApprovalCode in anything that reaches a log, and a
     * PaymentDetails body carries all three.
     *
     * The body is a frame argument of the method that decides what the gateway
     * said, so every exception thrown from there printed it.
     */
    public function testNoPrinterReachesTheCardDataThroughAFailedResponse(): void
    {
        $this->respondWith(200, json_encode([
            'ResponseCode' => '05',
            'ResponseMessage' => 'Declined',
            'CardNumber' => '4111111111111111',
            'ExpDate' => self::CARD_CANARY,
            'ApprovalCode' => 'ac-canary',
        ], JSON_THROW_ON_ERROR));

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A declined response must throw.');
        } catch (ApiException $exception) {
            $this->assertNothingPrintsTheCanary($exception, self::CARD_CANARY);
            $this->assertNothingPrintsTheCanary($exception, 'ac-canary');
        }
    }

    /**
     * The same body reached through the fault envelope, which is a different
     * method with its own copy of the decoded body.
     */
    public function testNoPrinterReachesTheCardDataThroughAGatewayFault(): void
    {
        $this->respondWith(500, json_encode([
            'Message' => 'An error has occurred.',
            'ExpDate' => self::CARD_CANARY,
        ], JSON_THROW_ON_ERROR));

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A fault envelope must throw.');
        } catch (GatewayFaultException $exception) {
            $this->assertNothingPrintsTheCanary($exception, self::CARD_CANARY);
        }
    }

    /**
     * And through a body that is not JSON at all, where the JsonException from
     * json_decode() carries the whole body as an argument.
     */
    public function testNoPrinterReachesTheCardDataThroughAMalformedResponse(): void
    {
        $this->respondWith(200, '{"ExpDate":"' . self::CARD_CANARY . '" oops');

        try {
            $this->transport()->send($this->detailsRequest());
            self::fail('A malformed body must throw.');
        } catch (SerializationException $exception) {
            $this->assertNothingPrintsTheCanary($exception, self::CARD_CANARY);
        }
    }

    /**
     * The structural half of the same guarantee.
     *
     * Every behavioural test above goes through one throw path; this one names
     * the parameters that must stay marked, so a new throw site added below an
     * unmarked parameter fails here rather than in whichever printer a merchant
     * happens to run. The list is every parameter of this class that carries the
     * merged request body or a raw response body.
     */
    public function testEveryParameterCarryingAPayloadIsMarkedSensitive(): void
    {
        $carriers = [
            'encode' => 'fields',
            'dispatch' => 'encodedBody',
            'paymentIdOf' => 'fields',
            'interpret' => 'body',
            'faultOrBody' => 'decoded',
            'decode' => 'body',
        ];

        foreach ($carriers as $method => $name) {
            $marked = false;

            foreach ((new ReflectionMethod(HttpTransport::class, $method))->getParameters() as $parameter) {
                if ($parameter->getName() === $name) {
                    $marked = $parameter->getAttributes(SensitiveParameter::class) !== [];
                }
            }

            self::assertTrue($marked, sprintf('%s() must mark $%s sensitive.', $method, $name));
        }
    }

    /**
     * Serialising one of these exceptions succeeds, and carries nothing.
     *
     * This replaces a test that pinned the opposite. Marking the payload-carrying
     * parameters put a SensitiveParameterValue in the trace, which the engine
     * refuses to serialise, so every exception the transport threw — an ordinary
     * decline included — became a fatal inside any queue worker that stored one.
     * The scrubbing hooks on the exception classes make the state safe instead of
     * letting the engine refuse it; see Exception\ExceptionSerializationTest and
     * Support\ExceptionState.
     *
     * Asserted here as well as there because only this path builds the failure
     * out of a real merged credential payload: the frames of a transport failure
     * hold the password the transport actually sent, not a fixture's.
     */
    public function testSerialisingAThrownFailureSucceedsAndCarriesNoCredential(): void
    {
        $this->client->setDefaultException($this->failureCarryingTheSentBody());

        try {
            $this->transport()->send(new ConfirmPaymentRequest('PID-CONFIRM-1', $this->amount()));
            self::fail('A capture that failed in transport must throw.');
        } catch (IndeterminateStateException $exception) {
            $payload = serialize($exception);

            self::assertStringNotContainsString(self::PASSWORD, $payload);
            self::assertStringNotContainsString(self::USERNAME, $payload);
            self::assertStringNotContainsString(self::CLIENT_ID, $payload);

            $restored = unserialize($payload);

            self::assertInstanceOf(IndeterminateStateException::class, $restored);
            self::assertSame('ConfirmPayment', $restored->operation());
            self::assertSame('PID-CONFIRM-1', $restored->paymentId());
            self::assertSame($exception->getMessage(), $restored->getMessage());
            self::assertTrue($restored->chainDropped());
            self::assertNull($restored->getPrevious());
        }
    }

    /**
     * The credential set is the one the request declared, asserted against the
     * bytes that were sent rather than against the request object.
     *
     * §4.12 records that the gateway ignores unknown fields in silence, so
     * sending ClientID where the model does not declare it would never be
     * reported by anything — which is why this is asserted at all.
     */
    public function testAMerchantScopedRequestCarriesTheClientId(): void
    {
        $this->respondWith(200, self::INIT_SUCCESS_BODY);
        $this->transport()->send($this->initRequest());

        $body = (string) $this->sentRequests()[0]->getBody();

        self::assertStringContainsString('"ClientID":"' . self::CLIENT_ID . '"', $body);
        self::assertStringContainsString('"Username":"' . self::USERNAME . '"', $body);
        self::assertStringContainsString('"Password":"' . self::PASSWORD . '"', $body);
    }

    public function testAUserScopedRequestCarriesNoClientId(): void
    {
        $this->respondWith(200, '{"ResponseCode":"00","ResponseMessage":"OK"}');
        $this->transport()->send($this->detailsRequest());

        $body = (string) $this->sentRequests()[0]->getBody();

        self::assertStringNotContainsString('ClientID', $body);
        self::assertStringContainsString('"Username":"' . self::USERNAME . '"', $body);
        self::assertStringContainsString('"Password":"' . self::PASSWORD . '"', $body);
    }

    /**
     * Contracts\RequestInterface is public surface, so a consumer can implement
     * it. One that puts a credential in its own body is refused before anything
     * is sent — §4.12's silent tolerance of unknown fields means the gateway
     * would never report it.
     */
    public function testARequestDeclaringACredentialFieldIsRefusedBeforeDispatch(): void
    {
        $rogue = new class implements RequestInterface {
            public function operation(): string
            {
                return 'InitPayment';
            }

            public function isIdempotent(): bool
            {
                return true;
            }

            public function requiresClientId(): bool
            {
                return false;
            }

            /**
             * @return array<string, scalar>
             */
            public function toArray(): array
            {
                return ['OrderID' => 1, 'ClientID' => 'someone-elses-merchant-id'];
            }
        };

        try {
            $this->transport()->send($rogue);
            self::fail('A request object must not be able to declare a credential field.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('ClientID', $exception->getMessage());
            self::assertStringContainsString('InitPayment', $exception->getMessage());
        }

        self::assertSame([], $this->sentRequests(), 'Nothing may be sent once the body is refused.');
    }

    /**
     * A Description that is not valid UTF-8 fails the encode, which is the only
     * reachable way a request body can fail to serialise.
     *
     * The bytes must not appear in the message: a Description is caller data of
     * unknown provenance.
     */
    public function testAnUnencodableBodyIsReportedWithoutQuotingIt(): void
    {
        try {
            $this->transport()->send($this->initRequest("invalid \xB1\x31 bytes"));
            self::fail('A body that cannot be encoded must not be sent.');
        } catch (SerializationException $exception) {
            self::assertTrue($exception->causedByJson());
            self::assertSame('InitPayment', $exception->operation());
            self::assertStringNotContainsString("\xB1", $exception->getMessage());
        }

        self::assertSame([], $this->sentRequests());
    }

    /**
     * The record for a completed exchange: metadata, and nothing else.
     *
     * duration_ms is bounded rather than pinned, because it is a real
     * measurement. The bound is what makes the arithmetic testable at all: a
     * mutation that multiplies by a million instead of dividing, or adds the two
     * clock readings instead of subtracting them, produces a number in the
     * hundreds of millions of milliseconds.
     */
    public function testACompletedExchangeIsLoggedAsMetadata(): void
    {
        $this->respondWith(500, self::INIT_SUCCESS_BODY);
        $this->transport()->send($this->initRequest());

        self::assertCount(1, $this->logger->records);

        $record = $this->logger->records[0];

        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame('Ameriabank vPOS exchange completed.', $record['message']);
        self::assertSame(
            ['operation', 'url', 'status', 'attempt', 'duration_ms'],
            array_keys($record['context']),
        );
        self::assertSame('InitPayment', $record['context']['operation']);
        self::assertSame(self::INIT_URL, $record['context']['url']);
        self::assertSame(500, $record['context']['status'], 'The status is reported, never acted on.');
        self::assertSame(1, $record['context']['attempt']);

        $duration = $record['context']['duration_ms'];

        self::assertIsFloat($duration);
        self::assertGreaterThan(0.0, $duration);
        self::assertLessThan(1_000.0, $duration, 'A mock exchange that took a second did not measure a second.');
    }

    /**
     * A retry is a warning, and it names the attempt that failed and the cause's
     * class — never the cause's message, which a client may build out of the
     * request it could not send.
     */
    public function testARetryIsLoggedAsAWarning(): void
    {
        $this->client->addException($this->networkFailure());
        $this->respondWith(200, self::INIT_SUCCESS_BODY);
        $this->transport()->send($this->initRequest());

        self::assertCount(2, $this->logger->records);

        $retry = $this->logger->records[0];

        self::assertSame(LogLevel::WARNING, $retry['level']);
        self::assertSame('Ameriabank vPOS request failed in transport; retrying.', $retry['message']);
        self::assertSame(
            ['operation', 'url', 'attempt', 'duration_ms', 'exception'],
            array_keys($retry['context']),
        );
        self::assertSame(1, $retry['context']['attempt']);
        self::assertSame(NetworkException::class, $retry['context']['exception']);
        self::assertSame(LogLevel::DEBUG, $this->logger->records[1]['level']);
        self::assertSame(2, $this->logger->records[1]['context']['attempt']);
    }

    public function testAFaultIsLoggedAsAWarning(): void
    {
        $this->respondWith(500, self::FAULT_BODY);

        try {
            $this->transport()->send($this->detailsRequest());
        } catch (GatewayFaultException) {
            // Asserted elsewhere; this test is about the record.
        }

        $fault = $this->logger->records[1];

        self::assertSame(LogLevel::WARNING, $fault['level']);
        self::assertSame('Ameriabank vPOS returned a gateway fault.', $fault['message']);
        self::assertSame(['operation', 'status'], array_keys($fault['context']));
        self::assertSame(500, $fault['context']['status']);
    }

    /**
     * No record, at any level, carries the password — including the failure
     * paths, where a naive implementation logs "the request that failed".
     */
    public function testNoLogRecordCarriesTheCredential(): void
    {
        $this->client->addException($this->networkFailure());
        $this->respondWith(500, self::FAULT_BODY);

        try {
            $this->transport()->send($this->initRequest());
        } catch (GatewayFaultException) {
            // The point is what was logged on the way.
        }

        self::assertNotSame([], $this->logger->records, 'A vacuous pass would prove nothing.');

        $rendered = json_encode($this->logger->records, JSON_THROW_ON_ERROR);

        self::assertFalse(str_contains($rendered, self::PASSWORD));
        self::assertFalse(str_contains($rendered, self::CLIENT_ID));
        self::assertFalse(str_contains($rendered, self::USERNAME));
    }

    /**
     * Every record goes through the redactor, asserted at the funnel rather
     * than through the transport's own contexts — which carry nothing
     * sensitive to begin with.
     *
     * That is exactly why the funnel is worth pinning: the redactor's value is
     * entirely in what a *future* log call would do, and a future log call that
     * bypassed it would look no different from these ones.
     */
    public function testEveryRecordPassesThroughTheRedactor(): void
    {
        $log = new ReflectionMethod(HttpTransport::class, 'log');
        $log->invoke($this->transport(), LogLevel::DEBUG, 'probe', [
            'Password' => self::PASSWORD,
            'CardNumber' => '4111111111111111',
            'operation' => 'InitPayment',
        ]);

        self::assertSame(
            [
                'Password' => '[redacted]',
                'CardNumber' => '411111******1111',
                'operation' => 'InitPayment',
            ],
            $this->logger->records[0]['context'],
        );
    }

    /**
     * The default pause is a real sleep.
     *
     * The only test that spends wall-clock time, and it earns it: every other
     * test replaces the sleeper, so without this one the default could be wired
     * to a no-op — or to nothing at all — and the suite would stay green while
     * the client hammered the gateway as fast as it could fail.
     */
    public function testTheDefaultPauseIsARealSleep(): void
    {
        $this->client->setDefaultException($this->networkFailure());

        $transport = new HttpTransport(
            credentials: $this->credentials(),
            environment: Environment::Test,
            httpClient: $this->client,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: new NullLogger(),
            maxAttempts: 2,
        );

        $startedAt = hrtime(true);

        try {
            $transport->send($this->initRequest());
        } catch (TransportException) {
            // Expected: two attempts, one real pause between them.
        }

        self::assertGreaterThan(0.09, (hrtime(true) - $startedAt) / 1e9);
    }

    /**
     * With nothing injected, the three PSR collaborators come from discovery.
     */
    public function testTheCollaboratorsAreDiscoveredWhenNoneAreInjected(): void
    {
        $transport = new HttpTransport($this->credentials(), Environment::Test);

        self::assertInstanceOf(ClientInterface::class, $this->collaborator($transport, 'client'));
        self::assertInstanceOf(RequestFactoryInterface::class, $this->collaborator($transport, 'requestFactory'));
        self::assertInstanceOf(StreamFactoryInterface::class, $this->collaborator($transport, 'streamFactory'));
    }

    /**
     * A discovery failure is a configuration error, not a runtime one: the
     * package is PSR-18 abstract and the consumer supplies the implementation
     * (CONVENTIONS.md §5).
     *
     * Discovery is disabled by emptying its strategy list, which is global
     * state, so the original list is restored whatever happens.
     */
    public function testEachDiscoveryFailureIsAConfigurationError(): void
    {
        $strategies = [...ClassDiscovery::getStrategies()];

        try {
            ClassDiscovery::setStrategies([]);

            $this->assertDiscoveryFailure('PSR-18 HTTP client', null, null);
            $this->assertDiscoveryFailure('PSR-17 request factory', $this->client, null);
            $this->assertDiscoveryFailure('PSR-17 stream factory', $this->client, $this->psr17);

            // The other half of the same statement: with all three injected,
            // discovery is never reached at all. Asserted while discovery is
            // still disabled, so an implementation that consulted it first and
            // fell back to the injected value would fail here rather than
            // quietly ignoring what the consumer passed.
            $transport = new HttpTransport(
                credentials: $this->credentials(),
                environment: Environment::Test,
                httpClient: $this->client,
                requestFactory: $this->psr17,
                streamFactory: $this->psr17,
            );

            self::assertSame($this->client, $this->collaborator($transport, 'client'));
            self::assertSame($this->psr17, $this->collaborator($transport, 'requestFactory'));
            self::assertSame($this->psr17, $this->collaborator($transport, 'streamFactory'));
        } finally {
            ClassDiscovery::setStrategies($strategies);
        }
    }

    private function assertDiscoveryFailure(
        string $expected,
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
    ): void {
        try {
            new HttpTransport(
                credentials: $this->credentials(),
                environment: Environment::Test,
                httpClient: $httpClient,
                requestFactory: $requestFactory,
            );
            self::fail('Discovery cannot succeed with no strategies configured.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
        }
    }

    private function collaborator(HttpTransport $transport, string $property): mixed
    {
        return (new ReflectionProperty(HttpTransport::class, $property))->getValue($transport);
    }

    /**
     * A transport whose pause is recorded rather than taken.
     *
     * The seam is private and has no constructor parameter, so this reaches it
     * by reflection. That asymmetry is deliberate: a test can get at it and a
     * consumer cannot, which is the difference between an observable delay and a
     * switch for turning backoff off.
     */
    private function transport(int $maxAttempts = 3): HttpTransport
    {
        $transport = new HttpTransport(
            credentials: $this->credentials(),
            environment: Environment::Test,
            httpClient: $this->client,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: $this->logger,
            maxAttempts: $maxAttempts,
        );

        return $this->recordPausesOn($transport);
    }

    /**
     * Replaces the pause with a recorder.
     */
    private function recordPausesOn(HttpTransport $transport): HttpTransport
    {
        (new ReflectionProperty(HttpTransport::class, 'sleeper'))->setValue(
            $transport,
            function (int $microseconds): void {
                $this->pauses[] = $microseconds;
            },
        );

        return $transport;
    }

    private function credentials(): Credentials
    {
        return new Credentials(self::CLIENT_ID, self::USERNAME, self::PASSWORD);
    }

    private function amount(): Amount
    {
        return Amount::fromMinorUnits(1_000, Currency::AMD);
    }

    private function initRequest(?string $description = null): InitPaymentRequest
    {
        return new InitPaymentRequest(
            amount: $this->amount(),
            orderId: 1234,
            backUrl: 'https://merchant.example/return',
            description: $description,
        );
    }

    private function detailsRequest(): PaymentDetailsRequest
    {
        return new PaymentDetailsRequest('7A2B0C1D-0000-0000-0000-000000000001');
    }

    private function pendingTransactionsRequest(): GetPendingTransactionsRequest
    {
        return new GetPendingTransactionsRequest(
            new DateTimeImmutable('2026-08-01 00:00:00'),
            new DateTimeImmutable('2026-08-02 00:00:00'),
        );
    }

    private function networkFailure(): NetworkException
    {
        return new NetworkException(
            'Connection timed out',
            $this->psr17->createRequest('POST', self::INIT_URL),
        );
    }

    private function respondWith(int $status, string $body): void
    {
        $this->client->addResponse(
            $this->psr17->createResponse($status)->withBody($this->psr17->createStream($body)),
        );
    }

    /**
     * A network failure of the shape a real PSR-18 client throws: it hands back
     * the request it was sent, body and all.
     *
     * The mock client cannot build this itself — it throws whatever it is given,
     * without seeing the request — so the body is written out here. It is the
     * payload the transport would have merged: the caller's fields plus the
     * three credential fields.
     */
    private function failureCarryingTheSentBody(): NetworkException
    {
        return new NetworkException(
            'Connection timed out',
            $this->psr17->createRequest('POST', self::INIT_URL)
                ->withBody($this->psr17->createStream($this->sentBody())),
        );
    }

    /**
     * A network failure that holds the request it was sent — payload included —
     * and throws from the accessor PSR-18 provides for getting it back.
     */
    private function uninspectableFailure(): UninspectableNetworkException
    {
        return new UninspectableNetworkException(
            'Connection timed out',
            $this->psr17->createRequest('POST', self::INIT_URL)
                ->withBody($this->psr17->createStream($this->sentBody())),
        );
    }

    private function sentBody(): string
    {
        return json_encode([
            'Amount' => '10.00',
            'OrderID' => 1234,
            'ClientID' => self::CLIENT_ID,
            'Username' => self::USERNAME,
            'Password' => self::PASSWORD,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Every channel that renders an exception graph, run against one canary.
     *
     * serialize() is asked plainly, not through a catch. It used to be caught,
     * because a trace holding a SensitiveParameterValue made the engine refuse
     * and a refusal is not a leak; the exception classes now scrub their own
     * state instead, so a refusal here would be a regression and must fail the
     * run rather than be swallowed into a string that trivially lacks the
     * canary.
     */
    private function assertNothingPrintsTheCanary(Throwable $thrown, string $canary): void
    {
        self::assertStringNotContainsString($canary, $thrown->getMessage(), 'getMessage()');
        self::assertStringNotContainsString($canary, $thrown->getTraceAsString(), 'getTraceAsString()');
        self::assertStringNotContainsString($canary, (string) $thrown, '__toString()');
        self::assertStringNotContainsString($canary, print_r($thrown, true), 'print_r()');
        self::assertStringNotContainsString($canary, $this->dumped($thrown), 'var_dump()');
        self::assertStringNotContainsString($canary, $this->exported($thrown), 'var_export()');
        self::assertStringNotContainsString($canary, $this->serialised($thrown), 'serialize()');
    }

    private function dumped(Throwable $thrown): string
    {
        ob_start();
        var_dump($thrown);

        return (string) ob_get_clean();
    }

    /**
     * var_export() emits a warning when it meets a circular reference, and the
     * graph reachable from an exception thrown inside a test run is full of
     * them: the runner's own objects arrive as stack-trace arguments of the
     * frames above this package. That warning is about PHPUnit, not about
     * anything here, and the export is still produced — so it is caught for the
     * duration of the call rather than allowed to fail the run, which would
     * cost the channel the assertion exists to check.
     */
    private function exported(Throwable $thrown): string
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return var_export($thrown, true);
        } finally {
            restore_error_handler();
        }
    }

    private function serialised(Throwable $thrown): string
    {
        return serialize($thrown);
    }

    /**
     * @return list<PsrRequest>
     */
    private function sentRequests(): array
    {
        return array_values($this->client->getRequests());
    }
}
