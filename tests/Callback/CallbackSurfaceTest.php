<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Callback;

use function array_intersect;
use function array_map;
use function array_merge;
use function array_values;
use function class_exists;
use function count;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Vpos;

use function file_get_contents;
use function implode;
use function is_dir;
use function is_string;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_match;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function scandir;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function substr;

/**
 * The structural half of CONVENTIONS.md §4.10: the callback surface cannot
 * report an outcome, and there is no mechanism on it that could.
 *
 * The behavioural tests next door assert what a callback parses into. They
 * cannot assert what a *future* contributor will not add, and that is the
 * property that actually protects a merchant. The BackURL carries no HMAC, no
 * signature and no shared secret, so `$callback->isSuccess()` would be one
 * commit, would look entirely reasonable in a diff, and would be a free-goods
 * vulnerability in every consumer that called it. §4.10's design answer —
 * "VposCallback must expose **no** success accessor" — is a rule about code
 * that does not exist yet, and a rule like that either has a mechanical check
 * or it quietly stops holding.
 *
 * Three things are asserted, and each closes a different route to the same
 * defect:
 *
 * 1. **No method whose *name* claims an outcome.** `isSuccess()`, `status()`,
 *    `result()` — the words somebody reaches for.
 * 2. **No method whose *type* is the outcome.** `ResponseCode` is this
 *    package's answer to "did this succeed", and a method handing one back
 *    would be the same defect wearing an innocent name. A name check alone
 *    leaves that half unasserted.
 * 3. **The value object's name does not appear in these files at all.** The
 *    reflective check above sees signatures; this one sees a private helper, a
 *    method body, an import, or a docblock inviting the next reader to build
 *    one. It is `grep -rn 'ResponseCode' src/Callback/ src/Vpos.php` returning
 *    nothing, executed rather than recorded.
 *
 * ## The subject list is derived
 *
 * The list is derived from its source of truth at test time rather than
 * written down: every `.php` file under `src/Callback/`, read recursively,
 * plus `Vpos` — the two ends of the callback path, since `verify()` lives on
 * `Vpos` and is what the callback exists to reach. A second callback class
 * added later is covered the moment it exists, without anyone remembering this
 * file. That is the failure mode a written list produces, where a rogue class
 * defeated three guards at once because each iterated a hand-written provider;
 * and the recursion is not decoration either — six `src/`-walking guards in
 * this suite once read one level only, and would have stopped seeing a nested
 * class without failing.
 *
 * Two lists here are written by hand, and each says why below: the words that
 * mean "success", and the one type that is the outcome. Neither can be derived,
 * because neither is a fact about the code — both are decisions recorded in a
 * document.
 */
#[CoversNothing]
final class CallbackSurfaceTest extends TestCase
{
    private const string CALLBACK_DIRECTORY = __DIR__ . '/../../src/Callback';

    private const string CALLBACK_NAMESPACE = 'DavitVardanyan\\AmeriabankVpos\\Callback\\';

    private const string VPOS_FILE = __DIR__ . '/../../src/Vpos.php';

    /**
     * Method names that would claim to report a payment's outcome, each with
     * the reason it is on the list.
     *
     * **Hand-maintained, and this is the one case that earns it:** no
     * manifest, filesystem scan or reflection can enumerate "words that mean
     * success". That set is not a fact about the code — it is a judgement
     * about what a caller would read as an answer, and it comes from
     * CONVENTIONS.md §4.10's rule that this type must expose no success
     * accessor. So each entry carries a one-line reason, and the reason is
     * *used*: it is printed in the failure, so a contributor who trips this
     * guard is told why the name is forbidden rather than just that it is.
     *
     * Matched case-insensitively, so `isPaid` and `IsPaid` are the same entry.
     *
     * @var array<string, string>
     */
    private const array OUTCOME_METHOD_NAMES = [
        'issuccess' => 'the direct reading of the forged `resposneCode=00`; §4.10 names this accessor as the one that must not exist',
        'succeeded' => 'the past-tense form of the same claim, and the one a reader trusts most',
        'ispaid' => 'the merchant-facing phrasing — the question whose wrong answer ships the goods',
        'isapproved' => 'the acquirer-facing phrasing of the same question',
        'status' => 'a single word that reads as authoritative while being derived from an unsigned query string',
        'responsecode' => 'the corrected spelling of the wire typo, as a scalar accessor, which must never be returned bare',
        'resposnecode' => 'the wire spelling itself, as a scalar accessor; only untrustedDiagnostics() may surface it',
        'result' => 'an outcome by another name, and one that invites a truthy check',
        'outcome' => 'the same, spelled as the thing §4.10 says only a server-side round trip may answer',
    ];

