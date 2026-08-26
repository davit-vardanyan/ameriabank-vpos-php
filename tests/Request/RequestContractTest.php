<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Request;

use function array_keys;
use function array_unique;
use function array_values;
use function class_exists;
use function count;

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

use function file_get_contents;
use function in_array;
use function is_a;
use function is_dir;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function substr;

/**
 * The contract the transport receives, and where each answer comes from.
 *
 * The eleven request DTOs share an interface so that HttpTransport::send() can
 * take one argument instead of a union of eleven class names. Three of its
 * four methods are answers the transport cannot work out for itself, and each
 * has a different source of truth:
 *
 * - operation() is the manifest's endpoint name, checked in
 *   RequestWireFormatTest.
 * - isIdempotent() is CONVENTIONS.md §4.5, also checked there.
 * - requiresClientId() is new here, and it is the manifest's again: a request
 *   model listing `ClientID` returns true, one that does not returns false.
 *
 * That last one is checked against `api-surface.json` read at test time, never
 * against a table written here. A hand-written expectation table would be a
 * second copy of the manifest that agrees with src/ by construction and stops
 * agreeing with the bank the moment the bank changes anything — and the way it
 * would stop is silent. CONVENTIONS.md §4.12 records that the gateway ignores
 * unknown request fields, so sending `ClientID` to an operation whose model
 * does not declare it produces no error at all; and the reverse, omitting it
 * where it is required, is an authentication failure returned as HTTP 200
 * (§4.1) that looks like a credential problem rather than a scoping one.
 * Neither mistake announces itself, so neither is left to a literal.
 *
 * The split is not derivable from what the operations mean. The four addressed
 * by `PaymentID` omit `ClientID` and the seven others carry it, which reads as
 * an accident of the bank's own models — `PaymentID` is globally unique, so
 * nothing needs a merchant to scope it — but that is a story told after
 * reading the manifest, not a rule the manifest is checked against.
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
final class RequestContractTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    private const string REQUEST_DIRECTORY = __DIR__ . '/../../src/Request';

    private const string REQUEST_NAMESPACE = 'DavitVardanyan\\AmeriabankVpos\\Request\\';

    private const string CLIENT_ID_FIELD = 'ClientID';

    /**
     * CONVENTIONS.md §7 records SSNCheck as excluded from v1.0 — it is
     * unrelated to the payment lifecycle, and it carries Armenian national
     * identity data — so there is no class for it and the manifest's model for
     * it is skipped. The one scope literal in this file.
     */
    private const string UNIMPLEMENTED_OPERATION = 'SSNCheck';

    private const string PAYMENT_ID = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';

    private const string CARD_HOLDER_ID = 'holder-id-fake';

    private const string BACK_URL = 'https://merchant.example.test/callback';

    /**
     * One instance of each of the eleven, built exactly as a caller would.
     *
     * Instances rather than class names, because requiresClientId() is an
     * instance method and because a constructor that stopped accepting these
     * arguments is something this file should notice too.
     *
     * @return array<string, array{object}>
     */
    public static function requests(): array
    {
        $amount = Amount::fromDecimalString('10.00', Currency::AMD);

        return [
            'InitPayment' => [new InitPaymentRequest($amount, 1001, self::BACK_URL)],
            'GetPaymentDetails' => [new PaymentDetailsRequest(self::PAYMENT_ID)],
            'GetPaymentId' => [new GetPaymentIdRequest(1001)],
            'GetBindings' => [new GetBindingsRequest(PaymentType::MainRest)],
            'GetPendingTransactions' => [
                new GetPendingTransactionsRequest(
                    new DateTimeImmutable('2026-01-01'),
                    new DateTimeImmutable('2026-01-31'),
                ),
            ],
            'ConfirmPayment' => [new ConfirmPaymentRequest(self::PAYMENT_ID, $amount)],
            'RefundPayment' => [new RefundPaymentRequest(self::PAYMENT_ID, $amount)],
            'CancelPayment' => [new CancelPaymentRequest(self::PAYMENT_ID)],
            'MakeBindingPayment' => [
                new MakeBindingPaymentRequest(
                    self::CARD_HOLDER_ID,
                    $amount,
                    2002,
                    self::BACK_URL,
                    PaymentType::BindingMainRest,
                ),
            ],
            'ActivateBinding' => [new ActivateBindingRequest(self::CARD_HOLDER_ID, PaymentType::BindingMainRest)],
            'DeactivateBinding' => [new DeactivateBindingRequest(self::CARD_HOLDER_ID, PaymentType::BindingMainRest)],
        ];
    }

    /**
     * Every request is something HttpTransport::send() will accept.
     *
     * Typed `object` rather than RequestInterface on purpose: a signature that
     * already names the interface would have PHP reject a non-implementor
     * before the assertion ran, and the failure would be a TypeError inside
     * PHPUnit's data-provider machinery rather than this sentence.
     */
    #[DataProvider('requests')]
    public function testEveryRequestImplementsTheTransportsInputContract(object $request): void
    {
        self::assertInstanceOf(
            RequestInterface::class,
            $request,
            'HttpTransport::send() takes RequestInterface. A request that does not implement it cannot be sent at all.',
        );
    }

    /**
     * requiresClientId() is whatever the manifest says, per request model.
     *
     * The model is resolved through the manifest's own endpoint table from the
     * operation the request names, so nothing here maps a class to a model by
     * hand — the one place that mapping exists is `api-surface.json`.
     *
     * Mutation demonstrated: flipping any requiresClientId() return value in
     * src/Request/ fails this row for that operation.
     */
    #[DataProvider('requests')]
    public function testCredentialScopeIsTheManifests(object $request): void
    {
        self::assertInstanceOf(RequestInterface::class, $request);

        $models = $this->manifestModels();
        $endpoints = $this->manifestRequestModels();
        $operation = $request->operation();

        self::assertArrayHasKey(
            $operation,
            $endpoints,
            sprintf('The manifest describes no endpoint named %s.', $operation),
        );

        $model = $endpoints[$operation];

        self::assertArrayHasKey($model, $models, sprintf('The manifest describes no model named %s.', $model));

        self::assertSame(
            in_array(self::CLIENT_ID_FIELD, $models[$model], true),
            $request->requiresClientId(),
            sprintf(
                '%s::requiresClientId() disagrees with the manifest model %s. True selects '
                . 'Credentials::merchantFields() and false selects userFields(); getting it wrong '
                . 'either sends a field the gateway silently ignores (CONVENTIONS.md §4.12) or omits one '
                . 'it needs, which comes back as an authentication failure at HTTP 200 (§4.1).',
                $operation,
                $model,
            ),
        );
    }

    /**
     * Both sides of the split are populated.
     *
     * Without this, a manifest read that returned no fields at all — a moved
     * file, a renamed key, a schema change — would make every row above expect
     * false, and eleven of eleven would still be green if the DTOs happened to
     * agree. This asserts the shape of the answer rather than its content: the
     * exact counts are left unpinned here, because the manifest is allowed to
     * change and this file is not the place that decides what it says.
     */
    public function testTheCredentialSplitHasBothSides(): void
    {
        $merchant = 0;
        $user = 0;

        foreach (self::requests() as [$request]) {
            self::assertInstanceOf(RequestInterface::class, $request);

            if ($request->requiresClientId()) {
                ++$merchant;
            } else {
                ++$user;
            }
        }

        self::assertGreaterThan(0, $merchant, 'No operation requires ClientID, which cannot be right.');
        self::assertGreaterThan(0, $user, 'Every operation requires ClientID, which cannot be right either.');
        self::assertSame(count(self::requests()), $merchant + $user);
    }

    /**
     * The provider covers every in-scope request model the manifest declares.
     *
     * The rows above are only as complete as this provider, and a twelfth
     * operation added upstream would otherwise be checked by nothing. Compared
     * against the manifest's endpoint table rather than a count.
     */
    public function testEveryInScopeOperationIsCovered(): void
    {
        $expected = array_keys($this->manifestRequestModels());
        $actual = [];

        foreach (self::requests() as [$request]) {
            self::assertInstanceOf(RequestInterface::class, $request);

            $actual[] = $request->operation();
        }

        $actual = array_values(array_unique($actual));
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * No class can be added to src/Request/ without implementing the contract.
     *
     * The provider is a list this file maintains; the directory is not. A new
     * request DTO that forgot the interface would pass every row above by
     * never appearing in one, and would fail at the call site instead — which
     * is the transport, at the moment somebody tries to send it.
     */
    public function testEveryClassInTheRequestDirectoryImplementsTheContract(): void
    {
        $classes = $this->requestClasses();

        self::assertNotSame([], $classes, sprintf('Found no request classes in %s.', self::REQUEST_DIRECTORY));

        foreach ($classes as $class) {
            self::assertTrue(
                is_a($class, RequestInterface::class, true),
                sprintf('%s does not implement RequestInterface and so cannot be sent.', $class),
            );
        }
    }

    /**
     * Every DTO declares all four methods itself.
     *
     * `implements` already guarantees the methods exist — PHP will not compile
     * a class that is missing one. What it does not guarantee is that the
     * interface stays the transport's whole question: a fifth method added to
     * RequestInterface and satisfied by, say, a trait or a parent would be a
     * structure CONVENTIONS.md §5 forbids anyway, and one this package should
     * notice arriving. So the check is on the declaring class, not on
     * existence.
     */
    public function testEveryRequestDeclaresTheWholeContractItself(): void
    {
        $contract = new ReflectionClass(RequestInterface::class);
        $methods = [];

        foreach ($contract->getMethods() as $method) {
            $methods[] = $method->getName();
        }

        sort($methods);

        self::assertSame(['isIdempotent', 'operation', 'requiresClientId', 'toArray'], $methods);

        foreach ($this->requestClasses() as $class) {
            foreach ($methods as $name) {
                $declared = new ReflectionMethod($class, $name);

                self::assertSame(
                    $class,
                    $declared->getDeclaringClass()->getName(),
                    sprintf('%s does not declare %s() itself.', $class, $name),
                );
                self::assertTrue($declared->isPublic(), sprintf('%s::%s() is not public.', $class, $name));
            }
        }
    }

    /**
     * Every class under src/Request/, as a fully qualified name.
     *
     * The walk recurses, and that is the whole point of it. `scandir()` reads
     * one level, so a request class at `src/Request/Binding/Something.php` was
     * absent from this list and every contract below was silently not applied
     * to it — a silent exemption arriving through the derivation rather than
     * through a literal. This was not hypothetical: a nested class was placed
     * there and this file stayed green while it went unguarded.
     *
     * Recursing does not weaken the "a human must classify a new subject"
     * property a derived list buys. A nested class is still held to every
     * contract here, and RequestWireFormatTest still holds its operation and
     * its keys to the manifest, so an unclassified one goes red on its own
     * merits rather than on its location.
     *
     * @return list<class-string>
     */
    private function requestClasses(): array
    {
        $classes = [];

        foreach ($this->relativePhpFilesIn(self::REQUEST_DIRECTORY) as $relative) {
            $class = self::REQUEST_NAMESPACE . str_replace('/', '\\', substr($relative, 0, -4));

            self::assertTrue(class_exists($class), sprintf('%s declares no class %s.', $relative, $class));

            $classes[] = $class;
        }

        self::assertNotSame([], $classes, sprintf('No class was found under %s at all.', self::REQUEST_DIRECTORY));

        return $classes;
    }

    /**
     * Every .php file under $directory, recursively, as a path relative to it
     * and using `/` as the separator, sorted.
     *
     * Deliberately not shared with the other guards in this suite, on the
     * reasoning tests/Money's guard records: a guard that borrows another
     * guard's walker fails open the day that walker is refactored for the
     * other guard's convenience.
     *
     * @return list<string>
     */
    private function relativePhpFilesIn(string $directory): array
    {
        $entries = scandir($directory);

        self::assertNotFalse($entries, sprintf('Could not list %s.', $directory));

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
     * Model name to the list of field names the manifest declares for it.
     *
     * @return array<string, list<string>>
     */
    private function manifestModels(): array
    {
        $decoded = $this->manifest();

        self::assertArrayHasKey('models', $decoded);
        self::assertIsArray($decoded['models']);

        $models = [];

        foreach ($decoded['models'] as $name => $model) {
            self::assertIsString($name);
            self::assertIsArray($model);
            self::assertArrayHasKey('fields', $model);
            self::assertIsArray($model['fields']);

            $fields = [];

            foreach ($model['fields'] as $field) {
                self::assertIsArray($field);
                self::assertArrayHasKey('Name', $field);
                self::assertIsString($field['Name']);

                $fields[] = $field['Name'];
            }

            $models[$name] = $fields;
        }

        return $models;
    }

    /**
     * Operation name to request model name, for every operation in scope.
     *
     * @return array<string, string>
     */
    private function manifestRequestModels(): array
    {
        $decoded = $this->manifest();

        self::assertArrayHasKey('endpoints', $decoded);
        self::assertIsArray($decoded['endpoints']);

        $endpoints = [];

        foreach ($decoded['endpoints'] as $endpoint) {
            self::assertIsArray($endpoint);
            self::assertIsString($endpoint['operation'] ?? null);
            self::assertIsArray($endpoint['request'] ?? null);
            self::assertIsString($endpoint['request']['model'] ?? null);

            if ($endpoint['operation'] === self::UNIMPLEMENTED_OPERATION) {
                continue;
            }

            $endpoints[$endpoint['operation']] = $endpoint['request']['model'];
        }

        self::assertNotSame([], $endpoints, 'The manifest yielded no endpoints at all.');

        return $endpoints;
    }

    /**
     * @return array<mixed>
     */
    private function manifest(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw, sprintf('Could not read the manifest at %s.', self::MANIFEST));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
