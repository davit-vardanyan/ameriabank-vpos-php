<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Support;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_unique;
use function array_values;
use function class_exists;
use function count;

use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use function lcfirst;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionParameter;

use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
use function token_get_all;
use function trim;
use function ucfirst;

/**
 * The contract between the specification of record and this package's DTOs.
 *
 * CONVENTIONS.md §2 names `docs/api-reference/api-surface.json` the
 * specification of record: it is reflected from the bank's own C# models, and
 * it is the only artefact in the repository that states what a field is called
 * upstream. The whole difficulty is that a transcription error against it
 * produces no error at all — a wire key spelled `CardBindingFields` instead of
 * `CardBindingFileds` yields a silently null property, on a response nobody
 * has ever seen (CONVENTIONS.md §13), in a payment flow where "no bindings"
 * and "the SDK misread the key" look identical to the caller.
 *
 * So this file does not assert that the DTOs match a list of names. It asserts
 * that they match *the manifest*, read from disk at test time and never
 * transcribed into a literal here. Nothing below can be satisfied by editing
 * this file to agree with src/: the expectations come from a file that is
 * generated from the API, so the day the manifest is regenerated and a field
 * has moved, this test reports it rather than the next payment doing so.
 *
 * ## The three axes
 *
 * A field can be lost at three joints, and each is checked separately because
 * each fails differently:
 *
 * 1. **Manifest → hydrator, forwards.** Every wire key the hydrator reads must
 *    exist upstream. A key that does not is a typo, and it reads null forever.
 * 2. **Manifest → hydrator, backwards.** Every upstream field must be read, or
 *    be listed in ResponseHydrator::IGNORED_FIELDS with a written reason.
 *    Without this half the forwards check is satisfied by mapping nothing.
 * 3. **Hydrator → DTO.** Every named argument the hydrator passes must be a
 *    constructor parameter of the DTO it constructs, and every constructor
 *    parameter must be passed. PHP would catch a mismatch here — but only at
 *    the moment a real response arrives, since these are named arguments on a
 *    call the type system cannot check ahead of time.
 *
 * ## How the hydrator is read
 *
 * By token_get_all(), never by regular expression. The hydrator's class
 * docblock freezes an introspection contract for exactly this purpose — one
 * public method per response model, named lcfirst() of the model; inside a
 * model method every string literal is a wire key; operation names live in the
 * OP_* constants and rejection wording in the private helpers, so that a
 * literal inside a model method needs no filtering to be read as a field name.
 *
 * The tokenizer is what makes that contract usable. A regular expression over
 * the same file cannot tell a method body from the docblock above it, and this
 * package's docblocks are long and quote code: a naive `/'([^']*)'/` scan of
 * ResponseHydrator.php picks up prose from the docblock of the *next* method as
 * though it were a wire key, because an apostrophe in "the response's own
 * `Currency` field" opens a string that closes several paragraphs later. That
 * is not hypothetical — it is what the first draft of this scan did.
 * token_get_all() emits T_DOC_COMMENT and T_COMMENT as their own tokens, so
 * dropping them removes the whole class of problem rather than patching it.
 *
 * The two existing source guards in this suite — tests/Enum's ::from() ban and
 * tests/Money's inexact-numeric ban — are textual by deliberate choice, and
 * both document the trade. This one is not, and the difference is not
 * inconsistency: those two ask "does this token appear anywhere", a question a
 * substring answers correctly and fail-closed, while this one asks "what does
 * this method map", which is a structural question about a specific scope. The
 * money guard's own docblock records the owner's ruling that its next widening
 * is a rewrite onto token_get_all(); this file is the first use of that tool
 * here rather than a departure from precedent.
 *
 * ## Scope
 *
 * SSNCheck is excluded, so its two models are out of scope. That is the one
 * scope decision spelled as a literal below, and it is a decision about which
 * operations this SDK implements — not about what any field is called.
 */
