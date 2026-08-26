<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Money;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Amount is the only thing standing between a caller's decimal string and the
 * integer this SDK will settle money with, so every assertion here was written
 * against a specific mutation of src/Money/Amount.php and confirmed to fail
 * before being kept.
 *
 * Two of the guards in fromDecimalString() are behaviourally redundant: "" and
 * " 10" would still be rejected if their own guard were deleted, because the
 * ASCII-digit check on the integer part rejects them anyway. The only thing
 * that separates a guard doing its job from a guard that has been deleted is
 * the text of the rejection, so those cases assert the message and not merely
 * the type.
 *
 * The one-decimal-point guard is not in that set, and an earlier version of
 * this docblock said it was. It is load-bearing, and precisely because the
 * fraction-digit check below it is gated on count($parts) === 2: delete the
 * count($parts) > 2 block and "10.5.5" has three parts, so that check is
 * skipped entirely, $parts[1] picks up "5", and the string is accepted as 1050
 * minor units — 10.50. Not an exception with the wrong text. A silent, wrong,
 * plausible-looking amount, from a typo, on a charge. Executed against the
 * mutant to confirm. The "two decimal points" row below is what fails, so the
 * suite does hold; this note exists so nobody reads that row as redundant and
 * removes the guard it pins.
 *
 * Messages name the field and the expectation and never the value
 * (CONVENTIONS.md §5 and §6); an assertion here must never be written in a way
 * that would only pass if a validator echoed its input.
 */
#[CoversClass(Amount::class)]
#[UsesClass(Currency::class)]
#[UsesClass(ValidationException::class)]
final class AmountTest extends TestCase
{
    /**
     * The rejection every non-digit integer part earns, in full.
     *
     * Pinned whole rather than by fragment: the message is one concatenated
     * literal, and asserting only "ASCII digit" leaves the half naming the
     * three things that are not accepted — a sign, an exponent, a separator —
     * free to be dropped. That half is the actionable part for a caller who
     * sent "-10" or "1e3".
     */
    private const string INTEGER_PART = 'Field "Amount" is malformed: expected at least one ASCII digit '
        . 'before the decimal point, and nothing else — no sign, no exponent, no separator.';

    private const string FRACTION_PART = 'Field "Amount" is malformed: expected at least one ASCII digit '
        . 'after the decimal point, and nothing else.';

    /**
     * The rejection an over-long fraction earns, whatever digits it holds.
     *
     * One constant for both the significant and the insignificant case, because
     * the two must earn the same rejection: the rule fromDecimalString()
     * states is positional, and a message that explained "10.001" in terms of
     * lost precision would be a false statement about "10.000", which loses
     * none. Two texts here would mean two rules in the parser.
     */
    private const string TOO_MANY_PLACES = 'Field "Amount" is malformed: expected at most 2 decimal places, '
        . 'the ISO 4217 exponent of the given currency; the limit is positional, so a longer fraction is '
        . 'rejected even when its extra places are zeros.';

    /**
     * Every currency crossed with the four canonical strings.
     *
     * The expected minor unit count is carried per row and asserted alongside
     * the round trip. A round trip on its own is invariant under a decimal
     * point that moves in both directions at once — parse "10.00" as 100 and
     * render 100 as "10.00" and the string still comes back — which is exactly
     * the mutation that would send a hundredth of a payment to the gateway.
     * The count is the fixed point that mutation cannot satisfy.
     *
     * The counts assume an exponent of 2, which CurrencyTest pins for all four
     * members. A currency whose exponent changed would fail here, loudly, and
     * that is correct: it is a change in what an amount means on the wire.
     *
     * @return array<string, array{Currency, string, int}>
     */
    public static function roundTrips(): array
    {
        $strings = [
            '10.00' => 1000,
            '0.01' => 1,
            '999999.99' => 99999999,
            '1.50' => 150,
        ];

        $rows = [];

        foreach (Currency::cases() as $currency) {
            foreach ($strings as $decimal => $minorUnitCount) {
                $rows[$currency->name . ' ' . $decimal] = [$currency, $decimal, $minorUnitCount];
            }
        }

        return $rows;
    }

