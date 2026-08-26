<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Money;

use function count;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

use function sprintf;
use function strlen;

/**
 * A monetary amount, held as an integer count of minor units plus a currency.
 *
 * No IEEE 754 value ever touches money here. The gateway's .NET deserialiser
 * accepts "10", "10.00", "10.0", 10 and 10.0 interchangeably (CONVENTIONS.md
 * §4.7), so the SDK is free to choose its wire form; the only safe choice is a
 * decimal string built by integer and string arithmetic, which is what
 * toDecimalString() produces. There is no bcmath dependency and none is needed.
 *
 * The prose in this file deliberately avoids naming the inexact numeric type,
 * the arithmetic that discards digits, and the formatting helpers that do both.
 * A test greps this file for those words, and the guard is textual by design:
 * it cannot distinguish a comment from a cast, and that bluntness is what keeps
 * it from failing open — every line of cleverness added to it is a line that
 * can. Same trade as the from() guard in tests/Enum. Write prose that steps
 * past those words rather than weakening the guard. If the guard ever needs
 * widening again, widen it by tokenising — token_get_all() with a T_DIV check —
 * rather than by adding more strings to the pattern: reading code as code also
 * ends the guard's dependence on this file's own docblocks never naming the
 * things they describe.
 *
 * Scale comes from Currency::exponent(), the ISO 4217 exponent, which is 2 for
 * all four currencies — including AMD, which is subdivided into 100 luma even
 * though Armenian prices are quoted in whole drams. Whether the gateway accepts
 * a fractional amount is unverified: probe A7.1 sent 10.55 AMD and was rejected
 * by the sandbox's blanket "must be 10 AMD" rule, which fires before any
 * precision check, so it says nothing either way.
 *
 * Vocabulary is load-bearing and settled: an exponent is a scale, a minor unit
 * count is a quantity, and no symbol here means both.
 *
 * The constructor is private. fromMinorUnits(1000, Currency::AMD) is
 * unmistakable at the call site as ten drams rather than a thousand, which a
 * bare constructor taking an int would not be.
 *
 * @todo unverified — see CONVENTIONS.md §13 (fractional amounts on the wire)
 */
