<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Request;

use DateTimeImmutable;
use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
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

use function in_array;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * What a request DTO puts on the wire, and what it refuses to put there.
 *
 * Every toArray() assertion below is against a whole array literal rather than
 * key by key. PHP's === on arrays requires the same keys, in the same order,
 * with values of the same type, so one assertion rejects a renamed key, an
 * added key, a dropped key, a reordered pair and a value whose type drifted
 * from string to int. Key by key would miss four of those five, and the added
 * key is the one that matters most: CONVENTIONS.md §4.12 records that the
 * gateway ignores unknown request fields silently, so a credential or a stray
 * field emitted here would never be reported by anything downstream.
 *
 * Field spellings are the manifest's and are checked against it by
 * ManifestConformanceTest. What is checked here is behaviour the manifest
 * cannot describe: which optional fields are omitted when null, which
 * operations may be retried (CONVENTIONS.md §4.5), and — in
 * RequestValidationTest — what each constructor refuses.
 *
 * Amounts are constructed rather than written as strings, because
 * `'Amount' => '10.00'` in a fixture would be green for a toArray() that
 * emitted a literal.
 *
 * The eleven request classes share a shape — operation(), isIdempotent(),
 * requiresClientId(), toArray() — and they share Contracts\RequestInterface,
 * which is the type HttpTransport::send() accepts. That is why the providers
 * below say RequestInterface where an earlier draft of this file needed an
 * eleven-way union alias: the interface exists now, because the transport
 * genuinely needs one argument rather than eleven, which is the extension
 * CONVENTIONS.md §5 asks an interface to justify. Whether each class
 * implements it is RequestContractTest's question, not this file's.
 */
#[CoversClass(ActivateBindingRequest::class)]
#[CoversClass(CancelPaymentRequest::class)]
#[CoversClass(ConfirmPaymentRequest::class)]
#[CoversClass(DeactivateBindingRequest::class)]
#[CoversClass(GetBindingsRequest::class)]
#[CoversClass(GetPaymentIdRequest::class)]
#[CoversClass(GetPendingTransactionsRequest::class)]
#[CoversClass(InitPaymentRequest::class)]
#[CoversClass(MakeBindingPaymentRequest::class)]
#[CoversClass(PaymentDetailsRequest::class)]
#[CoversClass(RefundPaymentRequest::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Currency::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(ValidationException::class)]
final class RequestWireFormatTest extends TestCase
{
    private const string PAYMENT_ID = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';

    private const string BACK_URL = 'https://merchant.example.test/callback';

    private const string CARD_HOLDER_ID = 'holder-id-fake';

    /**
     * InitPayment with only its required fields emits four keys and no nulls.
     *
     * Currency is among them without being optional-looking: it comes off the
     * Amount rather than from a parameter, so there is no way for a caller to
     * send an amount without saying what it is denominated in. CONVENTIONS.md
     * §4.12 records that the field is optional and the server defaults it —
     * this SDK sends it anyway, because a defaulted currency is a currency
     * nobody chose.
     */
    public function testInitPaymentEmitsOnlyTheRequiredFieldsWhenNoOptionalIsGiven(): void
    {
        $request = new InitPaymentRequest(
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 1001,
            backUrl: self::BACK_URL,
        );

        self::assertSame(
            [
                'Amount' => '10.00',
                'OrderID' => 1001,
                'BackURL' => self::BACK_URL,
                'Currency' => '051',
            ],
            $request->toArray(),
        );
    }

