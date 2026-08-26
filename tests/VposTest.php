<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests;

use Closure;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Enum\Language;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Http\FailureRedactor;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Http\RedactedNetworkException;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;
use DavitVardanyan\AmeriabankVpos\Request\PaymentDetailsRequest;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Http\Client\Exception\NetworkException;
use Http\Discovery\ClassDiscovery;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\NullLogger;
use ReflectionClassConstant;
use ReflectionProperty;

use function sprintf;

/**
 * The composition root: what it wires, what it hands back, and the one URL it
 * answers without a network.
 *
 * `Vpos` builds one transport and gives the same instance to three clients, so
 * the failures worth guarding are wiring failures rather than logic ones — a
 * sub-client rebuilt per call, a constructor argument dropped on the way to the
 * transport, a discovery failure escaping as somebody else's exception type.
 * Each of those is invisible at the call site and expensive at run time.
 *
 * Every credential literal here is an obviously-fake placeholder. The
 * sandbox's own credentials live outside this repository (CONVENTIONS.md §8).
 */
#[CoversClass(Vpos::class)]
#[UsesClass(BindingsClient::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(Environment::class)]
#[UsesClass(FailureRedactor::class)]
#[UsesClass(HttpTransport::class)]
#[UsesClass(Language::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(PaymentDetailsRequest::class)]
#[UsesClass(PaymentsClient::class)]
#[UsesClass(RedactedNetworkException::class)]
#[UsesClass(Redactor::class)]
#[UsesClass(ReportsClient::class)]
#[UsesClass(TransportException::class)]
#[UsesClass(ValidationException::class)]
final class VposTest extends TestCase
{
    private const string CLIENT_ID = 'client-x';

    private const string USERNAME = 'user-x';

    private const string PASSWORD = 'pw-x';

    /**
     * The same invented uppercase GUID the rest of the suite uses
     * (CONVENTIONS.md §4.12 records the shape; review confirmed the
     * literal appears nowhere in the recorded sandbox responses).
     */
    private const string PAYMENT_ID = 'C2E51643-0922-4442-A80C-30ADAE03BECC';

    private Client $client;

    private Psr17Factory $psr17;

    /**
     * Every pause the transport asked for, in microseconds.
     *
     * @var list<int>
     */
    private array $pauses = [];

    /**
     * Each accessor, and the type it must return.
     *
     * Carried as closures rather than as method names so the call is a real
     * call that PHPStan checks, not a string dispatch that would still pass if
     * the method were renamed.
     *
     * @return array<string, array{Closure(Vpos): object, class-string}>
     */
    public static function subClientAccessors(): array
    {
        return [
            'payments' => [static fn(Vpos $vpos): PaymentsClient => $vpos->payments(), PaymentsClient::class],
            'bindings' => [static fn(Vpos $vpos): BindingsClient => $vpos->bindings(), BindingsClient::class],
            'reports' => [static fn(Vpos $vpos): ReportsClient => $vpos->reports(), ReportsClient::class],
        ];
    }

    /**
     * The three payment page languages, each with the full URL it must produce
     * on the Test host.
     *
     * The whole URL rather than the suffix, so a row cannot be satisfied by a
     * correct query string appended to the wrong base.
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

    protected function setUp(): void
    {
        $this->client = new Client();
        $this->psr17 = new Psr17Factory();
        $this->pauses = [];
    }

    public function testTargetsProtocolVersion31(): void
    {
        $version = new ReflectionClassConstant(Vpos::class, 'PROTOCOL_VERSION');

        self::assertSame('3.1', $version->getValue());
    }

    /**
     * Each accessor returns an instance of its own client.
     *
     * Asserted by `::class` rather than assertInstanceOf: the three clients are
     * unrelated final classes today, and `::class` stays exact if one of them
     * ever gains a subtype.
     *
     * @param Closure(Vpos): object $accessor
     * @param class-string          $expected
     */
    #[DataProvider('subClientAccessors')]
    public function testEachAccessorReturnsItsOwnClient(Closure $accessor, string $expected): void
    {
        self::assertSame($expected, $accessor($this->vpos())::class);
    }

