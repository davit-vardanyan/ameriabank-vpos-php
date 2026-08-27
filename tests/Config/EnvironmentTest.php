<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Config;

use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Enum\Language;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Environment is the only place in the package that knows a host name, so a
 * mistake here does not produce a failing request — it produces a request sent
 * somewhere else. Two of the failure modes are silent in exactly that way: a
 * host swapped between the two cases sends live traffic to the sandbox or, far
 * worse, sandbox traffic to the live gateway, and both would return perfectly
 * ordinary-looking responses.
 *
 * Every URL below is therefore asserted as a whole string with assertSame, and
 * both environments are asserted for every method. Asserting only the Test
 * environment — the only one any probe has ever reached — would leave the
 * Production strings, which are transcribed and unverified (CONVENTIONS.md
 * §13), as the only part of this class nothing holds.
 *
 * Each assertion was written against a named mutation of
 * src/Config/Environment.php, applied and observed red before being kept.
 */
#[CoversClass(Environment::class)]
#[UsesClass(Language::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(ValidationException::class)]
final class EnvironmentTest extends TestCase
{
    /**
     * A PaymentID of the shape the gateway has been observed to emit: an
     * uppercase 36-character GUID (CONVENTIONS.md §4.12).
     *
     * Invented, not observed. It is the same literal tests/Exception uses,
     * kept identical so that the one made-up PaymentID in this suite stays
     * one; review traced it and confirmed it appears nowhere in the
     * recorded sandbox responses. A PaymentID is on neither CONVENTIONS.md
     * §6's forbidden list nor its credential list, but a value copied out of a
     * real run is still a value from a real run, and nothing here needs one.
     */
    private const string PAYMENT_ID = 'C2E51643-0922-4442-A80C-30ADAE03BECC';