    /**
     * The omit-a-null rule, stated as the absence of five keys rather than the
     * presence of nulls.
     *
     * A null on the wire is not the same as an omitted field. The gateway
     * ignores unknown fields, but a declared field carrying null is a value it
     * will read, and nobody has observed what it does with one —
     * CONVENTIONS.md §13 is a list of exactly this kind of unknown. Omitting
     * is the conservative answer and the one this package takes.
     *
     * The second assertion catches the shape the first cannot: a toArray() that
     * emitted `'Timeout' => null` would fail assertArrayNotHasKey, but one that
     * emitted some other key with a null value would not, and the whole point
     * is that no key carries null.
     *
     * Mutation demonstrated: turning any `if ($this->description !== null)`
     * into `if ($this->description === null)` makes the corresponding
     * assertArrayNotHasKey fail with the key present and its value null.
     */
    public function testInitPaymentOmitsEveryNullOptionalRatherThanEmittingNull(): void
    {
        $fields = (new InitPaymentRequest(
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 1001,
            backUrl: self::BACK_URL,
        ))->toArray();

        self::assertArrayNotHasKey('Description', $fields);
        self::assertArrayNotHasKey('CardHolderID', $fields);
        self::assertArrayNotHasKey('Opaque', $fields);
        self::assertArrayNotHasKey('Timeout', $fields);
        self::assertArrayNotHasKey('PaymentServiceType', $fields);

        self::assertNotContains(null, $fields, 'No key may carry a null value onto the wire.');
    }

    /**
     * With every optional supplied, all nine keys appear, in the order the DTO
     * builds them.
     *
     * Order is asserted because assertSame on arrays asserts it, not because
     * the gateway cares — a JSON object is unordered. The value of pinning it
     * is that the diff on a reordering is small and readable, where a
     * key-by-key test would go green through a rewrite of the method.
     */
    public function testInitPaymentEmitsEveryOptionalItWasGiven(): void
    {
        $request = new InitPaymentRequest(
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 1001,
            backUrl: self::BACK_URL,
            description: 'Ամերիաբանկ վճարում',
            cardHolderId: self::CARD_HOLDER_ID,
            opaque: 'opaque-value',
            timeout: 1200,
            paymentServiceType: 3,
        );

        self::assertSame(
            [
                'Amount' => '10.00',
                'OrderID' => 1001,
                'BackURL' => self::BACK_URL,
                'Description' => 'Ամերիաբանկ վճարում',
                'Currency' => '051',
                'CardHolderID' => self::CARD_HOLDER_ID,
                'Opaque' => 'opaque-value',
                'Timeout' => 1200,
                'PaymentServiceType' => 3,
            ],
            $request->toArray(),
        );
    }

    /**
     * Each optional is emitted on its own, so that one omission cannot hide
     * behind another.
     *
     * The all-present test above and the none-present test before it are both
     * green for a DTO whose five conditionals were collapsed into one — set any
     * optional and get all five, set none and get none. Five single-optional
     * rows are what separate them.
     *
     * The five optionals are carried as five explicit nullable columns rather
     * than an array of named arguments, because unpacking one would be typed
     * `mixed` and would let a row whose value had the wrong type reach the
     * constructor unchallenged.
     *
     * @return array<string, array{?string, ?string, ?string, ?int, ?int, string, int|string}>
     */
    public static function singleOptionals(): array
    {
        return [
            'Description alone' => ['just a description', null, null, null, null, 'Description', 'just a description'],
            'CardHolderID alone' => [
                null,
                self::CARD_HOLDER_ID,
                null,
                null,
                null,
                'CardHolderID',
                self::CARD_HOLDER_ID,
            ],
            'Opaque alone' => [null, null, 'just an opaque', null, null, 'Opaque', 'just an opaque'],
            'Timeout alone' => [null, null, null, 900, null, 'Timeout', 900],
            'PaymentServiceType alone' => [null, null, null, null, 7, 'PaymentServiceType', 7],
        ];
    }

    #[DataProvider('singleOptionals')]
    public function testEachInitPaymentOptionalIsEmittedIndependently(
        ?string $description,
        ?string $cardHolderId,
        ?string $opaque,
        ?int $timeout,
        ?int $paymentServiceType,
        string $wireKey,
        int|string $expected,
    ): void {
        $fields = (new InitPaymentRequest(
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 1001,
            backUrl: self::BACK_URL,
            description: $description,
            cardHolderId: $cardHolderId,
            opaque: $opaque,
            timeout: $timeout,
            paymentServiceType: $paymentServiceType,
        ))->toArray();

        self::assertArrayHasKey($wireKey, $fields);
        self::assertSame($expected, $fields[$wireKey]);
        self::assertCount(5, $fields, 'Exactly one optional was given, so exactly one is added to the four required.');
    }

