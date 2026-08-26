<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Client;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function class_exists;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Http\FailureRedactor;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\ActivateBindingRequest;
use DavitVardanyan\AmeriabankVpos\Request\CancelPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\ConfirmPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\DeactivateBindingRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetBindingsRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetPaymentIdRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetPendingTransactionsRequest;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\MakeBindingPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\PaymentDetailsRequest;
use DavitVardanyan\AmeriabankVpos\Request\RefundPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Response\ActivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\CancelPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\ConfirmPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\DeactivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetBindingsResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPaymentIdResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPendingTransactionsResponse;
use DavitVardanyan\AmeriabankVpos\Response\InitPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\MakeBindingPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use DavitVardanyan\AmeriabankVpos\Response\RefundPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;
use DavitVardanyan\AmeriabankVpos\Vpos;

use function file_get_contents;

use Http\Mock\Client;

use function in_array;
use function is_object;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionClassConstant;

use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function substr;

/**
 * Every operation the three clients expose, driven end to end against a mock
 * PSR-18 client.
 *
 * Four properties are asserted per operation, and each one is a mistake that
 * costs a payment rather than a test:
 *
 * 1. **The URL.** A client method that builds the wrong request DTO sends a
 *    perfectly valid body to another operation's endpoint. The gateway answers
 *    it — `RefundPayment` and `CancelPayment` take the same field — so nothing
 *    upstream reports the swap, and the money moves the wrong way.
 * 2. **The encoded body.** CONVENTIONS.md §4.12: unknown request fields are
 *    ignored silently. A dropped `Timeout` or a renamed `BackURL` is not
 *    rejected; it is discarded, and the customer is returned to nowhere.
 * 3. **The returned type.** Asserted by `::class`, because every response DTO in
 *    this package hydrates from the same shape of array and a transposed
 *    hydrator call yields an object that looks fine until a property is read.
 * 4. **Credential injection.** Which operations carry `ClientID` is an accident
 *    of the bank's own models — the four PaymentID-addressed ones omit it — and
 *    §4.12 means getting it wrong in either direction is answered with silence.
 *    So it is asserted against the *bytes on the wire*, not against the DTO.
 *
 * ## Nothing below enumerates the operations by hand
 *
 * A guard's subject list is derived from its source of truth at test time. The
 * operation list comes from `docs/api-reference/api-surface.json` — the
 * specification of record under CONVENTIONS.md §2 — read from disk by the data
 * provider, and the case table is asserted to match it exactly in both
 * directions. A twelfth operation appearing upstream therefore fails this file
 * until a human classifies it, and a case for an operation that no longer
 * exists fails it too.
 *
 * `SSNCheck` is the one exclusion, and it is excluded by name with a reason:
 * CONVENTIONS.md §7 records it as excluded from v1.0 — unrelated to the
 * payment lifecycle — and §6 puts the Armenian national identity data it
 * carries under the same handling as credentials, so this package has no
 * request class for it and no client method reaching it.
 *
 * The credential split is derived the same way — from which manifest request
 * models declare a `ClientID` field — and never transcribed. The expected
 * bodies below *are* literals, necessarily: a manifest declares which fields
 * exist, not which values a caller passed. Their *keys* are still the
 * manifest's: every expected body is asserted equal to its model's field list,
 * in both directions and in the manifest's order, so a literal here can neither
 * invent a field name nor quietly stop expecting one the client should send.
 *
 * ## Dates are ATOM, and that is the manifest's answer
 *
 * `GetPendingTransactions` sends `2026-08-01T09:30:00+04:00`, not `2026/08/01
 * 09:30`. The manifest types `StartDate` and `EndDate` as `date` and its own
 * sample renders `2026-08-23T19:46:22.3011509+04:00`; the slash-and-space form
 * is the vendor PDF's, and CONVENTIONS.md §2 ranks the PDF below the manifest.
 * The bound is built with an explicit `DateTimeZone` so the expectation does
 * not depend on the machine's clock configuration.
 *
 * ## Credentials
 *
 * The three values below are placeholders and are not credentials of any kind.
 * The sandbox's own credentials live outside this repository (CONVENTIONS.md
 * §8).
 */
