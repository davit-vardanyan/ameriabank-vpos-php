<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Enum;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

#[CoversClass(Currency::class)]
final class CurrencyTest extends TestCase
{
    /**
     * Each currency with its ISO 4217 numeric code and exponent, spelled out.
     *
     * The exponent is repeated per row rather than folded into a loop over
     * cases(). A loop asserting "the exponent is the same for all four", or
     * worse ">= 0", passes when an arm returns 0 for AMD — and that single arm
     * is a factor-of-100 error on every stored amount, in the one currency this
     * gateway actually settles in. Four rows that each name their own number
     * cannot express that mistake.
     *
     * All four are 2 by owner decision, ratified 2026-08-24: the internal
     * representation follows ISO, not Armenian pricing custom. See
     * CONVENTIONS.md §4.7.
     *
     * @return array<string, array{Currency, string, int}>
     */
    public static function currencies(): array
    {
        return [
            'AMD' => [Currency::AMD, '051', 2],
            'EUR' => [Currency::EUR, '978', 2],
            'USD' => [Currency::USD, '840', 2],
            'RUB' => [Currency::RUB, '643', 2],
        ];
    }

    /**
     * Codes no member uses, including the alpha spellings.
     *
     * 'AMD' and 'USD' are here on purpose. The wire carries the ISO 4217
     * *numeric* string — probe-verified for both AMD ("051") and USD ("840") —
     * so an alpha code arriving from anywhere is a value this SDK does not
     * know, not a currency it recognises under another name.
     *
     * @return array<string, array{string}>
     */
    public static function unknownCodes(): array
    {
        return [
            'reserved 999' => ['999'],
            'unlisted numeric' => ['826'],
            'alpha spelling of a known member' => ['AMD'],
            'alpha spelling of another member' => ['USD'],
            'unpadded numeric' => ['51'],
            'empty' => [''],
        ];
    }

    #[DataProvider('currencies')]
    public function testExponentIsTheIsoExponent(Currency $currency, string $code, int $exponent): void
    {

        self::assertSame(
            $exponent,
            $currency->exponent(),
            sprintf('Currency::%s must expose the ISO 4217 exponent %d.', $currency->name, $exponent),
        );
    }

    /**
     * The backing value is the numeric code, not the alpha code.
     *
     * Asserted because the enum's case names are the alpha codes, so nothing
     * else in the suite would notice AMD being rebacked as 'AMD' — which would
     * serialise a currency the gateway does not accept while every name-based
     * assertion stayed green.
     */
    #[DataProvider('currencies')]
    public function testTheBackingValueIsTheIsoNumericCode(
        Currency $currency,
        string $code,
        int $exponent,
    ): void {

        self::assertSame($code, $currency->value);
    }

    /**
     * The provider is hand-maintained, so it needs a completeness check.
     *
     * Without one a fifth currency added later is exempt from the exponent
     * assertion, which is the only assertion standing between an amount and a
     * hundredfold error.
     */
    public function testTheCurrencyProviderCoversEveryCase(): void
    {
        $inProvider = array_keys(self::currencies());
        $onEnum = array_map(static fn(Currency $case): string => $case->name, Currency::cases());

        sort($inProvider);
        sort($onEnum);

        self::assertSame($onEnum, $inProvider, 'currencies() must list every Currency case, and no other.');
    }

    /**
     * Asserted through cases() rather than against the literal Currency::AMD.
     *
     * With treatPhpDocTypesAsCertain the analyser folds default() to its return
     * expression, so a direct assertSame(Currency::AMD, Currency::default()) is
     * a comparison of two identical constants that PHPStan can discharge
     * statically. Looking the case up by name at runtime keeps the assertion a
     * runtime fact, and pins the name and the backing value together so
     * returning a different member cannot pass.
     */
    public function testDefaultIsTheDramBecauseThatIsWhatTheGatewaySubstitutes(): void
    {
        $default = Currency::default();

        self::assertSame('AMD', $default->name);
        self::assertSame('051', $default->value);
        self::assertSame(2, $default->exponent());
    }

    /**
     * Currency is optional in a request and the gateway defaults it (§4.12),
     * so a response may carry a code this SDK has never seen. It must degrade
     * to null rather than throw — CONVENTIONS.md §4.6.
     */
    #[DataProvider('unknownCodes')]
    public function testUnknownCodesDegradeToNull(string $code): void
    {
        self::assertNull(
            Currency::tryFrom($code),
            sprintf('Currency must not claim to know the code "%s".', $code),
        );
    }
}