    /**
     * The twelve malformed inputs, each with the rejection it earns.
     *
     * Listed one row per input rather than looped over inside a single test.
     * A loop with one assertion is green when eleven of the twelve throw and
     * the twelfth is silently accepted, because the first exception ends the
     * loop; twelve rows are twelve results.
     *
     * The message is part of the expectation for every row, not only the
     * redundant guards, because the message is the only observable difference
     * between "the guard for this input ran" and "some later guard happened to
     * catch it too". For "10.5.5" it is more than that: no later guard catches
     * it at all, so the row is the only thing standing between a mistyped
     * amount and a wrong charge. See the class docblock.
     *
     * @return array<string, array{string, string}>
     */
    public static function malformedInputs(): array
    {
        return [
            'empty string' => [
                '',
                'Field "Amount" is malformed: expected a non-empty decimal string such as "10.00".',
            ],
            'leading whitespace' => [
                ' 10',
                'Field "Amount" is malformed: expected a decimal string with no leading or trailing whitespace.',
            ],
            'trailing whitespace' => [
                '10 ',
                'Field "Amount" is malformed: expected a decimal string with no leading or trailing whitespace.',
            ],
            'negative sign' => ['-10', self::INTEGER_PART],
            'positive sign' => ['+10', self::INTEGER_PART],
            'scientific notation' => ['1e3', self::INTEGER_PART],
            'comma separator' => ['10,00', self::INTEGER_PART],
            'trailing decimal point' => ['10.', self::FRACTION_PART],
            'leading decimal point' => ['.5', self::INTEGER_PART],
            'two decimal points' => [
                '10.5.5',
                'Field "Amount" is malformed: expected at most one decimal point.',
            ],
            'letters' => ['abc', self::INTEGER_PART],
            'trailing letter in the fraction' => ['10.5a', self::FRACTION_PART],
        ];
    }

    /**
     * fromDecimalString() and toDecimalString() are exact inverses.
     *
     * Fails if the fraction is truncated rather than left-padded, if padding is
     * dropped, or if the decimal point moves in either direction.
     */
    #[DataProvider('roundTrips')]
    public function testADecimalStringRoundTripsThroughIntegerMinorUnits(
        Currency $currency,
        string $decimal,
        int $minorUnitCount,
    ): void {
        $amount = Amount::fromDecimalString($decimal, $currency);

        self::assertSame(
            $minorUnitCount,
            $amount->minorUnitCount(),
            sprintf('"%s" is %d minor units at an exponent of 2.', $decimal, $minorUnitCount),
        );

        self::assertSame(
            $decimal,
            $amount->toDecimalString(),
            sprintf('"%s" must come back byte-identical.', $decimal),
        );

        self::assertSame($currency, $amount->currency(), 'An amount carries the currency it was built with.');
    }

    /**
     * "10", "10.0" and "10.00" denote one quantity and must converge on one
     * count. The gateway accepts all three spellings (CONVENTIONS.md §4.7), so
     * all three will arrive from callers.
     */
    public function testEquivalentDecimalFormsConvergeOnTheSameCount(): void
    {
        self::assertSame(1000, Amount::fromDecimalString('10', Currency::AMD)->minorUnitCount());
        self::assertSame(1000, Amount::fromDecimalString('10.0', Currency::AMD)->minorUnitCount());
        self::assertSame(1000, Amount::fromDecimalString('10.00', Currency::AMD)->minorUnitCount());
    }

    /**
     * A fraction shorter than the exponent is padded on the right.
     *
     * This case exists because the three above cannot detect the mistake: the
     * only digit "10.0" has to pad is a zero, and a zero padded on the wrong
     * side still reads "00". "1.5" is the shortest input that tells the two
     * apart — padded right it is 150, padded left it is 105, a tenfold error
     * in the caller's favour or the bank's.
     */
    public function testAShortFractionIsPaddedOnTheRightNotTheLeft(): void
    {
        self::assertSame(
            150,
            Amount::fromDecimalString('1.5', Currency::AMD)->minorUnitCount(),
            '"1.5" is 150 minor units; 105 would mean the fraction was padded on the wrong side.',
        );
    }

    /**
     * Leading zeros are insignificant and must be stripped before the overflow
     * comparison, which is a comparison of digit-string lengths.
     *
     * Twenty digits of padding on a ten-dram amount: without the strip, the
     * concatenated digit string is longer than PHP_INT_MAX's and a perfectly
     * ordinary amount is rejected as an overflow.
     */
    public function testLeadingZerosAreInsignificantAndDoNotTripTheOverflowGuard(): void
    {
        self::assertSame(1000, Amount::fromDecimalString('00000000000000000010.00', Currency::AMD)->minorUnitCount());
    }

    /**
     * Five minor units is five hundredths, not five, not five tenths.
     *
     * The integer part is "0" rather than empty and the fraction is padded on
     * the left to the full scale. "5.00", "0.5" and "0.050" are each a
     * different rendering bug and assertSame rejects all three.
     */
    public function testAMinorUnitCountSmallerThanTheScaleRendersWithALeadingZero(): void
    {
        self::assertSame('0.05', Amount::fromMinorUnits(5, Currency::AMD)->toDecimalString());
    }