    /**
     * The sub-clients are the instances built in the constructor, not new ones
     * per call.
     *
     * Identity, not equality. Two freshly built PaymentsClients wrapping the
     * same transport are `==` and would satisfy a looser assertion, while a
     * consumer that stored `$vpos->payments()` and compared it later would find
     * out otherwise. And the cost of rebuilding is not only allocation: each
     * accessor would have to construct its client from a transport, which is
     * the moment a future edit reaches for a *new* transport and quietly gives
     * one client its own logger, redactor and attempt budget.
     *
     * @param Closure(Vpos): object $accessor
     * @param class-string          $expected
     */
    #[DataProvider('subClientAccessors')]
    public function testEachAccessorIsMemoised(Closure $accessor, string $expected): void
    {
        $vpos = $this->vpos();

        self::assertSame($accessor($vpos), $accessor($vpos), sprintf('%s is rebuilt on every call.', $expected));
    }

    /**
     * All three clients share one transport.
     *
     * The other half of that property, and the half that matters at run time:
     * one transport means one logger, one redactor and one attempt budget
     * across the whole surface. Three transports would still pass every
     * behavioural test in this suite and would differ only in a consumer's log
     * output and in how many times a retryable call is attempted.
     */
    public function testAllThreeClientsShareOneTransport(): void
    {
        $vpos = $this->vpos();

        $payments = $this->transportOf($vpos->payments());
        $bindings = $this->transportOf($vpos->bindings());
        $reports = $this->transportOf($vpos->reports());

        self::assertSame($payments, $bindings);
        self::assertSame($payments, $reports);
    }

    /**
     * The payment page URL for each of the three languages, in full.
     *
     * `Vpos::paymentPageUrl()` delegates to Environment rather than rebuilding
     * the URL, and that delegation is what is asserted here — the same
     * expectations appear in tests/Config/EnvironmentTest.php against
     * Environment directly, so a Vpos that started composing its own URL would
     * have to reproduce every one of them to stay green.
     */
    #[DataProvider('paymentPageLanguages')]
    public function testThePaymentPageUrlCarriesTheIdAndTheLanguage(Language $language, string $expected): void
    {
        self::assertSame($expected, $this->vpos()->paymentPageUrl(self::PAYMENT_ID, $language));
    }

    /**
     * English is the default, and it is a real default rather than an absent
     * parameter.
     *
     * A `lang` that went missing would still produce a URL the gateway
     * resolves, so nothing but an exact assertion reports its loss.
     */
    public function testThePaymentPageUrlDefaultsToEnglish(): void
    {
        self::assertSame(
            'https://servicestest.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=en',
            $this->vpos()->paymentPageUrl(self::PAYMENT_ID),
        );
    }

