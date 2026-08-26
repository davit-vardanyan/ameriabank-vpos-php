<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Support;

use function array_map;
use function array_replace;
use function count;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\OrderStatus;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentState;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Response\ActivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\BankInfo;
use DavitVardanyan\AmeriabankVpos\Response\CancelPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\CardBindingFiled;
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

use function is_string;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * The hydrator is the only place a wire key is spelled and the only place a
 * wire value is interpreted, so it is where the branching decisions about a
 * response are either enforced or merely described.
 *
 * Mapping is not tested here. ManifestConformanceTest already proves every key
 * this file could assert against the manifest, and it derives its expectations
 * from a file rather than a literal, so it cannot be made vacuous by copying a
 * typo into the test. What that test cannot see is a branch: whether
 * ctype_digit() runs, whether sprintf() is applied to the path it must not be
 * applied to, whether a missing currency is quietly replaced by a default.
 * Those are here, and every assertion below was written against a named
 * mutation of src/Support/ResponseHydrator.php, the mutation applied, the
 * failure observed, and only then was the assertion kept.
 *
 * Two habits are avoided throughout:
 *
 * - Asserting that a value "is not the enum". That is green for a hydrator
 *   which returns null for everything, so every null-enum assertion below is
 *   paired with an assertion that the raw value survived. The guard's whole
 *   claim is that the guard is lossless; a test that only checked the loss
 *   would pass on a hydrator that had lost the value too.
 * - Comparing an Amount with assertEquals. Two Amounts differing only in scale
 *   — 1000 minor units versus 100000 — are not equal under assertSame on the
 *   count, which is the assertion that catches a decimal point moving. Every
 *   monetary assertion below pins minorUnitCount() and currency() separately.
 *
 * Every value in a fixture here is invented. The card-shaped strings are
 * deliberately unusable: a PAN of all zeros fails Luhn, and no probe report or
 * .env was read to write this file.
 */
#[CoversClass(ResponseHydrator::class)]
#[UsesClass(ActivateBindingResponse::class)]
#[UsesClass(Amount::class)]
#[UsesClass(BankInfo::class)]
#[UsesClass(CancelPaymentResponse::class)]
#[UsesClass(CardBindingFiled::class)]
#[UsesClass(ConfirmPaymentResponse::class)]
#[UsesClass(Currency::class)]
#[UsesClass(DeactivateBindingResponse::class)]
#[UsesClass(GetBindingsResponse::class)]
#[UsesClass(GetPaymentIdResponse::class)]
#[UsesClass(GetPendingTransactionsResponse::class)]
#[UsesClass(InitPaymentResponse::class)]
#[UsesClass(MakeBindingPaymentResponse::class)]
#[UsesClass(OrderStatus::class)]
#[UsesClass(PaymentDetailsResponse::class)]
#[UsesClass(PaymentState::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(RefundPaymentResponse::class)]
#[UsesClass(ResponseCode::class)]
#[UsesClass(SerializationException::class)]
#[UsesClass(ValidationException::class)]
final class ResponseHydratorTest extends TestCase
{
    /**
     * An uppercase 36-character GUID, invented. CONVENTIONS.md §4.12 records
     * the shape; this is not a PaymentID any sandbox ever issued.
     */
    private const string PAYMENT_ID = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';

    /**
     * A masked PAN in the gateway's own shape, made of digits that cannot be a
     * card: all zeros fails the Luhn check, so this string is inert even in the
     * unlikely event it escapes the test suite.
     */
    private const string MASKED_PAN = '000000******0000';

    /**
     * The one field whose absence is fatal, and the string form CONVENTIONS.md
     * §4.3 gives for every endpoint other than InitPayment.
     */
    private const string SUCCESS_CODE = '00';

    // -------------------------------------------------- order status ---

    /**
     * The order-status guard, one row per wire form.
     *
     * The rows split into two groups that must not be collapsed. `"2"` and
     * `"0"` are the numeric form the vendor PDF's Table 2 implies, and they
     * resolve. `"payment_deposited"` is the form the C# member name would
     * produce, and it must not: OrderStatus is int-backed, `(int)
     * "payment_deposited"` is 0, and 0 is Registered — so a blind cast reports
     * a completed payment as one that was never paid. That is the single most
     * expensive way this hydrator could be wrong, and it is silent.
     *
     * Which form the wire carries is now observed, and the answer dissolved the
     * question rather than answering it: probe case P3 carries **both**, under
     * two different keys — `"OrderStatus":"2"` beside
     * `"PaymentState":"payment_deposited"` — so the two forms were never rivals
     * for this field. Both stay as rows anyway. The numeric row is what the
     * gateway sends here, and the named row pins the guard that keeps a value
     * spelled in words from being cast to Registered should that ever change.
     *
     * `"99"` is the third group of one: entirely numeric, so the guard admits
     * it, and not a member, so tryFrom() declines it. It separates "the guard
     * rejected this" from "the enum did not know this" — two different reasons
     * for a null that a single non-numeric row could not tell apart.
     *
     * @return array<string, array{string, ?OrderStatus}>
     */
    public static function orderStatusForms(): array
    {
        return [
            '"2" — Deposited, the numeric form of the vendor table' => ['2', OrderStatus::Deposited],
            '"0" — Registered, and the value a blind cast invents' => ['0', OrderStatus::Registered],
            '"6" — Declined, the highest member' => ['6', OrderStatus::Declined],
            '"payment_deposited" — the member-name form, never castable' => ['payment_deposited', null],
            '"99" — numeric but not a member' => ['99', null],
            '"" — the empty string GetPaymentDetails has actually returned' => ['', null],
            '" 2" — a leading space is not an ASCII digit' => [' 2', null],
            '"2.0" — a decimal point is not an ASCII digit' => ['2.0', null],
            '"-1" — a sign is not an ASCII digit' => ['-1', null],
        ];
    }