    /**
     * The one type that *is* an outcome.
     *
     * Hand-maintained for the same reason, and it is one entry: nothing
     * reachable from a callback may construct this value object, because
     * `ResponseCode::fromWire($_GET['resposneCode'])->isSuccess()` is the
     * ergonomic forgery path this file exists to close. The class is named by
     * `::class` rather than as a string, so a rename cannot leave the guard
     * pointing at nothing.
     *
     * @var list<class-string>
     */
    private const array OUTCOME_TYPES = [ResponseCode::class];

    /**
     * The exact public surface specified for VposCallback.
     *
     * Written out, because it *is* the specification: two named constructors
     * and four readers, no more. Asserted as an equality in both directions,
     * so a seventh method fails here even if its name is innocent — a
     * `paymentSucceeded()` would be caught by the name guard, but a
     * `toArray()` exposing the diagnostics as a flat scalar map would not, and
     * the shape argument is that the array's *keys* are what make the call
     * site self-documenting.
     *
     * @var list<string>
     */
    private const array VPOS_CALLBACK_METHODS = [
        'fromQuery',
        'fromServerRequest',
        'opaque',
        'orderId',
        'paymentId',
        'untrustedDiagnostics',
    ];

    /**
     * A docblock line that *is* the `@internal` tag rather than prose
     * mentioning it.
     *
     * The same anchored pattern tests/Client/PublicSurfaceTest.php uses, and
     * for the same reason recorded there: a substring search for `@internal`
     * was satisfied by docblock prose that merely narrated the word, so the
     * check could not be made to fail.
     */
    private const string INTERNAL_TAG = '/^\s*\*\s*@internal\b/m';

    /**
     * Every `.php` file under $directory, recursively, relative to it.
     *
     * @return list<string>
     */
    private static function relativePhpFilesIn(string $directory): array
    {
        $entries = scandir($directory);

        self::assertIsArray($entries, sprintf('%s could not be read.', $directory));

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                foreach (self::relativePhpFilesIn($path) as $nested) {
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
     * Every class in `src/Callback/`, read off the filesystem.
     *
     * @return array<string, array{class-string}>
     */
    public static function callbackClasses(): array
    {
        $classes = [];

        foreach (self::relativePhpFilesIn(self::CALLBACK_DIRECTORY) as $relative) {
            $class = self::CALLBACK_NAMESPACE . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                self::fail(sprintf('src/Callback/%s declares no class named %s.', $relative, $class));
            }

            $classes[$class] = [$class];
        }

        return $classes;
    }

    /**
     * The whole callback path: every class in `src/Callback/`, plus `Vpos`.
     *
     * `Vpos` is included because `verify()` lives there. A success accessor on
     * the entry point would be the same defect one object further out, and
     * arguably worse: `$vpos->isSuccess($callback)` reads as though the SDK had
     * asked the gateway.
     *
     * @return array<string, array{class-string}>
     */
    public static function callbackPathClasses(): array
    {
        return array_merge(self::callbackClasses(), [Vpos::class => [Vpos::class]]);
    }

    /**
     * The scan reached `src/Callback/` and found the class it is there for.
     *
     * Without this the whole file is green the day the directory is renamed or
     * emptied: an empty file list yields an empty subject list, and every
     * assertion over it is vacuously true. A floor rather than an equality,
     * because a second callback class is a legitimate addition that must be
     * *covered*, not rejected.
     */
    public function testTheSubjectListIsTheWholeCallbackPath(): void
    {
        $classes = self::callbackPathClasses();

        self::assertArrayHasKey(VposCallback::class, $classes, 'The scan of src/Callback/ did not find VposCallback.');
        self::assertArrayHasKey(Vpos::class, $classes);
        self::assertGreaterThanOrEqual(2, count($classes));
    }

    /**
     * No public method on the callback path reports an outcome.
     *
     * Constructors included: a named constructor taking a decided outcome would
     * be the same defect one frame earlier.
     *
     * ## What this catches, and what bounds it
     *
     * This is a blocklist and the lookup is by exact key, so it is unbounded by
     * construction: it refuses the nine spellings below and admits every other
     * name. `Vpos::isSuccessful()`, which is simply not one of the nine, was
     * added to a copy of the repository with the test a contributor would write
     * beside it, and the full gate went green, phpunit through infection. No
     * near-miss was needed for that: an exact-key lookup admits a name off the
     * list however close or far it reads, so `paymentWasMade()` would have gone
     * green the same way. The bound is elsewhere:
     * tests/Client/PublicSurfaceTest.php freezes `Vpos`'s public surface by
     * equality, so a name nobody predicted fails there.
     *
     * Both halves are kept on purpose. The equality pin *bounds* a surface —
     * it is the only half that can refuse an unforeseen name — while this list
     * *explains* the refusal, because each entry carries the reason it is
     * forbidden and that reason is printed in the failure, so a contributor
     * learns why and not merely that. This list also reaches where a pin would
     * be noise rather than a specification: it applies to every class the
     * derived provider finds in `src/Callback/`, present and future, without
     * anyone deciding that each one's whole surface should be frozen.
     * `VposCallback` has both, because its shape is itself the specification.
     *
     * @param class-string $class
     */
    #[DataProvider('callbackPathClasses')]
    public function testNoPublicMethodReportsAnOutcome(string $class): void
    {
        $inspected = [];
        $offenders = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $inspected[] = $method->getName();
            $reason = self::OUTCOME_METHOD_NAMES[strtolower($method->getName())] ?? null;

            if ($reason !== null) {
                $offenders[] = sprintf('%s::%s() — %s', $class, $method->getName(), $reason);
            }
        }

        self::assertNotSame([], $inspected, sprintf('%s exposes no public method at all, so this guard read nothing.', $class));

        self::assertSame(
            [],
            $offenders,
            'The BackURL is unsigned — no HMAC, no signature, no shared secret, so anyone can '
            . 'forge resposneCode=00 (CONVENTIONS.md §4.10). The only way to learn a payment\'s '
            . 'outcome is a server-side GetPaymentDetails round trip, so no accessor on this '
            . 'path may answer the question at all.',
        );
    }