#[CoversNothing]
final class ManifestConformanceTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    private const string HYDRATOR = __DIR__ . '/../../src/Support/ResponseHydrator.php';

    private const string REQUEST_DIRECTORY = __DIR__ . '/../../src/Request';

    private const string RESPONSE_NAMESPACE = 'DavitVardanyan\\AmeriabankVpos\\Response\\';

    /**
     * The operation this SDK does not implement, and the only scope literal in
     * the file.
     *
     * CONVENTIONS.md §7 records SSNCheck as excluded from v1.0, for two
     * reasons: it is unrelated to the payment lifecycle, and it carries
     * Armenian national identity data, which CONVENTIONS.md §6 puts under the
     * same handling as credentials. That is a settled exclusion, not a
     * question left open. Its models stay in the manifest — the manifest
     * describes the API, not this package — so they are named here and
     * skipped.
     */
    private const string UNIMPLEMENTED_OPERATION = 'SSNCheck';

    /**
     * The hydrator's one public method that maps no field of its own.
     *
     * Named rather than detected. Its introspection contract says
     * getPendingTransactionsList() is the only public non-model method, so a
     * *second* one appearing is something this test must notice rather than
     * quietly accommodate — and it will, because the method-set assertion below
     * compares against the manifest's model list.
     */
    private const string NOT_A_MODEL_METHOD = 'getPendingTransactionsList';

    /**
     * Credentials are injected by the transport and must never be
     * constructible by a caller (CONVENTIONS.md §5).
     *
     * These are real manifest fields — every request model declares all three —
     * so the subset check below passes them happily. They are enumerated here
     * because the requirement is the opposite of a subset: these three exist
     * upstream and must nonetheless never be emitted.
     */
    private const array CREDENTIAL_FIELDS = ['ClientID', 'Username', 'Password'];

    /**
     * The manifest must be readable and must describe the models this package
     * maps, or every assertion below is vacuously true.
     *
     * A missing file, a moved directory or a schema change would otherwise
     * yield empty expectation sets, and an empty set is a subset of everything.
     * The model names asserted are class names this package already declares in
     * src/Response/, so naming them here transcribes nothing the package does
     * not already state; what is *not* transcribed, and what this test
     * deliberately does not pin, is any field name or count.
     */
    public function testTheManifestIsReadableAndDescribesTheModelsThisPackageMaps(): void
    {
        $models = $this->manifestModels();

        self::assertNotSame([], $models, 'The manifest yielded no models at all.');

        foreach (['PaymentDetailsResponse', 'GetBindingsResponse', 'CardBindingFiled', 'InitPaymentRequest'] as $model) {
            self::assertArrayHasKey($model, $models, sprintf('The manifest no longer describes %s.', $model));
            self::assertNotSame([], $models[$model], sprintf('The manifest describes %s with no fields.', $model));
        }
    }

    /**
     * Axis one: no wire key the hydrator reads is absent upstream.
     *
     * This is the typo check. `CardBindingFileds`, `IsAvtive`, `rrn`,
     * `PaymentId` and `OrderId` are the wire format (CONVENTIONS.md §4.8), and
     * each is one keystroke from a spelling that looks more correct and reads
     * null forever.
     */
    public function testEveryWireKeyTheHydratorReadsExistsInTheManifest(): void
    {
        $models = $this->manifestModels();

        foreach ($this->hydratorModelMethods() as $model => $keys) {
            self::assertArrayHasKey($model, $models, sprintf('The hydrator maps %s, which the manifest does not describe.', $model));

            self::assertSame(
                [],
                array_values(array_diff($keys, $models[$model])),
                sprintf(
                    'ResponseHydrator::%s() reads a key %s does not declare. Wire '
                    . 'spellings come from the manifest and must not be corrected '
                    . '— CONVENTIONS.md §2 and §4.8. A key that is not upstream is not '
                    . 'an error at runtime; it is a property that is null forever.',
                    lcfirst($model),
                    $model,
                ),
            );
        }
    }

    /**
     * Axis two: no upstream field is silently dropped.
     *
     * Without this the forwards check above is satisfied by a hydrator that
     * maps one field per model, or none. A field the SDK chooses not to carry
     * is a decision, so it must be written down as one — in IGNORED_FIELDS,
     * with a reason — rather than being absent.
     */
    public function testEveryManifestFieldIsMappedOrExplicitlyIgnored(): void
    {
        $models = $this->manifestModels();
        $mapped = $this->hydratorModelMethods();

        foreach ($this->inScopeResponseModels() as $model) {
            self::assertArrayHasKey(
                $model,
                $mapped,
                sprintf(
                    'The manifest describes response model %s and the hydrator has '
                    . 'no %s() method for it. Every in-scope response model needs one.',
                    $model,
                    lcfirst($model),
                ),
            );

            $unmapped = array_values(array_diff($models[$model], $mapped[$model]));
            $ignored = $this->ignoredFields()[$model] ?? [];

            foreach ($unmapped as $field) {
                self::assertArrayHasKey(
                    $field,
                    $ignored,
                    sprintf(
                        'The manifest declares %s.%s and the hydrator does not read '
                        . 'it. An unmapped field must not be silently dropped: either '
                        . 'map it, or add it to ResponseHydrator::IGNORED_FIELDS with '
                        . 'a one-line reason.',
                        $model,
                        $field,
                    ),
                );

                self::assertNotSame(
                    '',
                    trim($ignored[$field]),
                    sprintf('ResponseHydrator::IGNORED_FIELDS[%s][%s] carries no reason.', $model, $field),
                );
            }
        }
    }

    /**
     * IGNORED_FIELDS must name fields that exist, and must not excuse a field
     * that is in fact mapped.
     *
     * A typo in the constant would be the same defect one level up: an entry
     * for `CardBindingFields` would silence nothing, because nothing is missing
     * under that name — and the real missing field would still be reported, but
     * the reader of the constant would believe it had been handled.
     *
     * The constant is currently empty, so this iterates nothing. It is written
     * anyway because the day it stops being empty is the day it needs checking,
     * and a check added at that moment is a check written by whoever wanted the
     * exemption. See ignoredFields() for why it is read reflectively.
     */
    public function testIgnoredFieldsNamesRealAndGenuinelyUnmappedFields(): void
    {
        $models = $this->manifestModels();
        $mapped = $this->hydratorModelMethods();

        foreach ($this->ignoredFields() as $model => $fields) {
            self::assertArrayHasKey($model, $models, sprintf('IGNORED_FIELDS names model %s, which is not in the manifest.', $model));

            foreach (array_keys($fields) as $field) {
                self::assertContains(
                    $field,
                    $models[$model],
                    sprintf('IGNORED_FIELDS names %s.%s, which is not a field of that model.', $model, $field),
                );

                self::assertNotContains(
                    $field,
                    $mapped[$model] ?? [],
                    sprintf('IGNORED_FIELDS excuses %s.%s, but the hydrator reads it.', $model, $field),
                );
            }
        }
    }

    /**
     * The hydrator has exactly one model method per in-scope response model —
     * no more, no fewer.
     *
     * The "no more" half matters as much as the other: a method for a model the
     * manifest does not describe is a model somebody invented, and an invented
     * model is a set of field names with nothing upstream to check them
     * against. The nested models are reached through the manifest's own
     * referenced_models links rather than being listed here, so `BankInfo` and
     * `CardBindingFiled` are in scope because PaymentDetailsResponse and
     * GetBindingsResponse reference them, not because this file says so.
     */
    public function testTheHydratorExposesExactlyOneModelMethodPerInScopeResponseModel(): void
    {
        $expected = $this->inScopeResponseModels();
        $actual = array_keys($this->hydratorModelMethods());
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * Axis three: what the hydrator passes is what the DTO declares.
     *
     * Every model method constructs its DTO with named arguments, which the
     * type system cannot check: an argument naming a parameter the class does
     * not have is an Error thrown when the call runs, and the call runs when a
     * real response arrives. Reflection closes that gap here instead.
     *
     * Both directions are asserted at once by comparing sets. A parameter the
     * hydrator never passes is the other failure — a DTO field that is always
     * its default, which for these DTOs means always null.
     */
    public function testTheHydratorPassesExactlyTheConstructorParametersEachResponseDtoDeclares(): void
    {
        foreach ($this->hydratorNamedArguments() as $model => $arguments) {
            $class = self::RESPONSE_NAMESPACE . $model;

            self::assertTrue(class_exists($class), sprintf('The hydrator constructs %s, which does not exist.', $class));

            $constructor = (new ReflectionClass($class))->getConstructor();

            self::assertNotNull($constructor, sprintf('%s declares no constructor.', $class));

            $parameters = array_map(
                static fn(ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            );

            sort($parameters);
            sort($arguments);

            self::assertSame(
                $parameters,
                $arguments,
                sprintf(
                    'ResponseHydrator::%s() and %s disagree about the constructor. '
                    . 'A named argument with no matching parameter is an Error thrown '
                    . 'when a real response arrives; a parameter never passed is a '
                    . 'field that is null on every response.',
                    lcfirst($model),
                    $class,
                ),
            );
        }
    }

    /**
     * Every key a request can emit exists upstream.
     *
     * A request key the gateway does not know is silently ignored
     * (CONVENTIONS.md §4.12, probe A9), which is the worst available failure
     * mode: `Timeuot` would be accepted, dropped, and the payment page would
     * expire at the server default with nothing to show for it.
     *
     * The scan reads every string literal in toArray(), so the conditional
     * branches that omit a null optional are covered without constructing
     * anything — "can emit", not "did emit on this fixture".
     */
    public function testEveryKeyARequestCanEmitExistsInTheManifest(): void
    {
        $models = $this->manifestModels();
        $emitted = $this->requestEmittedKeys();

        self::assertNotSame([], $emitted, 'No request class was scanned at all.');

        foreach ($emitted as $model => $keys) {
            self::assertArrayHasKey($model, $models, sprintf('%s has no model in the manifest.', $model));

            self::assertSame(
                [],
                array_values(array_diff($keys, $models[$model])),
                sprintf(
                    '%s::toArray() can emit a key %s does not declare. The gateway '
                    . 'ignores unknown request fields silently (CONVENTIONS.md §4.12), so '
                    . 'a misspelled key is not rejected — it is dropped.',
                    $model,
                    $model,
                ),
            );
        }
    }

    /**
     * There is a request class for every in-scope operation, and no other.
     *
     * The forwards check above says nothing about a request that does not
     * exist, and the set is fixed at eleven. SSNCheckRequest must stay absent:
     * CONVENTIONS.md §6 puts Armenian national identity data under the same
     * handling as credentials, and the cheapest way to honour that is not to
     * have a class that accepts it.
     */
    public function testThereIsOneRequestClassPerInScopeOperation(): void
    {
        $expected = $this->inScopeRequestModels();
        $actual = array_keys($this->requestEmittedKeys());
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);

        self::assertNotContains(
            'SSNCheckRequest',
            $actual,
            'SSNCheck is excluded from v1.0 (CONVENTIONS.md §7) and carries PII.',
        );
    }

    /**
     * No request emits a credential, whatever the manifest says.
     *
     * `ClientID`, `Username` and `Password` are fields of every request model
     * upstream, so the subset check welcomes them. CONVENTIONS.md §5 requires
     * the opposite: the transport merges them from Credentials at dispatch,
     * and a request object the caller builds never holds a secret. This is the
     * one place the manifest is a floor rather than a ceiling.
     */
    public function testNoRequestCanEmitACredentialField(): void
    {
        foreach ($this->requestEmittedKeys() as $model => $keys) {
            foreach (self::CREDENTIAL_FIELDS as $credential) {
                self::assertNotContains(
                    $credential,
                    $keys,
                    sprintf(
                        '%s::toArray() emits %s. Credentials are injected by the '
                        . 'transport; a request DTO that carried one would put a '
                        . 'password in every caller-constructed object — CONVENTIONS.md '
                        . '§5 and §6.',
                        $model,
                        $credential,
                    ),
                );
            }
        }
    }

    /**
     * The five spellings CONVENTIONS.md §4.8 calls load-bearing, asserted by
     * name and by the model that carries them.
     *
     * The checks above would pass if `PaymentId` and `PaymentID` were swapped
     * between two models, because each spelling exists somewhere in the
     * manifest and each model would still map some subset of its own fields —
     * the swap only shows up as a null property on a live response. §4.8 says
     * `PaymentId` is GetPaymentIdResponse's alone and `OrderId` is
     * GetPendingTransactionsResponse's alone, so that is asserted here, in both
     * directions, against the two models that must *not* carry them.
     *
     * These are the only field names spelled out in this file. They are
     * transcribed from CONVENTIONS.md §4.8, not from the manifest — the point
     * is to pin the association between a documented typo and the one model it
     * belongs to, which a manifest-derived check cannot express because the
     * manifest holds both spellings without saying they are the same idea.
     */
    public function testTheLoadBearingWireSpellingsAreMappedByTheModelsThatCarryThem(): void
    {
        $mapped = $this->hydratorModelMethods();

        self::assertContains('CardBindingFileds', $mapped['GetBindingsResponse']);
        self::assertContains('IsAvtive', $mapped['CardBindingFiled']);
        self::assertContains('rrn', $mapped['PaymentDetailsResponse']);

        self::assertContains('PaymentId', $mapped['GetPaymentIdResponse'], 'CONVENTIONS.md §4.8: GetPaymentIdResponse is the only model using PaymentId.');
        self::assertNotContains('PaymentID', $mapped['GetPaymentIdResponse']);
        self::assertContains('PaymentID', $mapped['InitPaymentResponse']);
        self::assertContains('PaymentID', $mapped['MakeBindingPaymentResponse']);

        self::assertContains('OrderId', $mapped['GetPendingTransactionsResponse'], 'CONVENTIONS.md §4.8: GetPendingTransactionsResponse is the only model using OrderId.');
        self::assertNotContains('OrderID', $mapped['GetPendingTransactionsResponse']);
        self::assertContains('OrderID', $mapped['PaymentDetailsResponse']);
        self::assertContains('OrderID', $mapped['MakeBindingPaymentResponse']);
    }

    /**
     * The scanner is the whole test, so its own failure modes are pinned
     * against a fixture rather than only against the real hydrator.
     *
     * The docblock case is the one that matters. Every method in
     * ResponseHydrator.php is preceded by a long docblock, several of which
     * contain an apostrophe — "the response's own `Currency` field" — and a
     * textual scan reads that apostrophe as opening a string literal that
     * closes paragraphs later, in the *next* method. The fixture below
     * reproduces exactly that shape.
     */
    public function testTheScannerReadsKeysAndNamedArgumentsAndIgnoresComments(): void
    {
        $php = <<<'PHP'
            <?php

            final class Fixture
            {
                private const string OP = 'SomeOperation';

                /**
                 * A docblock quoting the response's own `Currency` field, whose
                 * apostrophe opens nothing: 'NotAKey' and more prose.
                 */
                public static function widgetResponse(array $data): Widget
                {
                    // A comment mentioning 'AlsoNotAKey'.
                    return new Widget(
                        alpha: self::readText($data, 'Alpha', self::OP),
                        beta: self::readText($data, 'Beta', self::OP),
                    );
                }

                private static function helper(array $data): void
                {
                    throw new RuntimeException('not a key, a private helper');
                }
            }
            PHP;

        self::assertSame(
            ['WidgetResponse' => ['Alpha', 'Beta']],
            $this->modelMethodsIn($php, []),
        );

        self::assertSame(
            ['WidgetResponse' => ['alpha', 'beta']],
            $this->namedArgumentsIn($php, []),
        );
    }

    /**
     * A method named as a non-model method contributes nothing, and its
     * literals are not read as keys.
     *
     * getPendingTransactionsList() is the real instance: it is public, it
     * carries a string literal, and that literal is rejection wording.
     */
    public function testANonModelMethodIsExcludedByName(): void
    {
        $php = <<<'PHP'
            <?php

            final class Fixture
            {
                public static function widgetList(array $rows): array
                {
                    throw new RuntimeException('the collection held an element that was not an object');
                }
            }
            PHP;

        self::assertSame([], $this->modelMethodsIn($php, ['widgetList']));
        self::assertSame(['WidgetList' => ['the collection held an element that was not an object']], $this->modelMethodsIn($php, []));
    }

    /**
     * ResponseHydrator::IGNORED_FIELDS, read through Reflection and validated.
     *
     * Reflection rather than a direct reference, for one specific reason:
     * PHPStan at level 10 evaluates the constant to the literal type `array{}`
     * and then proves that every loop over it is unreachable and every
     * assertArrayHasKey() against it can only fail. Both are true today and
     * neither will be true the first time somebody adds an entry, so a direct
     * reference would force the choice between deleting the checks and
     * weakening the analysis. Reading the value reflectively types it `mixed`,
     * which is what it honestly is here — a declaration this test introspects,
     * not a value it consumes.
     *
     * The shape is asserted rather than assumed, so a constant redeclared as a
     * list of names with no reasons fails here rather than silently satisfying
     * the exemption check.
     *
     * @return array<string, array<string, string>>
     */
    private function ignoredFields(): array
    {
        $constant = new ReflectionClassConstant(ResponseHydrator::class, 'IGNORED_FIELDS');

        self::assertTrue($constant->isPublic(), 'IGNORED_FIELDS must stay public; the conformance test reads it.');

        $value = $constant->getValue();

        self::assertIsArray($value);

        $ignored = [];

        foreach ($value as $model => $fields) {
            self::assertIsString($model, 'IGNORED_FIELDS is keyed by model name.');
            self::assertIsArray($fields, sprintf('IGNORED_FIELDS[%s] must map field name to reason.', $model));

            $reasons = [];

            foreach ($fields as $field => $reason) {
                self::assertIsString($field, sprintf('IGNORED_FIELDS[%s] must be keyed by field name.', $model));
                self::assertIsString($reason, sprintf('IGNORED_FIELDS[%s][%s] must carry a reason.', $model, $field));

                $reasons[$field] = $reason;
            }

            $ignored[$model] = $reasons;
        }

        return $ignored;
    }

    /**
     * Model name to its manifest field names.
     *
     * Enum tables — PaymentsEnum and IdentifierType — are excluded: their rows
     * are members with Name/Value/Description columns and no Type column, so
     * they describe a vocabulary rather than a model, and nothing hydrates one.
     *
     * @return array<string, list<string>>
     */
    private function manifestModels(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw, sprintf('Could not read the manifest at %s.', self::MANIFEST));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('models', $decoded);
        self::assertIsArray($decoded['models']);

        $models = [];

        foreach ($decoded['models'] as $name => $model) {
            self::assertIsString($name);
            self::assertIsArray($model);
            self::assertArrayHasKey('fields', $model);
            self::assertIsArray($model['fields']);

            $fields = [];
            $isModel = false;

            foreach ($model['fields'] as $field) {
                self::assertIsArray($field);
                self::assertArrayHasKey('Name', $field);
                self::assertIsString($field['Name']);

                if (array_key_exists('Type', $field)) {
                    $isModel = true;
                }

                $fields[] = $field['Name'];
            }

            if ($isModel) {
                $models[$name] = $fields;
            }
        }

        return $models;
    }

    /**
     * The manifest's endpoints as operation => [request model, response model].
     *
     * @return array<string, array{string, string}>
     */
    private function manifestEndpoints(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('endpoints', $decoded);
        self::assertIsArray($decoded['endpoints']);

        $endpoints = [];

        foreach ($decoded['endpoints'] as $endpoint) {
            self::assertIsArray($endpoint);
            self::assertIsString($endpoint['operation'] ?? null);
            self::assertIsArray($endpoint['request'] ?? null);
            self::assertIsArray($endpoint['response'] ?? null);
            self::assertIsString($endpoint['request']['model'] ?? null);
            self::assertIsString($endpoint['response']['model'] ?? null);

            $endpoints[$endpoint['operation']] = [$endpoint['request']['model'], $endpoint['response']['model']];
        }

        return $endpoints;
    }

    /**
     * Every response model this SDK must hydrate: the response model of each
     * in-scope endpoint, plus every non-enum model reachable from it through
     * the manifest's referenced_models links.
     *
     * Reached transitively rather than listed, so a nested model the bank adds
     * to PaymentDetailsResponse tomorrow becomes required here without anyone
     * editing this file.
     *
     * @return list<string>
     */
    private function inScopeResponseModels(): array
    {
        $models = $this->manifestModels();
        $required = [];

        foreach ($this->manifestEndpoints() as $operation => [, $response]) {
            if ($operation === self::UNIMPLEMENTED_OPERATION) {
                continue;
            }

            $required = array_merge($required, $this->reachableFrom($response, $models));
        }

        return array_values(array_unique($required));
    }

    /**
     * The request model of every in-scope endpoint.
     *
     * @return list<string>
     */
    private function inScopeRequestModels(): array
    {
        $required = [];

        foreach ($this->manifestEndpoints() as $operation => [$request]) {
            if ($operation === self::UNIMPLEMENTED_OPERATION) {
                continue;
            }

            $required[] = $request;
        }

        return array_values(array_unique($required));
    }

    /**
     * $model and every non-enum model it references, transitively.
     *
     * @param array<string, list<string>> $models
     *
     * @return list<string>
     */
    private function reachableFrom(string $model, array $models): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['models'] ?? null);

        $seen = [];
        $queue = [$model];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (in_array($current, $seen, true) || !array_key_exists($current, $models)) {
                continue;
            }

            $seen[] = $current;

            $entry = $decoded['models'][$current] ?? null;

            if (!is_array($entry) || !is_array($entry['referenced_models'] ?? null)) {
                continue;
            }

            foreach ($entry['referenced_models'] as $referenced) {
                self::assertIsString($referenced);

                $queue[] = $referenced;
            }
        }

        return $seen;
    }

    /**
     * Model name to the wire keys ResponseHydrator reads for it.
     *
     * @return array<string, list<string>>
     */
    private function hydratorModelMethods(): array
    {
        return $this->modelMethodsIn($this->hydratorSource(), [self::NOT_A_MODEL_METHOD]);
    }

    /**
     * Model name to the named arguments ResponseHydrator passes to its DTO.
     *
     * @return array<string, list<string>>
     */
    private function hydratorNamedArguments(): array
    {
        return $this->namedArgumentsIn($this->hydratorSource(), [self::NOT_A_MODEL_METHOD]);
    }

    private function hydratorSource(): string
    {
        $raw = file_get_contents(self::HYDRATOR);

        self::assertIsString($raw, sprintf('Could not read the hydrator at %s.', self::HYDRATOR));

        return $raw;
    }

    /**
     * Request model name to every key its toArray() can emit.
     *
     * @return array<string, list<string>>
     */
    private function requestEmittedKeys(): array
    {
        $requests = [];

        foreach ($this->relativePhpFilesIn(self::REQUEST_DIRECTORY) as $relative) {
            $raw = file_get_contents(self::REQUEST_DIRECTORY . '/' . $relative);

            self::assertIsString($raw, sprintf('Could not read %s.', $relative));

            $keys = $this->literalsInMethod($raw, 'toArray');

            self::assertNotNull($keys, sprintf('%s declares no toArray().', $relative));

            $separator = strrpos($relative, '/');
            $model = substr($relative, $separator === false ? 0 : $separator + 1, -4);

            self::assertArrayNotHasKey(
                $model,
                $requests,
                sprintf('Two files under src/Request/ share the model name %s.', $model),
            );

            $requests[$model] = $keys;
        }

        return $requests;
    }

    /**
     * Every .php file under $directory, recursively, as a path relative to it
     * and using `/` as the separator, sorted.
     *
     * The recursion is the guard. `scandir()` reads one level, so a request
     * class at `src/Request/Binding/Something.php` was absent from the map
     * above and its `toArray()` keys were never held to the manifest at all —
     * a silent exemption reaching in through the derivation rather than
     * through a literal. A nested class was placed there and this file stayed
     * green, which is why the walk recurses now.
     *
     * The model name stays the basename, because the manifest names models by
     * their simple name and knows nothing of directories; two files claiming
     * the same basename would silently overwrite each other, so that is
     * asserted against rather than assumed.
     *
     * Deliberately not shared with the other guards in this suite. A guard that
     * borrows another guard's walker fails open the day that walker is
     * refactored for the other guard's convenience — the reasoning tests/Money's
     * guard records, and it applies here unchanged.
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
     * Every public method of $php that is a model method, as model name to the
     * string literals in its body.
     *
     * The model name is ucfirst() of the method name, inverting the hydrator's
     * contract that a model method is named lcfirst() of its model.
     *
     * @param list<string> $excluded method names that are public but map nothing
     *
     * @return array<string, list<string>>
     */
    private function modelMethodsIn(string $php, array $excluded): array
    {
        $methods = [];

        foreach ($this->publicMethodBodies($php) as $name => $body) {
            if (in_array($name, $excluded, true)) {
                continue;
            }

            $literals = [];

            foreach ($body as $index => $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = $this->literalValue($token[1]);
                }

                unset($index);
            }

            $methods[ucfirst($name)] = array_values(array_unique($literals));
        }

        return $methods;
    }

    /**
     * Every public method of $php that is a model method, as model name to the
     * named arguments it passes.
     *
     * A named argument is an identifier immediately preceded by '(' or ',' and
     * immediately followed by ':'. Requiring the opening delimiter is what
     * separates `alpha: $v` from the middle arm of a ternary, where an
     * identifier is also followed by a colon. '::' is a token of its own, so a
     * class constant cannot be mistaken for one.
     *
     * @param list<string> $excluded method names that are public but map nothing
     *
     * @return array<string, list<string>>
     */
    private function namedArgumentsIn(string $php, array $excluded): array
    {
        $methods = [];

        foreach ($this->publicMethodBodies($php) as $name => $body) {
            if (in_array($name, $excluded, true)) {
                continue;
            }

            $arguments = [];

            foreach ($body as $index => $token) {
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                $previous = $body[$index - 1] ?? null;
                $next = $body[$index + 1] ?? null;

                if ($next !== ':' || ($previous !== '(' && $previous !== ',')) {
                    continue;
                }

                $arguments[] = $token[1];
            }

            $methods[ucfirst($name)] = array_values(array_unique($arguments));
        }

        return $methods;
    }

    /**
     * Public method name to the significant tokens of its body.
     *
     * Whitespace, comments and docblocks are dropped, which is the whole reason
     * this is a tokenizer and not a pattern: a docblock in this package is
     * prose that quotes code, and no textual scan can be trusted to tell the
     * two apart.
     *
     * @return array<string, list<array{int, string, int}|string>>
     */
    private function publicMethodBodies(string $php): array
    {
        $tokens = $this->significantTokens($php);
        $count = count($tokens);
        $bodies = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            if (!$this->isPublic($tokens, $i)) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if (!is_array($name) || $name[0] !== T_STRING) {
                continue;
            }

            $bodies[$name[1]] = $this->bodyAfter($tokens, $i);
        }

        return $bodies;
    }

    /**
     * Whether the method whose T_FUNCTION sits at $index is public.
     *
     * Walks the modifiers backwards and stops at the first token that is not
     * one. An unmodified method is public in PHP, but every method in src/ is
     * explicitly modified and this returns false for a bare one — deliberately,
     * because the hydrator's contract is about its declared public surface and
     * a method with no modifier at all would be a style break worth noticing
     * elsewhere.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function isPublic(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                return false;
            }

            if ($token[0] === T_PUBLIC) {
                return true;
            }

            if (!in_array($token[0], [T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * The tokens between the braces of the method whose T_FUNCTION sits at
     * $index, excluding the braces themselves.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private function bodyAfter(array $tokens, int $index): array
    {
        $count = count($tokens);
        $depth = 0;
        $body = [];

        for ($i = $index; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $depth++;

                continue;
            }

            if ($token === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth > 0) {
                $body[] = $token;
            }
        }

        return $body;
    }

    /**
     * The string literals in $php's $method, or null when there is no such
     * method.
     *
     * @return list<string>|null
     */
    private function literalsInMethod(string $php, string $method): ?array
    {
        $bodies = $this->publicMethodBodies($php);

        if (!array_key_exists($method, $bodies)) {
            return null;
        }

        $literals = [];

        foreach ($bodies[$method] as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = $this->literalValue($token[1]);
            }
        }

        return array_values(array_unique($literals));
    }

    /**
     * $php's tokens with whitespace, comments and docblocks removed.
     *
     * @return list<array{int, string, int}|string>
     */
    private function significantTokens(string $php): array
    {
        $significant = [];

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }

    /**
     * The value of a T_CONSTANT_ENCAPSED_STRING token.
     *
     * Single quotes are unescaped properly — a backslash escapes only itself
     * and a quote inside them. A double-quoted literal's inner text is taken
     * as-is: no wire key needs an escape sequence, and interpolation would make
     * the token a T_ENCAPSED_AND_WHITESPACE sequence rather than this one.
     */
    private function literalValue(string $token): string
    {
        $inner = substr($token, 1, strlen($token) - 2);

        if ($token[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return $inner;
    }
}
