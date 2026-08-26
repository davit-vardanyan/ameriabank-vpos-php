<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests;

use function array_filter;
use function array_keys;
use function array_map;
use function array_shift;
use function array_unique;
use function array_values;
use function basename;
use function bin2hex;
use function class_exists;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function in_array;
use function interface_exists;
use function is_array;
use function is_dir;
use function ksort;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function random_bytes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

use function rmdir;
use function rtrim;
use function sort;

use SplFileInfo;

use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function sys_get_temp_dir;

use const T_ABSTRACT;
use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_ENUM;
use const T_FINAL;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_NEW;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_READONLY;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;

use function token_get_all;
use function trait_exists;
use function unlink;

/**
 * Every class in `src/` is `final`, held against the shipped tree.
 *
 * CONVENTIONS.md §5 says so — "All classes `final`, with one exception:
 * `ApiException` is non-final because the exception hierarchy *is* its
 * extension point" — and §8 repeats it as a prohibition. What was missing
 * until this file existed was not enforcement but *package-wide* enforcement:
 * finality was already held per directory, by four separate tests, each
 * deriving its own subjects and each doing that job well.
 *
 * - `tests/Exception/ExceptionHierarchyTest.php:325`, `:334` — `src/Exception/`,
 *   including the `ApiException` carve-out.
 * - `tests/Response/ResponseDtoTest.php:270` — the response DTOs its own
 *   provider derives, which is not the same set as `src/Response/`:
 *   `src/Response/ResponseCode.php` is not a DTO and was not among them.
 * - `tests/Callback/CallbackSurfaceTest.php:469` — `src/Callback/`.
 * - `tests/Client/PublicSurfaceTest.php:882` — `src/Client/` and `src/Vpos.php`.
 *
 * What that left is the gap this file closes: a non-final class passed all nine
 * gates in `src/Config/`, `src/Http/`, `src/Money/`, `src/Request/` and
 * `src/Support/`, and also in `src/Response/ResponseCode.php` — inside a
 * directory that looked covered. (`src/Contracts/` and `src/Enum/` appear on
 * neither list because neither holds a class at all.)
 *
 * That gap was measured, not assumed. With this file removed, `final` was
 * stripped from ten classes one at a time and the suite run for each. Four went
 * red — `src/Response/BankInfo.php`, `src/Callback/VposCallback.php`,
 * `src/Client/ReportsClient.php`, `src/Vpos.php`. Six stayed green —
 * `src/Money/Amount.php`, `src/Config/Credentials.php`, `src/Http/Redactor.php`,
 * `src/Support/ExceptionState.php`, `src/Request/GetBindingsRequest.php`,
 * `src/Response/ResponseCode.php`.
 *
 * The reasoning that finds this is already written down twice in this file —
 * `testTheExemptionAppliesToTheNamedFileAndNotToItsDirectory()` says a second
 * guard covering the same subjects is "precisely the kind of overlap that makes
 * a hole invisible" — and it was not applied to this docblock, which claimed
 * "exactly one directory" until a reviewer ran the control above. A guard that
 * misdescribes what preceded it overstates its own reach — the failure
 * "What this guard does not catch" below calls worse than no guard at all.
 *
 * ## Why the subject list is walked rather than written down
 *
 * The list of classes is derived from the filesystem at test time, recursively,
 * and appears nowhere in this file. A hand-maintained list silently exempts
 * everything not on it, which is the failure this package has now paid for
 * twice: three structural guards were once defeated at the same time by a class
 * that was simply not in their nine-row provider, and later
 * `Vpos::isSuccessful()` and `Vpos::verifyFromQuery()` went green through 1006
 * tests, 100% coverage and MSI 100% because the guard covering them was a
 * hand-maintained blocklist. Neither was ever committed — adversarial review
 * caught both before the commit — but neither was caught by anything
 * automated, and the whole gate line is what they passed.
 *
 * Recursion is part of the same point rather than a detail of the walk. A guard
 * that reads only the top level of `src/` does not fail when a subdirectory
 * appears — it stops guarding, and a guard that stops guarding looks exactly
 * like a clean tree. `testTheWalkReachesEveryDirectoryOfTheSourceTree()` is what
 * makes that visible.
 *
 * ## Why declarations are read from tokens and never from a regex
 *
 * A regex over source matches the word `class` inside a docblock, inside a
 * string literal, and inside the `::class` constant, and every one of those is
 * a false positive that gets a matcher weakened until it catches nothing real.
 * This suite has found the inverse and worse: a guard asserting the absence of
 * `@internal` in a docblock passed for every subject because each docblock
 * *narrated* the phrase `@internal`, so the guard's own subject matter was its
 * only match. Tokens drop comments and string literals, which are precisely
 * the two constructs that cannot contain a declaration, and nothing else.
 *
 * Three token traps are handled deliberately, because each one is a place a
 * naive `T_CLASS` scan reads a declaration that is not there:
 *
 * - **`Foo::class`** is the class-name constant. It is a `T_CLASS` token
 *   preceded by `T_DOUBLE_COLON`, and this package uses it in nearly every file.
 * - **`new class {…}`** is an anonymous class: `T_CLASS` preceded by `T_NEW`,
 *   with no name token after it.
 * - **A method named `class` or `enum`** — `class` is semi-reserved and `enum`
 *   is only soft-reserved, so `function enum()`, `$x->enum()` and `Foo::enum()`
 *   are all legal. Each is a keyword token in a name position rather than a
 *   declaration.
 *
 * All three are excluded by the same two conditions: the token after the keyword
 * must be the `T_STRING` that names the type, and the token before it must not
 * be one of the operators that put a keyword in a name position. Both are
 * applied, not one, and both are pinned in
 * `testTheDeclarationMatcherSeparatesAClassFromEverythingElse()`.
 *
 * ## What is not a class for this purpose
 *
 * Interfaces, enums and traits are excluded by the declaration check — they are
 * `T_INTERFACE`, `T_ENUM` and `T_TRAIT` tokens, not `T_CLASS` ones — and never
 * by the exemption list. That distinction matters: an interface cannot be
 * declared `final` at all, and an enum is implicitly final, so putting either on
 * an exemption list would be recording a permission that the language already
 * refuses to need. This package has eight enums and interfaces between them and
 * the list would then be nine entries long, eight of which nobody could ever
 * remove. `testInterfacesEnumsAndTraitsAreExcludedByTheDeclarationCheck()` holds
 * that split.
 *
 * ## What this guard does not catch
 *
 * Stated plainly, because a guard that overstates its reach is worse than none:
 *
 * - **An anonymous class.** `new class {}` has no name, so nothing can extend
 *   it and finality is unenforceable and vacuous. Pinned as a blind spot below
 *   rather than assumed away.
 * - **A class produced at runtime** by `eval()` or by a code generator. Nothing
 *   static can see it. This package has neither.
 * - **Whether `final` is the right call for a given class.** That is
 *   CONVENTIONS.md §5's judgment, already made; this file only checks it is
 *   still being kept.
 */
