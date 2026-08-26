<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Client;

use function array_intersect;
use function array_merge;
use function array_values;
use function class_exists;
use function count;

use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Vpos;

use function implode;
use function in_array;
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
use function sprintf;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Four structural properties of this package's public entry surface, held by
 * reflection rather than by prose.
 *
 * All four are rules that a reader could satisfy today and break next quarter
 * without noticing, which is exactly the shape this suite keeps finding: a
 * rule with no mechanical check silently stops holding, and nobody learns that
 * from the document that states it.
 *
 * ## 1. No raw callback value reaches this surface (CONVENTIONS.md §4.10)
 *
 * The BackURL carries `orderID`, `resposneCode`, `paymentID`, `opaque` and
 * `description`, and it is unsigned — no HMAC, no signature, no shared secret.
 * Anyone can forge `resposneCode=00`. The design answer is that only a
 * server-side round trip can say what happened to a payment, and the way that
 * answer survives contact with a future contributor is that a callback may
 * reach this surface **only** as the `VposCallback` marker type.
 *
 * That type is the checkpoint: it is where the five wire spellings are pinned
 * and where an unusable callback is rejected, and `Vpos::verify(VposCallback
 * $callback)` is the one method that takes it. What is forbidden is the raw
 * value arriving instead of it — a convenience `confirmFromCallback(string
 * $resposneCode)` would be one commit, and it would be a forged-payment
 * vulnerability in every consumer that called it.
 *
 * The guard below is deliberately exact about its own reach, and the reason is
 * not tidiness. It once claimed that "no public method on this surface may take
 * a callback parameter at all", which stopped being true of the surface it
 * iterates the moment `verify()` existed — and it printed that claim in its own
 * failure message. A contributor who adds
 * `confirmFromCallback(string $resposneCode)`, trips the guard, reads a rule the
 * class visibly breaks two methods away, and concludes the rule is stale will
 * take the cheapest path that quiets the message: rename the parameter to
 * `$code`. The guard goes green and the forged-payment method ships, coached
 * past the guard by its own misdescription. An overstated guard is worse than a
 * narrower one.
 *
 * So what is asserted is stated as narrowly as it is held: three forbidden
 * parameter *names*, compared case-insensitively, and one forbidden parameter
 * *type*. The type half is not decoration — a
 * `confirmFromCallback(ResponseCode $code)` passes any name check while being
 * precisely the thing forbidden here, a response code built from caller input.
 *
 * ## 2. The entry point's public surface is closed
 *
 * Property 1 is a blocklist, and a blocklist is unbounded by construction: it
 * refuses the spellings on it and ships everything else. That is not a
 * hypothesis. `Vpos::isSuccessful(VposCallback $callback): bool`, returning
 * `$callback->untrustedDiagnostics()['resposneCode'] === '00'`, was added to a
 * copy of this repository together with the one test a contributor would
 * naturally write beside it, and the **entire** gate went green — phpunit,
 * coverage at 100%, phpstan, infection at MSI 100. It takes no forbidden
 * parameter name, it names no forbidden type, and its own name is simply
 * absent from the outcome-name list in tests/Callback/CallbackSurfaceTest.php,
 * which compares by exact key. No near-miss is involved and none is needed: an
 * exact lookup admits every name that is not on the list, `paymentWasMade()`
 * exactly as much as this one. A merchant calling it on a forged query string
 * gets `true` with no round trip: free goods, past every gate. The forbidden
 * `verifyFromQuery(array $query)` overload was proven green the same way, and
 * the assertion count *rose* while it was there — the guards did iterate the
 * new method, and none of them objected to it.
 *
 * The answer is the pattern this project already applies twice: `VposCallback`
 * is pinned by equality in tests/Callback/CallbackSurfaceTest.php and
 * `Credentials` in tests/Config/CredentialsTest.php. The entry point was the one
 * class of the three left open. Pinning it by equality means every new public
 * method on `Vpos` fails the build until a human classifies it, which closes
 * both holes above with one assertion and closes the ones nobody has thought of
 * yet as well.
 *
 * The blocklist stays, and the two are not redundant. The allowlist *bounds* the
 * surface — it is the only half that can refuse a name nobody predicted. The
 * blocklist *explains* the refusal, naming the forbidden channel and, next door,
 * the reason each forbidden word is forbidden; and it applies to classes where
 * an equality pin would be noise rather than a specification, which is why
 * `VposCallback` has both and every operation client has only the blocklist.
 *
 * ## 3. No internal type appears on that surface (CONVENTIONS.md §5)
 *
 * Everything under `Http\` and `Support\` is `@internal`. A consumer who can
 * see one of those types on a public signature can depend on it, and once they
 * do it is public whatever the annotation says. The list of internal types is
 * read from those two directories at test time and each class's `@internal` tag
 * is asserted while reading it — a literal naming only the two the clients
 * happen to touch would exempt the other six.
 *
 * This is checked by reflection rather than by grepping for the two names,
 * because a grep for those names cannot be satisfied: `ResponseHydrator` is an
 * all-static utility and `ReportsClient::pending()` legitimately names it
 * inside a method body — the collection form of `GetPendingTransactions` lives
 * there and has no single-DTO equivalent to reach through. Reflection asks the
 * question that actually matters, which is not "does this name appear in this
 * file" but "can a consumer see this type from outside". It is also strictly
 * stronger: it would catch the name arriving through an alias, an import, or a
 * class this file has never heard of.
 *
 * The sub-client constructors do take an `HttpTransport`, and that is the
 * whole point — a public constructor would export the type, so those
 * constructors are annotated `@internal` and the annotation is asserted below
 * rather than trusted.
 *
 * ## 4. Every class here is `final` (CONVENTIONS.md §5, §8)
 *
 * `src/Response/` and `src/Exception/` each already have a guard holding this;
 * `src/Client/` arrived without one, so a non-final `PaymentsClient` failed no
 * gate. §8 permits exactly one non-final class in the package, `ApiException`,
 * and it does not live here.
 *
 * ## The subject list is derived, not written down
 *
 * A guard's subject list is derived from its source of truth at test time
 * rather than written down. The classes checked are `Vpos` plus every class in
 * `src/Client/`, read from the filesystem. A fifth client added later is
 * covered the moment it exists, without anyone remembering this file — which
 * is the failure mode a written list produces: three structural guards were
 * once defeated at the same time by a class that was simply not in the
 * nine-row provider each of them iterated.
 *
 * Property 2's frozen list is the one subject list here that is written out,
 * and a written list is permitted in exactly this case: it *is* the
 * specification — a class's own methods cannot derive their own approved-ness,
 * since the offending method would arrive on the surface it is being checked
 * against and approve itself. The source of truth is CONVENTIONS.md §5's
 * public-surface block, so the reason each entry is on the list is not left as
 * prose: a second test extracts the block and asserts the two agree, in both
 * directions. Widening the surface therefore takes two deliberate edits in two
 * files, and a reviewer sees both.
 */
#[CoversNothing]
final class PublicSurfaceTest extends TestCase
{
    private const string CLIENT_DIRECTORY = __DIR__ . '/../../src/Client';

    private const string CLIENT_NAMESPACE = 'DavitVardanyan\\AmeriabankVpos\\Client\\';

    /**
     * Parameter names that would mean this surface had started reading the
     * BackURL.
     *
     * The one hand-maintained list in this file, and it is hand-maintained for
     * the one reason a derived list cannot cover: these name a *channel*, not
     * a field, and no manifest can name a channel. `resposneCode` is not in
     * `api-surface.json` at all — it is the misspelling the gateway puts in a
     * redirect query string (CONVENTIONS.md §4.8, §4.10), observed on the wire
     * and recorded in the project document. `responseCode` is the same idea
     * spelled the way somebody would spell it while "fixing" the typo, and
     * `opaque` is the merchant-supplied value the callback echoes back.
     *
     * Compared case-insensitively, so `$Opaque` and `$ResponseCode` are caught
     * too.
     *
     * @var list<string>
     */
    private const array CALLBACK_PARAMETER_NAMES = ['resposnecode', 'responsecode', 'opaque'];

    /**
     * Parameter *types* that would mean the same thing.
     *
     * A name check alone leaves half the rule unasserted:
     * `confirmFromCallback( ResponseCode $code)` takes no forbidden name and
     * is exactly the defect — a response code built from caller input,
     * presented as if the SDK had obtained it. The rule's second clause is
     * that nothing on this surface may construct a `ResponseCode` from user
     * input, and this is the clause that holds it.
     *
     * Hand-maintained for the one reason a derived list cannot cover: this
     * names a *policy* subject, and no manifest, filesystem scan or reflection
     * over `src/` can enumerate "the types a public method must not accept" —
     * that set is a decision recorded in a document, not a fact about the
     * code. It is one entry, and the entry is the rule's own words.
     *
     * @var list<class-string>
     */
    private const array CALLBACK_PARAMETER_TYPES = [ResponseCode::class];

    /**
     * The entry point's entire public surface. A seventh name is a failure.
     *
     * **Hand-maintained, and this is the one case a written list is right
     * for:** the list *is* the specification, and a class cannot derive its
     * own approved-ness — the method being judged would arrive on the very
     * surface the check reads and so would approve itself. That is not a
     * theoretical gap; it is the hole property 2 above records being walked
     * through twice.
     *
     * The reason each entry is here is therefore not written as prose but
     * asserted: the source of truth is CONVENTIONS.md §5's public-surface
     * block, and
     * testTheFrozenSurfaceIsExactlyWhatTheProjectDocumentPublishes() extracts
     * that block and compares it with this list in both directions. So a name
     * added here without being published fails, and a method published without
     * being pinned fails too.
     *
     * `__construct` is on the list because a constructor is a public method and
     * reflection reports it as one; §5 publishes it as the `new Vpos(...)` the
     * block opens with.
     *
     * @var list<string>
     */
    private const array ENTRY_POINT_METHODS = [
        '__construct',
        'bindings',
        'paymentPageUrl',
        'payments',
        'reports',
        'verify',
    ];

    /**
     * CONVENTIONS.md, which §2 makes this project's document of record for
     * everything the API surface manifest does not describe — and the public
     * surface is one of those things, since the manifest describes the bank's
     * request models and not this package's API.
     *
     * Read at test time rather than transcribed, which makes *which* document
     * is read a functional dependency and not a citation. §7 separates three
     * tiers, and this guard has already been broken once by conflating two of
     * them:
     *
     * - **Untracked** — the maintainer's local operating notes, the vendor PDF
     *   and the sandbox probes. Present on one disk and in no clone, so a
     *   guard that reads one is red on every fresh checkout. That is what this
     *   constant pointed at until the repository was published, and the
     *   docblock said the opposite.
     * - **Tracked but `export-ignore`d** — `docs/`, which is where
     *   `docs/api-reference/api-surface.json` lives, and `tests/` itself. In
     *   the repository, absent from the Composer distribution: a clone has it,
     *   a consumer installing from Packagist does not.
     * - **Shipped** — `src/`, the root documents, and this file. It is neither
     *   untracked nor `export-ignore`d, and that is deliberate: the section
     *   citations throughout `src/` resolve for a consumer only because the
     *   document they name travels with the distribution.
     *
     * What this guard needs is only that the file exist in a clone, which the
     * second and third tiers both satisfy and the first does not. Nothing read
     * here reaches a consumer either way, because `tests/` is itself
     * `export-ignore`d — but "does not ship" and "is not in the repository"
     * are different claims, and reading the first as the second is the bug.
     */
    private const string PROJECT_DOCUMENT = __DIR__ . '/../../CONVENTIONS.md';

    /**
     * The heading §5 puts above the public-surface listing, and the fence that
     * opens the listing itself.
     *
     * Located by heading rather than by line number so that editing §5's prose
     * cannot silently move the extraction off the block. If the heading is
     * renamed the extraction fails loudly and a human repoints it, which is
     * the correct failure. Not because the alternative is a green comparison —
     * an empty block is compared against a six-name literal and goes red
     * regardless, as the probes recorded on
     * testTheFrozenSurfaceIsExactlyWhatTheProjectDocumentPublishes show — but
     * because the alternative is an unreadable one: an array diff, or a
     * `TypeError` raised inside `strpos()`, neither of which says that the
     * extraction lost the block.
     */
    private const string PUBLIC_SURFACE_HEADING = '### Public surface';

    private const string CODE_FENCE = '```';

    /**
     * A call on the documented entry-point instance, capturing the method name.
     *
     * Only the first hop is captured, so `$vpos->payments()->init(...)` yields
     * `payments` — which is exactly right: `init()` is `PaymentsClient`'s
     * surface, not the entry point's.
     */
    private const string ENTRY_POINT_CALL = '/\$vpos->([A-Za-z_][A-Za-z0-9_]*)\(/';

    /**
     * How §5 publishes the constructor.
     */
    private const string CONSTRUCTOR_CALL = 'new Vpos(';

    /**
     * The directories CONVENTIONS.md §5 puts `@internal` on, mapped to their
     * namespaces.
     *
     * The *directories* are named because §5 names them — "`@internal` on
     * everything under `Http\` and `Support\`" is the rule, and a rule about
     * two directories cannot be derived from anything smaller. The classes
     * inside them are not named; see internalTypes().
     *
     * @var array<string, string>
     */
    private const array INTERNAL_DIRECTORIES = [
        __DIR__ . '/../../src/Http' => 'DavitVardanyan\\AmeriabankVpos\\Http\\',
        __DIR__ . '/../../src/Support' => 'DavitVardanyan\\AmeriabankVpos\\Support\\',
    ];

    /**
     * A docblock line that *is* the `@internal` tag, as opposed to one that
     * merely mentions it.
     *
     * `str_contains($docblock, '@internal')` was the first version of this
     * check and it could not be made to fail: every one of these constructors
     * opens with the sentence "HttpTransport is `@internal` (CONVENTIONS.md
     * §5), so this constructor is too", and that prose satisfied a substring
     * search on its own. Deleting the real tag left the guard green — which is
     * the exact shape of decorative guard tasks 003, 008 and 009 each found,
     * discovered here only because the mutation was applied and observed.
     *
     * So the pattern is anchored to the start of a docblock line: `* @internal`
     * at a line's leading position is the tag, and `is `@internal`` mid-sentence
     * is not.
     */
    private const string INTERNAL_TAG = '/^\s*\*\s*@internal\b/m';

    /**
     * Every .php file under $directory, recursively, as a path relative to it
     * and using `/` as the separator, sorted.
     *
     * Every scan in this file used to read **one** directory level and assert
     * that the level below was empty, offering the reader "recurse here, or keep
     * src/ flat". This is the first branch taken, and the recursion is now the
     * thing that makes the derived subject lists below complete.
     *
     * The assertion it replaces was not decorative — a class was placed at
     * `src/Support/<sub>/Probe.php` and three tests in this file did go red.
     * But what they reported was a *layout* violation, and the property this
     * file actually cares about is that no `@internal` type reaches a public
     * signature. Recursing asserts that property for a nested class instead of
     * refusing to look at it: the class is still required to exist, still
     * required to carry its own `@internal` tag, and still checked against
     * every public signature below. So a nested class fails on its own merits
     * rather than on its location, which is the property that matters, and the
     * per-directory `found > 0` floor keeps a renamed directory from turning
     * the file vacuously green.
     *
     * Static because publicSurfaceClasses() is a data provider and PHPUnit
     * calls it before any instance exists.
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
     * The public entry surface: Vpos and every operation client.
     *
     * @return array<string, array{class-string}>
     */
    public static function publicSurfaceClasses(): array
    {
        $classes = [Vpos::class => [Vpos::class]];

        foreach (self::relativePhpFilesIn(self::CLIENT_DIRECTORY) as $relative) {
            $class = self::CLIENT_NAMESPACE . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                self::fail(sprintf('src/Client/%s declares no class named %s.', $relative, $class));
            }

            $classes[$class] = [$class];
        }

        return $classes;
    }

    /**
     * Every `@internal` type, read off the filesystem rather than written down.
     *
     * This list used to be the literal `[HttpTransport::class,
     * ResponseHydrator::class]` — the two types the clients happen to touch —
     * and that is the silent-exemption shape a written subject list produces.
     * CONVENTIONS.md §5 puts `@internal` on *everything* under `Http\` and
     * `Support\`, which is eight classes today; a public method returning a
     * `Redactor` or a `FailureRedactor` would export an internal type and
     * every guard below would have stayed green, because the offender was not
     * on the list. A hand-maintained list exempts everything it forgets.
     *
     * The `@internal` tag is asserted here rather than assumed. Two things
     * follow from that. A class under those directories that lacks the tag
     * fails this method loudly instead of being quietly dropped from the
     * subject list — silently skipping it would reintroduce the exemption by
     * another route. And the assertion doubles as the check that §5's
     * annotation rule still holds for the directories it names.
     *
     * @return list<class-string>
     */
    private function internalTypes(): array
    {
        $types = [];

        foreach (self::INTERNAL_DIRECTORIES as $directory => $namespace) {
            $found = 0;

            foreach (self::relativePhpFilesIn($directory) as $relative) {
                $class = $namespace . str_replace('/', '\\', substr($relative, 0, -4));

                if (!class_exists($class)) {
                    self::fail(sprintf('%s/%s declares no class named %s.', $directory, $relative, $class));
                }

                $docblock = (new ReflectionClass($class))->getDocComment();

                self::assertTrue(
                    is_string($docblock) && preg_match(self::INTERNAL_TAG, $docblock) === 1,
                    sprintf(
                        '%s lives under a directory CONVENTIONS.md §5 marks `@internal` and carries no `@internal` tag of its own.',
                        $class,
                    ),
                );

                $types[] = $class;
                ++$found;
            }

            self::assertGreaterThan(
                0,
                $found,
                sprintf('%s yielded no classes, so every guard reading this list would be vacuously green.', $directory),
            );
        }

        return $types;
    }

    /**
     * The scan reached src/Client/ and found more than Vpos.
     *
     * Without this the whole file is green the day the directory is renamed:
     * an empty file list yields an empty subject list, and every assertion
     * over it is vacuously true. The count is asserted as a floor rather than
     * as an equality, because a fifth client is a legitimate addition and must
     * be *covered*, not rejected.
     */
    public function testTheSubjectListIsTheWholePublicEntrySurface(): void
    {
        $classes = self::publicSurfaceClasses();

        self::assertArrayHasKey(Vpos::class, $classes);
        self::assertGreaterThanOrEqual(4, count($classes), 'The public surface scan found fewer classes than src/Client/ holds.');

        foreach (['PaymentsClient', 'BindingsClient', 'ReportsClient'] as $client) {
            self::assertArrayHasKey(
                self::CLIENT_NAMESPACE . $client,
                $classes,
                sprintf('%s is part of the public entry surface and was not scanned.', $client),
            );
        }
    }

    /**
     * The entry point's public surface is exactly the six methods
     * CONVENTIONS.md §5 publishes. A seventh is a failure.
     *
     * An equality in both directions, and the reason it is an equality rather
     * than a blocklist is recorded in property 2 of this file's header: a
     * blocklist ships everything it did not predict, and two methods that
     * nobody predicted have already been driven through the full gate green —
     * `isSuccessful(VposCallback $callback): bool` reading the forged
     * `resposneCode` straight out of the diagnostics array, and the forbidden
     * `verifyFromQuery(array $query)` overload. Neither takes a forbidden
     * parameter name, neither names a forbidden type, and neither is caught by
     * anything else in this suite. Both fail here, and so does the third one
     * nobody has thought of.
     *
     * The maintenance cost is one line, paid only when somebody widens the entry
     * point on purpose — which is precisely the moment a reviewer should be
     * reading. It is the same freeze `Credentials` carries
     * (tests/Config/CredentialsTest.php) and the same one `VposCallback` carries
     * (tests/Callback/CallbackSurfaceTest.php); this class was the one of the
     * three left open.
     *
     * A *missing* method fails too, and that is deliberate: all six are
     * published API, and dropping one is a breaking change that no other test
     * here would report.
     */
    public function testTheEntryPointSurfaceIsFrozenToExactlyTheseMethods(): void
    {
        $names = [];

        foreach ((new ReflectionClass(Vpos::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $names[] = $method->getName();
        }

        sort($names);

        self::assertSame(
            self::ENTRY_POINT_METHODS,
            $names,
            'The public surface of Vpos is frozen to what CONVENTIONS.md §5 publishes. Adding a method '
            . 'here is a deliberate act, and the reason this is an equality is that the BackURL is '
            . 'unsigned (§4.10): a method reading an outcome out of a callback — isSuccessful(), '
            . 'paymentWasMade(), verifyFromQuery() — passes every other guard in this suite, '
            . 'because none of them can enumerate the names nobody has thought of. If you added a '
            . 'method on purpose, publish it in §5 and pin it here, and say in its docblock why it '
            . 'cannot answer "did this payment succeed" from anything but a server-side round trip. '
            . 'If you did not, you have widened the one surface a merchant trusts.',
        );
    }

    /**
     * The frozen list above is exactly what CONVENTIONS.md §5 publishes.
     *
     * This is what keeps the freeze from being a private opinion of this test
     * file. A written-out subject list earns its place only where the source
     * of truth cannot name the subject, and then owes a reason per entry; the
     * source of truth here is §5's public-surface block, so rather than write
     * six reasons in prose, the block is read at test time and compared with
     * the list in both directions.
     *
     * Two failure modes follow, and both are the point. A method pinned above
     * but absent from §5 fails, so the freeze cannot quietly legalise something
     * the project document does not publish. A method published in §5 but not
     * pinned fails too, so the document cannot grow a surface the freeze has not
     * seen. Widening the entry point therefore takes two edits in two files, and
     * a reviewer reading either diff sees the other is owed.
     *
     * The floor below, and the three assertions in the helper that locate the
     * block, are diagnostics rather than the anti-vacuity half they were once
     * described as, and which of the two they are was settled by probe rather
     * than by reading. An extraction that finds nothing cannot make this test
     * green, because the *expected* side is the six-element
     * ENTRY_POINT_METHODS literal above and not something derived from the same
     * document: with the floor deleted and §5's fences rewritten until the
     * extraction yielded `[]`, the comparison still went red on `assertSame`,
     * reporting the six names against `Array &0 []`. Deleting a locating
     * assertion does not go green either — with the heading one removed and the
     * heading renamed, the test *errors*, `TypeError: strpos(): Argument #3
     * ($offset) must be of type int, false given`.
     *
     * What the two mechanisms buy is therefore where and how a reorganised
     * document fails: at the extraction, in a sentence that names it, instead
     * of as an opaque array diff or a `TypeError` several frames inside
     * `strpos()`/`substr()`. Vacuity is a real hazard of the genre — a
     * document-reading guard whose *expected* side is also read from the
     * document — but it is not this guard's, and the literal expectation is why.
     * Both were run red — one by repointing the heading at a name §5 does not
     * carry, one by repointing the call pattern at a variable the block never
     * names.
     */
    public function testTheFrozenSurfaceIsExactlyWhatTheProjectDocumentPublishes(): void
    {
        $documented = $this->documentedEntryPointMethods();

        self::assertGreaterThanOrEqual(
            2,
            count($documented),
            'CONVENTIONS.md §5\'s public-surface block yielded almost no entry-point calls, so this '
            . 'comparison read nothing. Repoint the extraction at the block rather than deleting '
            . 'this assertion.',
        );

        self::assertSame(
            self::ENTRY_POINT_METHODS,
            $documented,
            'The frozen surface above and CONVENTIONS.md §5\'s public-surface block disagree. §5 is the '
            . 'source of truth for what this package publishes, so a name pinned without being '
            . 'published, or published without being pinned, is an unfinished change rather than a '
            . 'style question.',
        );
    }

    /**
     * The entry-point methods CONVENTIONS.md §5's public-surface block
     * publishes, sorted.
     *
     * @return list<string>
     */
    private function documentedEntryPointMethods(): array
    {
        $document = file_get_contents(self::PROJECT_DOCUMENT);

        self::assertTrue(
            is_string($document) && $document !== '',
            sprintf('%s could not be read, so the published surface is unknown.', self::PROJECT_DOCUMENT),
        );

        $heading = strpos($document, self::PUBLIC_SURFACE_HEADING);

        self::assertIsInt(
            $heading,
            sprintf('CONVENTIONS.md carries no "%s" heading, so §5 could not be located.', self::PUBLIC_SURFACE_HEADING),
        );

        $opening = strpos($document, self::CODE_FENCE, $heading);

        self::assertIsInt($opening, 'CONVENTIONS.md §5 opens no code block, so nothing published could be read.');

        $closing = strpos($document, self::CODE_FENCE, $opening + strlen(self::CODE_FENCE));

        self::assertIsInt($closing, 'CONVENTIONS.md §5\'s code block is not closed.');

        $block = substr($document, $opening, $closing - $opening);
        $names = [];

        preg_match_all(self::ENTRY_POINT_CALL, $block, $matches);

        foreach ($matches[1] as $name) {
            $names[$name] = $name;
        }

        if (str_contains($block, self::CONSTRUCTOR_CALL)) {
            $names['__construct'] = '__construct';
        }

        $documented = array_values($names);

        sort($documented);

        return $documented;
    }

    /**
     * No public method on this surface takes a raw callback value — a BackURL
     * scalar by name, or a response code built from caller input by type.
     *
     * CONVENTIONS.md §4.10. Constructors are included: a `new Vpos(..., string
     * $resposneCode)` would be the same defect one frame earlier.
     *
     * What this holds, stated as narrowly as it holds it: three forbidden
     * parameter *names* compared case-insensitively, and one forbidden
     * parameter *type*. The name check catches `details(string
     * $resposneCode)`. The type check catches
     * `confirmFromCallback(ResponseCode $code)` — a parameter whose name is
     * innocent and whose type is the whole defect, since a `ResponseCode`
     * built from caller input is a forged outcome wearing the SDK's own value
     * object. Until the type check existed, that second clause was stated in
     * three places (CHANGELOG, PaymentsClient's docblock, this file's own
     * header) and asserted in none.
     *
     * What it does **not** hold is that no method here touches a callback at
     * all. `Vpos::verify(VposCallback $callback)` does, and must: it is the
     * server-side round trip that is the only honest answer to "did this
     * payment succeed", and the marker type is the checkpoint that makes the
     * round trip the ergonomic path. A callback reaching this surface as that
     * type is the design; reaching it as a raw string or as a pre-built response
     * code is the defect. The guard used to claim the broader rule and print it
     * in this message, which invited a contributor to read the rule as stale and
     * rename their way past it — see property 1 in the class docblock.
     *
     * The bound on what may be added at all is
     * testTheEntryPointSurfaceIsFrozenToExactlyTheseMethods(), not this test.
     *
     * @param class-string $class
     */
    #[DataProvider('publicSurfaceClasses')]
    public function testNoPublicMethodTakesARawCallbackValue(string $class): void
    {
        $inspected = [];
        $offenders = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $signature = sprintf('%s::%s($%s)', $class, $method->getName(), $parameter->getName());
                $inspected[] = $signature;

                if (in_array(strtolower($parameter->getName()), self::CALLBACK_PARAMETER_NAMES, true)) {
                    $offenders[] = $signature;
                }

                $forbidden = array_values(array_intersect(
                    $this->typeNames($parameter->getType()),
                    self::CALLBACK_PARAMETER_TYPES,
                ));

                if ($forbidden !== []) {
                    $offenders[] = sprintf('%s is typed %s', $signature, implode('|', $forbidden));
                }
            }
        }

        self::assertNotSame([], $inspected, sprintf('%s exposes no public parameter at all, so this guard read nothing.', $class));

        self::assertSame(
            [],
            $offenders,
            'The BackURL is unsigned — no HMAC, no signature, no shared secret, so anyone '
            . 'can forge resposneCode=00 (CONVENTIONS.md §4.10). Only a server-side round trip can '
            . 'say what happened to a payment, so a callback may reach this surface only as the '
            . 'VposCallback marker type — that type is the checkpoint, and Vpos::verify() is the '
            . 'method that takes it. What is forbidden, and what this guard checks, is '
            . 'the raw value arriving instead: a parameter named resposneCode, responseCode or '
            . 'opaque, or one typed with the package\'s response-code value object, which would '
            . 'be a forged outcome wearing the SDK\'s own type. Renaming the parameter is not '
            . 'the fix. Take a VposCallback and call verify(), or take an identifier the gateway '
            . 'can be asked about.',
        );
    }

    /**
     * No public method other than a constructor mentions an `@internal` type in
     * its signature.
     *
     * Parameters and return type both, since either direction exports the type.
     * Union and intersection types are flattened rather than skipped: a
     * `HttpTransport|null` return is the same leak as a bare one, and skipping
     * a shape the guard does not understand is how a guard fails open.
     *
     * @param class-string $class
     */
    #[DataProvider('publicSurfaceClasses')]
    public function testNoInternalTypeAppearsOnAPublicMethodSignature(string $class): void
    {
        $inspected = [];
        $offenders = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            $inspected[] = sprintf('%s::%s()', $class, $method->getName());
            $named = $this->typeNames($method->getReturnType());

            foreach ($method->getParameters() as $parameter) {
                $named = array_merge($named, $this->typeNames($parameter->getType()));
            }

            foreach ($this->internalTypes() as $internal) {
                if (in_array($internal, $named, true)) {
                    $offenders[] = sprintf('%s::%s() names %s', $class, $method->getName(), $internal);
                }
            }
        }

        self::assertNotSame([], $inspected, sprintf('%s exposes no public method other than a constructor.', $class));

        self::assertSame(
            [],
            $offenders,
            'Everything under Http\\ and Support\\ is `@internal` (CONVENTIONS.md §5). A consumer '
            . 'who can see one of those types on a public signature can depend on it, and once '
            . 'they do it is public whatever the annotation says.',
        );
    }

    /**
     * Every `@internal` collaborator this surface holds is held in a
     * **non-public** property, and every one the constructor takes is held.
     *
     * Stated positively, and deliberately so. The first version of this guard
     * asked "is any *public* property typed with an internal type", iterating
     * `ReflectionProperty::IS_PUBLIC` — and no class on this surface has a
     * public property at all, so the loop never ran and the assertion compared
     * `[]` with `[]` forever. It could not fail, which means it was not
     * checking anything: the same decorative shape found in the `@internal`
     * annotation check, and in several guards before it.
     *
     * The claim below is falsifiable in both of its halves. Making a client's
     * `$transport` public fails the export half. Deleting the property while
     * the constructor still takes an `HttpTransport` — reaching for it some
     * other way, a static, a container, a global — fails the second: a
     * collaborator taken and not stored is a collaborator this file can no
     * longer see, and an unseen one is unguarded.
     *
     * Not data-provider-driven, because per class it would be vacuous again:
     * `Vpos` legitimately holds no internal type. Iterating the whole derived
     * surface at once lets a single floor assertion prove the loop ran, which
     * is the same shape as
     * testAtLeastOneConstructorIsCoveredByTheInternalAnnotationRule() below.
     */
    public function testEveryInternalCollaboratorIsHeldInANonPublicProperty(): void
    {
        $internalTypes = $this->internalTypes();
        $inspected = [];
        $offenders = [];

        foreach (self::publicSurfaceClasses() as [$class]) {
            $reflection = new ReflectionClass($class);
            $held = [];

            foreach ($reflection->getProperties() as $property) {
                $named = array_values(array_intersect($this->typeNames($property->getType()), $internalTypes));

                if ($named === []) {
                    continue;
                }

                $held = array_merge($held, $named);
                $inspected[] = sprintf('%s::$%s', $class, $property->getName());

                if ($property->isPublic()) {
                    $offenders[] = sprintf(
                        '%s::$%s is a public %s',
                        $class,
                        $property->getName(),
                        implode('|', $named),
                    );
                }
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                foreach (array_intersect($this->typeNames($parameter->getType()), $internalTypes) as $taken) {
                    if (!in_array($taken, $held, true)) {
                        $offenders[] = sprintf(
                            '%s::__construct() takes %s and no property of that type holds it',
                            $class,
                            $taken,
                        );
                    }
                }
            }
        }

        self::assertNotSame(
            [],
            $inspected,
            'No class on the public surface holds an `@internal` type in a property at all, so this guard read nothing.',
        );

        self::assertSame(
            [],
            $offenders,
            'A public property typed with an `@internal` type exports it as surely as a '
            . 'getter would, and without even a method name to grep for; and an internal '
            . 'collaborator the constructor takes but never stores is one this guard cannot see.',
        );
    }

    /**
     * Every class on this surface is `final`.
     *
     * CONVENTIONS.md §8 forbids a second non-final class in `src/` —
     * `ApiException` is the one permitted exception, because the exception
     * hierarchy *is* its extension point (§5). The project enforces that per
     * directory: tests/Response/ResponseDtoTest.php holds it for
     * `src/Response/` and tests/Exception/ExceptionHierarchyTest.php for
     * `src/Exception/`.
     *
     * `src/Client/` arrived later and the invariant arrived uncovered — a
     * non-final `PaymentsClient` would have failed no gate. This inherits the
     * derived subject list above, so it covers `src/Client/` and `Vpos` today
     * and a fifth client the moment one exists.
     *
     * @param class-string $class
     */
    #[DataProvider('publicSurfaceClasses')]
    public function testEveryClassOnThePublicSurfaceIsFinal(string $class): void
    {
        self::assertTrue(
            (new ReflectionClass($class))->isFinal(),
            sprintf(
                '%s is not final. CONVENTIONS.md §5 is final-by-default and §8 permits exactly one '
                . 'exception, ApiException, whose subclasses are the extension point.',
                $class,
            ),
        );
    }

    /**
     * A constructor that does take an `@internal` type says so.
     *
     * This is the exemption the test above grants, made explicit: the
     * sub-client constructors take an `HttpTransport` because they must, and
     * they are annotated `@internal` so a consumer's static analyser reports
     * the call. An unannotated one would be a public factory for a private
     * type.
     *
     * Vpos's constructor takes none, so it is not annotated and is not
     * required to be — the condition is checked before the assertion rather
     * than the annotation being demanded of every class.
     *
     * @param class-string $class
     */
    #[DataProvider('publicSurfaceClasses')]
    public function testAConstructorTakingAnInternalTypeIsAnnotatedInternal(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $named = [];

        if ($constructor !== null && $constructor->isPublic()) {
            foreach ($constructor->getParameters() as $parameter) {
                $named = array_merge($named, $this->typeNames($parameter->getType()));
            }
        }

        $docblock = $constructor?->getDocComment();
        $unannotated = [];

        foreach ($this->internalTypes() as $internal) {
            if (!in_array($internal, $named, true)) {
                continue;
            }

            if (!is_string($docblock) || preg_match(self::INTERNAL_TAG, $docblock) !== 1) {
                $unannotated[] = sprintf('%s::__construct() takes %s', $class, $internal);
            }
        }

        self::assertSame(
            [],
            $unannotated,
            'A public constructor taking an `@internal` type must itself be annotated '
            . '`@internal`, or it is a public factory for a private type. Construct the '
            . 'operation clients through Vpos.',
        );
    }

    /**
     * Both sub-client constructors are in fact exercised by the check above.
     *
     * Without it, testAConstructorTakingAnInternalTypeIsAnnotatedInternal()
     * would pass on a surface where no constructor takes an internal type at
     * all — including one where a client had grown a public no-argument
     * constructor and reached for a transport some other way.
     */
    public function testAtLeastOneConstructorIsCoveredByTheInternalAnnotationRule(): void
    {
        $covered = [];

        foreach (self::publicSurfaceClasses() as [$class]) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                if (in_array(HttpTransport::class, $this->typeNames($parameter->getType()), true)) {
                    $covered[] = $class;
                }
            }
        }

        self::assertGreaterThanOrEqual(
            3,
            count($covered),
            'The three operation clients each take an HttpTransport; fewer were found, so the annotation rule guards nothing.',
        );
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
