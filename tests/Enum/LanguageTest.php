<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Enum;

use DavitVardanyan\AmeriabankVpos\Enum\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Language is not a modelled type: it is the `lang` query parameter on
 * {base}Payments/Pay, so its members come from the vendor PDF, which
 * CONVENTIONS.md §2 ranks non-authoritative.
 *
 * The payment page renders now, and one of the three spellings is exercised:
 * case L2 opened it at a URL carrying `lang=en`, the form rendered and a card
 * was charged (CONVENTIONS.md §4.24). Read that narrowly — what the page
 * rendered *in* was not recorded, so `lang=en` is observed harmless rather than
 * observed to select English, and `am` and `ru` have never been sent (§13).
 * What is pinned here is therefore still only the spelling that goes into a
 * URL, and a wrong spelling there is a payment page the customer cannot read.
 */
#[CoversClass(Language::class)]
final class LanguageTest extends TestCase
{
    /**
     * @return array<string, array{Language, string}>
     */
    public static function languages(): array
    {
        return [
            'Armenian' => [Language::Armenian, 'am'],
            'Russian' => [Language::Russian, 'ru'],
            'English' => [Language::English, 'en'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedTags(): array
    {
        return [
            'unsupported language' => ['fr'],
            'uppercase' => ['EN'],
            'ISO 639-1 for Armenian' => ['hy'],
            'locale form' => ['en-US'],
            'empty' => [''],
        ];
    }

    /**
     * The backing value is the two-letter tag the gateway expects, not the
     * ISO 639-1 code for the language. Armenian is 'am' here and 'hy' in
     * ISO 639-1; substituting the standard code produces a URL the gateway
     * does not understand, and nothing else in the suite would notice.
     */
    #[DataProvider('languages')]
    public function testTheBackingValueIsTheTagTheGatewayExpects(Language $language, string $tag): void
    {
        self::assertSame(
            $tag,
            $language->value,
            sprintf('Language::%s must serialise as "%s".', $language->name, $tag),
        );
    }

    /**
     * The provider is hand-maintained, so it needs a completeness check.
     */
    public function testTheLanguageProviderCoversEveryCase(): void
    {
        $inProvider = array_keys(self::languages());
        $onEnum = array_map(static fn(Language $case): string => $case->name, Language::cases());

        sort($inProvider);
        sort($onEnum);

        self::assertSame($onEnum, $inProvider, 'languages() must list every Language case, and no other.');
    }

    /**
     * An unsupported tag degrades to null so the caller can fall back, rather
     * than throwing from from(). CONVENTIONS.md §4.6.
     */
    #[DataProvider('unsupportedTags')]
    public function testUnsupportedTagsDegradeToNull(string $tag): void
    {
        self::assertNull(
            Language::tryFrom($tag),
            sprintf('Language must not claim to know the tag "%s".', $tag),
        );
    }
}