#[CoversClass(BindingsClient::class)]
#[CoversClass(PaymentsClient::class)]
#[CoversClass(ReportsClient::class)]
#[UsesClass(Amount::class)]
#[UsesClass(ActivateBindingRequest::class)]
#[UsesClass(CancelPaymentRequest::class)]
#[UsesClass(ConfirmPaymentRequest::class)]
#[UsesClass(DeactivateBindingRequest::class)]
#[UsesClass(GetBindingsRequest::class)]
#[UsesClass(GetPaymentIdRequest::class)]
#[UsesClass(GetPendingTransactionsRequest::class)]
#[UsesClass(PaymentDetailsRequest::class)]
#[UsesClass(RefundPaymentRequest::class)]
#[UsesClass(ActivateBindingResponse::class)]
#[UsesClass(CancelPaymentResponse::class)]
#[UsesClass(ConfirmPaymentResponse::class)]
#[UsesClass(DeactivateBindingResponse::class)]
#[UsesClass(GetBindingsResponse::class)]
#[UsesClass(GetPaymentIdResponse::class)]
#[UsesClass(GetPendingTransactionsResponse::class)]
#[UsesClass(InitPaymentResponse::class)]
#[UsesClass(MakeBindingPaymentResponse::class)]
#[UsesClass(PaymentDetailsResponse::class)]
#[UsesClass(RefundPaymentResponse::class)]
#[UsesClass(InitPaymentRequest::class)]
#[UsesClass(MakeBindingPaymentRequest::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(Currency::class)]
#[UsesClass(Environment::class)]
#[UsesClass(FailureRedactor::class)]
#[UsesClass(HttpTransport::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(Redactor::class)]
#[UsesClass(ResponseCode::class)]
#[UsesClass(ResponseHydrator::class)]
#[UsesClass(Vpos::class)]
final class ClientOperationTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    private const string REQUEST_DIRECTORY = __DIR__ . '/../../src/Request';

    private const string REQUEST_NAMESPACE = 'DavitVardanyan\\AmeriabankVpos\\Request\\';

    /**
     * The operation this SDK deliberately does not implement.
     *
     * Named rather than derived, because the manifest describes the API and
     * has no notion of this package's scope. CONVENTIONS.md §7 records
     * `SSNCheck` as excluded from v1.0, and §6 puts the Armenian national
     * identity data it carries under the same handling as credentials.
     */
    private const string UNIMPLEMENTED_OPERATION = 'SSNCheck';

    /**
     * The REST prefix every operation hangs off, transcribed from
     * CONVENTIONS.md §4.13 rather than obtained from Environment.
     *
     * Asking Environment::apiUrl() for the expectation would assert that the
     * transport agrees with Environment about the URL, which it does by
     * construction — it calls the same method. The point of this literal is that
     * both must agree with the project document instead. The doubled `VPOS` is
     * correct; see EnvironmentTest.
     */
    private const string REST_PREFIX = 'https://servicestest.ameriabank.am/VPOS/api/VPOS/';

    private const string CLIENT_ID = 'client-x';

    private const string USERNAME = 'user-x';

    private const string PASSWORD = 'pw-x';

    /**
     * A PaymentID of the shape the gateway has been observed to emit — an
     * uppercase 36-character GUID (CONVENTIONS.md §4.12). Invented; it is the
     * same literal tests/Config and tests/Exception use, kept identical so the
     * one made-up PaymentID in this suite stays one.
     */
    private const string PAYMENT_ID = 'C2E51643-0922-4442-A80C-30ADAE03BECC';

    private const string CARD_HOLDER_ID = 'holder-1';

    private const string BACK_URL = 'https://merchant.example/vpos/return';

    private const int ORDER_ID = 1234;

    /**
     * The generic success envelope: `ResponseCode` is the string `"00"` on
     * every endpoint but `InitPayment` (CONVENTIONS.md §4.3).
     */
    private const string SUCCESS_BODY = '{"ResponseCode":"00","ResponseMessage":"OK"}';

    private Client $client;

    private Psr17Factory $psr17;

    /**
     * The operations this package implements, read from the specification of
     * record at test time.
     *
     * @return array<string, array{string}>
     */
    public static function inScopeOperations(): array
    {
        $operations = [];

        foreach (self::manifestEndpoints() as $operation => $fields) {
            self::assertNotSame([], $fields, sprintf('The manifest describes %s with no request fields.', $operation));

            if ($operation === self::UNIMPLEMENTED_OPERATION) {
                continue;
            }

            $operations[$operation] = [$operation];
        }

        self::assertNotSame([], $operations, 'The manifest yielded no endpoints at all.');

        return $operations;
    }

    protected function setUp(): void
    {
        $this->client = new Client();
        $this->psr17 = new Psr17Factory();
    }

    /**
     * The case table below and the manifest name the same operations, in both
     * directions.
     *
     * This is the assertion that makes every other test in the file
     * manifest-derived rather than hand-maintained. Without it, a twelfth
     * operation added upstream would simply never be exercised — the failure
     * mode a written list produces, where a rogue subject once slipped past
     * three guards at once because each iterated a hand-written provider
     * instead of its source of truth.
     */
    public function testTheCaseTableNamesExactlyTheManifestsInScopeOperations(): void
    {
        $expected = array_keys(self::inScopeOperations());
        $actual = array_keys($this->cases());
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);

        self::assertNotContains(
            self::UNIMPLEMENTED_OPERATION,
            $actual,
            'SSNCheck is excluded from v1.0 (CONVENTIONS.md §7) and carries Armenian national identity data (§6).',
        );
    }

    /**
     * Every expected body below is exactly the field list its manifest model
     * declares, in the manifest's order — no invented key, and no omitted one.
     *
     * The values in the case table are this file's; the keys are not. §4.12
     * records that the gateway ignores an unknown request field silently, so a
     * misspelled key in a test expectation would agree with a misspelled key in
     * src/ and neither would ever be reported.
     *
     * The assertion runs in **both** directions, and the second direction is
     * the one that earns its keep. A one-directional `array_diff($expected,
     * $declared)` catches an invented key and nothing else: an optional field
     * dropped from both the case literal *and* the client — `Currency`,
     * `Timeout`, `PaymentServiceType`, `Opaque` or `Description` on
     * `InitPayment` — would leave every test in this file green while the field
     * silently stopped being sent, and §4.12 means the gateway reports nothing
     * either. That is the decorative-guard shape tasks 003, 008 and 009 each
     * found. Asserting equality is also what makes the case table's own claim
     * — that every optional a request model accepts is supplied — mechanical
     * rather than narrative.
     *
     * Order is part of it: `assertSame` on two lists is order-sensitive, and
     * the request DTOs emit their fields in the manifest's order.
     *
     * The credential names subtracted from the declared list are not written
     * down here either — they are read from `Credentials::merchantFields()`,
     * which is the thing that actually injects them. Credentials never appear
     * in a request DTO; the transport merges them last, which is what
     * testEveryOperationSendsExactlyTheBodyItsModelDeclares() holds.
     */
    #[DataProvider('inScopeOperations')]
    public function testEveryExpectedBodyIsExactlyTheFieldsTheManifestDeclares(string $operation): void
    {
        $credentialNames = $this->credentialFieldNames();

        $declared = array_values(array_filter(
            self::manifestEndpoints()[$operation],
            static fn(string $field): bool => !in_array($field, $credentialNames, true),
        ));

        $expected = array_keys($this->cases()[$operation]['body']);

        self::assertNotSame([], $expected, sprintf('The %s case expects no fields at all.', $operation));

        self::assertSame(
            $declared,
            $expected,
            sprintf(
                'The %s case and its manifest request model disagree about the business fields. '
                . 'A key here the manifest does not declare is invented; a field the manifest '
                . 'declares and this case omits is a field the client may have stopped sending, '
                . 'which the gateway answers with silence (CONVENTIONS.md §4.12).',
                $operation,
            ),
        );
    }

    /**
     * Assertion family 1 — every operation reaches its own endpoint.
     *
     * Transposing any two client methods fails here: the case for one operation
     * would send to the other's URL. It is a whole-string assertion because a
     * `assertStringContainsString('Payment', …)` would pass for six of the
     * eleven.
     */
    #[DataProvider('inScopeOperations')]
    public function testEveryOperationReachesItsOwnUrl(string $operation): void
    {
        $this->invoke($operation);

        self::assertSame(
            self::REST_PREFIX . $operation,
            (string) $this->sentRequest()->getUri(),
            sprintf('%s was dispatched to another operation\'s endpoint.', $operation),
        );
    }

    /**
     * Assertion family 2 — the encoded body is exactly the wire keys the
     * operation declares, plus the credential set it carries.
     *
     * Compared as a decoded array with assertSame, which is order-sensitive:
     * the request DTOs emit their fields in the manifest's order and the
     * transport merges credentials last, so key order is part of what is being
     * held. A dropped optional, a renamed key or a reordered body all fail.
     */
    #[DataProvider('inScopeOperations')]
    public function testEveryOperationSendsExactlyTheBodyItsModelDeclares(string $operation): void
    {
        $this->invoke($operation);

        self::assertSame(
            array_merge($this->cases()[$operation]['body'], $this->credentialFieldsFor($operation)),
            $this->sentBody(),
            sprintf('%s put a different body on the wire.', $operation),
        );
    }

    /**
     * Assertion family 3 — the hydrated type, by `::class`.
     *
     * Not assertInstanceOf. Every response DTO here is final and none extends
     * another, so the two are equivalent today — but `::class` stays equivalent
     * on the day one of them gains a subtype, and an assertion that would
     * quietly weaken later is not the one to write.
     *
     * `GetPendingTransactions` is the one operation answering with a bare JSON
     * array and no `ResponseCode` envelope (CONVENTIONS.md §13), so its method
     * returns a list. The element type is asserted per element.
     */
    #[DataProvider('inScopeOperations')]
    public function testEveryOperationReturnsItsOwnHydratedDto(string $operation): void
    {
        $case = $this->cases()[$operation];
        $result = $this->invoke($operation);

        if ($case['list']) {
            self::assertIsList($result);
            self::assertNotSame([], $result, sprintf('%s hydrated an empty collection from a non-empty body.', $operation));

            foreach ($result as $element) {
                self::assertIsObject($element);
                self::assertSame($case['type'], $element::class);
            }

            return;
        }

        self::assertTrue(is_object($result), sprintf('%s returned something that is not an object.', $operation));
        self::assertSame($case['type'], $result::class, sprintf('%s returned the wrong DTO.', $operation));
    }

    /**
     * Assertion family 4 — credential injection, read off the encoded body.
     *
     * The split is the manifest's: a request model that declares `ClientID`
     * gets Credentials::merchantFields(), one that does not gets userFields().
     * Nothing here transcribes which is which.
     *
     * Asserted against the bytes rather than against the request object because
     * that is where the mistake lands. A DTO cannot carry a credential at all —
     * the transport rejects a body that declares one — so the only place the
     * merchant identifier can be present or missing is the payload, and §4.12
     * means the gateway will not tell anyone either way. For `InitPayment` the
     * stakes are concrete: §4.4 keys payment identity on (ClientID, OrderID),
     * so a missing ClientID is a missing half of the idempotency key.
     */
    #[DataProvider('inScopeOperations')]
    public function testCredentialsAreInjectedIntoTheBodyOnTheManifestsTerms(string $operation): void
    {
        $this->invoke($operation);

        $body = $this->sentBody();
        $declared = self::manifestEndpoints()[$operation];

        self::assertSame(self::USERNAME, $body['Username'] ?? null, sprintf('%s sent no Username.', $operation));
        self::assertSame(self::PASSWORD, $body['Password'] ?? null, sprintf('%s sent no Password.', $operation));

        if (in_array('ClientID', $declared, true)) {
            self::assertSame(
                self::CLIENT_ID,
                $body['ClientID'] ?? null,
                sprintf('The manifest model for %s declares ClientID and the request did not carry it.', $operation),
            );

            return;
        }

        self::assertArrayNotHasKey(
            'ClientID',
            $body,
            sprintf('The manifest model for %s declares no ClientID and the request carried one.', $operation),
        );
    }

    /**
     * The same split, read off the request object rather than the wire.
     *
     * A deliberately independent derivation of what
     * tests/Request/RequestContractTest.php already asserts, kept because the
     * test above is only meaningful if the two halves agree: requiresClientId()
     * is what the transport consults, and this file's subject is the path from
     * a client method to the bytes. The request classes are found on the
     * filesystem and keyed by their own OPERATION constant, so the mapping is
     * not written down here either.
     *
     * Instances are built without the constructor because none of these
     * implementations reads state in requiresClientId() — it returns a literal —
     * and constructing eleven valid requests to ask a constant question would
     * make the guard depend on eleven sets of fixture values.
     */
    #[DataProvider('inScopeOperations')]
    public function testTheRequestObjectAgreesWithTheManifestAboutClientId(string $operation): void
    {
        $classes = $this->requestClassesByOperation();

        self::assertArrayHasKey($operation, $classes, sprintf('No request class declares the operation %s.', $operation));

        $request = (new ReflectionClass($classes[$operation]))->newInstanceWithoutConstructor();

        self::assertInstanceOf(RequestInterface::class, $request);

        self::assertSame(
            in_array('ClientID', self::manifestEndpoints()[$operation], true),
            $request->requiresClientId(),
            sprintf('%s::requiresClientId() disagrees with the manifest.', $classes[$operation]),
        );
    }

    /**
     * Both sides of the credential split are populated.
     *
     * Without this, the two tests above are green if every model declared
     * ClientID — or if none did — because each asserts only the branch the
     * manifest selects. The counts are not pinned, only their non-emptiness:
     * the day the bank moves an operation from one side to the other, that is a
     * fact about the API and the derived tests should follow it, not fight it.
     */
    public function testTheCredentialSplitHasBothSides(): void
    {
        $withClientId = [];
        $withoutClientId = [];

        foreach (array_keys(self::inScopeOperations()) as $operation) {
            if (in_array('ClientID', self::manifestEndpoints()[$operation], true)) {
                $withClientId[] = $operation;

                continue;
            }

            $withoutClientId[] = $operation;
        }

        self::assertNotSame([], $withClientId, 'No operation carries ClientID, so the merchant branch is never exercised.');
        self::assertNotSame([], $withoutClientId, 'Every operation carries ClientID, so the user branch is never exercised.');
    }

    /**
     * Exactly one request leaves for one client call.
     *
     * A pass-through client that accidentally sent twice would satisfy every
     * assertion above, since each reads the first recorded request. On
     * `ConfirmPayment`, `RefundPayment`, `CancelPayment` and
     * `MakeBindingPayment` a second dispatch is money moved twice
     * (CONVENTIONS.md §4.5).
     */
    #[DataProvider('inScopeOperations')]
    public function testOneClientCallSendsExactlyOneRequest(string $operation): void
    {
        $this->invoke($operation);

        self::assertCount(1, $this->client->getRequests(), sprintf('%s did not send exactly one request.', $operation));
    }

    /**
     * Runs one operation against a queued response and returns what the client
     * method returned.
     */
    private function invoke(string $operation): mixed
    {
        $case = $this->cases()[$operation];

        $this->client->addResponse(
            $this->psr17->createResponse(200)->withBody($this->psr17->createStream($case['response'])),
        );

        return ($case['invoke'])($this->vpos());
    }

    /**
     * One case per in-scope operation: how to call it, what it must put on the
     * wire, what the gateway answers, and what comes back.
     *
     * The invocations are the only thing in this file that cannot be derived —
     * no manifest names a PHP method — so the table's completeness is asserted
     * against the manifest instead, in both directions, by
     * testTheCaseTableNamesExactlyTheManifestsInScopeOperations().
     *
     * Every optional field the request models accept is supplied, because an
     * optional that is never sent is an optional whose wire key is never
     * asserted — and that is not a promise this docblock makes, it is one
     * testEveryExpectedBodyIsExactlyTheFieldsTheManifestDeclares() holds: each
     * body below must equal its manifest model's whole field list, so dropping
     * an optional here fails the build. The two binding-capable payment types
     * are deliberately not the
     * same across ActivateBinding, DeactivateBinding and GetBindings: identical
     * values would let two of those three be transposed without a body
     * differing.
     *
     * @return array<string, array{
     *     invoke: Closure(Vpos): mixed,
     *     body: array<string, int|string>,
     *     response: string,
     *     type: class-string,
     *     list: bool,
     * }>
     */
    private function cases(): array
    {
        $amount = Amount::fromMinorUnits(1000, Currency::AMD);
        $from = new DateTimeImmutable('2026-08-01 09:30:00', new DateTimeZone('+04:00'));
        $to = new DateTimeImmutable('2026-08-31 21:45:00', new DateTimeZone('+04:00'));

        return [
            'InitPayment' => [
                'invoke' => static fn(Vpos $vpos): InitPaymentResponse => $vpos->payments()->init(
                    new InitPaymentRequest(
                        amount: $amount,
                        orderId: self::ORDER_ID,
                        backUrl: self::BACK_URL,
                        description: 'Order 1234',
                        cardHolderId: self::CARD_HOLDER_ID,
                        opaque: 'opaque-1',
                        timeout: 1200,
                        paymentServiceType: 5,
                    ),
                ),
                'body' => [
                    'Amount' => '10.00',
                    'OrderID' => self::ORDER_ID,
                    'BackURL' => self::BACK_URL,
                    'Description' => 'Order 1234',
                    'Currency' => '051',
                    'CardHolderID' => self::CARD_HOLDER_ID,
                    'Opaque' => 'opaque-1',
                    'Timeout' => 1200,
                    'PaymentServiceType' => 5,
                ],
                'response' => '{"PaymentID":"' . self::PAYMENT_ID . '","ResponseCode":1,"ResponseMessage":"OK","Opaque":"opaque-1"}',
                'type' => InitPaymentResponse::class,
                'list' => false,
            ],
            'GetPaymentDetails' => [
                'invoke' => static fn(Vpos $vpos): PaymentDetailsResponse => $vpos->payments()->details(self::PAYMENT_ID),
                'body' => ['PaymentID' => self::PAYMENT_ID],
                'response' => self::SUCCESS_BODY,
                'type' => PaymentDetailsResponse::class,
                'list' => false,
            ],
            'ConfirmPayment' => [
                'invoke' => static fn(Vpos $vpos): ConfirmPaymentResponse => $vpos->payments()->confirm(self::PAYMENT_ID, $amount),
                'body' => ['PaymentID' => self::PAYMENT_ID, 'Amount' => '10.00'],
                'response' => self::SUCCESS_BODY,
                'type' => ConfirmPaymentResponse::class,
                'list' => false,
            ],
            'CancelPayment' => [
                'invoke' => static fn(Vpos $vpos): CancelPaymentResponse => $vpos->payments()->cancel(self::PAYMENT_ID),
                'body' => ['PaymentID' => self::PAYMENT_ID],
                'response' => self::SUCCESS_BODY,
                'type' => CancelPaymentResponse::class,
                'list' => false,
            ],
            'RefundPayment' => [
                'invoke' => static fn(Vpos $vpos): RefundPaymentResponse => $vpos->payments()->refund(self::PAYMENT_ID, $amount),
                'body' => ['PaymentID' => self::PAYMENT_ID, 'Amount' => '10.00'],
                'response' => self::SUCCESS_BODY,
                'type' => RefundPaymentResponse::class,
                'list' => false,
            ],
            'GetPaymentId' => [
                'invoke' => static fn(Vpos $vpos): GetPaymentIdResponse => $vpos->payments()->paymentIdForOrder(self::ORDER_ID),
                'body' => ['OrderID' => self::ORDER_ID],
                // `PaymentId` — lowercase d, this model alone (CONVENTIONS.md
                // §4.8).
                'response' => '{"PaymentId":"' . self::PAYMENT_ID . '","ResponseCode":"00","ResponseMessage":"OK"}',
                'type' => GetPaymentIdResponse::class,
                'list' => false,
            ],
            'MakeBindingPayment' => [
                'invoke' => static fn(Vpos $vpos): MakeBindingPaymentResponse => $vpos->bindings()->pay(
                    new MakeBindingPaymentRequest(
                        cardHolderId: self::CARD_HOLDER_ID,
                        amount: $amount,
                        orderId: self::ORDER_ID,
                        backUrl: self::BACK_URL,
                        paymentType: PaymentType::BindingMainRest,
                        description: 'Order 1234',
                        opaque: 'opaque-1',
                    ),
                ),
                'body' => [
                    'CardHolderID' => self::CARD_HOLDER_ID,
                    'Amount' => '10.00',
                    'OrderID' => self::ORDER_ID,
                    'BackURL' => self::BACK_URL,
                    'PaymentType' => 6,
                    'Description' => 'Order 1234',
                    'Currency' => '051',
                    'Opaque' => 'opaque-1',
                ],
                'response' => '{"PaymentID":"' . self::PAYMENT_ID . '","ResponseCode":"00","ResponseMessage":"OK"}',
                'type' => MakeBindingPaymentResponse::class,
                'list' => false,
            ],
            'GetBindings' => [
                'invoke' => static fn(Vpos $vpos): GetBindingsResponse => $vpos->bindings()->all(PaymentType::BindingMainRest),
                'body' => ['PaymentType' => 6],
                'response' => self::SUCCESS_BODY,
                'type' => GetBindingsResponse::class,
                'list' => false,
            ],
            'ActivateBinding' => [
                'invoke' => static fn(Vpos $vpos): ActivateBindingResponse => $vpos->bindings()->activate(
                    self::CARD_HOLDER_ID,
                    PaymentType::MainRest,
                ),
                'body' => ['CardHolderID' => self::CARD_HOLDER_ID, 'PaymentType' => 5],
                'response' => self::SUCCESS_BODY,
                'type' => ActivateBindingResponse::class,
                'list' => false,
            ],
            'DeactivateBinding' => [
                'invoke' => static fn(Vpos $vpos): DeactivateBindingResponse => $vpos->bindings()->deactivate(
                    self::CARD_HOLDER_ID,
                    PaymentType::BindingMainRest,
                ),
                'body' => ['CardHolderID' => self::CARD_HOLDER_ID, 'PaymentType' => 6],
                'response' => self::SUCCESS_BODY,
                'type' => DeactivateBindingResponse::class,
                'list' => false,
            ],
            'GetPendingTransactions' => [
                'invoke' => static fn(Vpos $vpos): array => $vpos->reports()->pending($from, $to),
                // ATOM, per the manifest's `date` type and its own sample; the
                // vendor PDF's `Y/m/d H:i` loses to it under CONVENTIONS.md
                // §2.
                'body' => [
                    'StartDate' => '2026-08-01T09:30:00+04:00',
                    'EndDate' => '2026-08-31T21:45:00+04:00',
                ],
                // The CardNumber is invented and is not a card number of any
                // kind. It is written already masked to first-6/last-4, the
                // form CONVENTIONS.md §6 requires of anything this package
                // handles. It is not the shape the gateway sends: probe case P3
                // returned a twelve-character mask, not a sixteen-character one.
                // ClientName names a cardholder because that is what the field
                // holds (P3), whatever its spelling suggests.
                'response' => '[{"OrderId":1234,"ClientName":"Test Cardholder","CardNumber":"411111******1111",'
                    . '"Amount":"10.00","PaymentDate":"2026-08-01T10:00:00+04:00","ErrorMessage":"Pending"}]',
                'type' => GetPendingTransactionsResponse::class,
                'list' => true,
            ],
        ];
    }

    /**
     * The wire keys Credentials injects, read from Credentials itself.
     *
     * The superset — merchantFields() is userFields() plus `ClientID` — so
     * subtracting it from a manifest field list leaves exactly the business
     * fields a caller supplies. Derived rather than written down: if a fourth
     * credential key were ever added, this follows it, whereas a literal
     * `['ClientID', 'Username', 'Password']` would silently start treating the
     * new key as a business field.
     *
     * @return list<string>
     */
    private function credentialFieldNames(): array
    {
        return array_keys((new Credentials(self::CLIENT_ID, self::USERNAME, self::PASSWORD))->merchantFields());
    }

    /**
     * The credential fields this operation must carry, and in the order
     * Credentials emits them.
     *
     * @return array<string, string>
     */
    private function credentialFieldsFor(string $operation): array
    {
        $user = ['Username' => self::USERNAME, 'Password' => self::PASSWORD];

        return in_array('ClientID', self::manifestEndpoints()[$operation], true)
            ? array_merge(['ClientID' => self::CLIENT_ID], $user)
            : $user;
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

    private function sentRequest(): PsrRequest
    {
        $requests = array_values($this->client->getRequests());

        self::assertArrayHasKey(0, $requests, 'No request was dispatched at all.');

        return $requests[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $decoded = json_decode((string) $this->sentRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        $body = [];

        foreach ($decoded as $key => $value) {
            self::assertIsString($key, 'The encoded body carried a non-string key.');

            $body[$key] = $value;
        }

        return $body;
    }

    /**
     * Operation name to request class, read off the filesystem and each class's
     * own OPERATION constant.
     *
     * The recursion is what keeps this list derived.
     *
     * This walk used to read a single level and *assert* that `src/Request/`
     * held no subdirectory — offering the reader a choice of "recurse here, or
     * keep src/ flat". This is the first branch taken. The assertion was not
     * decorative: a nested request class was placed at
     * `src/Request/<sub>/ProbeRequest.php` and it did fail, loudly. But two
     * other guards over the same directory — RequestContractTest and
     * ManifestConformanceTest — stayed green on that same fixture, so the
     * directory had two contradictory policies at once, and only one of them
     * was written down. Both of those now recurse, and so does this, which
     * leaves one policy for one directory.
     *
     * Recursing loses nothing the assertion bought. A nested class is still
     * required to exist, still required to implement the transport contract,
     * still required to declare a unique `OPERATION`, and still checked
     * against the manifest below — so an unclassified one goes red on its own
     * merits rather than on its location, which is the property that matters.
     * What the assertion did buy, and what recursing gives up, is a demand
     * that a human ratify the *layout*; that is CONVENTIONS.md §7's subject,
     * not this map's.
     *
     * @return array<string, class-string>
     */
    private function requestClassesByOperation(): array
    {
        $classes = [];

        foreach ($this->relativePhpFilesIn(self::REQUEST_DIRECTORY) as $relative) {
            $class = self::REQUEST_NAMESPACE . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                self::fail(sprintf('src/Request/%s declares no class named %s.', $relative, $class));
            }

            self::assertTrue(
                (new ReflectionClass($class))->implementsInterface(RequestInterface::class),
                sprintf('%s is in src/Request/ and does not implement the transport contract.', $class),
            );

            $operation = (new ReflectionClassConstant($class, 'OPERATION'))->getValue();

            self::assertIsString($operation, sprintf('%s::OPERATION is not a string.', $class));

            self::assertArrayNotHasKey($operation, $classes, sprintf('Two request classes claim the operation %s.', $operation));

            $classes[$operation] = $class;
        }

        self::assertNotSame([], $classes, 'No request class was found at all.');

        return $classes;
    }

    /**
     * Every .php file under $directory, recursively, as a path relative to it
     * and using `/` as the separator, sorted.
     *
     * Deliberately not shared with the other guards in this suite, on the
     * reasoning tests/Money's guard records: a guard that borrows another
     * guard's walker fails open the day that walker is refactored for the other
     * guard's convenience.
     *
     * @return list<string>
     */
    private function relativePhpFilesIn(string $directory): array
    {
        $entries = scandir($directory);

        self::assertIsArray($entries, sprintf('%s could not be read.', $directory));

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                foreach ($this->relativePhpFilesIn($path) as $nested) {
                    $files[] = $entry . '/' . $nested;
                }

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The manifest's endpoints as operation => request field names.
     *
     * @return array<string, list<string>>
     */
    private static function manifestEndpoints(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw, sprintf('Could not read the manifest at %s.', self::MANIFEST));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('endpoints', $decoded);
        self::assertIsArray($decoded['endpoints']);

        $endpoints = [];

        foreach ($decoded['endpoints'] as $endpoint) {
            self::assertIsArray($endpoint);
            self::assertArrayHasKey('operation', $endpoint);
            self::assertIsString($endpoint['operation']);
            self::assertArrayHasKey('request', $endpoint);
            self::assertIsArray($endpoint['request']);
            self::assertArrayHasKey('fields', $endpoint['request']);
            self::assertIsArray($endpoint['request']['fields']);

            $fields = [];

            foreach ($endpoint['request']['fields'] as $field) {
                self::assertIsArray($field);
                self::assertArrayHasKey('Name', $field);
                self::assertIsString($field['Name']);

                $fields[] = $field['Name'];
            }

            self::assertFalse(
                array_key_exists($endpoint['operation'], $endpoints),
                sprintf('The manifest describes %s twice.', $endpoint['operation']),
            );

            $endpoints[$endpoint['operation']] = $fields;
        }

        return $endpoints;
    }
}