    /**
     * A digit the currency cannot represent is a rejection, never a rounding.
     *
     * The message is pinned whole. Truncating or rounding "10.001" both land on
     * 1000, which is indistinguishable from a correct parse of "10.00" — so the
     * only evidence that the guard exists is the rejection itself, and the only
     * evidence it is this guard rather than a later one is the text.
     */
    public function testAFractionLongerThanTheCurrencyExponentIsRejectedNotRounded(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(self::TOO_MANY_PLACES);

        Amount::fromDecimalString('10.001', Currency::AMD);
    }

    /**
     * An over-long fraction of zeros is rejected too, on the same terms.
     *
     * "10.000" loses nothing — it is 1000 minor units under any reading — and
     * it is what any three-decimal formatter emits, so a caller will send it.
     * It is rejected anyway, because the alternative is a parser that decides
     * case by case which excess places it will absorb, and that is a rule this
     * class cannot state and a caller cannot predict.
     *
     * Asserted against the same constant as "10.001" on purpose. A future edit
     * that softens the message for the zero case, or accepts it outright, has
     * to come through here.
     */
    public function testAnOverLongFractionOfZerosIsRejectedOnTheSameTerms(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(self::TOO_MANY_PLACES);

        Amount::fromDecimalString('10.000', Currency::AMD);
    }

    #[DataProvider('malformedInputs')]
    public function testAMalformedDecimalStringIsRejected(string $input, string $expectedMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expectedMessage);