    /**
     * MakeBindingPayment likewise omits what it was not given, and emits what
     * it was.
     *
     * Its Currency is in the same position — after Description, before Opaque —
     * as InitPayment's, and both come off the Amount. There is no parameter for
     * it on either request, which is what makes a currency mismatch between the
     * amount and the field unrepresentable rather than merely discouraged.
     */
    public function testMakeBindingPaymentEmitsItsRequiredFieldsAndOmitsItsNullOptionals(): void
    {
        $minimal = new MakeBindingPaymentRequest(
            cardHolderId: self::CARD_HOLDER_ID,
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 2002,
            backUrl: self::BACK_URL,
            paymentType: PaymentType::BindingMainRest,
        );

        self::assertSame(
            [
                'CardHolderID' => self::CARD_HOLDER_ID,
                'Amount' => '10.00',
                'OrderID' => 2002,
                'BackURL' => self::BACK_URL,
                'PaymentType' => 6,
                'Currency' => '051',
            ],
            $minimal->toArray(),
        );
        self::assertNotContains(null, $minimal->toArray());

        $full = new MakeBindingPaymentRequest(
            cardHolderId: self::CARD_HOLDER_ID,
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 2002,
            backUrl: self::BACK_URL,
            paymentType: PaymentType::BindingMainRest,
            description: 'binding description',
            opaque: 'opaque-value',
        );

        self::assertSame(
            [
                'CardHolderID' => self::CARD_HOLDER_ID,
                'Amount' => '10.00',
                'OrderID' => 2002,
                'BackURL' => self::BACK_URL,
                'PaymentType' => 6,
                'Description' => 'binding description',
                'Currency' => '051',
                'Opaque' => 'opaque-value',
            ],
            $full->toArray(),
        );
    }

    /**
     * MakeBindingPayment emits each of its two optionals independently.
     */
    public function testEachMakeBindingPaymentOptionalIsEmittedIndependently(): void
    {
        $withDescription = (new MakeBindingPaymentRequest(
            cardHolderId: self::CARD_HOLDER_ID,
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 2002,
            backUrl: self::BACK_URL,
            paymentType: PaymentType::MainRest,
            description: 'just a description',
        ))->toArray();

        self::assertSame('just a description', $withDescription['Description']);
        self::assertArrayNotHasKey('Opaque', $withDescription);

        $withOpaque = (new MakeBindingPaymentRequest(
            cardHolderId: self::CARD_HOLDER_ID,
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 2002,
            backUrl: self::BACK_URL,
            paymentType: PaymentType::MainRest,
            opaque: 'just an opaque',
        ))->toArray();

        self::assertSame('just an opaque', $withOpaque['Opaque']);
        self::assertArrayNotHasKey('Description', $withOpaque);
    }

    /**
     * An amount is serialised as a decimal string, never as a number.
     *
     * CONVENTIONS.md §4.7 is unambiguous: a PHP float must never reach the
     * wire. assertSame is what enforces it here — `'10.00'` and `10.0` are
     * equal under assertEquals and are two entirely different things to a .NET
     * deserialiser and to a reviewer reading a log.
     *
     * The three rows are the three shapes a decimal string can take at exponent
     * 2: a whole amount, a fractional one, and one below the unit, where the
     * integer part is "0" rather than empty.
     *
     * @return array<string, array{Amount, string}>
     */
    public static function amountSerialisations(): array
    {
        return [
            'a whole amount' => [Amount::fromMinorUnits(1000, Currency::AMD), '10.00'],
            'a fractional amount' => [Amount::fromMinorUnits(1055, Currency::USD), '10.55'],
            'below one unit' => [Amount::fromMinorUnits(5, Currency::EUR), '0.05'],
        ];
    }

    #[DataProvider('amountSerialisations')]
    public function testAnAmountReachesTheWireAsADecimalString(Amount $amount, string $expected): void
    {
        $confirm = new ConfirmPaymentRequest(paymentId: self::PAYMENT_ID, amount: $amount);
        $refund = new RefundPaymentRequest(paymentId: self::PAYMENT_ID, amount: $amount);

        self::assertSame($expected, $confirm->toArray()['Amount']);
        self::assertSame($expected, $refund->toArray()['Amount']);
    }

