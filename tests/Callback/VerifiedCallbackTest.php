<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Callback;

use function array_values;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Http\FailureRedactor;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;
use DavitVardanyan\AmeriabankVpos\Request\PaymentDetailsRequest;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Http\Mock\Client;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use function ksort;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function sprintf;
use function strtolower;

/**
 * `Vpos::verify()` — the one call that turns an unsigned redirect into an answer
 * the merchant may act on.
 *
 * Five outcomes are pinned, and the table is the whole contract:
 *
 * | Response `OrderID`      | Outcome                                        |
 * |-------------------------|------------------------------------------------|
 * | matches the callback's  | returns the `PaymentDetailsResponse`           |
 * | differs, non-blank      | `ValidationException::callbackOrderMismatch()`  |
 * | `""` or whitespace      | `ValidationException::callbackOrderUnconfirmable()` |
 * | absent, or JSON `null`  | returns; the cross-check is skipped             |
 *
 * ## Why the mismatch branch is a security test and not a bookkeeping one
 *
 * A `PaymentID` is not a secret — it is handed to the customer's browser — and
 * the BackURL carries no signature (CONVENTIONS.md §4.10). So an attacker can
 * take a **genuine** `paymentID` from somebody else's paid order, put it on
 * the merchant's callback URL beside *their own* `orderID`, and the details
 * round trip answers, truthfully, that a payment succeeded. A merchant who
 * looks their order up by the callback's `orderID` then ships goods against a
 * stranger's payment. Verifying the payment without pinning it to the order
 * verifies nothing, which is why `verify()` is not an alias for
 * `payments()->details($callback->paymentId())` and why the comparison below
 * is byte for byte: `'4565028'`, `'04565028'` and `' 4565028'` are three
 * different orders.
 *
 * ## Why each refusal is asserted by *which factory raised it*
 *
 * Asserting "a ValidationException was thrown" would have passed while the
 * blank-`OrderID` branch still raised `callbackOrderMismatch()` — a message
 * stating that two identifiers disagreed when in fact the gateway had named no
 * order at all. That is a false diagnosis in a security log, and the only
 * assertion that can tell it apart from the true one is one that names the
 * factory. So each refusal is checked three ways: the frame the exception was
 * constructed in, the message it carries, and that the message is *not* the
 * other branch's.
 *
 * ## Credentials
 *
 * The three values below are obvious placeholders and are not credentials of
 * any kind. The sandbox's own live outside this repository (CONVENTIONS.md
 * §8).
 */
#[CoversClass(Vpos::class)]
#[UsesClass(BindingsClient::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(Environment::class)]
#[UsesClass(FailureRedactor::class)]
#[UsesClass(HttpTransport::class)]
#[UsesClass(PaymentDetailsRequest::class)]
#[UsesClass(PaymentDetailsResponse::class)]
#[UsesClass(PaymentsClient::class)]
#[UsesClass(Redactor::class)]
#[UsesClass(ReportsClient::class)]
#[UsesClass(ResponseCode::class)]
#[UsesClass(ResponseHydrator::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(VposCallback::class)]
final class VerifiedCallbackTest extends TestCase
{
    /**
     * The illustrative all-zero GUID CONVENTIONS.md §5 now shows, replacing the
     * six-digit `'000000'` this constant copied from that example before it was
     * corrected. Never reaches the wire here: verify() sends GetPaymentDetails,
     * whose request model carries no ClientID — which the body assertion in
     * testVerifyReachesGetPaymentDetailsWithTheCallbacksPaymentId pins by
     * equality against exactly `Password`, `PaymentID` and `Username`, so this
     * constant reaching a request body would fail that test rather than pass
     * silently.
     */
    private const string CLIENT_ID = '00000000-0000-0000-0000-000000000000';

    private const string USERNAME = 'placeholder-user';

    private const string PASSWORD = 'placeholder-pass';