    /**
     * No public method on the callback path names the outcome value object, in
     * either direction.
     *
     * Return type and parameter types both, unions and intersections flattened:
     * a `?ResponseCode` return is the same export as a bare one, and skipping a
     * shape the guard does not understand is how a guard fails open.
     *
     * @param class-string $class
     */
    #[DataProvider('callbackPathClasses')]
    public function testNoPublicMethodSignatureNamesTheOutcomeValueObject(string $class): void
    {
        $inspected = [];
        $offenders = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $named = $this->typeNames($method->getReturnType());

            foreach ($method->getParameters() as $parameter) {
                $named = array_merge($named, $this->typeNames($parameter->getType()));
            }

            $inspected[] = sprintf('%s::%s()', $class, $method->getName());
            $forbidden = array_values(array_intersect($named, self::OUTCOME_TYPES));

            if ($forbidden !== []) {
                $offenders[] = sprintf('%s::%s() names %s', $class, $method->getName(), implode('|', $forbidden));
            }
        }

        self::assertNotSame([], $inspected, sprintf('%s exposes no public method at all, so this guard read nothing.', $class));

        self::assertSame(
            [],
            $offenders,
            'Nothing on the callback path may construct or hand back this package\'s '
            . 'response-code value object. The exact hazard is reading the forged query '
            . 'parameter into it and asking whether it succeeded.',
        );
    }

    /**
     * The outcome value object's name appears nowhere in `src/Callback/` or in
     * `src/Vpos.php`.
     *
     * Stronger than the reflective check above, and deliberately so: it sees a
     * private helper, a method body, an import, and a docblock suggesting the
     * next reader build one. Both files currently refer to the type in prose as
     * "the package's response-code value object" precisely so that this
     * assertion can be exact rather than fuzzy.
     *
     * The needle is the class's own short name, taken by reflection rather than
     * written as a string, so renaming the value object cannot leave this guard
     * searching for a word that no longer exists.
     */
    public function testTheOutcomeValueObjectIsNotNamedAnywhereInTheCallbackSource(): void
    {
        $needle = (new ReflectionClass(ResponseCode::class))->getShortName();
        $files = [self::VPOS_FILE];

        foreach (self::relativePhpFilesIn(self::CALLBACK_DIRECTORY) as $relative) {
            $files[] = self::CALLBACK_DIRECTORY . '/' . $relative;
        }

        self::assertGreaterThanOrEqual(2, count($files), 'The file list is short, so this guard read almost nothing.');

        $offenders = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            self::assertTrue(is_string($contents) && $contents !== '', sprintf('%s could not be read.', $file));

            if (str_contains($contents, $needle)) {
                $offenders[] = $file;
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                '`grep -rn \'%s\' src/Callback/ src/Vpos.php` must return nothing. Every route '
                . 'from a forged query parameter to "did this succeed" is closed by not opening '
                . 'it.',
                $needle,
            ),
        );
    }

    /**
     * VposCallback exposes exactly the six methods specified for it.
     *
     * An equality in both directions. A seventh method fails here even when
     * its name is innocent and its types are clean, which is the point: the
     * argument is about the *shape* of the surface, and a `toArray()`
     * flattening the diagnostics into scalars would satisfy every other guard
     * in this file while undoing the reason the array exists.
     *
     * A missing method fails too — the two named constructors and the four
     * readers are the documented API, and dropping one is a breaking change
     * that no other test in the suite would report.
     */
    public function testVposCallbackExposesExactlyTheSixDocumentedMethods(): void
    {
        $methods = [];

        foreach ((new ReflectionClass(VposCallback::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[] = $method->getName();
        }

        sort($methods);

        self::assertSame(
            self::VPOS_CALLBACK_METHODS,
            $methods,
            'VposCallback\'s public surface is exactly two named constructors and four readers. '
            . 'The constructor is private, so fromQuery() and fromServerRequest() are the only '
            . 'ways in.',
        );
    }

    /**
     * Every class in `src/Callback/` is `final` and `readonly`.
     *
     * `final` is CONVENTIONS.md §5's default and §8 permits exactly one
     * exception, `ApiException`, which does not live here. `readonly` is what
     * makes the marker type a marker: a mutable callback could be handed to
     * `verify()`, verified, and then edited to name a different order, which
     * would make the cross-check a formality.
     *
     * @param class-string $class
     */
    #[DataProvider('callbackClasses')]
    public function testEveryCallbackClassIsFinalAndReadonly(string $class): void
    {
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal(), sprintf('%s is not final (CONVENTIONS.md §5, §8).', $class));
        self::assertTrue(
            $reflection->isReadOnly(),
            sprintf(
                '%s is not readonly. A callback that can be edited after verification makes the '
                . 'order cross-check a formality.',
                $class,
            ),
        );
    }

    /**
     * No class in `src/Callback/` is marked `@internal`.
     *
     * This is public API. The annotation matters in the opposite direction
     * from usual — a consumer's static analyser would report the one call a
     * merchant *must* make, `VposCallback::fromQuery($_GET)`, as a violation,
     * and a merchant told off for verifying is a merchant who stops verifying.
     * CONVENTIONS.md §5 puts `@internal` on `Http\` and `Support\` only.
     *
     * A class with no docblock at all satisfies this, and the empty string
     * standing in for one is why: the assertion runs either way. The first
     * version of this guard returned early when there was no docblock, and a
     * nested fixture class carrying none turned it into a test that performed
     * **no assertions** — reported as risky rather than as a pass, which this
     * project's phpunit.xml treats as a failure, but only by luck of that
     * setting. An assertion that does not run is not a guard, and this one now
     * runs for every class the scan finds. Found by executing the mutation and
     * watching the guard stay green, not by reading the test.
     *
     * @param class-string $class
     */
    #[DataProvider('callbackClasses')]
    public function testNoCallbackClassIsMarkedInternal(string $class): void
    {
        $docblock = (new ReflectionClass($class))->getDocComment();

        self::assertSame(
            0,
            preg_match(self::INTERNAL_TAG, is_string($docblock) ? $docblock : ''),
            sprintf(
                '%s carries an `@internal` tag. It is public API: a consumer\'s '
                . 'analyser must not report the one call a merchant has to make.',
                $class,
            ),
        );
    }

    /**
     * The hand-maintained lists above are populated and each entry carries its
     * reason.
     *
     * A written subject list earns its place only where the source of truth
     * cannot name the subject, and then owes a reason per entry. This asserts
     * that requirement mechanically instead of trusting the docblock: an entry
     * added later with an empty reason fails here, and an emptied list — which
     * would make the name guard vacuously green for every class — fails too.
     */
    public function testEveryHandMaintainedEntryCarriesAReason(): void
    {
        $reflection = new ReflectionClass(self::class);
        $names = $reflection->getConstant('OUTCOME_METHOD_NAMES');
        $types = $reflection->getConstant('OUTCOME_TYPES');

        self::assertIsArray($names);
        self::assertIsArray($types);
        self::assertGreaterThanOrEqual(9, count($names), 'The forbidden-name list has shrunk, which exempts whatever left it.');
        self::assertNotSame([], $types);

        foreach ($names as $name => $reason) {
            self::assertIsString($name);
            self::assertIsString($reason);
            self::assertNotSame('', $reason, sprintf('The forbidden name %s carries no reason.', $name));
            self::assertSame(strtolower($name), $name, sprintf('%s is not lowercased, so the check would miss it.', $name));
            self::assertNotContains(
                $name,
                array_map(strtolower(...), self::VPOS_CALLBACK_METHODS),
                sprintf('%s is both required and forbidden, so the two lists contradict each other.', $name),
            );
        }

        foreach ($types as $type) {
            self::assertIsString($type);
            self::assertTrue(class_exists($type), sprintf('%s is not a class, so the type guard searches for nothing.', $type));
        }
    }

    /**
     * Every class name a type mentions, with unions and intersections
     * flattened.
     *
     * @return list<string>
     */
    private function typeNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                $names = array_merge($names, $this->typeNames($member));
            }

            return $names;
        }

        return [];
    }
}
