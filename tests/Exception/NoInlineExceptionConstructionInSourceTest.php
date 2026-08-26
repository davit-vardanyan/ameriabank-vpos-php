<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Exception;

use function array_map;
use function array_unique;
use function class_exists;
use function count;
use function dirname;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;

use const T_AS;
use const T_CLASS;
use const T_COMMENT;
use const T_CONST;
use const T_DOC_COMMENT;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_STATIC;
use const T_STRING;
use const T_USE;
use const T_WHITESPACE;

use Throwable;

use function token_get_all;

/**
 * Every string this package can emit as an exception message is produced
 * inside an audited file, enforced against the shipped tree.
 *
 * ## What the invariant buys
 *
 * CONVENTIONS.md §6 forbids a credential, a PAN, an `ExpDate`, an
 * `ApprovalCode`, an `SSN` or a raw response body from ever reaching a log or
 * an exception message. An exception message reaches a log by definition, so
 * that promise is only as good as the set of places a message can be written.
 * Keeping every one of those places behind a named static factory in a known
 * set of files makes the promise auditable by reading that set and nothing
 * else — a reviewer can confirm it in one sitting instead of re-reading `src/`
 * on every change.
 *
 * That is only true while the set is exact. One inline `throw new
 * X(sprintf(…))` somewhere else turns the invariant into a tendency, and a
 * tendency cannot be audited: the reviewer who read the audited files would
 * have read the wrong thing and would not know it. That is why the invariant
 * is asserted mechanically rather than written down: this suite has repeatedly
 * found a guard to be decorative for precisely that reason — it was written
 * down rather than run.
 *
 * ## The audited surface
 *
 * `src/Exception/` — derived from the filesystem at test time, never written
 * down, so a new exception class joins the surface the moment it is added.
 *
 * Plus three named files. A hand-maintained entry earns its place only where
 * the source of truth cannot name the subject, and nothing can name these:
 * they are PSR-18 stand-ins, classified by the interface each implements
 * rather than by what they construct, and they do not live in `src/Exception/`
 * because that directory is homogeneous — every class in it implements
 * `VposExceptionInterface`, which `ExceptionHierarchyTest` enforces. Each
 * entry carries its one-line reason below. They are not excluded by a silent
 * filter; they are listed, and
 * `testEveryNamedFileStillEarnsItsPlaceOnTheSurface()` fails if one of them
 * stops constructing anything.
 *
 * ## What this test does not catch
 *
 * Stated plainly, because a guard that overstates its reach is worse than none:
 *
 * - **A dynamic class name.** `new $class(...)` and `new ('Foo' . 'Exception')`
 *   are invisible to a static scan. Pinned as blind spots below so the gap is
 *   recorded rather than assumed away.
 * - **An anonymous class.** `new class extends RuntimeException {}` names
 *   nothing to resolve. Also pinned.
 * - **A message assembled elsewhere and passed in.** The invariant is about
 *   where an exception is *constructed*; a factory handed a pre-built string
 *   still satisfies it. Reading `src/Exception/` is what catches that, and this
 *   guard is what makes reading it sufficient.
 * - **A Throwable whose class cannot be autoloaded and is named neither
 *   `*Exception` nor `*Error`.** Resolution falls back to the name for classes
 *   that do not exist, which is what lets the fixture tree and the snippets
 *   below be checked without autoloading them.
 * - **A group import.** The resolver reads `use A\B\C;` and `use A\B\C as D;`
 *   and nothing else, so `use A\{B, C};` would resolve wrongly.
 *   `testNoSourceFileUsesAGroupImportTheResolverCannotRead()` turns that from a
 *   silent miss into a failure.
 *
 * ## Why tokens rather than the grep the rule describes
 *
 * The rule's own wording is `grep -rn "new [A-Za-z]*Exception(" src/`, and that
 * misses three things this scan catches: a Throwable not named `*Exception`
 * (`new Error`, `new TypeError`), a fully-qualified or aliased construction, and
 * — the one that matters most here — the string `new SomethingException(` inside
 * a docblock. `src/Http/` explains its own exception handling at length, so a
 * textual matcher would either flag those sentences or be weakened until it
 * flagged nothing. Tokens drop comments and string literals, which are the two
 * constructs that cannot contain a construction, and nothing else.
 *
 * Resolution is by autoload where the class exists, so the question asked is
 * "is this a Throwable" rather than "is this spelled like one".
 */