    /**
     * The optional card-form selector appends `&type={backing int}`, and
     * omitting it appends nothing at all.
     *
     * Two payment types are asserted rather than one, because a single row
     * would be satisfied by a hard-coded suffix. `13` is Apple Pay and `5` the
     * Visa/MasterCard/ArCa form, per the third-party documentation this
     * parameter's only claim comes from.
     *
     * This is the weakest-sourced behaviour in the package: not in the vendor
     * PDF, not in `api-surface.json`, and never sent by any probe
     * (CONVENTIONS.md §13). What is asserted here is only that the SDK builds
     * the URL the way that source describes.
     *
     * The third row passes the unset card form through a variable rather than
     * as a literal `null`, and that is load-bearing rather than cosmetic.
     * `RemoveNullArgOnNullDefaultParamRector` deletes a literal null sitting in
     * a parameter's own default position — it is dead at the language level —
     * which would collapse this row into the argument-omitted assertion in
     * testThePaymentPageUrlDefaultsToEnglish() and delete the distinction
     * without failing anything. A variable is not a `ConstFetch`, so the rule
     * does not fire, and it is the shape a real caller has anyway: a
     * `?PaymentType` held in a variable, unset for this merchant.
     *
     * @todo unverified — see CONVENTIONS.md §13 (the `type` query parameter: now testable, still untested)
     */
    public function testThePaymentPageUrlAppendsTheCardFormWhenOneIsChosen(): void
    {
        $base = 'https://servicestest.ameriabank.am/VPOS/Payments/Pay?id=' . self::PAYMENT_ID . '&lang=en';
        $vpos = $this->vpos();

        $noCardFormConfigured = null;

        self::assertSame($base . '&type=13', $vpos->paymentPageUrl(self::PAYMENT_ID, Language::English, PaymentType::ApplePay));
        self::assertSame($base . '&type=5', $vpos->paymentPageUrl(self::PAYMENT_ID, Language::English, PaymentType::MainRest));
        self::assertSame($base, $vpos->paymentPageUrl(self::PAYMENT_ID, Language::English, $noCardFormConfigured));
    }

    /**
     * A blank PaymentID throws instead of building a broken page.
     *
     * Not hypothetical: a failed InitPayment answers with `"PaymentID": ""` —
     * empty string, never null (CONVENTIONS.md §4.12) — and HTTP 200 carries
     * no business meaning (§4.1), so a caller who skipped the response code
     * holds an ordinary-looking empty string. The whitespace row is what pins
     * trim().
     */
    #[DataProvider('blankPaymentIds')]
    public function testABlankPaymentIdIsRejected(string $paymentId): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field "PaymentID" must not be blank.');

        $this->vpos()->paymentPageUrl($paymentId, Language::English);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankPaymentIds(): array
    {
        return [
            'empty' => [''],
            'whitespace-only' => ["  \t "],
        ];
    }

    /**
     * A failed discovery surfaces as ConfigurationException, not as a bare
     * PSR-18 or `php-http/discovery` error.
     *
     * This package is PSR-18 abstract and the consumer supplies the client
     * (CONVENTIONS.md §5), so "no HTTP client installed" is a configuration
     * mistake and must be catchable through the package's own marker interface
     * — a consumer writing `catch (VposExceptionInterface)` around their
     * payment call must not have a `Http\Discovery\Exception` escape it.
     *
     * Discovery is disabled by emptying its strategy list, which is global
     * state, so the original list is restored whatever happens.
     */
    public function testADiscoveryFailureIsAConfigurationError(): void
    {
        $strategies = [...ClassDiscovery::getStrategies()];

        try {
            ClassDiscovery::setStrategies([]);

            $this->assertDiscoveryFailure('PSR-18 HTTP client', null, null);
            $this->assertDiscoveryFailure('PSR-17 request factory', $this->client, null);

            // The other half of the statement: with the collaborators injected,
            // discovery is never consulted. Asserted while it is still
            // disabled, so an implementation that reached for discovery first
            // would fail here rather than quietly ignoring what was passed.
            $vpos = new Vpos(
                credentials: $this->credentials(),
                environment: Environment::Test,
                httpClient: $this->client,
                requestFactory: $this->psr17,
                streamFactory: $this->psr17,
            );

            self::assertSame($this->client, $this->psrClientOf($vpos));
        } finally {
            ClassDiscovery::setStrategies($strategies);
        }
    }