    /**
     * The same invented uppercase GUID the rest of the suite uses
     * (CONVENTIONS.md §4.12 records the shape; review confirmed the
     * literal appears nowhere in the recorded sandbox responses).
     */
    private const string PAYMENT_ID = 'C2E51643-0922-4442-A80C-30ADAE03BECC';

    private const string ORDER_ID = '4565028';

    /**
     * The `opaque` and `description` a real redirect carried (§4.10). Present so
     * the tests below can assert that neither reaches the wire or a log line.
     */
    private const string OPAQUE = 'OPAQUE-4565028';

    private const string DESCRIPTION = 'Internal server error';

    /**
     * Where `GetPaymentDetails` must land: `{base}api/VPOS/{operation}`, with
     * the doubled `VPOS` that CONVENTIONS.md §4.13 and the manifest's routes
     * together produce.
     */
    private const string DETAILS_URL = 'https://servicestest.ameriabank.am/VPOS/api/VPOS/GetPaymentDetails';

    private Client $client;

    private Psr17Factory $psr17;

    /**
     * Response `OrderID` values that are not the callback's, each of which must
     * be refused.
     *
     * The last two are the point. A leading zero and a leading space are what a
     * comparison written with `==`, with `trim()` on both sides, or with a cast
     * would let through — and each of those would reopen the replay hole
     * described in this file's header, because an attacker chooses the
     * `orderID` they send.
     *
     * @return array<string, array{string}>
     */
    public static function mismatchedOrderIds(): array
    {
        return [
            'a different order entirely' => ['9999999'],
            'the same digits with a leading zero' => ['0' . self::ORDER_ID],
            'the same digits with a leading space' => [' ' . self::ORDER_ID],
            'the same digits with a trailing space' => [self::ORDER_ID . ' '],
            'a prefix of the callback\'s value' => ['456'],
        ];
    }

    /**
     * Response `OrderID` values that are present but name no order.
     *
     * `""` is not hypothetical at this endpoint: probe B2's failed lookup
     * returned `"OrderID":""` itself, and the hydrator passes an empty string
     * through verbatim rather than folding it to null. The whitespace forms are
     * the same condition wearing a character. A completed payment has since been
     * observed returning a populated `OrderID` (probe case P3), so this is not
     * the shape a settled payment produces — which makes refusing it cheap, and
     * leaves it exactly as reachable as it was.
     *
     * @return array<string, array{string}>
     */
    public static function blankOrderIds(): array
    {
        return [
            'an empty string' => [''],
            'a single space' => [' '],
            'mixed whitespace' => ["  \t\n "],
        ];
    }

    /**
     * The two shapes in which the response names no order at all.
     *
     * A key that is absent and a key whose JSON value is `null` are different
     * bodies and are both observed shapes of an ASP.NET response, so both are
     * exercised. Neither can be cross-checked, so both skip.
     *
     * @return array<string, array{string}>
     */
    public static function absentOrderIdBodies(): array
    {
        return [
            'the key is absent' => ['{"ResponseCode":"00","ResponseMessage":"OK"}'],
            'the key is JSON null' => ['{"ResponseCode":"00","ResponseMessage":"OK","OrderID":null}'],
        ];
    }

    protected function setUp(): void
    {
        $this->client = new Client();
        $this->psr17 = new Psr17Factory();
    }

    /**
     * A response naming the same order is returned to the caller.
     *
     * The returned object is the gateway's answer, not the callback's: its
     * `orderId` is asserted to be the response's own field.
     */
    public function testVerifyReturnsTheDetailsWhenTheResponseNamesTheSameOrder(): void
    {
        $this->queue($this->detailsBody(self::ORDER_ID));

        $details = $this->vpos()->verify($this->parsedCallback());

        self::assertSame(self::ORDER_ID, $details->orderId);
        self::assertSame('00', $details->responseCode->raw());
        self::assertCount(1, $this->client->getRequests());
    }