        Amount::fromDecimalString($input, Currency::AMD);
    }

    /**
     * A trailing newline is whitespace and is rejected as such.
     *
     * Deliberately not a thirteenth row in the provider above, so that the
     * completeness check stays a verbatim copy of the specification's list.
     * It is here because "10\n" is what a caller gets from reading an amount
     * out of a file or a form field, and because it is the input the digit
     * check is anchored against: isDigitString() ends with \z rather than $
     * precisely so a trailing newline cannot slip past. That anchor is
     * unreachable while the whitespace guard stands in front of it, so this
     * asserts the outcome a caller actually sees rather than which of the two
     * produced it.
     */
    public function testATrailingNewlineIsRejectedAsWhitespace(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            'Field "Amount" is malformed: expected a decimal string with no leading or trailing whitespace.',
        );

        Amount::fromDecimalString("10\n", Currency::AMD);
    }

    /**
     * The provider is hand-maintained, so it needs a completeness check.
     *
     * Without one, a row deleted in a future edit is a malformed input this
     * suite no longer rejects, and nothing would say so. The twelve are pinned
     * here in the order the provider declares them.
     */
    public function testTheMalformedProviderCoversEveryInputItMustReject(): void
    {
        $inputs = [];

        foreach (self::malformedInputs() as $row) {
            $inputs[] = $row[0];
        }

        self::assertSame(
            ['', ' 10', '10 ', '-10', '+10', '1e3', '10,00', '10.', '.5', '10.5.5', 'abc', '10.5a'],
            $inputs,
        );
    }

    /**
     * PHP_INT_MAX itself is a valid amount and must not be rejected.
     *
     * The boundary is asserted from both sides because the overflow guard is a
     * comparison and a comparison has two neighbouring mistakes. This side
     * catches an off-by-one that rejects the largest representable amount; the
     * test below catches the one that accepts an unrepresentable one.
     */
    public function testTheLargestRepresentableAmountIsAccepted(): void
    {
        $amount = Amount::fromDecimalString('92233720368547758.07', Currency::AMD);

        self::assertSame(PHP_INT_MAX, $amount->minorUnitCount());
        self::assertSame('92233720368547758.07', $amount->toDecimalString());
    }

    /**
     * One minor unit past PHP_INT_MAX throws instead of saturating.
     *
     * An unchecked (int) cast of "9223372036854775808" does not wrap negative
     * in PHP — it saturates at PHP_INT_MAX, quietly turning an overflow into an
     * amount one luma short of the requested one. Either outcome is a wrong
     * number reaching the gateway, and both are caught by requiring a throw.
     */
    public function testOneMinorUnitAbovePhpIntMaxIsRejectedRatherThanSaturated(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            'Field "Amount" is malformed: expected an amount whose minor unit count fits in a platform integer.',
        );

        Amount::fromDecimalString('92233720368547758.08', Currency::AMD);
    }

    /**
     * A digit string longer than PHP_INT_MAX's is rejected on length alone,
     * before any lexical comparison — "99999..." is not merely one past the
     * boundary, it is a different number of digits.
     */
    public function testADigitStringLongerThanPhpIntMaxIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            'Field "Amount" is malformed: expected an amount whose minor unit count fits in a platform integer.',
        );

        Amount::fromDecimalString('99999999999999999999.99', Currency::AMD);
    }

    /**
     * Money that does not move is not an amount — probe A7.3 had a zero-value
     * refund rejected by the gateway.
     *
     * The message is asserted because it is what separates this guard from the
     * malformed-value ones: a positivity failure and a parse failure are
     * different errors for the caller, and only the text says which happened.
     */
    public function testZeroMinorUnitsAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Amount must be greater than zero, got 0 minor units.');

        Amount::fromMinorUnits(0, Currency::AMD);
    }

    public function testANegativeMinorUnitCountIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Amount must be greater than zero, got -1 minor units.');

        Amount::fromMinorUnits(-1, Currency::AMD);
    }

    /**
     * "0.00" parses cleanly and is then rejected for being zero, not for being
     * malformed. The message pins which of the two guards fired: routing every
     * constructor through fromMinorUnits() is what keeps the positivity rule
     * from drifting, and a fromDecimalString() that grew its own zero check
     * would report the wrong error.
     */
    public function testAZeroDecimalStringIsRejectedForNotBeingPositive(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Amount must be greater than zero, got 0 minor units.');

        Amount::fromDecimalString('0.00', Currency::AMD);
    }

    /**
     * The smallest positive amount is accepted.
     *
     * The counterweight to the three rejections above: a positivity guard
     * flipped to reject everything at or above zero would satisfy all of them
     * and fail only here.
     */
    public function testTheSmallestPositiveAmountIsAccepted(): void
    {
        $amount = Amount::fromMinorUnits(1, Currency::USD);

        self::assertSame(1, $amount->minorUnitCount());
        self::assertSame(Currency::USD, $amount->currency());
        self::assertSame('0.01', $amount->toDecimalString());
    }

    public function testTwoAmountsWithTheSameCountAndCurrencyAreEqual(): void
    {
        self::assertTrue(
            Amount::fromMinorUnits(1000, Currency::AMD)->equals(Amount::fromDecimalString('10.00', Currency::AMD)),
        );
    }

    public function testTwoAmountsWithDifferentCountsAreNotEqual(): void
    {
        self::assertFalse(
            Amount::fromMinorUnits(1000, Currency::AMD)->equals(Amount::fromMinorUnits(1001, Currency::AMD)),
        );
    }

    /**
     * equals() answers false across currencies rather than throwing.
     *
     * "10 AMD and 10 USD are not equal" is a true statement, so there is an
     * answer to give. The asymmetry with isGreaterThan() is deliberate and is
     * asserted from both sides, here and below, because swapping the two
     * behaviours is a one-line edit that neither test would catch alone.
     */
    public function testEqualsIsFalseAcrossCurrenciesRatherThanThrowing(): void
    {
        self::assertFalse(
            Amount::fromMinorUnits(1000, Currency::AMD)->equals(Amount::fromMinorUnits(1000, Currency::USD)),
        );
    }

    public function testIsGreaterThanOrdersTwoAmountsOfTheSameCurrency(): void
    {
        $larger = Amount::fromMinorUnits(1001, Currency::AMD);
        $smaller = Amount::fromMinorUnits(1000, Currency::AMD);

        self::assertTrue($larger->isGreaterThan($smaller));
        self::assertFalse($smaller->isGreaterThan($larger));
    }

    /**
     * Strictly greater, not greater-or-equal.
     *
     * The equal pair is the only input that separates > from >=, and the
     * difference matters wherever this is used to decide whether a refund
     * exceeds what was captured: at exactly the captured amount, >= would call
     * a permitted refund an over-refund, or the reverse, depending on which
     * side the caller wrote.
     */
    public function testIsGreaterThanIsFalseForTwoEqualAmounts(): void
    {
        self::assertFalse(
            Amount::fromMinorUnits(1000, Currency::AMD)->isGreaterThan(Amount::fromMinorUnits(1000, Currency::AMD)),
        );
    }

    /**
     * isGreaterThan() throws across currencies rather than answering.
     *
     * Ordering 10 AMD against 10 USD has no answer without a rate this SDK does
     * not have, and either boolean would be a lie that silently approves or
     * blocks a transfer.
     */
    public function testIsGreaterThanThrowsAcrossCurrenciesRatherThanAnswering(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            'Field "Amount" is malformed: expected two amounts in the same currency; ordering across '
            . 'currencies has no answer without an exchange rate.',
        );

        Amount::fromMinorUnits(1000, Currency::AMD)->isGreaterThan(Amount::fromMinorUnits(1000, Currency::USD));
    }
}