final readonly class Amount
{
    /**
     * The wire field this object serialises into, named in every rejection.
     *
     * Every rejection raised in this file passes this field name and a constant
     * expectation, and no value of its own (CONVENTIONS.md §5): a rejected
     * amount is not itself sensitive, but a validator that echoes its input is
     * one refactor away from echoing a PAN. That holds of the throw sites here,
     * not of every collaborator — ValidationException::amountNotPositive(),
     * called below, does report the count it rejected.
     */
    private const string FIELD = 'Amount';

    private function __construct(
        private int $minorUnitCount,
        private Currency $currency,
    ) {}

    /**
     * Zero and negative counts are rejected here, and every other constructor
     * routes through this one so the rule cannot drift.
     *
     * Money that does not move is not an amount: probe A7.3 sent a zero-value
     * refund and the gateway rejected it.
     *
     * @throws ValidationException
     */
    public static function fromMinorUnits(int $minorUnitCount, Currency $currency): self
    {
        if ($minorUnitCount <= 0) {
            throw ValidationException::amountNotPositive($minorUnitCount);
        }

        return new self($minorUnitCount, $currency);
    }

    /**
     * Parses a decimal string exactly, or throws. It never discards a digit.
     *
     * "10", "10.0" and "10.00" all denote the same quantity and converge on the
     * same count. The rule for the fraction is positional, not a test of
     * significance: more decimal places than Currency::exponent() allows is
     * rejected, whether or not those places carry a digit the count would lose.
     * "10.001" in a two-decimal currency is rejected because dropping its last
     * digit would misstate a transfer, and "10.000" is rejected as well, though
     * it loses nothing — accepting it would mean deciding case by case which
     * excess places are safe to absorb, and a parser that sometimes absorbs is
     * one this class cannot state a rule about.
     *
     * The overflow guard compares digit strings — by length, then with strcmp()
     * — rather than casting and inspecting the result. Both operands are ASCII
     * digits with leading zeros stripped, so lexical order is numeric order and
     * this comparison is exact by construction.
     *
     * Do not substitute PHP's numeric-string comparison: its behaviour at this
     * boundary depends on how each side is spelled. An unchecked cast is the
     * real hazard — it saturates at PHP_INT_MAX rather than failing, turning an
     * overflow into a wrong amount.
     *
     * @throws ValidationException
     */
    public static function fromDecimalString(string $value, Currency $currency): self
    {
        if ($value === '') {
            throw ValidationException::malformedValue(self::FIELD, 'a non-empty decimal string such as "10.00"');
        }

        if (trim($value) !== $value) {
            throw ValidationException::malformedValue(
                self::FIELD,
                'a decimal string with no leading or trailing whitespace',
            );
        }

        $parts = explode('.', $value);

        if (count($parts) > 2) {
            throw ValidationException::malformedValue(self::FIELD, 'at most one decimal point');
        }

        if (!self::isDigitString($parts[0])) {
            throw ValidationException::malformedValue(
                self::FIELD,
                'at least one ASCII digit before the decimal point, and nothing else — no sign, no exponent, no separator',
            );
        }

        if (count($parts) === 2 && !self::isDigitString($parts[1])) {
            throw ValidationException::malformedValue(
                self::FIELD,
                'at least one ASCII digit after the decimal point, and nothing else',
            );
        }

        $exponent = $currency->exponent();
        $fraction = $parts[1] ?? '';

        if (strlen($fraction) > $exponent) {
            throw ValidationException::malformedValue(self::FIELD, sprintf(
                'at most %d decimal places, the ISO 4217 exponent of the given currency; '
                . 'the limit is positional, so a longer fraction is rejected even when its extra places are zeros',
                $exponent,
            ));
        }

        $digits = $parts[0] . str_pad($fraction, $exponent, '0', STR_PAD_RIGHT);
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            $digits = '0';
        }

        $largest = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($largest) || (strlen($digits) === strlen($largest) && strcmp($digits, $largest) > 0)) {
            throw ValidationException::malformedValue(
                self::FIELD,
                'an amount whose minor unit count fits in a platform integer',
            );
        }

        return self::fromMinorUnits((int) $digits, $currency);
    }

    public function minorUnitCount(): int
    {
        return $this->minorUnitCount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * The wire form: a decimal string with exactly exponent decimal places.
     *
     * The exact inverse of fromDecimalString(). Five minor units at exponent 2
     * is "0.05" — the digits are padded on the left to reach the scale, never
     * on the right, and the integer part is "0" rather than empty.
     *
     * A zero-exponent currency would render with no decimal point at all. None
     * of the four supported currencies is zero-exponent, so that path is
     * unreachable today; it is written this way because the alternative emits a
     * trailing "." if one is ever added.
     */
    public function toDecimalString(): string
    {
        $exponent = $this->currency->exponent();
        $digits = str_pad((string) $this->minorUnitCount, $exponent + 1, '0', STR_PAD_LEFT);

        $integerPart = substr($digits, 0, strlen($digits) - $exponent);
        $fraction = substr($digits, strlen($digits) - $exponent);

        return $fraction === '' ? $integerPart : $integerPart . '.' . $fraction;
    }

    /**
     * Two amounts are equal when they carry the same count in the same currency.
     *
     * A currency mismatch returns false rather than throwing, and the asymmetry
     * with isGreaterThan() is deliberate: "these two are not equal" is a true
     * statement about 10 AMD and 10 USD, whereas "one is greater than the
     * other" has no answer without a rate the SDK does not have. Answering a
     * question that has an answer is not the same act as answering one that
     * does not.
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnitCount === $other->minorUnitCount;
    }

    /**
     * Orders two amounts of the same currency.
     *
     * Throws on a currency mismatch. Ordering across currencies has no answer
     * at all, so either boolean would be a lie, and a lie here silently
     * approves or blocks a transfer. See equals() for why that one does not
     * throw.
     *
     * @throws ValidationException
     */
    public function isGreaterThan(self $other): bool
    {
        if ($this->currency !== $other->currency) {
            throw ValidationException::malformedValue(
                self::FIELD,
                'two amounts in the same currency; ordering across currencies has no answer without an exchange rate',
            );
        }

        return $this->minorUnitCount > $other->minorUnitCount;
    }

    /**
     * True when $value is a non-empty run of ASCII digits and nothing else.
     *
     * Anchored with \z rather than $, which would accept a trailing newline.
     * The character class is spelled out rather than \d, which matches non-ASCII
     * digits under some configurations — a wire format is ASCII.
     */
    private static function isDigitString(string $value): bool
    {
        return preg_match('/^[0-9]+\z/', $value) === 1;
    }
}