    /**
     * The round trip goes to `GetPaymentDetails`, addressed by the callback's
     * `paymentID` and by nothing else from the callback.
     *
     * Three separate claims, and each is a different mistake. The **URL**:
     * `RefundPayment` and `CancelPayment` accept the same field, so a verify()
     * built on the wrong request DTO would move money instead of reading state,
     * and the gateway would answer it. The **body**: §4.12 records that unknown
     * request fields are ignored silently, so a `PaymentID` that arrived under
     * the wrong key would not be rejected — it would be discarded, and the
     * gateway would answer about nothing. The **absence of everything else**:
     * `orderID`, `opaque`, `resposneCode` and `description` are attacker-
     * controlled, and none of them may influence what is asked.
     */
    public function testVerifyReachesGetPaymentDetailsWithTheCallbacksPaymentId(): void
    {
        $this->queue($this->detailsBody(self::ORDER_ID));

        $this->vpos()->verify($this->parsedCallback());

        $requests = array_values($this->client->getRequests());

        self::assertCount(1, $requests, 'verify() must make exactly one round trip.');
        self::assertSame(self::DETAILS_URL, (string) $requests[0]->getUri());

        $body = (string) $requests[0]->getBody();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        ksort($decoded);

        self::assertSame(
            ['Password' => self::PASSWORD, 'PaymentID' => self::PAYMENT_ID, 'Username' => self::USERNAME],
            $decoded,
            'The request must carry the callback\'s PaymentID under exactly that key, plus the '
            . 'credentials the transport injects — and nothing else.',
        );

        foreach ([self::ORDER_ID, self::OPAQUE, self::DESCRIPTION, '0999'] as $fromTheQueryString) {
            self::assertStringNotContainsString(
                $fromTheQueryString,
                $body,
                'No value from the unsigned query string may reach the request the SDK asks the gateway.',
            );
        }
    }