#[CoversNothing]
final class NoInlineExceptionConstructionInSourceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    private const string EXCEPTION_DIRECTORY = self::SOURCE . '/Exception';

    /**
     * The audited files that are not in `src/Exception/`.
     *
     * One line of reason each, as a written-out entry owes. All three share
     * it: this is a site where a string this package did **not** write becomes
     * an exception message.
     *
     * @var list<string>
     */
    private const array AUDITED_FILES_OUTSIDE_THE_EXCEPTION_DIRECTORY = [
        // Foreign message: carries getMessage() from a PSR-18 failure that is
        // neither network- nor request-shaped, about a request this package
        // could not inspect.
        'Http/RedactedClientException.php',
        // Foreign message: carries getMessage() from the consumer's PSR-18
        // client, about a request whose body held the merchant's password.
        'Http/RedactedNetworkException.php',
        // Foreign message: same, for a request the client rejected before
        // sending it.
        'Http/RedactedRequestException.php',
    ];

    /**
     * The guard proper.
     */
    public function testNoFileOutsideTheAuditedSurfaceConstructsAnException(): void
    {
        self::assertSame(
            [],
            $this->constructionSitesOutsideTheAuditedSurface(),
            'Every exception this package throws must be built by a named static factory living in '
            . 'src/Exception/ or in one of the three PSR-18 stand-ins. An inline construction '
            . 'anywhere else puts an emittable message outside the set of files a reviewer reads to '
            . 'confirm CONVENTIONS.md §6, which is the entire value of the rule.',
        );
    }

    /**
     * The surface is what this file claims it is: a derived half and three
     * named files.
     *
     * Asserted so that a wrong path — a renamed directory, a moved stand-in —
     * fails here rather than quietly widening or emptying the scan.
     */
    public function testTheAuditedSurfaceIsTheExceptionDirectoryPlusThreeNamedFiles(): void
    {
        $derived = $this->auditedFilesInTheExceptionDirectory();

        self::assertNotSame([], $derived, 'No files found under src/Exception/: the derived half is reading nothing.');
        self::assertContains('Exception/ValidationException.php', $derived);
        self::assertContains('Exception/ApiException.php', $derived);

        foreach (self::AUDITED_FILES_OUTSIDE_THE_EXCEPTION_DIRECTORY as $named) {
            self::assertFileExists(
                self::SOURCE . '/' . $named,
                sprintf('%s is named as audited but is not there. A stale entry exempts nothing and hides that.', $named),
            );
            self::assertNotContains($named, $derived, 'A named entry must not double as a derived one.');
        }
    }

    /**
     * A named entry that constructs nothing is an exemption nobody is using.
     *
     * The three stand-ins are on the surface because a foreign message becomes
     * an exception message inside them. If that stops being true — the factory
     * moves, the class is deleted — the entry must go with it, and the way to
     * make sure it does is to fail when it stops earning its place.
     */
    public function testEveryNamedFileStillEarnsItsPlaceOnTheSurface(): void
    {
        foreach (self::AUDITED_FILES_OUTSIDE_THE_EXCEPTION_DIRECTORY as $named) {
            self::assertNotSame(
                [],
                $this->constructionLines($this->contentsOf(self::SOURCE . '/' . $named)),
                sprintf('%s is named as audited but constructs no exception. Remove the entry.', $named),
            );
        }
    }

    /**
     * The matcher is the whole guard, so both of its failure modes are pinned,
     * along with the three shapes it is known not to see.
     */
    public function testTheMatcherSeparatesAConstructionFromEverythingElse(): void
    {
        // Must be caught.
        self::assertSame([1], $this->constructionLinesOf('throw new ValidationException("x");'));
        self::assertSame([1], $this->constructionLinesOf('$e = new RuntimeException("x");'));
        self::assertSame(
            [1],
            $this->constructionLinesOf('throw new Error("x");'),
            'A Throwable not spelled *Exception is still a Throwable — this is why resolution autoloads.',
        );
        self::assertSame(
            [1],
            $this->constructionLinesOf(
                'throw new \DavitVardanyan\AmeriabankVpos\Exception\ValidationException("x");',
            ),
            'A fully-qualified construction must not evade the guard.',
        );
        self::assertSame(
            [3],
            $this->constructionLinesOf(
                "namespace N;\nuse DavitVardanyan\\AmeriabankVpos\\Exception\\ValidationException as Boom;\n"
                . 'throw new Boom("x");',
            ),
            'An aliased import must not evade the guard.',
        );
        self::assertSame(
            [1],
            $this->constructionLinesOf('namespace N; class FooException extends \RuntimeException {'
                . ' public static function make(): self { return new self("x"); } }'),
            'new self() inside an exception class is a construction; it is permitted by where it lives, not by what it is.',
        );
        self::assertSame(
            [1],
            $this->constructionLinesOf('$c = fn() => new SerializationException("x");'),
            'A construction inside a closure counts.',
        );
        self::assertSame(
            [1, 3],
            $this->constructionLinesOf("throw new AException('a');\n\$x = 1;\nthrow new BException('b');"),
        );

        // Must not be caught: not a Throwable, or not code.
        self::assertSame([], $this->constructionLinesOf('$d = new \DateTimeImmutable("now");'));
        self::assertSame([], $this->constructionLinesOf('$r = new \ReflectionProperty("A", "b");'));
        self::assertSame(
            [],
            $this->constructionLinesOf('// throw new ValidationException("x");'),
            'A docblock explaining a throw is the main reason this matcher runs over tokens.',
        );
        self::assertSame(
            [],
            $this->constructionLinesOf('/** Never write throw new ValidationException("x") here. */ $a = 1;'),
        );
        self::assertSame(
            [],
            $this->constructionLinesOf('$m = \'throw new ValidationException("x");\';'),
            'A string literal is not code.',
        );

        // Known blind spots, pinned so the gap is recorded rather than assumed away.
        self::assertSame(
            [],
            $this->constructionLinesOf('$e = new $class("x");'),
            'A dynamic class name is invisible to a static scan. Stated in the class docblock.',
        );
        self::assertSame(
            [],
            $this->constructionLinesOf('$e = new class("x") extends \RuntimeException {};'),
            'An anonymous class names nothing to resolve. Stated in the class docblock.',
        );
    }

    /**
     * The scanner reports a violation in a fixture tree.
     *
     * A guard with no test of its own is the defect that has already cost this
     * suite a review cycle. The fixture lives in a temporary directory and
     * never in `src/`: an interrupted run must not be able to leave a
     * throwaway file in the shipped package. The violation is one level down,
     * so a scanner that stopped recursing would report this tree as clean.
     */
    public function testTheScannerReportsAViolationInAFixtureTree(): void
    {
        $root = sys_get_temp_dir() . '/vpos-factory-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Client';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($root . '/Clean.php', "<?php\n\n\$d = new \\DateTimeImmutable('now');\n");
            file_put_contents($nested . '/Dirty.php', "<?php\n\nthrow new ValidationException('x');\n");
            file_put_contents($nested . '/NotPhp.txt', "throw new ValidationException('x');\n");

            self::assertSame(['Client/Dirty.php:3'], $this->constructionSitesIn($root, []));
        } finally {
            foreach (['/Clean.php', '/Client/Dirty.php', '/Client/NotPhp.txt'] as $file) {
                if (is_file($root . $file)) {
                    unlink($root . $file);
                }
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * The exemption is per file, not per directory.
     *
     * `src/Http/` holds three audited files and eight that are not, and the
     * difference between "three named stand-ins" and "everything under Http/"
     * is the whole substance of the ruling this guard implements.
     */
    public function testTheExemptionAppliesToTheNamedFileAndNotToItsDirectory(): void
    {
        $root = sys_get_temp_dir() . '/vpos-factory-scope-' . bin2hex(random_bytes(8));
        $nested = $root . '/Http';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($nested . '/RedactedClientException.php', "<?php\n\nthrow new AException('x');\n");
            file_put_contents($nested . '/Redactor.php', "<?php\n\nthrow new BException('x');\n");

            self::assertSame(
                ['Http/Redactor.php:3'],
                $this->constructionSitesIn($root, ['Http/RedactedClientException.php']),
            );
        } finally {
            foreach (['/Http/RedactedClientException.php', '/Http/Redactor.php'] as $file) {
                unlink($root . $file);
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * The scan must actually reach the tree it claims to scan.
     *
     * Without this the guard passes trivially the day someone moves `src/`: an
     * empty file list yields an empty hit list, which is indistinguishable from
     * a clean tree.
     */
    public function testTheScanReachesEveryDirectoryOfTheSourceTree(): void
    {
        $found = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            $found[] = $this->relativeTo(self::SOURCE, $path);
        }

        self::assertContains('Http/HttpTransport.php', $found);
        self::assertContains('Exception/ValidationException.php', $found);
        self::assertContains('Client/PaymentsClient.php', $found);

        $directories = array_unique(array_map(dirname(...), $found));

        self::assertGreaterThan(
            1,
            count($directories),
            'The scan found files in only one directory of src/ — a scanner that '
            . 'stopped recursing at the root would look identical to a clean tree.',
        );
    }

    /**
     * The resolver reads one import form; this makes the other one loud.
     *
     * A group import would be resolved wrongly rather than reported, and a
     * wrongly-resolved name is a silent exemption. Turning it into a failure is
     * cheaper than teaching the resolver a form this package does not use.
     */
    public function testNoSourceFileUsesAGroupImportTheResolverCannotRead(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            if (preg_match('/^\s*use\b[^;]*\{/m', $this->contentsOf($path)) === 1) {
                $offenders[] = $this->relativeTo(self::SOURCE, $path);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'The name resolver in this guard reads `use A\B\C;` and `use A\B\C as D;` only. A group import '
            . 'would resolve to the wrong class and silently exempt a construction.',
        );
    }

    /**
     * Every construction site in `src/` that is not on the audited surface, as
     * "path:line", sorted.
     *
     * @return list<string>
     */
    private function constructionSitesOutsideTheAuditedSurface(): array
    {
        $exempt = self::AUDITED_FILES_OUTSIDE_THE_EXCEPTION_DIRECTORY;

        foreach ($this->auditedFilesInTheExceptionDirectory() as $derived) {
            $exempt[] = $derived;
        }

        return $this->constructionSitesIn(self::SOURCE, $exempt);
    }

    /**
     * Every construction site under $directory whose file is not in $exempt, as
     * "path:line" relative to $directory, sorted.
     *
     * @param list<string> $exempt
     *
     * @return list<string>
     */
    private function constructionSitesIn(string $directory, array $exempt): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $relative = $this->relativeTo($directory, $path);

            if (in_array($relative, $exempt, true)) {
                continue;
            }

            foreach ($this->constructionLines($this->contentsOf($path)) as $line) {
                $sites[] = $relative . ':' . $line;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * The derived half of the audited surface: every file under
     * `src/Exception/`, relative to `src/`, sorted.
     *
     * Read off the filesystem so a new exception class is covered the moment it
     * lands, and recursively so a subdirectory would be too.
     *
     * @return list<string>
     */
    private function auditedFilesInTheExceptionDirectory(): array
    {
        $files = [];

        foreach ($this->phpFilesIn(self::EXCEPTION_DIRECTORY) as $path) {
            $files[] = $this->relativeTo(self::SOURCE, $path);
        }

        return $files;
    }

    /**
     * 1-based line numbers of every place $php constructs a Throwable.
     *
     * @return list<int>
     */
    private function constructionLines(string $php): array
    {
        $tokens = $this->significantTokens($php);
        $namespace = $this->namespaceOf($tokens);
        $imports = $this->importsOf($tokens);
        $enclosing = $this->declaredClassOf($tokens, $namespace);

        $lines = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_NEW) {
                continue;
            }

            $name = $this->constructedNameAt($tokens, $index + 1);

            if ($name === null) {
                continue;
            }

            if ($this->isThrowable($this->resolve($name, $namespace, $imports, $enclosing))) {
                $lines[] = $token['line'];
            }
        }

        return $lines;
    }

    /**
     * The class name written after a `new`, or null when there is none to read.
     *
     * Null covers the two blind spots the class docblock records: `new $class`
     * (a T_VARIABLE) and `new class {…}` (a T_CLASS).
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function constructedNameAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index] ?? null;

        if ($token === null) {
            return null;
        }

        if ($token['id'] === T_STATIC) {
            return 'static';
        }

        $nameIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

        return in_array($token['id'], $nameIds, true) ? $token['text'] : null;
    }

    /**
     * $name as a fully-qualified class name, given the file it was written in.
     *
     * `self`, `static` and `parent` resolve to the class the file declares:
     * whether such a construction is permitted is decided by which file it sits
     * in, which is exactly what the caller then asks.
     *
     * A name that resolves to nothing loadable is handed back as written, so
     * that isThrowable() can fall back to the spelling. That is what lets the
     * fixture trees and the pinned snippets be checked without autoloading a
     * class that does not exist.
     *
     * @param array<string, string> $imports
     */
    private function resolve(string $name, string $namespace, array $imports, string $enclosing): string
    {
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return $enclosing;
        }

        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $separator = strpos($name, '\\');
        $head = $separator === false ? $name : substr($name, 0, $separator);
        $imported = $imports[strtolower($head)] ?? null;

        if ($imported !== null) {
            return $separator === false ? $imported : $imported . substr($name, $separator);
        }

        if ($namespace !== '' && $this->exists($namespace . '\\' . $name)) {
            return $namespace . '\\' . $name;
        }

        return $name;
    }

    /**
     * Whether $class is a Throwable.
     *
     * Answered by autoload where the class is loadable, which catches `Error`
     * and every vendor Throwable not spelled `*Exception`. Where it is not —
     * a fixture, a pinned snippet, a class deleted in the same edit — the
     * spelling is the fallback, which is the rule's own wording and is stated as
     * a limit on the class docblock.
     */
    private function isThrowable(string $class): bool
    {
        if ($class === '') {
            return false;
        }

        if (class_exists($class)) {
            return is_a($class, Throwable::class, true);
        }

        $separator = strrpos($class, '\\');
        $short = $separator === false ? $class : substr($class, $separator + 1);

        return str_ends_with($short, 'Exception') || str_ends_with($short, 'Error');
    }

    /**
     * The namespace $tokens declares, or '' for the global one.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function namespaceOf(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_NAMESPACE) {
                return $tokens[$index + 1]['text'] ?? '';
            }
        }

        return '';
    }

    /**
     * The file's class imports, keyed by lowercased alias.
     *
     * Only depth-0 `use` statements are read, which is what separates an import
     * from a trait use inside a class body and from a closure's `use (…)`.
     * `use function` and `use const` are skipped: neither can name a class.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     *
     * @return array<string, string>
     */
    private function importsOf(array $tokens): array
    {
        $imports = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token['text'] === '{') {
                ++$depth;

                continue;
            }

            if ($token['text'] === '}') {
                --$depth;

                continue;
            }

            if ($token['id'] !== T_USE || $depth !== 0) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;

            if ($name === null || in_array($name['id'], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $alias = ($tokens[$index + 2]['id'] ?? 0) === T_AS
                ? ($tokens[$index + 3]['text'] ?? '')
                : $this->shortNameOf($name['text']);

            if ($alias !== '') {
                $imports[strtolower($alias)] = $name['text'];
            }
        }

        return $imports;
    }

    /**
     * The fully-qualified name of the class $tokens declares, or '' for none.
     *
     * A `new class {…}` is skipped: T_CLASS preceded by T_NEW is an anonymous
     * class, which declares no name for `self` to mean.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function declaredClassOf(array $tokens, string $namespace): string
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_CLASS || ($tokens[$index - 1]['id'] ?? 0) === T_NEW) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;

            if ($name === null || $name['id'] !== T_STRING) {
                continue;
            }

            return $namespace === '' ? $name['text'] : $namespace . '\\' . $name['text'];
        }

        return '';
    }

    private function shortNameOf(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * class_exists() without the fatal an unloadable name can raise, and
     * interfaces too, so that resolution does not depend on which of the two a
     * name turns out to be.
     */
    private function exists(string $class): bool
    {
        return class_exists($class) || interface_exists($class);
    }

    /**
     * $php as a list of code tokens, with whitespace, comments and docblocks
     * dropped and every token carrying the line it starts on.
     *
     * Single-character tokens come out of token_get_all() as bare strings with
     * no line number, so the line is tracked as the stream is walked. Dropping
     * comments is what lets `src/Http/` explain its exception handling at length
     * without tripping the guard that constrains it.
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
     * The matcher, applied to a bare snippet.
     *
     * The open tag is on the same line as the snippet so that line numbers read
     * as the snippet is written.
     *
     * @return list<int>
     */
    private function constructionLinesOf(string $snippet): array
    {
        return $this->constructionLines('<?php ' . $snippet);
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
     * Absolute paths of every .php file under $directory, recursively, sorted.
     *
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
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
                foreach ($this->phpFilesIn($path) as $nested) {
                    $files[] = $nested;
                }

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Strips $root from $path, so a failure message is readable.
     */
    private function relativeTo(string $root, string $path): string
    {
        $prefix = $root . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