    /**
     * The enum resolves only for an entirely numeric raw value, and the raw
     * value survives either way.
     *
     * The second assertion is the one that makes the first mean anything.
     * Without it this test is green for a hydrator that returned null for the
     * enum and dropped the string — which would satisfy "does not miscast" and
     * violate the guard's other half, that it is lossless. The caller of a
     * gateway that has started sending `payment_deposited` still needs to be
     * able to read it.
     *
     * Mutation demonstrated: dropping `!ctype_digit($raw)` from
     * resolveOrderStatus() leaves `(int) "payment_deposited"` = 0 and the row
     * for the member-name form reports OrderStatus::Registered.
     */
    #[DataProvider('orderStatusForms')]
    public function testOrderStatusResolvesOnlyForAnEntirelyNumericRawValue(string $raw, ?OrderStatus $expected): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith(['OrderStatus' => $raw]));

        self::assertSame(
            $expected,
            $response->orderStatus,
            'OrderStatus is declared string on the wire and int-backed in this SDK. The enum may be '
            . 'attempted only when the raw value is entirely ASCII digits.',
        );
        self::assertSame(
            $raw,
            $response->orderStatusRaw,
            'The raw value is kept whatever the enum did with it. A null enum must cost the caller '
            . 'the convenience and nothing else.',
        );
    }

    /**
     * An absent OrderStatus is null on both properties, and is not the empty
     * string.
     *
     * Kept apart from the provider above because absence and emptiness are
     * different facts about the gateway — one did not mention the field, the
     * other said it had no value — and nullability exists so a caller can tell
     * them apart.
     */
    public function testAnAbsentOrderStatusIsNullOnBothProperties(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([]));

        self::assertNull($response->orderStatus);
        self::assertNull($response->orderStatusRaw);
    }

    // ----------------------------------------------- optional fields ---

    /**
     * The three forms `PaymentType` arrives in, and what each resolves to.
     *
     * JSON renders the C# enum as its integer; XML renders it as the member
     * name. Both are reachable — CONVENTIONS.md §4.12 confirms `Accept:
     * application/xml` is honoured — and the SDK carries whichever arrived.
     *
     * The numeric-string row is the one to read carefully. `"5"` resolves, on
     * the same ctype_digit guard OrderStatus uses, because it is an exact match
     * on a declared value rather than an interpretation of a name. `"None"`
     * does not, even though it names a real member, because the member names
     * are the bank's spelling of its own C# enum and nothing has been promised
     * about their stability. That asymmetry is a decision (S3-D3), it is
     * flagged for review, and this row is where it is visible: flipping it
     * means changing the expectation on exactly one line.
     *
     * @return array<string, array{int|string, ?PaymentType}>
     */
    public static function paymentTypeForms(): array
    {
        return [
            'int 5 — the JSON form' => [5, PaymentType::MainRest],
            'int 6 — the other binding-capable member' => [6, PaymentType::BindingMainRest],
            'int 0 — None, a real member and not a null signal' => [0, PaymentType::None],
            'int 8 — a gap the bank has not filled yet' => [8, null],
            'string "5" — resolved, per S3-D3' => ['5', PaymentType::MainRest],
            'string "None" — the XML member-name form, never mapped back' => ['None', null],
            'string "MainRest" — likewise a name, not a value' => ['MainRest', null],
            'string "" — nothing to resolve' => ['', null],
        ];
    }

    /**
     * PaymentType resolves from an integer and from a numeric string, never
     * from a member name, and the raw value survives in every case with its
     * original type.
     *
     * assertSame on the raw is doing real work here: the property is typed
     * int|string|null, and a hydrator that stringified the integer would still
     * be "carrying the raw value" under assertEquals while having thrown away
     * the distinction CONVENTIONS.md §4.3 spends a table on.
     */
    #[DataProvider('paymentTypeForms')]
    public function testPaymentTypeResolvesFromAValueAndNeverFromAName(int|string $raw, ?PaymentType $expected): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith(['PaymentType' => $raw]));

        self::assertSame($expected, $response->paymentType);
        self::assertSame($raw, $response->paymentTypeRaw, 'The raw value keeps both its content and its type.');
    }

    /**
     * PaymentState is string-backed and the field is declared string, so no
     * guard is needed and none is applied — but an unknown state is still null
     * rather than a throw.
     */
    public function testPaymentStateResolvesDirectlyAndIsNullForAnUnknownState(): void
    {
        $known = ResponseHydrator::paymentDetailsResponse(
            $this->paymentDetailsWith(['PaymentState' => 'payment_deposited']),
        );

        self::assertSame(PaymentState::Deposited, $known->paymentState);
        self::assertSame('payment_deposited', $known->paymentStateRaw);

        $unknown = ResponseHydrator::paymentDetailsResponse(
            $this->paymentDetailsWith(['PaymentState' => 'payment_teleported']),
        );

        self::assertNull($unknown->paymentState);
        self::assertSame('payment_teleported', $unknown->paymentStateRaw);
    }

    // ------------------------------------------------ absence rules ---

    /**
     * A field the bank adds tomorrow is ignored, and does not reach any
     * property.
     *
     * The first assertion is the contract: hydration does not throw. The rest
     * are what stop it from being vacuous — a hydrator that threw away the
     * whole payload on an unknown key would also not throw, so the known fields
     * are checked to have survived alongside it, and the unknown value is
     * checked not to have landed anywhere by rendering every property.
     */
    public function testAnUnknownWireKeyIsIgnoredAndReachesNoProperty(): void
    {
        $canary = 'unknown-field-canary-9e1f';

        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'OrderID' => '1234',
            'FieldTheBankAddedOnATuesday' => $canary,
        ]));

        self::assertSame('1234', $response->orderId, 'The known fields still hydrate.');
        self::assertSame(self::SUCCESS_CODE, $response->responseCode->raw());

        foreach ((array) $response as $property => $value) {
            if (!is_string($value)) {
                continue;
            }

            self::assertFalse(
                str_contains($value, $canary),
                'An unknown wire value must not land on any property. It reached ' . $property . '.',
            );
        }
    }

    /**
     * Absence is null on every optional field, not an exception and not an
     * invented value.
     *
     * Every response field is nullable because the manifest declares none of
     * them required, so no field's presence is guaranteed — not even the ones
     * probe case P3's completed payment happened to carry. The payload here is
     * the smallest one that can exist: the single required code, and nothing
     * else at all.
     *
     * Every nullable property is enumerated rather than a handful being spot
     * checked, so that a field added to the DTO later is covered by this test
     * on the day it is added.
     */
    public function testEveryOptionalFieldIsNullWhenTheWireOmitsIt(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse(['ResponseCode' => self::SUCCESS_CODE]);

        $populated = [];

        foreach ((array) $response as $property => $value) {
            if ($property !== 'responseCode' && $value !== null) {
                $populated[] = $property;
            }
        }

        self::assertSame(
            [],
            $populated,
            'An absent key yields null. A hydrator that invented a default would name it here.',
        );
        self::assertSame(self::SUCCESS_CODE, $response->responseCode->raw());
    }

    /**
     * An explicit null on the wire is read the same way an absent key is.
     *
     * .NET's serialiser writes a null for an unset reference type rather than
     * omitting the property, so both shapes are reachable from the same gateway
     * and neither may throw.
     */
    public function testAnExplicitNullIsReadAsAnAbsentKey(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'OrderID' => null,
            'Amount' => null,
            'BankInfo' => null,
            'PaymentType' => null,
        ]));

        self::assertNull($response->orderId);
        self::assertNull($response->amountRaw);
        self::assertNull($response->amount);
        self::assertNull($response->bankInfo);
        self::assertNull($response->paymentTypeRaw);
        self::assertNull($response->paymentType);
    }

    // ----------------------------------------------- monetary fields ---

    /**
     * The three scalar types a monetary field arrives as, and the minor unit
     * count each must produce.
     *
     * The int and string rows are chosen so that the no-rounding rule is
     * falsifiable rather than merely stated. Both were executed on PHP 8.3
     * before being written down:
     *
     * - int 12345678901234567 renders exactly as itself, but through
     *   sprintf('%.2F', …) it becomes "12345678901234568.00" — the float
     *   conversion loses the low digit — so the two paths differ by 100 minor
     *   units. A row of, say, int 10 could not tell the paths apart at all:
     *   "10" and "10.00" are the same amount.
     * - string "10.5" is exact both ways and is here only as the ordinary case;
     *   the string path's discriminator is the over-precise value, tested
     *   separately below, where sprintf would round "10.005" into an amount and
     *   the exact path correctly refuses to invent one.
     *
     * The float rows are the documented lossy step. 10.55 is what JSON decoding
     * yields for the decimal literal the gateway sent, and %.2F rounds it back
     * to the currency's ISO 4217 scale.
     *
     * @return array<string, array{float|int|string, int}>
     */
    public static function monetaryScalars(): array
    {
        return [
            'string "10.00" — the form the gateway declares' => ['10.00', 1000],
            'string "10.5" — one place, still exact' => ['10.5', 1050],
            'string "10" — no fraction at all' => ['10', 1000],
            'int 10 — the .NET integer form' => [10, 1000],
            'int 12345678901234567 — exact only without sprintf' => [12345678901234567, 1234567890123456700],
            'float 10.55 — what json_decode yields, rounded back by %.2F' => [10.55, 1055],
            'float 4.0 — a zero fraction is still two places on the wire' => [4.0, 400],
            'float 0.01 — the smallest representable amount' => [0.01, 1],
        ];
    }

    /**
     * An amount hydrates to the right minor unit count from each of the three
     * scalar types, and never through a path that would round it.
     *
     * The currency is asserted alongside the count because a count without a
     * currency is not an amount — 1000 is ten drams or ten dollars, and the
     * response's own Currency is the rule that decides which.
     *
     * Mutation demonstrated: routing the int branch of buildAmount() through
     * sprintf('%.' . $currency->exponent() . 'F', $value) — the treatment
     * reserved for floats — makes the 12345678901234567 row report
     * 1234567890123456800.
     */
    #[DataProvider('monetaryScalars')]
    public function testAnAmountHydratesExactlyFromEachScalarType(float|int|string $raw, int $minorUnitCount): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Currency' => '051',
            'Amount' => $raw,
        ]));

        self::assertInstanceOf(Amount::class, $response->amount);
        self::assertSame($minorUnitCount, $response->amount->minorUnitCount());
        self::assertSame(Currency::AMD, $response->amount->currency());
    }

    /**
     * The float path renders through var_export(), not a string cast, and the
     * difference is visible on the wire.
     *
     * `(string) 4.0` is "4"; `var_export(4.0, true)` is "4.0". The raw
     * property exists to record what the gateway sent, and a gateway that sent
     * a zero fraction sent a zero fraction — CONVENTIONS.md §4.7 has this SDK
     * setting JSON_PRESERVE_ZERO_FRACTION on the way out for the same reason.
     *
     * The second row is the one that matters for money rather than for
     * fidelity: a string cast renders at the `precision` ini setting, which is
     * 14 significant digits by default, so a value with more of them silently
     * loses its tail. var_export() renders the shortest string that reads back
     * as the same float.
     *
     * @return array<string, array{float, string}>
     */
    public static function floatRenderings(): array
    {
        return [
            'a zero fraction survives' => [4.0, '4.0'],
            'an ordinary two-place decimal' => [10.55, '10.55'],
            'seventeen significant digits, beyond precision=14' => [1.2345678901234567, '1.2345678901234567'],
        ];
    }

    /**
     * Mutation demonstrated: replacing var_export($value, true) with
     * (string) $value in renderDecimal() renders 4.0 as "4" and the
     * seventeen-digit float as "1.2345678901".
     */
    #[DataProvider('floatRenderings')]
    public function testADecimalFloatIsRenderedByItsShortestRoundTrip(float $raw, string $expected): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith(['Amount' => $raw]));

        self::assertSame(
            $expected,
            $response->amountRaw,
            'The raw property records what arrived. Rendering it at the precision ini setting '
            . 'discards digits the gateway sent.',
        );
    }

    /**
     * The Amount is derived from the scalar, never from the rendered raw — and
     * this is the only value in the file that can tell those two apart.
     *
     * renderDecimal() and buildAmount() are independent derivations of one
     * validated scalar. Routing the first's output into the second is a
     * refactor rather than a mutation, so Infection cannot reach the
     * difference: this assertion is the only guard the arrangement has, which
     * is why it is not folded into monetaryScalars().
     *
     * Both paths were run on PHP 8.3 before this was written down, with
     * Currency "051" (AMD, exponent 2):
     *
     * - from the scalar, as the hydrator does it: var_export() renders
     *   "1.2345678901234567" for the raw property, and sprintf('%.2F', …)
     *   rounds the same float to "1.23" — 123 minor units.
     * - from the rendered text: Amount::fromDecimalString('1.2345678901234567',
     *   AMD) throws, because the decimal-place limit is positional and
     *   seventeen places exceed an exponent of 2. buildAmount() catches that
     *   and returns null, so a roundable amount becomes no amount at all.
     *
     * Every other row here is blind to it. "10.55", 4.0, 0.01, 10,
     * 12345678901234567, "10.005" and PHP_INT_MIN all yield the same Amount
     * whichever way they are routed.
     */
    public function testTheAmountIsDerivedFromTheScalarAndNotFromTheRenderedRaw(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Currency' => '051',
            'Amount' => 1.2345678901234567,
        ]));

        self::assertSame('1.2345678901234567', $response->amountRaw);
        self::assertInstanceOf(
            Amount::class,
            $response->amount,
            'Feeding renderDecimal()\'s output into buildAmount() would make this null: Amount '
            . 'rejects a seventeen-place string against an exponent of 2.',
        );
        self::assertSame(123, $response->amount->minorUnitCount());
        self::assertSame(Currency::AMD, $response->amount->currency());
    }

    /**
     * An int and a string reach the raw property unchanged, which is the other
     * half of renderDecimal().
     *
     * The third row is the one that keeps the int arm honest. var_export()
     * renders every other integer exactly as a string cast does, so dropping
     * the arm and letting an int fall through to the float rendering looks
     * harmless — until PHP_INT_MIN, which var_export() writes as the
     * expression `-9223372036854775807-1` because the positive literal does not
     * fit. That is not a decimal string, and the raw property is supposed to be
     * one. Mutation demonstrated: removing `return (string) $value;` from
     * renderDecimal()'s int arm makes this row report
     * "-9223372036854775807-1".
     */
    public function testAnIntegerAndAStringReachTheRawPropertyUnchanged(): void
    {
        $fromInt = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith(['Amount' => 10]));
        $fromString = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith(['Amount' => '10.000']));
        $fromSmallestInt = ResponseHydrator::paymentDetailsResponse(
            $this->paymentDetailsWith(['Amount' => PHP_INT_MIN]),
        );

        self::assertSame('10', $fromInt->amountRaw);
        self::assertSame('10.000', $fromString->amountRaw, 'A string is not reformatted, even when over-precise.');
        self::assertSame(
            '-9223372036854775808',
            $fromSmallestInt->amountRaw,
            'An integer is rendered as a decimal string, never as the expression var_export() writes '
            . 'for the smallest one.',
        );
    }

    /**
     * With no currency there is no Amount — and no default is stamped on.
     *
     * Both rows produce a null currency for different reasons: one key is
     * missing, the other holds a code this SDK does not know. `""` is the third
     * row and is not hypothetical — GetPaymentDetails returned exactly that on
     * probe B2. It is not the only shape that endpoint sends, either: probe case
     * P3's completed payment came back with `"051"`, so the blank row pins a
     * real failure mode rather than the normal one.
     *
     * Currency::default() would make all three of them AMD. It is an SDK
     * assumption, not observed behaviour, and stamping it on a transaction the
     * gateway declined to label would produce an amount that is wrong and looks
     * right.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function currencylessPayloads(): array
    {
        return [
            'the Currency key is absent' => [[]],
            'the Currency key is an unknown numeric code' => [['Currency' => '999']],
            'the Currency key is the empty string probe B2 observed' => [['Currency' => '']],
            // A success-coded body carries a populated code instead — probe
            // case P3 returned '051' — so this row is the failure shape, not
            // the only shape this endpoint produces.
            'the Currency key is explicitly null' => [['Currency' => null]],
        ];
    }

    /**
     * Mutation demonstrated: replacing buildAmount()'s `!$currency instanceof
     * Currency` guard with a `$currency ??= Currency::default()` fallback makes
     * every row report 1000 AMD.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('currencylessPayloads')]
    public function testAnAmountIsNullWithoutACurrencyAndTheRawScalarIsKept(array $overrides): void
    {
        $response = ResponseHydrator::paymentDetailsResponse(
            $this->paymentDetailsWith(array_replace(['Amount' => '10.00'], $overrides)),
        );

        self::assertNull($response->currency, 'No currency resolved, so none may be substituted.');
        self::assertNull(
            $response->amount,
            'An Amount without a currency is not an amount. Currency::default() is an SDK assumption '
            . 'and must never be reached from a response.',
        );
        self::assertSame(
            '10.00',
            $response->amountRaw,
            'Nothing is lost: the scalar the gateway sent is still readable.',
        );
    }

    /**
     * Every monetary field on the model goes null together when the currency
     * does, not just the first one.
     *
     * Written after noticing that a single-field assertion is green for a
     * hydrator which resolved the currency once and then passed it to only one
     * of the four buildAmount() calls.
     */
    public function testAllFourMonetaryFieldsGoNullTogetherWhenTheCurrencyDoes(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Amount' => '10.00',
            'ApprovedAmount' => '9.00',
            'DepositedAmount' => '8.00',
            'RefundedAmount' => '7.00',
        ]));

        self::assertNull($response->amount);
        self::assertNull($response->approvedAmount);
        self::assertNull($response->depositedAmount);
        self::assertNull($response->refundedAmount);

        self::assertSame('10.00', $response->amountRaw);
        self::assertSame('9.00', $response->approvedAmountRaw);
        self::assertSame('8.00', $response->depositedAmountRaw);
        self::assertSame('7.00', $response->refundedAmountRaw);
    }

    /**
     * The four monetary fields all resolve when a currency is present, each to
     * its own count.
     *
     * Four different amounts, because four identical ones would be satisfied by
     * a hydrator that read the same key four times.
     */
    public function testEachMonetaryFieldResolvesToItsOwnCountWhenACurrencyIsPresent(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Currency' => '840',
            'Amount' => '10.00',
            'ApprovedAmount' => '9.00',
            'DepositedAmount' => '8.00',
            'RefundedAmount' => '7.00',
        ]));

        self::assertSame(Currency::USD, $response->currency);
        self::assertSame('840', $response->currencyRaw);

        self::assertInstanceOf(Amount::class, $response->amount);
        self::assertInstanceOf(Amount::class, $response->approvedAmount);
        self::assertInstanceOf(Amount::class, $response->depositedAmount);
        self::assertInstanceOf(Amount::class, $response->refundedAmount);

        self::assertSame(1000, $response->amount->minorUnitCount());
        self::assertSame(900, $response->approvedAmount->minorUnitCount());
        self::assertSame(800, $response->depositedAmount->minorUnitCount());
        self::assertSame(700, $response->refundedAmount->minorUnitCount());

        self::assertSame(Currency::USD, $response->amount->currency());
    }

    /**
     * A scalar Amount rejects cannot become an Amount, and does not take the
     * response down with it.
     *
     * Zero is the case that will actually happen: `RefundedAmount` is 0 on
     * every payment that was never refunded, and Amount requires a positive
     * count. Throwing there would discard the whole response — including the
     * ResponseCode the caller has to act on — over a field whose raw property
     * already preserves the answer.
     *
     * The over-precise row is the other half, and it is the string path's
     * discriminator for the no-rounding rule: "10.005" has three decimal
     * places, AMD's exponent is 2, and Amount refuses it positionally. Route
     * the string through sprintf('%.2F', …) instead and it becomes "10.01" — a
     * hundredth of a dram invented out of a rounding rule nobody asked for —
     * and this row reports an Amount of 1001 rather than null.
     *
     * @return array<string, array{int|string}>
     */
    public static function unrepresentableAmounts(): array
    {
        return [
            'zero, which RefundedAmount carries on every unrefunded payment' => [0],
            'zero as a decimal string' => ['0.00'],
            'over-precise for the currency exponent' => ['10.005'],
            'more minor units than a platform integer holds' => ['99999999999999999999'],
            'not a decimal at all' => ['ten drams'],
            'signed, which Amount reads as malformed' => ['-10.00'],
        ];
    }

    /**
     * Mutation demonstrated: removing the `catch (ValidationException)` from
     * buildAmount() turns each of these rows into an uncaught ValidationException
     * escaping paymentDetailsResponse().
     */
    #[DataProvider('unrepresentableAmounts')]
    public function testAnUnrepresentableAmountYieldsNullAndKeepsTheRaw(int|string $raw): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Currency' => '051',
            'Amount' => $raw,
        ]));

        self::assertNull($response->amount);
        self::assertSame((string) $raw, $response->amountRaw);
        self::assertSame(
            self::SUCCESS_CODE,
            $response->responseCode->raw(),
            'The rest of the response survives. A field Amount cannot represent must not cost the '
            . 'caller the code it needs to act on.',
        );
    }

    /**
     * The two reasons an Amount is null are indistinguishable at the Amount
     * property, and only the raw tells them apart.
     *
     * This is a real consequence of that rule plus the swallowed
     * ValidationException above, and it is pinned rather than left to be
     * discovered: `amount === null` does not mean "the gateway sent no
     * amount". It means "no Amount could be built", for either of two reasons,
     * and a caller that needs to know which must read amountRaw and
     * currencyRaw.
     */
    public function testANullAmountFromAMissingCurrencyIsToldApartFromOneAmountRejectedOnlyByTheRaw(): void
    {
        $noCurrency = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith(['Amount' => '10.00']));
        $unrepresentable = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Currency' => '051',
            'Amount' => '0.00',
        ]));

        self::assertNull($noCurrency->amount);
        self::assertNull($unrepresentable->amount);

        self::assertNull($noCurrency->currency, 'One has no currency…');
        self::assertSame(Currency::AMD, $unrepresentable->currency, '…and the other has one.');

        self::assertSame('10.00', $noCurrency->amountRaw);
        self::assertSame('0.00', $unrepresentable->amountRaw);
    }

    // -------------------------------------------------------- collections ---

    /**
     * `CardBindingFileds` — the manifest's spelling, reproduced verbatim per
     * CONVENTIONS.md §4.8 — hydrates to a list of CardBindingFiled, and each
     * element carries `IsAvtive` onto an idiomatic property.
     */
    public function testTheBindingCollectionHydratesToAListOfElements(): void
    {
        $response = ResponseHydrator::getBindingsResponse([
            'ResponseCode' => self::SUCCESS_CODE,
            'ResponseMessage' => 'OK',
            'CardBindingFileds' => [
                [
                    'CardHolderID' => 'holder-one',
                    'CardPan' => self::MASKED_PAN,
                    'ExpDate' => '1230',
                    'IsAvtive' => true,
                ],
                [
                    'CardHolderID' => 'holder-two',
                    'CardPan' => self::MASKED_PAN,
                    'ExpDate' => '1231',
                    'IsAvtive' => false,
                ],
            ],
        ]);

        self::assertIsArray($response->cardBindings);
        self::assertCount(2, $response->cardBindings);
        self::assertContainsOnlyInstancesOf(CardBindingFiled::class, $response->cardBindings);

        self::assertSame(
            ['holder-one', 'holder-two'],
            array_map(static fn(CardBindingFiled $b): ?string => $b->cardHolderId, $response->cardBindings),
        );
        self::assertSame(
            [true, false],
            array_map(static fn(CardBindingFiled $b): ?bool => $b->isActive, $response->cardBindings),
            'Both elements are hydrated, and the second one is not a copy of the first.',
        );
        self::assertSame('1230', $response->cardBindings[0]->expDate);
        self::assertSame(self::MASKED_PAN, $response->cardBindings[0]->cardPan);
    }

    /**
     * An empty collection is an empty list. An absent one is null. They are
     * different answers and must not be collapsed.
     *
     * "The merchant has no bindings" and "the gateway did not tell us about
     * bindings" are the two facts, and CONVENTIONS.md §4.12 records the shape
     * that makes the confusion easy: `<CardBindingFileds />` self-closes when
     * empty, and an XML reader that treats that as absent has silently
     * answered the wrong question.
     *
     * Mutation demonstrated: returning null instead of $bindings for an empty
     * collection — or hoisting the `$value === null` early return to cover
     * `$value === []` — makes the first assertion report null.
     */
    public function testAnEmptyBindingCollectionIsAnEmptyListAndAnAbsentOneIsNull(): void
    {
        $empty = ResponseHydrator::getBindingsResponse([
            'ResponseCode' => self::SUCCESS_CODE,
            'ResponseMessage' => 'OK',
            'CardBindingFileds' => [],
        ]);

        self::assertIsArray($empty->cardBindings, 'An empty collection is a list, not a null.');
        self::assertSame([], $empty->cardBindings);

        $absent = ResponseHydrator::getBindingsResponse([
            'ResponseCode' => self::SUCCESS_CODE,
            'ResponseMessage' => 'OK',
        ]);

        self::assertNull($absent->cardBindings, 'An absent collection is a null, not an empty list.');
    }

    /**
     * A binding element with no fields at all is still an element, with four
     * nulls.
     *
     * The `IsAvtive` null matters more than it looks: CardBindingFiled has no
     * raw companion for the flag, so null is the only way to say "the gateway
     * did not state this binding's state", and it must not default to either
     * true or false.
     */
    public function testABindingElementWithNoFieldsHydratesToFourNulls(): void
    {
        $binding = ResponseHydrator::cardBindingFiled([]);

        self::assertNull($binding->cardHolderId);
        self::assertNull($binding->cardPan);
        self::assertNull($binding->expDate);
        self::assertNull($binding->isActive);
    }

    /**
     * The boolean forms `IsAvtive` arrives in, and the one non-form that must
     * throw.
     *
     * JSON carries a real boolean. XML carries the text .NET writes for one,
     * which is lowercase "true" or "false". "1" and "0" are accepted on the
     * same reasoning that lets readText() accept an integer: the wire's types
     * drift, and both spellings are unambiguous.
     *
     * The uppercase rows are what pin strtolower(). Without it "TRUE" reaches
     * the match with no arm to land on and the binding throws — which is the
     * mutation, and it is the one an XML writer that emits .NET's `Boolean`
     * ToString() output would trigger in production.
     *
     * @return array<string, array{bool|int|string, bool}>
     */
    public static function booleanForms(): array
    {
        return [
            'JSON true' => [true, true],
            'JSON false' => [false, false],
            'the .NET XML text "true"' => ['true', true],
            'the .NET XML text "false"' => ['false', false],
            'uppercase "TRUE"' => ['TRUE', true],
            'mixed-case "False"' => ['False', false],
            'integer 1' => [1, true],
            'integer 0' => [0, false],
            'the string "1"' => ['1', true],
            'the string "0"' => ['0', false],
        ];
    }

    #[DataProvider('booleanForms')]
    public function testTheIsAvtiveFlagIsReadFromEveryFormTheWireUses(bool|int|string $raw, bool $expected): void
    {
        $binding = ResponseHydrator::cardBindingFiled(['IsAvtive' => $raw]);

        self::assertSame($expected, $binding->isActive);
    }

    /**
     * The pending-transactions collection is a bare array with no envelope, and
     * each row hydrates independently.
     *
     * `OrderId` is asserted by name: GetPendingTransactionsResponse is one of
     * the two models whose casing breaks from the rest of the API
     * (CONVENTIONS.md §4.8), and reading `OrderID` here would yield a silent
     * null rather than an error.
     *
     * The `ClientName` literals are invented, and they name a cardholder rather
     * than a merchant on purpose. `ClientName` holds the *cardholder's* name on
     * GetPaymentDetails (probe case P3); this endpoint has never been called, so
     * the fixture follows the sibling reading rather than the field's misleading
     * spelling.
     */
    public function testThePendingTransactionCollectionHydratesRowByRow(): void
    {
        $rows = ResponseHydrator::getPendingTransactionsList([
            [
                'OrderId' => 2001,
                'ClientName' => 'Test Cardholder One',
                'CardNumber' => self::MASKED_PAN,
                'Amount' => '10.00',
                'PaymentDate' => '2026-01-01T00:00:00',
                'ErrorMessage' => 'first',
            ],
            [
                'OrderId' => '2002',
                'ClientName' => 'Test Cardholder Two',
                'CardNumber' => self::MASKED_PAN,
                'Amount' => 4.0,
                'PaymentDate' => '2026-01-02T00:00:00',
                'ErrorMessage' => 'second',
            ],
        ]);

        self::assertCount(2, $rows);
        self::assertSame(2001, $rows[0]->orderId, 'The declared integer form is kept as an integer.');
        self::assertSame('2002', $rows[1]->orderId, 'And a string form is kept as a string.');
        self::assertSame('first', $rows[0]->errorMessage);
        self::assertSame('second', $rows[1]->errorMessage);
        self::assertSame('10.00', $rows[0]->amountRaw);
        self::assertSame('4.0', $rows[1]->amountRaw);
        self::assertSame('Test Cardholder One', $rows[0]->clientName);
        self::assertSame(self::MASKED_PAN, $rows[0]->cardNumber);
        self::assertSame('2026-01-01T00:00:00', $rows[0]->paymentDate);
    }

    /**
     * An empty pending-transactions collection is an empty list, and a merchant
     * with nothing pending is the ordinary case.
     */
    public function testAnEmptyPendingTransactionCollectionIsAnEmptyList(): void
    {
        self::assertSame([], ResponseHydrator::getPendingTransactionsList([]));
    }

    /**
     * BankInfo hydrates as a model in its own right, called directly.
     *
     * Every other model method on this hydrator is exercised by a direct
     * external call somewhere in this file; bankInfo() was reachable only
     * through readNestedBankInfo(), which calls it as `self::`. Infection
     * found that: marking the method protected left the whole suite green,
     * because an internal call does not care about visibility. A model method
     * is public surface — the transport calls it — and this is the assertion
     * that says so.
     */
    public function testBankInfoHydratesAsAModelInItsOwnRight(): void
    {
        $bankInfo = ResponseHydrator::bankInfo([
            'BankName' => 'Directly Hydrated Issuer',
            'BankCountryCode' => 'AM',
            'BankCountryName' => 'Armenia',
        ]);

        self::assertSame('Directly Hydrated Issuer', $bankInfo->bankName);
        self::assertSame('AM', $bankInfo->bankCountryCode);
        self::assertSame('Armenia', $bankInfo->bankCountryName);

        $empty = ResponseHydrator::bankInfo([]);

        self::assertNull($empty->bankName);
        self::assertNull($empty->bankCountryCode);
        self::assertNull($empty->bankCountryName);
    }

    /**
     * The nested BankInfo object hydrates, and is null when absent.
     *
     * Both shapes are handled and neither is assumed. Probe case P3's completed
     * payment returns the nested object partly populated — a country code and a
     * country name, with `BankName` still the empty string — and probe B2's
     * failed lookup returned it with all three members blank. The key has not
     * been seen absent, which is why the absent case is a test rather than an
     * expectation.
     */
    public function testTheNestedBankInfoHydratesAndIsNullWhenAbsent(): void
    {
        $withBank = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'BankInfo' => [
                'BankName' => 'Test Issuer',
                'BankCountryCode' => 'AM',
                'BankCountryName' => 'Armenia',
            ],
        ]));

        self::assertInstanceOf(BankInfo::class, $withBank->bankInfo);
        self::assertSame('Test Issuer', $withBank->bankInfo->bankName);
        self::assertSame('AM', $withBank->bankInfo->bankCountryCode);
        self::assertSame('Armenia', $withBank->bankInfo->bankCountryName);

        self::assertNull(ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([]))->bankInfo);
    }

    // ------------------------------------------------- required fields ---

    /**
     * ResponseCode carries both wire types end to end, and InitPayment's
     * integer is not normalised into a string.
     *
     * CONVENTIONS.md §4.3 is a table about exactly this: InitPayment answers
     * with int 1, everything else with string "00", and failure code 20
     * appears as int 20 from one and string "20" from the other. assertSame is
     * what makes the difference visible; assertEquals would not.
     */
    public function testTheResponseCodeKeepsWhicheverWireTypeArrived(): void
    {
        $fromInit = ResponseHydrator::initPaymentResponse([
            'PaymentID' => self::PAYMENT_ID,
            'ResponseCode' => 1,
            'ResponseMessage' => 'OK',
        ]);

        self::assertSame(1, $fromInit->responseCode->raw());
        self::assertTrue($fromInit->responseCode->isSuccess());
        self::assertSame(self::PAYMENT_ID, $fromInit->paymentId);
        self::assertSame('OK', $fromInit->responseMessage);

        $fromDetails = ResponseHydrator::paymentDetailsResponse(['ResponseCode' => '20']);

        self::assertSame('20', $fromDetails->responseCode->raw());
        self::assertFalse($fromDetails->responseCode->isSuccess());
    }

    /**
     * The two non-nullable fields are the one place absence is fatal, and the
     * rejection names the field.
     *
     * Absence never throws, and these two fields are non-nullable, so for
     * exactly these fields the rules meet. Inventing a ResponseMessage the
     * gateway never sent is the worse failure by a wide margin — it would be
     * indistinguishable from one it did send — so the exception is correct,
     * and this test is what stops someone from later resolving the tension the
     * other way with a `?? ''`.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function absentRequiredFields(): array
    {
        return [
            'no ResponseMessage at all' => [['ResponseCode' => 1], 'ResponseMessage'],
            'a null ResponseMessage' => [['ResponseCode' => 1, 'ResponseMessage' => null], 'ResponseMessage'],
            'a ResponseMessage of the wrong shape' => [
                ['ResponseCode' => 1, 'ResponseMessage' => ['OK']],
                'ResponseMessage',
            ],
            'no ResponseCode at all' => [['ResponseMessage' => 'OK'], 'ResponseCode'],
            'a null ResponseCode' => [['ResponseCode' => null, 'ResponseMessage' => 'OK'], 'ResponseCode'],
            'a ResponseCode of the wrong shape' => [['ResponseCode' => 1.0, 'ResponseMessage' => 'OK'], 'ResponseCode'],
        ];
    }

    /**
     * @param array<string, mixed> $wire
     */
    #[DataProvider('absentRequiredFields')]
    public function testAnAbsentRequiredFieldThrowsAndNamesTheFieldOnly(array $wire, string $field): void
    {
        try {
            ResponseHydrator::initPaymentResponse($wire);
            self::fail('A missing ' . $field . ' must not be invented.');
        } catch (SerializationException $exception) {
            self::assertSame('InitPayment', $exception->operation());
            self::assertStringContainsString($field, $exception->getMessage());
            self::assertStringContainsString('InitPayment', $exception->getMessage());
            self::assertFalse($exception->causedByJson());
        }
    }

    /**
     * A ResponseMessage the gateway sent as an integer is stringified rather
     * than rejected, on the same reasoning readText() uses.
     */
    public function testAnIntegerRequiredFieldIsStringifiedRatherThanRejected(): void
    {
        $response = ResponseHydrator::initPaymentResponse(['ResponseCode' => 1, 'ResponseMessage' => 200]);

        self::assertSame('200', $response->responseMessage);
    }

    // --------------------------------------------------- shape rejections ---

    /**
     * Every shape the hydrator refuses, with the field it must name.
     *
     * The value in each row is chosen to be recognisable if it ever leaked
     * into a message, which is the second assertion in the test below:
     * CONVENTIONS.md §6 forbids a raw response value in an exception message,
     * because messages reach logs and a body may carry card data. Naming the
     * field is the whole permitted output.
     *
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function unrepresentableShapes(): array
    {
        return [
            'a text field holding an array' => [
                ['ResponseCode' => '00', 'OrderID' => ['leak-canary-a']],
                'OrderID',
                'leak-canary-a',
            ],
            'a text field holding a float' => [
                ['ResponseCode' => '00', 'OrderID' => 1.5],
                'OrderID',
                '1.5',
            ],
            'a text field holding a boolean' => [
                ['ResponseCode' => '00', 'OrderID' => true],
                'OrderID',
                'true',
            ],
            'a decimal field holding an array' => [
                ['ResponseCode' => '00', 'Amount' => ['leak-canary-b']],
                'Amount',
                'leak-canary-b',
            ],
            'a decimal field holding a boolean' => [
                ['ResponseCode' => '00', 'Amount' => true],
                'Amount',
                'true',
            ],
            'a monetary field holding an array while a currency is present' => [
                ['ResponseCode' => '00', 'Currency' => '051', 'Amount' => ['leak-canary-c']],
                'Amount',
                'leak-canary-c',
            ],
            'a monetary field holding a boolean while a currency is present' => [
                ['ResponseCode' => '00', 'Currency' => '051', 'Amount' => false],
                'Amount',
                'false',
            ],
            'an int-or-text field holding an array' => [
                ['ResponseCode' => '00', 'PaymentType' => ['leak-canary-d']],
                'PaymentType',
                'leak-canary-d',
            ],
            'an int-or-text field holding a float' => [
                ['ResponseCode' => '00', 'PaymentType' => 5.0],
                'PaymentType',
                '5',
            ],
            'a nested object holding a scalar' => [
                ['ResponseCode' => '00', 'BankInfo' => 'leak-canary-e'],
                'BankInfo',
                'leak-canary-e',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $wire
     */
    #[DataProvider('unrepresentableShapes')]
    public function testAnUnrepresentableShapeThrowsNamingTheFieldAndNeverTheValue(
        array $wire,
        string $field,
        string $canary,
    ): void {
        try {
            ResponseHydrator::paymentDetailsResponse($wire);
            self::fail('A ' . $field . ' of the wrong shape must not hydrate to a silent null.');
        } catch (SerializationException $exception) {
            self::assertSame('GetPaymentDetails', $exception->operation());
            self::assertStringContainsString($field, $exception->getMessage());
            self::assertStringNotContainsString(
                $canary,
                $exception->getMessage(),
                'A message names the field and never the value. Messages reach logs, and a response '
                . 'body may carry card data (CONVENTIONS.md §6).',
            );
        }
    }

    /**
     * A flag that is neither a boolean nor a spelling of one throws, because
     * CardBindingFiled has nowhere to put a raw value.
     *
     * @return array<string, array{mixed}>
     */
    public static function unreadableFlags(): array
    {
        return [
            'a word that is not a boolean' => ['yes'],
            'an integer that is not 1 or 0' => [2],
            'a float' => [1.0],
            'an array' => [[true]],
            'the empty string' => [''],
        ];
    }

    #[DataProvider('unreadableFlags')]
    public function testAFlagThatIsNotABooleanThrows(mixed $raw): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('The GetBindings response had an unexpected shape: the IsAvtive field was not a boolean');

        ResponseHydrator::cardBindingFiled(['IsAvtive' => $raw]);
    }

    /**
     * The binding collection refuses three shapes, each for its own reason.
     *
     * A non-array is not a collection. A keyed array is not a list, and reading
     * it as a single element would be a guess about a representation nobody has
     * observed — if an XML decoder ever collapses a one-element collection into
     * a bare map, normalising it belongs in that decoder, where the document is
     * still in hand. An element that is not an object cannot be a binding.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function malformedBindingCollections(): array
    {
        return [
            'a scalar where the collection belongs' => ['not-a-collection', 'was not a collection'],
            'a keyed array, which is a map and not a list' => [
                ['CardHolderID' => 'holder-one'],
                'was not a collection',
            ],
            'a list holding a scalar element' => [
                ['not-an-object'],
                'collection held an element that was not an object',
            ],
        ];
    }

    #[DataProvider('malformedBindingCollections')]
    public function testAMalformedBindingCollectionThrows(mixed $raw, string $reason): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage($reason);

        ResponseHydrator::getBindingsResponse([
            'ResponseCode' => self::SUCCESS_CODE,
            'ResponseMessage' => 'OK',
            'CardBindingFileds' => $raw,
        ]);
    }

    /**
     * A pending-transactions row that is not an object throws, naming the
     * operation rather than a key: there is no envelope field to name.
     */
    public function testAPendingTransactionRowThatIsNotAnObjectThrows(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage(
            'The GetPendingTransactions response had an unexpected shape: the transaction collection '
            . 'held an element that was not an object',
        );

        ResponseHydrator::getPendingTransactionsList([['OrderId' => 1], 'not-an-object']);
    }

    /**
     * A text field the gateway sent as an integer is stringified, not rejected.
     *
     * CONVENTIONS.md §4.12 records four fields the vendor PDF called integers
     * arriving as strings, so the wire's types drift in exactly this direction
     * and the reverse drift is as likely. The conversion is exact, which is
     * why it is done here and not for a float — a float would have to be
     * rendered at a precision nobody has specified.
     *
     * `OrderID` is the field used because §4.8 records it as declared string on
     * this model and integer on GetPendingTransactionsResponse, which is the
     * disagreement this branch exists to absorb.
     */
    public function testATextFieldSentAsAnIntegerIsStringified(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'OrderID' => 4001,
            'MerchantId' => 7,
        ]));

        self::assertSame('4001', $response->orderId);
        self::assertSame('7', $response->merchantId);
    }

    /**
     * A value that is not a decimal scalar is rejected once, where the key is
     * read — and there is no second, unreachable copy of that rejection.
     *
     * A boolean is the case: JSON can carry one, and rendering it as an amount
     * would mean spelling `true` as a number. The hydrator reads the key a
     * single time through readDecimalScalar() and hands that one validated
     * scalar to both derivations, so this throw is the only rejection a
     * monetary field has, and it is reachable from the public surface with no
     * reflection.
     *
     * The shape this replaced was two reads of the same key — one for the raw
     * property, one for the Amount — each with its own identically worded
     * rejection. Only the first could ever fire, because PHP evaluates
     * arguments left to right and `amountRaw` happened to precede `amount` in
     * all eight argument lists. The second was dead code kept alive by a
     * reflective call in this test, which is the vacuous guard this suite once
     * spent thirty-seven mutations finding, and it was dead only by an
     * argument ordering that nothing enforced. Sharing the scalar removes the
     * branch rather than the evidence for it: buildAmount()'s three arms are
     * now exhaustive over its parameter type — \PHPStan\dumpType() in the
     * final arm reports `string`, with nothing left over.
     *
     * That exhaustiveness is a property of the signature, not a guard. Nothing
     * in this toolchain rejects a fourth arm, and both halves of that were run
     * on PHP 8.3 under this project's own configuration (level 10,
     * strict-rules, treatPhpDocTypesAsCertain). Splitting the `else` into
     * `elseif (is_string($value))` plus an `else` that throws leaves
     * `composer stan` at exit 0 and `composer infection` at 535/535 killed,
     * MSI 100% — neither gate says a word. Only an assignment-shaped dead arm
     * surfaces at all, and then indirectly: it makes the two ElseIfNegation
     * mutants above it behaviourally identical, so both escape and MSI falls
     * to 99%, which is still above the configured minMsi of 90. A dead fourth
     * arm is a report to read, not a build that breaks. What actually keeps
     * one out is that readDecimalScalar() has already rejected every shape the
     * parameter type does not name.
     *
     * No currency is set here, so buildAmount() returns before it inspects
     * anything — the throw can only be the read.
     */
    public function testANonDecimalScalarIsRejectedOnceWhereTheKeyIsRead(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage(
            'The GetPaymentDetails response had an unexpected shape: the Amount field was not a decimal scalar',
        );

        ResponseHydrator::paymentDetailsResponse(['ResponseCode' => self::SUCCESS_CODE, 'Amount' => true]);
    }

    // ------------------------------------------------- the small models ---

    /**
     * The three-field envelopes, each asserted whole.
     *
     * Grouped into one test on purpose: individually they would be five copies
     * of the same three lines, and what is actually at risk is not any one of
     * them but the chance that two of them were wired to the same hydrator
     * method. Distinct values in every field are what would catch that.
     */
    public function testTheThreeFieldEnvelopesEachCarryTheirOwnFields(): void
    {
        $confirm = ResponseHydrator::confirmPaymentResponse([
            'ResponseCode' => '00',
            'ResponseMessage' => 'confirmed',
            'Opaque' => 'opaque-confirm',
        ]);
        $refund = ResponseHydrator::refundPaymentResponse([
            'ResponseCode' => '01',
            'ResponseMessage' => 'refunded',
            'Opaque' => 'opaque-refund',
        ]);
        $cancel = ResponseHydrator::cancelPaymentResponse([
            'ResponseCode' => '02',
            'ResponseMessage' => 'cancelled',
            'Opaque' => 'opaque-cancel',
        ]);
        $activate = ResponseHydrator::activateBindingResponse([
            'ResponseCode' => '03',
            'ResponseMessage' => 'activated',
            'CardHolderID' => 'holder-activate',
        ]);
        $deactivate = ResponseHydrator::deactivateBindingResponse([
            'ResponseCode' => '04',
            'ResponseMessage' => 'deactivated',
            'CardHolderID' => 'holder-deactivate',
        ]);

        self::assertSame(['00', 'confirmed', 'opaque-confirm'], [
            $confirm->responseCode->raw(),
            $confirm->responseMessage,
            $confirm->opaque,
        ]);
        self::assertSame(['01', 'refunded', 'opaque-refund'], [
            $refund->responseCode->raw(),
            $refund->responseMessage,
            $refund->opaque,
        ]);
        self::assertSame(['02', 'cancelled', 'opaque-cancel'], [
            $cancel->responseCode->raw(),
            $cancel->responseMessage,
            $cancel->opaque,
        ]);
        self::assertSame(['03', 'activated', 'holder-activate'], [
            $activate->responseCode->raw(),
            $activate->responseMessage,
            $activate->cardHolderId,
        ]);
        self::assertSame(['04', 'deactivated', 'holder-deactivate'], [
            $deactivate->responseCode->raw(),
            $deactivate->responseMessage,
            $deactivate->cardHolderId,
        ]);
    }

    /**
     * GetPaymentId reads `PaymentId`, and no other model does.
     *
     * The casing break is the whole content of this test. `PaymentID` — the
     * spelling every other model uses — is present in the payload and must be
     * ignored, because a hydrator reading the wrong one produces a silent null
     * on the operation whose only job is to return that value.
     */
    public function testGetPaymentIdReadsTheLowercaseCasingVariantAndNotTheCommonOne(): void
    {
        $response = ResponseHydrator::getPaymentIdResponse([
            'PaymentId' => self::PAYMENT_ID,
            'PaymentID' => 'the-spelling-every-other-model-uses',
            'ResponseCode' => self::SUCCESS_CODE,
            'ResponseMessage' => 'OK',
        ]);

        self::assertSame(self::PAYMENT_ID, $response->paymentId);
    }

    /**
     * GetPendingTransactions reads `OrderId`, and not `OrderID`.
     */
    public function testAPendingTransactionReadsTheLowercaseCasingVariantAndNotTheCommonOne(): void
    {
        $response = ResponseHydrator::getPendingTransactionsResponse([
            'OrderId' => 3001,
            'OrderID' => 9999,
        ]);

        self::assertSame(3001, $response->orderId);
    }

    /**
     * An InitPayment failure carries an empty PaymentID, never a null, and the
     * SDK does not tidy that into one.
     *
     * CONVENTIONS.md §4.12 records the shape. The distinction is small and
     * real: the gateway answered, and it said the payment has no identifier.
     */
    public function testAnEmptyPaymentIdIsCarriedAsTheEmptyStringItArrivedAs(): void
    {
        $response = ResponseHydrator::initPaymentResponse([
            'PaymentID' => '',
            'ResponseCode' => 20,
            'ResponseMessage' => 'Incorrect Username and Password',
        ]);

        self::assertSame('', $response->paymentId);
        self::assertNotNull($response->paymentId);
        self::assertTrue($response->responseCode->isAuthenticationFailure());
    }

    // ------------------------------------------------ the two wide models ---

    /**
     * Every field of GetPaymentDetails, populated with a distinct value, read
     * back off the DTO.
     *
     * Distinct values throughout, so that two properties wired to the same key
     * — the copy-paste failure a 39-argument constructor invites — is visible
     * rather than green.
     */
    public function testEveryPaymentDetailsFieldIsCarriedOntoItsOwnProperty(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->fullPaymentDetailsWire());

        self::assertSame('10.00', $response->amountRaw);
        self::assertSame('9.00', $response->approvedAmountRaw);
        self::assertSame('approval-code', $response->approvalCode);
        self::assertSame(self::MASKED_PAN, $response->cardNumber);
        self::assertSame('Test Cardholder', $response->clientName);
        self::assertSame('client@example.test', $response->clientEmail);
        self::assertSame('051', $response->currencyRaw);
        self::assertSame(Currency::AMD, $response->currency);
        self::assertSame('01/01/2026 12:00:00', $response->dateTime);
        self::assertSame('8.00', $response->depositedAmountRaw);
        self::assertSame('Ամերիաբանկ description', $response->description);
        self::assertSame('md-order-id', $response->mdOrderId);
        self::assertSame('merchant-id', $response->merchantId);
        self::assertSame('terminal-id', $response->terminalId);
        self::assertSame('4001', $response->orderId);
        self::assertSame('payment_deposited', $response->paymentStateRaw);
        self::assertSame(PaymentState::Deposited, $response->paymentState);
        self::assertSame(5, $response->paymentTypeRaw);
        self::assertSame(PaymentType::MainRest, $response->paymentType);
        self::assertSame('primary-rc', $response->primaryRc);
        self::assertSame(self::SUCCESS_CODE, $response->responseCode->raw());
        self::assertSame('1230', $response->expDate);
        self::assertSame('203.0.113.7', $response->processingIp);
        self::assertSame('2', $response->orderStatusRaw);
        self::assertSame(OrderStatus::Deposited, $response->orderStatus);
        self::assertSame('holder-id', $response->cardHolderId);
        self::assertSame('binding-id', $response->bindingId);
        self::assertSame('7.00', $response->refundedAmountRaw);
        self::assertSame('opaque-value', $response->opaque);
        self::assertSame('trxn-description', $response->trxnDescription);
        self::assertSame('rrn-value', $response->rrn);
        self::assertSame('action-code', $response->actionCode);
        self::assertSame('1.0000', $response->exchangeRate);

        self::assertInstanceOf(Amount::class, $response->amount);
        self::assertInstanceOf(Amount::class, $response->approvedAmount);
        self::assertInstanceOf(Amount::class, $response->depositedAmount);
        self::assertInstanceOf(Amount::class, $response->refundedAmount);
        self::assertSame(1000, $response->amount->minorUnitCount());
        self::assertSame(900, $response->approvedAmount->minorUnitCount());
        self::assertSame(800, $response->depositedAmount->minorUnitCount());
        self::assertSame(700, $response->refundedAmount->minorUnitCount());

        self::assertInstanceOf(BankInfo::class, $response->bankInfo);
        self::assertSame('Test Issuer', $response->bankInfo->bankName);
    }

    /**
     * The merchant's own text comes back under `TrxnDescription`, and the
     * gateway's own text comes back under `Description`.
     *
     * The two keys are adjacent, both declared `string`, and the manifest
     * carries no description of either — so nothing upstream says which is
     * which, and the vendor PDF calls both "description of the transaction".
     * Probe case P3 settles it: `InitPayment` was sent
     * `Description: "Probe order 4565037"` (probe case P1) and
     * `GetPaymentDetails` answered with that string in `TrxnDescription` while
     * `Description` held the processor's `Approved. - Payment post authorized`.
     * After a refund the same field read `Approved. - Refunded payment back to
     * client card` (P4.1b), so it tracks the processor and not the merchant.
     *
     * This is the one assertion in the suite that would catch the two
     * properties being wired to each other's key. Every other fixture uses
     * values distinct enough to detect a *crossed* mapping but says nothing
     * about which key means what, and a reader who assumed the obvious would
     * assume wrong. The values below are shaped like P3's and invented.
     */
    public function testTheMerchantsTextArrivesUnderTrxnDescriptionAndTheProcessorsUnderDescription(): void
    {
        $response = ResponseHydrator::paymentDetailsResponse($this->paymentDetailsWith([
            'Description' => 'Approved. - Payment post authorized',
            'TrxnDescription' => 'Merchant order reference',
        ]));

        self::assertSame(
            'Merchant order reference',
            $response->trxnDescription,
            'What the merchant submitted as Description is read back from trxnDescription.',
        );
        self::assertSame(
            'Approved. - Payment post authorized',
            $response->description,
            'The response Description carries the processor\'s text, never the merchant\'s.',
        );
        self::assertNotSame(
            $response->description,
            $response->trxnDescription,
            'The two keys must not be wired to one property.',
        );
    }

    /**
     * Every field of MakeBindingPayment, likewise.
     *
     * The three 3-D Secure fields are the reason this model is not
     * PaymentDetailsResponse with a different name: CONVENTIONS.md §4.12
     * records that a binding payment answers with an AcsUrl / PaReq / TermUrl
     * challenge triple rather than settling silently, and a caller that
     * ignored them would have a payment that never completes.
     */
    public function testEveryMakeBindingPaymentFieldIsCarriedOntoItsOwnProperty(): void
    {
        $response = ResponseHydrator::makeBindingPaymentResponse($this->fullMakeBindingPaymentWire());

        self::assertSame(self::PAYMENT_ID, $response->paymentId);
        self::assertSame(self::SUCCESS_CODE, $response->responseCode->raw());
        self::assertSame('10.00', $response->amountRaw);
        self::assertSame('9.00', $response->approvedAmountRaw);
        self::assertSame('approval-code', $response->approvalCode);
        self::assertSame(self::MASKED_PAN, $response->cardNumber);
        self::assertSame('Test Cardholder', $response->clientName);
        self::assertSame('051', $response->currencyRaw);
        self::assertSame(Currency::AMD, $response->currency);
        self::assertSame('01/01/2026 12:00:00', $response->dateTime);
        self::assertSame('8.00', $response->depositedAmountRaw);
        self::assertSame('binding description', $response->description);
        self::assertSame('md-order-id', $response->mdOrderId);
        self::assertSame('merchant-id', $response->merchantId);
        self::assertSame('terminal-id', $response->terminalId);
        self::assertSame('5001', $response->orderId);
        self::assertSame('payment_approved', $response->paymentStateRaw);
        self::assertSame(PaymentState::Approved, $response->paymentState);
        self::assertSame(6, $response->paymentTypeRaw);
        self::assertSame(PaymentType::BindingMainRest, $response->paymentType);
        self::assertSame('primary-rc', $response->primaryRc);
        self::assertSame('1230', $response->expDate);
        self::assertSame('203.0.113.7', $response->processingIp);
        self::assertSame('1', $response->orderStatusRaw);
        self::assertSame(OrderStatus::Approved, $response->orderStatus);
        self::assertSame('holder-id', $response->cardHolderId);
        self::assertSame('binding-id', $response->bindingId);
        self::assertSame('7.00', $response->refundedAmountRaw);
        self::assertSame('opaque-value', $response->opaque);
        self::assertSame('trxn-description', $response->trxnDescription);
        self::assertSame('rrn-value', $response->rrn);
        self::assertSame('action-code', $response->actionCode);
        self::assertSame('https://acs.example.test/challenge', $response->acsUrl);
        self::assertSame('pa-req-value', $response->paReq);
        self::assertSame('https://merchant.example.test/term', $response->termUrl);

        self::assertInstanceOf(Amount::class, $response->amount);
        self::assertSame(1000, $response->amount->minorUnitCount());
        self::assertInstanceOf(Amount::class, $response->approvedAmount);
        self::assertSame(900, $response->approvedAmount->minorUnitCount());
        self::assertInstanceOf(Amount::class, $response->depositedAmount);
        self::assertSame(800, $response->depositedAmount->minorUnitCount());
        self::assertInstanceOf(Amount::class, $response->refundedAmount);
        self::assertSame(700, $response->refundedAmount->minorUnitCount());
    }

    /**
     * MakeBindingPayment runs the same branching rules as GetPaymentDetails,
     * on its own model.
     *
     * The two wide models share no code — each names its own keys — so a guard
     * fixed on one is not thereby fixed on the other. These three rows are the
     * branches that would be most expensive to have got right once.
     */
    public function testMakeBindingPaymentAppliesTheSameGuardsAsPaymentDetails(): void
    {
        $response = ResponseHydrator::makeBindingPaymentResponse([
            'ResponseCode' => self::SUCCESS_CODE,
            'OrderStatus' => 'payment_deposited',
            'PaymentType' => 'BindingMainRest',
            'Amount' => '10.00',
        ]);

        self::assertNull($response->orderStatus, 'The ctype_digit guard applies here too.');
        self::assertSame('payment_deposited', $response->orderStatusRaw);
        self::assertNull($response->paymentType, 'A member name is not mapped back here either.');
        self::assertSame('BindingMainRest', $response->paymentTypeRaw);
        self::assertNull($response->amount, 'No Currency field, so no Amount and no default.');
        self::assertSame('10.00', $response->amountRaw);
        self::assertNull($response->currency);
    }

    /**
     * MakeBindingPayment resolves its amounts through its own Currency field.
     */
    public function testMakeBindingPaymentResolvesItsAmountsThroughItsOwnCurrency(): void
    {
        $response = ResponseHydrator::makeBindingPaymentResponse([
            'ResponseCode' => self::SUCCESS_CODE,
            'Currency' => '978',
            'Amount' => 12.34,
            'OrderStatus' => '4',
            'PaymentType' => 5,
        ]);

        self::assertSame(Currency::EUR, $response->currency);
        self::assertInstanceOf(Amount::class, $response->amount);
        self::assertSame(1234, $response->amount->minorUnitCount());
        self::assertSame(Currency::EUR, $response->amount->currency());
        self::assertSame(OrderStatus::Refunded, $response->orderStatus);
        self::assertSame(PaymentType::MainRest, $response->paymentType);
    }

    // ------------------------------------------------------------ fixtures ---

    /**
     * The smallest GetPaymentDetails payload that can hydrate, plus whatever
     * the case under test is about.
     *
     * Minimal rather than full on purpose: a branch test built on a full
     * payload asserts its own branch against a background of thirty other
     * fields, and any of them could be the reason it passes.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function paymentDetailsWith(array $overrides): array
    {
        return array_replace(['ResponseCode' => self::SUCCESS_CODE], $overrides);
    }

    /**
     * Every declared field of GetPaymentDetails, each with a value that appears
     * nowhere else in the payload.
     *
     * The Description is Armenian because CONVENTIONS.md §4.7 requires
     * JSON_UNESCAPED_UNICODE for exactly that reason and §4.12 confirms the
     * gateway round-trips it; a fixture in ASCII would never notice a hydrator
     * that mangled it.
     *
     * `DateTime` is written in the shape probe case P3 observed, `d/m/Y H:i:s`,
     * rather than the ISO string this fixture used to carry. The hydrator does
     * not parse the field, so nothing here depends on the shape — but a fixture
     * that cannot occur on the wire teaches a reader the wrong format for free.
     * The value itself is invented.
     *
     * `ClientName` names a cardholder, not a merchant: that is what the field
     * holds (P3). No observed name appears here or anywhere else in this
     * package.
     *
     * @return array<string, mixed>
     */
    private function fullPaymentDetailsWire(): array
    {
        return [
            'Amount' => '10.00',
            'ApprovedAmount' => '9.00',
            'ApprovalCode' => 'approval-code',
            'CardNumber' => self::MASKED_PAN,
            'ClientName' => 'Test Cardholder',
            'ClientEmail' => 'client@example.test',
            'Currency' => '051',
            'DateTime' => '01/01/2026 12:00:00',
            'DepositedAmount' => '8.00',
            'Description' => 'Ամերիաբանկ description',
            'MDOrderID' => 'md-order-id',
            'MerchantId' => 'merchant-id',
            'TerminalId' => 'terminal-id',
            'OrderID' => '4001',
            'PaymentState' => 'payment_deposited',
            'PaymentType' => 5,
            'PrimaryRC' => 'primary-rc',
            'ResponseCode' => self::SUCCESS_CODE,
            'ExpDate' => '1230',
            'ProcessingIP' => '203.0.113.7',
            'OrderStatus' => '2',
            'CardHolderID' => 'holder-id',
            'BindingID' => 'binding-id',
            'RefundedAmount' => '7.00',
            'Opaque' => 'opaque-value',
            'TrxnDescription' => 'trxn-description',
            'rrn' => 'rrn-value',
            'ActionCode' => 'action-code',
            'ExchangeRate' => '1.0000',
            'BankInfo' => [
                'BankName' => 'Test Issuer',
                'BankCountryCode' => 'AM',
                'BankCountryName' => 'Armenia',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fullMakeBindingPaymentWire(): array
    {
        return [
            'PaymentID' => self::PAYMENT_ID,
            'ResponseCode' => self::SUCCESS_CODE,
            'Amount' => '10.00',
            'ApprovedAmount' => '9.00',
            'ApprovalCode' => 'approval-code',
            'CardNumber' => self::MASKED_PAN,
            'ClientName' => 'Test Cardholder',
            'Currency' => '051',
            'DateTime' => '01/01/2026 12:00:00',
            'DepositedAmount' => '8.00',
            'Description' => 'binding description',
            'MDOrderID' => 'md-order-id',
            'MerchantId' => 'merchant-id',
            'TerminalId' => 'terminal-id',
            'OrderID' => '5001',
            'PaymentState' => 'payment_approved',
            'PaymentType' => 6,
            'PrimaryRC' => 'primary-rc',
            'ExpDate' => '1230',
            'ProcessingIP' => '203.0.113.7',
            'OrderStatus' => '1',
            'CardHolderID' => 'holder-id',
            'BindingID' => 'binding-id',
            'RefundedAmount' => '7.00',
            'Opaque' => 'opaque-value',
            'TrxnDescription' => 'trxn-description',
            'rrn' => 'rrn-value',
            'ActionCode' => 'action-code',
            'AcsUrl' => 'https://acs.example.test/challenge',
            'PaReq' => 'pa-req-value',
            'TermUrl' => 'https://merchant.example.test/term',
        ];
    }
}