    /**
     * The callback's PaymentID reaches the wire in the case it arrived in, and
     * that case is lowercase on a real callback.
     *
     * This is not a style point. `InitPayment` answers with an uppercase GUID
     * and the BackURL echoes the identical identifier entirely in lowercase —
     * probe case P1 against probe case P2 — and CONVENTIONS.md §4.8 forbids
     * normalising either. So verify() sends a lowercase PaymentID, and no other
     * call in this package does.
     *
     * What the assertion pins is byte-for-byte pass-through: an `strtoupper()`
     * added anywhere between VposCallback and the request DTO would fail here
     * and nowhere else in the suite, because every other PaymentID fixture is
     * already uppercase. The fixture is derived from PAYMENT_ID by
     * strtolower() rather than written out, so it cannot drift out of case with
     * it; that PAYMENT_ID is uppercase is fixed by the constant itself, and
     * asserting it here is something PHPStan resolves at analysis time rather
     * than a check that could ever run.
     *
     * What it cannot pin is the gateway's side. That Ameriabank accepts the
     * lowercase form is no longer unobserved: case L3 sent a callback's
     * lowercase PaymentID to `GetPaymentDetails` for a payment InitPayment had
     * issued in uppercase, and the gateway answered `ResponseCode` `"00"` with
     * the correct payment's body. But a mock client could not have established
     * that and does not establish it here — this test pins the pass-through and
     * nothing about the far end. See CONVENTIONS.md §4.12.
     */
    public function testVerifySendsThePaymentIdInTheCaseTheCallbackUsedIt(): void
    {
        $lowercase = strtolower(self::PAYMENT_ID);

        $this->queue($this->detailsBody(self::ORDER_ID));

        $this->vpos()->verify(VposCallback::fromQuery([
            'orderID' => self::ORDER_ID,
            'resposneCode' => '00',
            'paymentID' => $lowercase,
            'opaque' => self::OPAQUE,
            'description' => 'Operation Approved ',
        ]));

        $requests = array_values($this->client->getRequests());

        self::assertCount(1, $requests);

        $decoded = json_decode((string) $requests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame($lowercase, $decoded['PaymentID'] ?? null);
    }

    /**
     * A response naming a different order is refused as a mismatch.
     */
    #[DataProvider('mismatchedOrderIds')]
    public function testVerifyRefusesAResponseNamingADifferentOrder(string $responseOrderId): void
    {
        $this->queue($this->detailsBody($responseOrderId));

        try {
            $this->vpos()->verify($this->parsedCallback());
            self::fail(sprintf('A response naming order "%s" was accepted for order "%s".', $responseOrderId, self::ORDER_ID));
        } catch (ValidationException $thrown) {
            $this->assertRefusedBy(
                'callbackOrderMismatch',
                ValidationException::callbackOrderMismatch()->getMessage(),
                $thrown,
            );
        }
    }

    /**
     * A response whose `OrderID` is present but blank is refused as
     * *unconfirmable* — not as a mismatch.
     *
     * This is the assertion the blank branch exists for. Nothing disagreed:
     * the gateway supplied no order identity to disagree with, so reporting a
     * mismatch would state something false in a log a merchant reads while
     * deciding whether they are being attacked.
     *
     * It refuses rather than skipping, because a blank string is a value the
     * gateway chose to send — probe B2's failed lookup sent exactly that. A
     * completed payment has since been observed sending a populated `OrderID`
     * instead (probe case P3), so this is not the shape a settled payment
     * produces; one payment does not rule it out either (CONVENTIONS.md §13),
     * and failing closed is the only answer that cannot be exploited.
     */
    #[DataProvider('blankOrderIds')]
    public function testVerifyRefusesABlankOrderIdAsUnconfirmableRatherThanAsAMismatch(string $responseOrderId): void
    {
        $this->queue($this->detailsBody($responseOrderId));

        try {
            $this->vpos()->verify($this->parsedCallback());
            self::fail('A response carrying a blank OrderID was accepted.');
        } catch (ValidationException $thrown) {
            $this->assertRefusedBy(
                'callbackOrderUnconfirmable',
                ValidationException::callbackOrderUnconfirmable()->getMessage(),
                $thrown,
            );

            self::assertNotSame(
                ValidationException::callbackOrderMismatch()->getMessage(),
                $thrown->getMessage(),
                'A blank OrderID is not a mismatch. Nothing disagreed — the gateway named no order.',
            );
        }
    }

    /**
     * A response naming no order at all returns, with the cross-check skipped.
     *
     * A real gap rather than an oversight: a check cannot be made against an
     * absent value. It is a narrower gap than it was — probe case P3, a completed
     * payment, returns a populated `OrderID` matching the callback's byte for
     * byte, so the branch a real payment reaches is the comparison and not this
     * one. One payment is not a guarantee that the gateway always populates it
     * (CONVENTIONS.md §13), so a caller who has their own order to hand should
     * still compare it against `$details->orderId`.
     */
    #[DataProvider('absentOrderIdBodies')]
    public function testVerifySkipsTheCrossCheckWhenTheResponseNamesNoOrder(string $body): void
    {
        $this->queue($body);

        $details = $this->vpos()->verify($this->parsedCallback());

        self::assertNull($details->orderId);
        self::assertCount(1, $this->client->getRequests());
    }

    /**
     * The two refusals carry different messages.
     *
     * Asserted directly, because every other assertion in this file compares a
     * thrown message against a factory's — and two factories returning the same
     * string would satisfy all of them while making the distinction the
     * previous test relies on invisible in a log.
     */
    public function testTheTwoRefusalsAreDistinguishableInALog(): void
    {
        self::assertNotSame(
            ValidationException::callbackOrderMismatch()->getMessage(),
            ValidationException::callbackOrderUnconfirmable()->getMessage(),
        );
    }

    /**
     * No value from the query string appears in either refusal message.
     *
     * CONVENTIONS.md §6: exception messages reach logs, and BackURL parameters
     * are untrusted input. Embedding the callback's `orderID` would hand an
     * attacker a writable line in the merchant's log — CRLF, a forged entry,
     * or simply noise chosen to bury the real one.
     */
    public function testNeitherRefusalMessageEchoesAValueFromTheQueryString(): void
    {
        foreach ([
            ValidationException::callbackOrderMismatch()->getMessage(),
            ValidationException::callbackOrderUnconfirmable()->getMessage(),
        ] as $message) {
            foreach ([self::ORDER_ID, self::PAYMENT_ID, self::OPAQUE, self::DESCRIPTION, '0999'] as $value) {
                self::assertStringNotContainsString($value, $message);
            }
        }
    }

    /**
     * The refusal names the factory it claims to.
     *
     * The exception's first stack frame is the function it was constructed in,
     * so this pins the *branch taken* rather than the text produced — which is
     * the assertion that could tell the blank branch's honest diagnosis from
     * the mismatch branch's false one, since both were a ValidationException
     * raised from the same method for the same callback.
     *
     * The expected message is passed in as well as the factory name, and the
     * redundancy is deliberate. The name pins which branch ran; the message
     * pins what a merchant reads in a log. A branch that reached the right
     * factory and then rewrote its text would satisfy the first and fail the
     * second, and the whole reason the blank branch exists at all is a message
     * that was wrong while the code path was right.
     */
    private function assertRefusedBy(string $factory, string $expectedMessage, ValidationException $thrown): void
    {
        $frames = $thrown->getTrace();

        self::assertArrayHasKey(0, $frames, 'The exception carries no stack frame, so its branch cannot be identified.');
        self::assertArrayHasKey('class', $frames[0], 'The exception was not constructed inside a class at all.');
        self::assertSame(ValidationException::class, $frames[0]['class']);
        self::assertSame($factory, $frames[0]['function'], 'The refusal came from a different branch than expected.');
        self::assertSame($expectedMessage, $thrown->getMessage());
    }

    /**
     * The callback a merchant's BackURL handler would have parsed — all five
     * parameters, at their observed spellings.
     */
    private function parsedCallback(): VposCallback
    {
        return VposCallback::fromQuery([
            'orderID' => self::ORDER_ID,
            'resposneCode' => '0999',
            'paymentID' => self::PAYMENT_ID,
            'opaque' => self::OPAQUE,
            'description' => self::DESCRIPTION,
        ]);
    }

    /**
     * A `GetPaymentDetails` success envelope naming $orderId.
     *
     * `ResponseCode` is the string `"00"` on every endpoint but `InitPayment`
     * (§4.3), and probe case P3 observed exactly that value here. Encoded rather
     * than concatenated so a value containing whitespace or a quote produces
     * valid JSON.
     *
     * There is no `ResponseMessage` key, and its absence is the fixture being
     * accurate rather than minimal: this endpoint does not send one. Every
     * `GetPaymentDetails` body on record — probe B2's failure and probe case
     * P3's success alike — omits it, which is why PaymentDetailsResponse has no
     * such property and why the endpoint's diagnostic text arrives in
     * `Description` instead.
     */
    private function detailsBody(string $orderId): string
    {
        return json_encode(
            ['ResponseCode' => '00', 'OrderID' => $orderId],
            JSON_THROW_ON_ERROR,
        );
    }

    private function queue(string $body): void
    {
        $this->client->addResponse(
            $this->psr17->createResponse(200)->withBody($this->psr17->createStream($body)),
        );
    }

    private function vpos(): Vpos
    {
        return new Vpos(
            credentials: new Credentials(self::CLIENT_ID, self::USERNAME, self::PASSWORD),
            environment: Environment::Test,
            httpClient: $this->client,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: new NullLogger(),
        );
    }
}