    /**
     * The single-field and two-field requests, each emitted whole.
     *
     * `PaymentID` in the uppercase spelling on all four payment operations,
     * `OrderID` likewise on GetPaymentId — which is the one to read twice,
     * because its *response* is the model that breaks casing to `PaymentId`
     * (CONVENTIONS.md §4.8) and the request does not.
     */
    public function testTheNarrowRequestsEmitExactlyTheirOwnFields(): void
    {
        $amount = Amount::fromDecimalString('10.00', Currency::AMD);

        self::assertSame(
            ['PaymentID' => self::PAYMENT_ID],
            (new PaymentDetailsRequest(self::PAYMENT_ID))->toArray(),
        );
        self::assertSame(
            ['PaymentID' => self::PAYMENT_ID],
            (new CancelPaymentRequest(self::PAYMENT_ID))->toArray(),
        );
        self::assertSame(
            ['PaymentID' => self::PAYMENT_ID, 'Amount' => '10.00'],
            (new ConfirmPaymentRequest(self::PAYMENT_ID, $amount))->toArray(),
        );
        self::assertSame(
            ['PaymentID' => self::PAYMENT_ID, 'Amount' => '10.00'],
            (new RefundPaymentRequest(self::PAYMENT_ID, $amount))->toArray(),
        );
        self::assertSame(
            ['OrderID' => 3003],
            (new GetPaymentIdRequest(3003))->toArray(),
        );
        self::assertSame(
            ['PaymentType' => 5],
            (new GetBindingsRequest(PaymentType::MainRest))->toArray(),
        );
        self::assertSame(
            ['CardHolderID' => self::CARD_HOLDER_ID, 'PaymentType' => 6],
            (new ActivateBindingRequest(self::CARD_HOLDER_ID, PaymentType::BindingMainRest))->toArray(),
        );
        self::assertSame(
            ['CardHolderID' => self::CARD_HOLDER_ID, 'PaymentType' => 6],
            (new DeactivateBindingRequest(self::CARD_HOLDER_ID, PaymentType::BindingMainRest))->toArray(),
        );
    }

    /**
     * An enum reaches the wire as its backing value, not as its member name.
     *
     * The response side never maps a member name back to a member — the names
     * are the bank's spelling of its own C# enum and nothing has been promised
     * about them — and the request side is the mirror of that decision: this
     * SDK sends the number the manifest declares. Asserting `5` rather than
     * `'MainRest'` is the whole test, and assertSame rather than assertEquals is
     * what stops `'5'` from passing.
     */
    public function testAPaymentTypeReachesTheWireAsItsBackingIntegerAndNotItsName(): void
    {
        $fields = (new GetBindingsRequest(PaymentType::BindingMainRest))->toArray();

        self::assertSame(6, $fields['PaymentType']);
    }

    /**
     * The two dates of GetPendingTransactions are rendered as ATOM.
     *
     * The manifest types both fields `date` rather than `string`, and
     * CONVENTIONS.md §4.12 says in as many words to verify the wire format
     * before fixing a DTO format — nobody has. ATOM is chosen because it is
     * unambiguous about the offset, which is the part a date-only format would
     * silently drop, and the assertion here is what makes the choice a stated
     * one rather than an accident of whatever DateTimeImmutable::format() was
     * reached for.
     *
     * @todo unverified — see CONVENTIONS.md §13 (the wire format of these two fields has never been observed)
     */
    public function testThePendingTransactionWindowIsRenderedAsAtom(): void
    {
        $request = new GetPendingTransactionsRequest(
            startDate: new DateTimeImmutable('2026-01-01T00:00:00+04:00'),
            endDate: new DateTimeImmutable('2026-01-31T23:59:59+04:00'),
        );

        self::assertSame(
            [
                'StartDate' => '2026-01-01T00:00:00+04:00',
                'EndDate' => '2026-01-31T23:59:59+04:00',
            ],
            $request->toArray(),
        );
    }