#[CoversNothing]
final class ClassFinalityTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../src';

    /**
     * The PSR-4 prefix `src/` maps to, so a path can be turned into the symbol
     * name the engine knows it by.
     */
    private const string NAMESPACE_PREFIX = 'DavitVardanyan\\AmeriabankVpos\\';

    /**
     * The one class in `src/` that is permitted to be non-final.
     *
     * Listed with its reason inline, never applied as a silent filter — a
     * filter that skips a file tells no reader why, and a reason that lives in
     * a commit message is a reason nobody will find. Paths are relative to
     * `src/` and the exemption is per file, not per directory:
     * `src/Exception/` holds nine other classes and every one of them must
     * still be final, which `testTheExemptionAppliesToTheNamedFileAndNotToItsDirectory()`
     * holds.
     *
     * `testEveryExemptionStillEarnsItsPlace()` fails if an entry here stops
     * describing a non-final class, so a stale exemption cannot sit unnoticed
     * as dead permission.
     *
     * @var list<string>
     */
    private const array NON_FINAL_BY_DESIGN = [
        // src/Exception/ApiException.php — non-final by design; the exception
        // hierarchy is its extension point. CONVENTIONS.md §5.
        'Exception/ApiException.php',
    ];

    /**
     * The guard proper.
     */
    public function testEveryClassInSourceIsFinal(): void
    {
        self::assertSame(
            [],
            $this->nonFinalClassSitesIn(self::SOURCE, self::NON_FINAL_BY_DESIGN),
            'Every class in src/ must be declared final (CONVENTIONS.md §5, restated as a prohibition in §8). '
            . 'Each site above is reported as "path:line class Name", relative to src/. Declare the class '
            . 'final. If it genuinely cannot be — the only such case so far is a documented extension '
            . 'point — raise it rather than adding an entry to NON_FINAL_BY_DESIGN, because §8 forbids a '
            . 'second non-final class in src/ and this guard is not the place that decision is made. '
            . 'Note that an abstract class is reported here too: it is not final, and CONVENTIONS.md §5 '
            . 'permits no abstract base in src/ either.',
        );
    }

    /**
     * The exemption list is exactly what CONVENTIONS.md §5 authorises: one
     * entry, and it is the class that is actually non-final.
     *
     * Both halves are asserted against the tree rather than against a literal
     * copy of the list, which would be a tautology — the analyser can see that
     * a constant equals itself, and a test it can decide statically is a test
     * that cannot fail for a real reason. The count comes from the scan, so
     * §8's "no second non-final class in src/" is enforced by what the code is,
     * not by what this file remembers; the equality then says the list names
     * that class and nothing stale.
     */
    public function testExactlyOneClassInSourceIsNonFinalAndTheListNamesIt(): void
    {
        $nonFinal = $this->filesOfNonFinalClassesIn(self::SOURCE);

        self::assertCount(
            1,
            $nonFinal,
            'CONVENTIONS.md §5 permits exactly one non-final class in src/ and §8 forbids adding a second. '
            . 'Widening that is a change to the document before it is a change to the code, and it is '
            . 'not this file\'s to make.',
        );
        self::assertSame(
            self::NON_FINAL_BY_DESIGN,
            $nonFinal,
            'NON_FINAL_BY_DESIGN must name exactly the files that hold a non-final class — no stale entry '
            . 'authorising nothing, and no unlisted one.',
        );
    }

    /**
     * An exemption that no longer describes a non-final class is dead
     * permission, and dead permission is how a list starts drifting.
     *
     * The check is the guard's own scan run with no exemptions applied: every
     * exempted path must then appear as a violation. If `ApiException` is ever
     * declared `final`, the entry stops earning its place and this fails —
     * rather than sitting there quietly authorising something nobody is doing.
     * This shape keeps the audited surface readable from one directory.
     */
    public function testEveryExemptionStillEarnsItsPlace(): void
    {
        $unexempted = $this->nonFinalClassSitesIn(self::SOURCE, []);

        foreach (self::NON_FINAL_BY_DESIGN as $exempt) {
            self::assertFileExists(
                self::SOURCE . '/' . $exempt,
                sprintf('%s is exempted from the finality guard but is not there. A stale entry exempts nothing and hides that.', $exempt),
            );

            self::assertNotSame(
                [],
                array_values(array_filter(
                    $unexempted,
                    static fn(string $site): bool => str_starts_with($site, $exempt . ':'),
                )),
                sprintf(
                    '%s is exempted from the finality guard but declares no non-final class, so the '
                    . 'exemption is doing nothing. If the class was made final, delete the entry from '
                    . 'NON_FINAL_BY_DESIGN — and note that CONVENTIONS.md §5 states the opposite, that '
                    . 'ApiException is non-final because the exception hierarchy is its extension point, '
                    . 'so making it final is a change to the document before it is a change to the code.',
                    $exempt,
                ),
            );
        }
    }

    /**
     * Interfaces, enums and traits are excluded by what they are, not by being
     * listed.
     *
     * The package holds eight of them between interfaces and enums — six enums
     * (the five under `src/Enum/` plus `src/Config/Environment.php`, which is an
     * enum living in a configuration directory) and two interfaces
     * (`src/Contracts/RequestInterface.php` and
     * `src/Exception/VposExceptionInterface.php`). Every one is a real subject of
     * the walk: read, classified, and then not reported. The distinction is
     * asserted here so that a future change which starts reporting them cannot
     * be "fixed" by adding eight entries to the exemption list, which would bury
     * the one entry that matters.
     *
     * ## The counts are exact and derived, not "more than none"
     *
     * `assertGreaterThan(0, $kinds['enum'])` would pass while the walk saw one
     * enum of six — the walk could stop at `src/Config/` and this would still be
     * green. That is the same shape of hole as a hand-maintained subject list,
     * and it is the shape this whole file exists to close, so the expectation is
     * an exact count per kind.
     *
     * It is derived by reflection, which is a genuinely independent source of
     * truth from the thing under test: reflection asks the engine what a
     * loaded symbol *is*, where `declarationsIn()` reads a declaration off the
     * source text. Both sides must agree on all four kinds, so a file the
     * matcher misses is a shortfall and a file it misclassifies is a shift
     * between kinds.
     */
    public function testInterfacesEnumsAndTraitsAreExcludedByTheDeclarationCheck(): void
    {
        $kinds = ['class' => 0, 'enum' => 0, 'interface' => 0, 'trait' => 0];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            foreach ($this->declarationsIn($this->contentsOf($path)) as $declaration) {
                $kinds[$declaration['kind']] = ($kinds[$declaration['kind']] ?? 0) + 1;
            }
        }

        ksort($kinds);

        self::assertSame(
            $this->declaredKindsByReflection(),
            $kinds,
            'The declaration matcher must see every symbol under src/ and classify each one as the engine '
            . 'does. A kind that is short means the walk missed a file or the matcher missed a declaration; '
            . 'a kind that has moved to another means it misclassified one — and a matcher that reports an '
            . 'enum or an interface as a class would put subjects in front of the finality guard that the '
            . 'language will not let anyone satisfy.',
        );

        foreach (self::NON_FINAL_BY_DESIGN as $exempt) {
            foreach ($this->declarationsIn($this->contentsOf(self::SOURCE . '/' . $exempt)) as $declaration) {
                self::assertSame(
                    'class',
                    $declaration['kind'],
                    'The exemption list may only ever hold classes. An interface cannot be final and an enum '
                    . 'already is, so listing either would record a permission nobody could withdraw.',
                );
            }
        }
    }

    /**
     * The matcher is the whole guard, so both of its answers are pinned along
     * with the shapes it is known not to see.
     */
    public function testTheDeclarationMatcherSeparatesAClassFromEverythingElse(): void
    {
        // A final class, in every spelling this package's PHP version allows.
        self::assertSame([], $this->nonFinalClassesOf('final class A {}'));
        self::assertSame([], $this->nonFinalClassesOf('final readonly class A {}'));
        self::assertSame(
            [],
            $this->nonFinalClassesOf('readonly final class A {}'),
            'The modifiers may be written in either order, so finality is read by walking back over all of them.',
        );
        self::assertSame(
            [],
            $this->nonFinalClassesOf('#[SomeAttribute] final class A {}'),
            'An attribute sits between the docblock and the modifiers and must not hide them.',
        );
        self::assertSame(
            [],
            $this->nonFinalClassesOf("final /* why */ class A {}"),
            'Comments are dropped before the modifiers are read.',
        );

        // A non-final class, which is the violation.
        self::assertSame([['A', 1]], $this->nonFinalClassesOf('class A {}'));
        self::assertSame(
            [['A', 1]],
            $this->nonFinalClassesOf('abstract class A {}'),
            'An abstract class is not final. CONVENTIONS.md §5 permits no abstract base in src/, so this is a violation, not an exemption.',
        );
        self::assertSame(
            [['A', 1]],
            $this->nonFinalClassesOf('readonly class A {}'),
            'readonly is not final.',
        );
        self::assertSame(
            [['A', 1], ['B', 3]],
            $this->nonFinalClassesOf("class A {}\n\n class B {}"),
            'Every declaration in a file is reported, with its own line.',
        );
        self::assertSame(
            [['B', 1]],
            $this->nonFinalClassesOf('final class A {} class B {}'),
            'One final class in a file says nothing about the next one.',
        );

        // Not a class declaration.
        self::assertSame([], $this->nonFinalClassesOf('interface I {}'), 'An interface cannot be declared final.');
        self::assertSame([], $this->nonFinalClassesOf('enum E {}'), 'An enum is implicitly final.');
        self::assertSame([], $this->nonFinalClassesOf('enum E: string { case A = "a"; }'));
        self::assertSame([], $this->nonFinalClassesOf('trait T {}'), 'A trait cannot be instantiated or extended.');

        // Not a declaration at all: the token traps.
        self::assertSame(
            [],
            $this->nonFinalClassesOf('$name = Foo::class;'),
            'The ::class constant is a T_CLASS token and appears in nearly every file in src/.',
        );
        self::assertSame([], $this->nonFinalClassesOf('$map = [Foo::class => 1, Bar::class => 2];'));
        self::assertSame(
            [],
            $this->nonFinalClassesOf('final class A { public function enum(): void {} }'),
            'enum is only soft-reserved, so it is legal as a method name.',
        );
        self::assertSame(
            [],
            $this->nonFinalClassesOf('$x->enum(); $y::enum();'),
            'A keyword in a name position is not a declaration.',
        );
        self::assertSame(
            [],
            $this->nonFinalClassesOf('$m = "class A {}";'),
            'A string literal is not code — this is why the scan runs over tokens.',
        );
        self::assertSame(
            [],
            $this->nonFinalClassesOf('/** Never write class A {} here. */ $a = 1;'),
            'A docblock explaining the rule must not trip the guard enforcing it. This suite has lost a guard to exactly this.',
        );
        self::assertSame([], $this->nonFinalClassesOf('// class A {}'));

        // Known blind spot, pinned so the gap is recorded rather than assumed away.
        self::assertSame(
            [],
            $this->nonFinalClassesOf('$o = new class {};'),
            'An anonymous class has no name, so nothing can extend it and finality is vacuous. Stated in the class docblock.',
        );
    }

    /**
     * The scanner reports a non-final class that is one level down.
     *
     * The fixture lives in a temporary directory and never in `src/`: an
     * interrupted run must not be able to leave a throwaway file in the
     * shipped package. The violation is nested specifically, because a
     * top-level fixture would pass even a one-level walker and prove nothing —
     * which is the exact defect once found in the guard this file replaces.
     */
    public function testTheScannerReportsANonFinalClassInANestedFixtureTree(): void
    {
        $root = sys_get_temp_dir() . '/vpos-finality-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Enum/Deeper';

        self::assertTrue(mkdir($nested, 0o700, true));

        $files = [
            '/Clean.php' => "<?php\n\nfinal class Clean {}\n",
            '/Enum/Kind.php' => "<?php\n\nenum Kind: string { case A = 'a'; }\n",
            '/Enum/Deeper/Rogue.php' => "<?php\n\nclass Rogue {}\n",
            '/Enum/Deeper/Contract.php' => "<?php\n\ninterface Contract {}\n",
            '/Enum/Deeper/NotPhp.txt' => "class AlsoRogue {}\n",
        ];

        try {
            foreach ($files as $name => $contents) {
                file_put_contents($root . $name, $contents);
            }

            self::assertSame(
                ['Enum/Deeper/Rogue.php:3 class Rogue'],
                $this->nonFinalClassSitesIn($root, []),
                'A non-final class two directories down must be reported, and nothing else in the tree must be.',
            );
        } finally {
            foreach (array_keys($files) as $name) {
                unlink($root . $name);
            }

            rmdir($nested);
            rmdir($root . '/Enum');
            rmdir($root);
        }
    }

    /**
     * The exemption is per file, not per directory.
     *
     * `src/Exception/` holds ten classes and exactly one of them is exempt. A
     * filter written against the directory rather than the file would exempt
     * the other nine at the same time, and nothing else in this suite would
     * notice — `ExceptionHierarchyTest` derives its own subjects and would keep
     * covering them, which is precisely the kind of overlap that makes a hole
     * invisible.
     */
    public function testTheExemptionAppliesToTheNamedFileAndNotToItsDirectory(): void
    {
        $root = sys_get_temp_dir() . '/vpos-finality-scope-' . bin2hex(random_bytes(8));
        $nested = $root . '/Exception';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($nested . '/ApiException.php', "<?php\n\nclass ApiException {}\n");
            file_put_contents($nested . '/DeclinedException.php', "<?php\n\nclass DeclinedException {}\n");

            self::assertSame(
                ['Exception/DeclinedException.php:3 class DeclinedException'],
                $this->nonFinalClassSitesIn($root, self::NON_FINAL_BY_DESIGN),
            );
        } finally {
            unlink($nested . '/ApiException.php');
            unlink($nested . '/DeclinedException.php');
            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * The walk must actually reach the tree it claims to walk, and every
     * directory of it.
     *
     * Without this the guard passes trivially the day someone moves `src/`: an
     * empty file list yields an empty violation list, which is
     * indistinguishable from a clean tree.
     *
     * ## Both halves are derived, and set equality is the assertion
     *
     * Nothing here is written down — no file list, no directory list, and no
     * count. `phpFilesUnder()` re-walks the same tree through `glob()`, sharing
     * no code with the `RecursiveDirectoryIterator` under test, and the two
     * results must be identical as sets. A subdirectory added to `src/` and
     * missed by the walk therefore fails the build the day it appears, without
     * anyone remembering to extend a list.
     *
     * The two assertions are ordered so the failure names the cause. Directories
     * first: a walker that stopped recursing is missing whole directories, and
     * naming those reads far better than a diff of every file inside them. Files
     * second: a walk that reached every directory can still skip a file in one —
     * a filter on extension or on name — and that is a different fault. Neither
     * assertion is implied by the other in the order they are made, so both can
     * be made to fail.
     *
     * What was here before was a seven-entry `assertContains` list paired with
     * `assertGreaterThan(1, count($directories))`. `src/` has twelve
     * directories: a walker that found two would have satisfied that, and every
     * directory not among the seven was exempt by omission.
     */
    public function testTheWalkReachesEveryDirectoryOfTheSourceTree(): void
    {
        $expected = $this->phpFilesUnder(self::SOURCE);

        $walked = array_map(
            fn(string $path): string => $this->relativeTo(self::SOURCE, $path),
            $this->phpFilesIn(self::SOURCE),
        );

        self::assertNotSame(
            [],
            $expected,
            'src/ holds no .php file at all, so every assertion in this file is passing against an empty '
            . 'tree. Either the source directory moved or SOURCE no longer points at it.',
        );

        self::assertSame(
            $this->directoriesOf($expected),
            $this->directoriesOf($walked),
            'The walk did not reach every directory of src/. A walker that stopped recursing does not fail '
            . 'when a subdirectory appears — it stops guarding, and a guard that has stopped guarding looks '
            . 'exactly like a clean tree.',
        );

        self::assertSame(
            $expected,
            $walked,
            'The walk reached every directory of src/ but not every .php file in them, so some file is '
            . 'exempt from the finality guard without anything recording that it is.',
        );
    }

    /**
     * Every non-final class under $directory whose file is not in $exempt, as
     * "path:line class Name" relative to $directory, sorted.
     *
     * @param list<string> $exempt
     *
     * @return list<string>
     */
    private function nonFinalClassSitesIn(string $directory, array $exempt): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $relative = $this->relativeTo($directory, $path);

            if (in_array($relative, $exempt, true)) {
                continue;
            }

            foreach ($this->declarationsIn($this->contentsOf($path)) as $declaration) {
                if ($declaration['kind'] !== 'class' || $declaration['final']) {
                    continue;
                }

                $sites[] = sprintf('%s:%d class %s', $relative, $declaration['line'], $declaration['name']);
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * The files under $directory that declare at least one non-final class,
     * relative to $directory, deduplicated and sorted.
     *
     * The same scan as the guard, with no exemptions applied and the line and
     * class name dropped, so it can be compared with the exemption list
     * directly.
     *
     * @return list<string>
     */
    private function filesOfNonFinalClassesIn(string $directory): array
    {
        $files = [];

        foreach ($this->nonFinalClassSitesIn($directory, []) as $site) {
            $colon = strpos($site, ':');
            $files[substr($site, 0, $colon === false ? strlen($site) : $colon)] = true;
        }

        $paths = array_keys($files);

        sort($paths);

        return $paths;
    }

    /**
     * Every class, interface, enum and trait $php declares.
     *
     * The three token traps described on the class docblock are excluded by two
     * conditions applied together, belt and braces: the keyword must be
     * followed by the T_STRING naming the type, and must not be preceded by an
     * operator that puts a keyword in a name position.
     *
     * @return list<array{kind: string, name: string, line: int, final: bool}>
     */
    private function declarationsIn(string $php): array
    {
        $tokens = $this->significantTokens($php);
        $declarations = [];

        foreach ($tokens as $index => $token) {
            $kind = match ($token['id']) {
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_ENUM => 'enum',
                T_TRAIT => 'trait',
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            // Trap 1 and 2. `Foo::class` is followed by whatever ends the
            // expression, and `new class {…}` by `(` or `{` — neither is a
            // name, so a declaration must be followed by one.
            $name = $tokens[$index + 1] ?? null;

            if ($name === null || $name['id'] !== T_STRING) {
                continue;
            }

            // Trap 3. `class` is semi-reserved and `enum` only soft-reserved, so
            // both are legal as method names: `function enum()`, `$x->enum()`,
            // `$x?->enum()`, `Foo::enum()`. Each puts the keyword after an
            // operator rather than at the start of a declaration. T_NEW and
            // T_DOUBLE_COLON are listed again here rather than relied on above,
            // because two independent conditions are what make the exclusion
            // hold when either one is later loosened.
            $previous = $tokens[$index - 1]['id'] ?? 0;

            $namePositions = [
                T_DOUBLE_COLON,
                T_NEW,
                T_FUNCTION,
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
            ];

            if (in_array($previous, $namePositions, true)) {
                continue;
            }

            $declarations[] = [
                'kind' => $kind,
                'name' => $name['text'],
                'line' => $token['line'],
                'final' => $this->isPrecededByFinal($tokens, $index),
            ];
        }

        return $declarations;
    }

    /**
     * Whether the declaration at $index carries the `final` modifier.
     *
     * The modifiers may be written in either order — `final readonly class` and
     * `readonly final class` are both legal — so the walk goes back over every
     * modifier rather than inspecting only the token immediately before. It
     * stops at the first token that is not one, which is the docblock,
     * attribute or statement separator that precedes the declaration.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function isPrecededByFinal(array $tokens, int $index): bool
    {
        for ($position = $index - 1; $position >= 0; --$position) {
            $id = $tokens[$position]['id'];

            if ($id === T_FINAL) {
                return true;
            }

            if ($id === T_ABSTRACT || $id === T_READONLY) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * $php as a list of code tokens, with whitespace, comments and docblocks
     * dropped and every token carrying the line it starts on.
     *
     * Single-character tokens come out of token_get_all() as bare strings with
     * no line number, so the line is tracked as the stream is walked. Dropping
     * comments is what lets this file document the rule it enforces without
     * tripping over its own prose.
     *
     * @return list<array{id: int, text: string, line: int}>
     */
    private function significantTokens(string $php): array
    {
        $line = 1;
        $significant = [];

        foreach (token_get_all($php) as $token) {
            $id = is_array($token) ? $token[0] : 0;
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token)) {
                $line = $token[2];
            }

            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $significant[] = ['id' => $id, 'text' => $text, 'line' => $line];
            }

            $line += substr_count($text, "\n");
        }

        return $significant;
    }

    /**
     * The matcher applied to a bare snippet, as [name, line] pairs.
     *
     * The open tag is on the same line as the snippet so that line numbers read
     * as the snippet is written.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function nonFinalClassesOf(string $snippet): array
    {
        $found = [];

        foreach ($this->declarationsIn('<?php ' . $snippet) as $declaration) {
            if ($declaration['kind'] === 'class' && !$declaration['final']) {
                $found[] = [$declaration['name'], $declaration['line']];
            }
        }

        return $found;
    }

    /**
     * Not skipped silently: an unreadable file is a hole in the guard, and a
     * hole in this guard is indistinguishable from a clean tree.
     */
    private function contentsOf(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }

    /**
     * What the engine says each file under `src/` declares, counted per kind.
     *
     * Derived rather than written down, and derived from a source of
     * truth the thing under test does not share: the class name comes from the
     * path by PSR-4, the symbol is loaded, and the engine is asked what it is.
     * A file whose declaration does not match its path fails here rather than
     * being silently counted as something else, because PSR-4 could not have
     * autoloaded it either.
     *
     * The file list comes from `phpFilesUnder()`, not from `phpFilesIn()`. An
     * expectation built on the walk under test cannot fail when that walk misses
     * a file: both sides would miss it and the comparison would stay green.
     *
     * @return array{class: int, enum: int, interface: int, trait: int}
     */
    private function declaredKindsByReflection(): array
    {
        $kinds = ['class' => 0, 'enum' => 0, 'interface' => 0, 'trait' => 0];

        foreach ($this->phpFilesUnder(self::SOURCE) as $relative) {
            $symbol = self::NAMESPACE_PREFIX . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));

            if (!class_exists($symbol) && !interface_exists($symbol) && !trait_exists($symbol)) {
                self::fail(sprintf(
                    'src/%s does not declare %s, so PSR-4 cannot autoload it and no gate reads it. Either '
                    . 'the file name and the declaration disagree, or the namespace does.',
                    $relative,
                    $symbol,
                ));
            }

            $reflection = new ReflectionClass($symbol);

            $kind = match (true) {
                $reflection->isEnum() => 'enum',
                $reflection->isInterface() => 'interface',
                $reflection->isTrait() => 'trait',
                default => 'class',
            };

            ++$kinds[$kind];
        }

        return $kinds;
    }

    /**
     * The distinct directories $files live in, relative and sorted, with the
     * root of the tree reading as `.`.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function directoriesOf(array $files): array
    {
        $directories = array_values(array_unique(array_map(dirname(...), $files)));

        sort($directories);

        return $directories;
    }

    /**
     * Every .php file under $root, relative to it, sorted — found by expanding
     * `glob()` directory by directory.
     *
     * Deliberately a second walk rather than a call to `phpFilesIn()`. This one
     * is the expectation and that one is the subject, so they must not share an
     * implementation: a bug in `RecursiveDirectoryIterator`'s configuration —
     * a depth limit, a skipped dot directory, a filter — has to show up as a
     * difference between the two, which it cannot do if one is written in terms
     * of the other.
     *
     * @return list<string>
     */
    private function phpFilesUnder(string $root): array
    {
        $files = [];
        $pending = [''];

        while ($pending !== []) {
            $prefix = array_shift($pending);
            $entries = glob(rtrim($root . '/' . $prefix, '/') . '/*');

            self::assertIsArray($entries, sprintf('Could not list %s%s.', $root . '/', $prefix));

            foreach ($entries as $entry) {
                $relative = $prefix . basename($entry);

                if (is_dir($entry)) {
                    $pending[] = $relative . '/';

                    continue;
                }

                if (str_ends_with($relative, '.php')) {
                    $files[] = $relative;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Absolute paths of every .php file under $directory, recursively, sorted.
     *
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Strips $root from $path, so a failure message names a file a reader can
     * open.
     */
    private function relativeTo(string $root, string $path): string
    {
        $prefix = $root . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