    /**
     * An out-of-range attempt budget is refused at construction.
     *
     * The bound is the transport's and is not restated here; what this asserts
     * is that Vpos forwards the argument rather than swallowing it, so a
     * consumer's mistake is reported where they made it instead of on their
     * first retryable call.
     */
    public function testAnOutOfRangeAttemptBudgetIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        new Vpos(
            credentials: $this->credentials(),
            environment: Environment::Test,
            httpClient: $this->client,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: new NullLogger(),
            maxAttempts: 6,
        );
    }

    /**
     * The default attempt budget is three, observed by counting dispatches
     * rather than by reading a property.
     *
     * A property assertion would hold the number; this holds what the number
     * *does*. `GetPaymentDetails` is read-only and therefore retryable
     * (CONVENTIONS.md §4.5), so a network failure is attempted the full budget
     * of times and then reported as a TransportException — and a Vpos that
     * forwarded a different default, or none, sends a different count.
     *
     * The transport's pause is replaced by a recorder for the duration, the
     * same seam tests/Http uses, so the suite observes the backoff instead of
     * spending it.
     */
    public function testTheDefaultAttemptBudgetIsForwardedToTheTransport(): void
    {
        $vpos = $this->vpos();
        $this->silencePauses($this->transportOf($vpos->payments()));

        $this->client->setDefaultException(
            new NetworkException('Connection timed out', $this->psr17->createRequest('POST', 'https://example.invalid/')),
        );

        try {
            $vpos->payments()->details(self::PAYMENT_ID);
            self::fail('A network failure on every attempt must not return.');
        } catch (TransportException $failure) {
            self::assertStringContainsString('GetPaymentDetails', $failure->getMessage());
        }

        self::assertCount(3, $this->client->getRequests(), 'The default attempt budget did not reach the transport.');

        // Two pauses for three attempts. A budget that arrived as something
        // else would show up here as well as in the dispatch count, and the
        // backoff is the half a request count cannot see.
        self::assertCount(2, $this->pauses);
    }

    /**
     * $expected is the collaborator whose absence must be named: a consumer
     * reading the message has to learn *which* package to install.
     */
    private function assertDiscoveryFailure(
        string $expected,
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
    ): void {
        try {
            new Vpos(
                credentials: $this->credentials(),
                environment: Environment::Test,
                httpClient: $httpClient,
                requestFactory: $requestFactory,
            );
            self::fail('Discovery cannot succeed with no strategies configured.');
        } catch (ConfigurationException $failure) {
            self::assertStringContainsString($expected, $failure->getMessage());
        }
    }

    /**
     * The PSR-18 client the transport behind $vpos is actually holding.
     */
    private function psrClientOf(Vpos $vpos): ClientInterface
    {
        $client = (new ReflectionProperty(HttpTransport::class, 'client'))
            ->getValue($this->transportOf($vpos->payments()));

        self::assertInstanceOf(ClientInterface::class, $client);

        return $client;
    }

    private function credentials(): Credentials
    {
        return new Credentials(self::CLIENT_ID, self::USERNAME, self::PASSWORD);
    }

    private function vpos(): Vpos
    {
        return new Vpos(
            credentials: $this->credentials(),
            environment: Environment::Test,
            httpClient: $this->client,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: new NullLogger(),
        );
    }

    /**
     * The transport a sub-client is holding.
     *
     * By reflection, because there is no accessor and there must not be one:
     * HttpTransport is `@internal` (CONVENTIONS.md §5) and tests/Client
     * asserts structurally that no public signature names it. A test can reach
     * it and a consumer cannot, which is the whole point.
     */
    private function transportOf(object $client): HttpTransport
    {
        $transport = (new ReflectionProperty($client::class, 'transport'))->getValue($client);

        self::assertInstanceOf(HttpTransport::class, $transport);

        return $transport;
    }

    /**
     * Replaces the transport's backoff with a recorder, so the suite observes
     * the pauses instead of spending them.
     *
     * The seam is private and has no constructor parameter, the same asymmetry
     * tests/Http relies on: a test can reach it and a consumer cannot, which is
     * the difference between an observable delay and a switch for turning
     * backoff off.
     */
    private function silencePauses(HttpTransport $transport): void
    {
        (new ReflectionProperty(HttpTransport::class, 'sleeper'))->setValue(
            $transport,
            function (int $microseconds): void {
                $this->pauses[] = $microseconds;
            },
        );
    }
}