    /**
     * The three languages the payment page accepts, with the full URL each
     * produces on the Test host.
     *
     * The whole URL is carried per row rather than just the suffix, so a row
     * cannot be satisfied by a method that builds the query string correctly
     * onto the wrong base.
     *
     * @return array<string, array{Language, string}>
     */
    public static function paymentPageLanguages(): array
    {
        $base = 'https://servicestest.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=';

        return [
            'Armenian' => [Language::Armenian, $base . 'am'],
            'Russian' => [Language::Russian, $base . 'ru'],
            'English' => [Language::English, $base . 'en'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankStrings(): array
    {
        return [
            'empty' => [''],
            'whitespace-only' => ["  \t "],
        ];
    }

    /**
     * The REST root of each environment, trailing slash included.
     *
     * The slash is part of the value rather than something callers append, so
     * dropping it does not produce a broken URL that fails loudly — it produces
     * `.../VPOSapi/VPOS/InitPayment`, a 404 whose cause is two methods away
     * from where it is read.
     */
    public function testRestBaseUrlIsTheHostOfItsOwnEnvironmentAndEndsInASlash(): void
    {
        self::assertSame('https://servicestest.ameriabank.am/VPOS/', Environment::Test->restBaseUrl());
        self::assertSame('https://services.ameriabank.am/VPOS/', Environment::Production->restBaseUrl());
    }

    /**
     * The doubled VPOS is correct and this is what keeps it.
     *
     * `api-surface.json` — the specification of record under CONVENTIONS.md §2
     * — lists the routes as `POST api/VPOS/InitPayment`, relative to a base
     * that already ends in `/VPOS/`. It reads like a typo, which is precisely
     * why an exact assertion is needed: a future reader tidying it to `api/`
     * would otherwise find the suite green.
     */
    public function testApiUrlPutsTheOperationUnderTheDoubledVposPath(): void
    {
        self::assertSame(
            'https://servicestest.ameriabank.am/VPOS/api/VPOS/InitPayment',
            Environment::Test->apiUrl('InitPayment'),
        );
        self::assertSame(
            'https://services.ameriabank.am/VPOS/api/VPOS/InitPayment',
            Environment::Production->apiUrl('InitPayment'),
        );
    }

    /**
     * The SOAP reporting endpoint is a different host from REST in both
     * environments, and a complete URL rather than a base.
     *
     * The two hosts are one prefix apart — `testpayments` against `payments` —
     * which is the pair most likely to be transposed by an edit that "fixes"
     * one of them, and the pair whose transposition would point sandbox
     * reporting at the live gateway.
     */
    public function testReportingSoapUrlIsItsOwnHostInEachEnvironment(): void
    {
        self::assertSame(
            'https://testpayments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc',
            Environment::Test->reportingSoapUrl(),
        );
        self::assertSame(
            'https://payments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc',
            Environment::Production->reportingSoapUrl(),
        );
    }

    /**
     * The payment page URL, in full, for each of the three languages.
     *
     * `lang` is not optional decoration, and the reason is narrower than it
     * looks. What the page renders *in* has never been recorded: case L2 opened
     * it at `lang=en`, the card form rendered and a card was charged, so that
     * spelling is confirmed **harmless** rather than confirmed to have selected
     * English, and `am` and `ru` have never been sent at all (CONVENTIONS.md
     * §13). What makes the parameter load-bearing here is the published shape —
     * §4.13 publishes the page as `Pay?id=…&lang={am|ru|en}` — and the fact that
     * dropping it produces a URL that still resolves, so nothing but this
     * assertion would report its loss.
     */
    #[DataProvider('paymentPageLanguages')]
    public function testThePaymentPageUrlCarriesTheIdAndTheLanguage(Language $language, string $expected): void
    {
        self::assertSame($expected, Environment::Test->paymentPageUrl(self::PAYMENT_ID, $language));
    }

    /**
     * The Production payment page, asserted separately so both environments are
     * covered for this method too.
     */
    public function testThePaymentPageUrlUsesTheProductionHostOnProduction(): void
    {
        self::assertSame(
            'https://services.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=en',
            Environment::Production->paymentPageUrl(self::PAYMENT_ID, Language::English),
        );
    }

    /**
     * The PaymentID is percent-encoded, so it cannot reshape the URL.
     *
     * The needle is chosen to be the attack rather than a curiosity: an
     * unencoded `&lang=ru` inside the ID would append a second `lang` parameter
     * ahead of the real one, and a server that reads the first occurrence would
     * show the customer a page in a language the caller did not choose. The
     * same mechanism appends any parameter, to any handler, from a value the
     * SDK received from the gateway.
     *
     * The ID's shape is deliberately not validated beyond blankness — the
     * 36-character GUID is observed, not contracted (CONVENTIONS.md §4.12) —
     * so encoding is the whole of the defence.
     */
    public function testThePaymentIdIsPercentEncodedRatherThanValidated(): void
    {
        self::assertSame(
            'https://servicestest.ameriabank.am/VPOS/Payments/Pay?id=not%20a%20guid%26lang%3Dru&lang=en',
            Environment::Test->paymentPageUrl('not a guid&lang=ru', Language::English),
        );
    }

    /**
     * The operation is percent-encoded on the same terms, and is not trimmed.
     *
     * Every operation the manifest names is plain ASCII letters, so the
     * encoding is an identity transform on all of them and no ordinary call can
     * observe it. This is the call that observes it. The surrounding spaces
     * survive as %20 rather than being trimmed away, which is the honest
     * behaviour: trim() decides whether the argument is blank, it does not
     * silently rewrite an argument the caller passed.
     */
    public function testTheOperationIsPercentEncodedAndNotTrimmed(): void
    {
        self::assertSame(
            'https://servicestest.ameriabank.am/VPOS/api/VPOS/%20InitPayment%20',
            Environment::Test->apiUrl(' InitPayment '),
        );
    }

    /**
     * A blank PaymentID throws instead of building `Pay?id=&lang=en`.
     *
     * Not hypothetical: a failed InitPayment answers with `"PaymentID": ""`,
     * an empty string and never null (CONVENTIONS.md §4.12), and HTTP 200
     * carries no business meaning (§4.1). A caller who skipped the response
     * code check holds an ordinary-looking empty string and would send a
     * customer to a broken page. The whitespace row is what pins trim() in
     * this guard.
     */
    #[DataProvider('blankStrings')]
    public function testABlankPaymentIdIsRejected(string $paymentId): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field "PaymentID" must not be blank.');

        Environment::Test->paymentPageUrl($paymentId, Language::English);
    }

    /**
     * The optional card-form selector, for each of the three languages.
     *
     * `type` goes after `lang`, not before it, and the value on the wire is the
     * enum's backing integer rather than its case name. Both are asserted by
     * carrying the whole URL per row: a `&type=ApplePay` or a `type` inserted
     * ahead of `lang` would satisfy any assertion looser than this one.
     *
     * This is the weakest-sourced claim in the package. The parameter is in
     * neither the vendor PDF nor `api-surface.json`; its only source is a
     * third-party Laravel package's documentation, and no probe has ever sent
     * it (CONVENTIONS.md §13). It used not to be sendable — the sandbox payment
     * page did not render — and now it is: a payment completed through that page
     * on probe cases P1 through P6. So the gap is that nobody has tried, which
     * is a different thing from nobody being able to. What is held here is still
     * only that the SDK builds the URL the way that source describes — nothing
     * about how the gateway reads it.
     *
     * @todo unverified — see CONVENTIONS.md §13 (the `type` query parameter: now testable, still untested)
     */
    #[DataProvider('paymentPageLanguages')]
    public function testThePaymentPageUrlAppendsTheChosenCardFormAfterTheLanguage(Language $language, string $expected): void
    {
        self::assertSame(
            $expected . '&type=13',
            Environment::Test->paymentPageUrl(self::PAYMENT_ID, $language, PaymentType::ApplePay),
        );
    }

    /**
     * A second payment type, so the suffix cannot be a constant.
     *
     * `5` is the Visa/MasterCard/ArCa form and `13` opens Apple Pay, per the
     * same third-party source. One row alone would be satisfied by a hard-coded
     * `&type=13`.
     *
     * @todo unverified — see CONVENTIONS.md §13 (the `type` query parameter: now testable, still untested)
     */
    public function testTheCardFormSuffixIsTheChosenTypeAndNotAConstant(): void
    {
        $base = 'https://servicestest.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=en';

        self::assertSame($base . '&type=5', Environment::Test->paymentPageUrl(self::PAYMENT_ID, Language::English, PaymentType::MainRest));
        self::assertSame($base . '&type=1', Environment::Test->paymentPageUrl(self::PAYMENT_ID, Language::English, PaymentType::Arca));
        self::assertSame($base . '&type=0', Environment::Test->paymentPageUrl(self::PAYMENT_ID, Language::English, PaymentType::None));
    }

    /**
     * Omitting the type appends nothing, rather than an empty `&type=`.
     *
     * An empty value is a value, and this SDK has no idea how the page reads
     * one. The null row is asserted explicitly as well as through the default,
     * because passing null and omitting the argument are two call sites and a
     * future edit could distinguish them.
     *
     * The explicit null arrives through a variable rather than as a literal,
     * and that is load-bearing rather than cosmetic. `RemoveNullArgOnNullDefault
     * ParamRector` deletes a literal null sitting in a parameter's own default
     * position — it is dead at the language level — which would collapse this
     * row into a byte-identical duplicate of the one above it and delete the
     * distinction the test exists to draw, while leaving the suite green. A
     * variable is not a `ConstFetch`, so the rule does not fire, and it is the
     * shape a real caller has anyway: a `?PaymentType` held in a variable and
     * unset for this merchant, not a hand-typed `null`.
     */
    public function testNoCardFormAppendsNothingAtAll(): void
    {
        $expected = 'https://servicestest.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=en';

        $noCardFormConfigured = null;

        self::assertSame($expected, Environment::Test->paymentPageUrl(self::PAYMENT_ID, Language::English));
        self::assertSame($expected, Environment::Test->paymentPageUrl(self::PAYMENT_ID, Language::English, $noCardFormConfigured));
    }

    /**
     * The card form is appended on Production too.
     *
     * Both environments are asserted for every method in this file, for the
     * reason its class docblock gives: the Production strings are transcribed
     * and unverified (CONVENTIONS.md §13), so nothing else holds them.
     *
     * @todo unverified — see CONVENTIONS.md §13 (the `type` query parameter: now testable, still untested)
     */
    public function testTheCardFormIsAppendedOnProductionToo(): void
    {
        self::assertSame(
            'https://services.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=ru&type=6',
            Environment::Production->paymentPageUrl(self::PAYMENT_ID, Language::Russian, PaymentType::BindingMainRest),
        );
    }

    /**
     * A blank PaymentID is still refused when a card form is chosen.
     *
     * The guard runs before anything is concatenated, so adding the parameter
     * did not create a path around it — which is exactly the kind of regression
     * an optional argument introduces, since the new argument is the one every
     * new call site passes.
     */
    #[DataProvider('blankStrings')]
    public function testABlankPaymentIdIsRejectedEvenWithACardForm(string $paymentId): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field "PaymentID" must not be blank.');

        Environment::Test->paymentPageUrl($paymentId, Language::English, PaymentType::ApplePay);
    }

    /**
     * A blank operation throws rather than producing a URL ending in the base
     * path, which the gateway would answer with an ASP.NET error page rather
     * than a structured response.
     */
    #[DataProvider('blankStrings')]
    public function testABlankOperationIsRejected(string $operation): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field "operation" must not be blank.');

        Environment::Test->apiUrl($operation);
    }
}