    /**
     * The operation each request names, and whether it may be retried.
     *
     * The retry column is CONVENTIONS.md §4.5 transcribed, and it is not a
     * preference: a false here is a promise that the transport will not send
     * this request twice, and the four operations carrying one are the four
     * that move money — confirm captures funds, refund moves them, cancel
     * changes state, and a binding payment charges a card. ActivateBinding and
     * DeactivateBinding are not in §4.5's table and are false on the same
     * reasoning: both change state on the bank's side, and neither is a read.
     *
     * InitPayment is the one true that carries a condition. §4.4 makes it
     * idempotent on (ClientID, OrderID), but a repeat call overwrites the
     * earlier parameters — so the retry is safe only with a byte-identical
     * body, which is the transport's obligation, not this DTO's.
     *
     * @return array<string, array{RequestInterface, string, bool}>
     */
    public static function operations(): array
    {
        $amount = Amount::fromDecimalString('10.00', Currency::AMD);

        return [
            'InitPayment' => [
                new InitPaymentRequest($amount, 1001, self::BACK_URL),
                'InitPayment',
                true,
            ],
            'GetPaymentDetails' => [new PaymentDetailsRequest(self::PAYMENT_ID), 'GetPaymentDetails', true],
            'GetPaymentId' => [new GetPaymentIdRequest(1001), 'GetPaymentId', true],
            'GetBindings' => [new GetBindingsRequest(PaymentType::MainRest), 'GetBindings', true],
            'GetPendingTransactions' => [
                new GetPendingTransactionsRequest(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31')),
                'GetPendingTransactions',
                true,
            ],
            'ConfirmPayment' => [new ConfirmPaymentRequest(self::PAYMENT_ID, $amount), 'ConfirmPayment', false],
            'RefundPayment' => [new RefundPaymentRequest(self::PAYMENT_ID, $amount), 'RefundPayment', false],
            'CancelPayment' => [new CancelPaymentRequest(self::PAYMENT_ID), 'CancelPayment', false],
            'MakeBindingPayment' => [
                new MakeBindingPaymentRequest(
                    self::CARD_HOLDER_ID,
                    $amount,
                    2002,
                    self::BACK_URL,
                    PaymentType::BindingMainRest,
                ),
                'MakeBindingPayment',
                false,
            ],
            'ActivateBinding' => [
                new ActivateBindingRequest(self::CARD_HOLDER_ID, PaymentType::BindingMainRest),
                'ActivateBinding',
                false,
            ],
            'DeactivateBinding' => [
                new DeactivateBindingRequest(self::CARD_HOLDER_ID, PaymentType::BindingMainRest),
                'DeactivateBinding',
                false,
            ],
        ];
    }

    /**
     * Mutation demonstrated: flipping any isIdempotent() return value fails the
     * row for that operation, and the four money-moving operations are the four
     * where flipping it would let a transport double-charge.
     */
    #[DataProvider('operations')]
    public function testEachRequestNamesItsOperationAndDeclaresWhetherItMayBeRetried(
        object $request,
        string $operation,
        bool $isIdempotent,
    ): void {
        self::assertTrue(method_exists($request, 'operation'), 'Every request names the operation it is for.');
        self::assertTrue(method_exists($request, 'isIdempotent'), 'Every request states whether it may be retried.');

        self::assertSame($operation, $request->operation());
        self::assertSame(
            $isIdempotent,
            $request->isIdempotent(),
            'CONVENTIONS.md §4.5 decides this and it is not user configurable. A true here is a promise '
            . 'that sending this request twice cannot move money twice.',
        );
    }

    /**
     * The endpoint names are singular, and the plural forms the vendor PDF's
     * table of contents lists return 404 (CONVENTIONS.md §4.9).
     *
     * Asserted as a property of the whole set rather than as two more string
     * literals, because the mistake is a plural creeping in, and the guard
     * should catch the third one as well as the two that exist.
     */
    public function testNoOperationNameIsPluralised(): void
    {
        foreach (self::operations() as $label => [$request, $operation]) {
            self::assertFalse(
                in_array($operation, ['ActivateBindings', 'DeactivateBindings'], true),
                $label . ' names a plural endpoint, which returns 404.',
            );
            self::assertSame($operation, $request->operation());
        }
    }
}
