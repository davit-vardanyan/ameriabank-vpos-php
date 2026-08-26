<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Support;

use function count;
use function file_get_contents;
use function in_array;
use function is_array;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function token_get_all;

/**
 * The rule, restated by the hydrator's own docblock: `Currency::default()` is
 * never called when hydrating a response.
 *
 * A monetary field becomes an Amount only if the response's own `Currency`
 * field resolves. When it does not, the Amount stays null and the raw scalar
 * remains as the record of what arrived. Substituting a default would stamp
 * AMD on a foreign-currency transaction and produce a wrong amount that looks
 * right — and CONVENTIONS.md §4.7 records that AMD is the *only* currency code
 * ever accepted by the gateway on a request, which makes the default an SDK
 * assumption rather than an observation.
 *
 * ## Why this is a token scan and not a grep
 *
 * The obvious check is `grep -rn 'Currency::default' src/` returning nothing,
 * and that check cannot pass — and it must not be made to pass.
 *
 * The grep matches six lines in src/, every one of them a docblock sentence
 * stating that the method is *not* called: "Currency::default() is never called
 * here", and similar. A textual search cannot tell a statement about a call
 * from a call. Satisfying the grep would mean deleting six accurate sentences
 * that document the single most valuable decision in the hydrator — trading a
 * real explanation for a green string search.
 *
 * A token scan does not have the ambiguity. token_get_all() emits T_DOC_COMMENT
 * as one token, so the prose is invisible to it; what it looks for is a
 * T_DOUBLE_COLON immediately followed by the identifier `default`, which is a
 * static call and cannot be anything else. Run against src/ today it reports
 * zero sites, so the rule the criterion meant to express holds, and the
 * documentation stays.
 *
 * This is the same substitution the money guard's docblock proposes for itself
 * — "the next widening is a rewrite onto token_get_all()" — applied where the
 * textual form is not merely lossy but unsatisfiable.
 *
 * ## Width
 *
 * Every static `::default()` is banned, not only Currency's, and the scan does
 * not check what precedes the `::`. That is wider than the rule and
 * deliberately so, on the reasoning tests/Enum's ::from() guard records: a
 * matcher that tried to resolve the class would be a matcher that could fail
 * open. Nothing in this package has a legitimate static default(), and if
 * something needs one it should be named for what it defaults.
 *
 * `default` is a reserved word, and PHP's lexer emits T_DEFAULT for it even
 * after `::`. That is why the scan accepts T_DEFAULT as well as T_STRING there
 * — a scan looking only for T_STRING would find nothing, forever, and look
 * exactly like a clean tree. A match arm's `default =>` and a switch's
 * `default:` carry no preceding T_DOUBLE_COLON and so are not matched; the
 * hydrator contains one of each.
 */
#[CoversNothing]
final class NoCurrencyDefaultCallInSourceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    /**
     * The guard itself.
     */
    public function testNoStaticDefaultCallExistsAnywhereInSrc(): void
    {
        self::assertSame(
            [],
            $this->defaultCallSitesIn(self::SOURCE),
            'Currency::default() must never be called on wire data. '
            . 'When a response carries no recognisable Currency, the Amount stays '
            . 'null and the raw scalar is kept. Stamping a default currency on a '
            . 'foreign transaction produces a wrong amount that looks right.',
        );
    }

    /**
     * The scan must reach the tree it claims to scan, and past its root.
     *
     * An empty file list yields an empty hit list, which is indistinguishable
     * from a clean tree. The two pinned paths are the files this rule is about:
     * the enum that declares default(), and the hydrator that must not call it.
     */
    public function testTheScanReachesTheFilesThisRuleIsAbout(): void
    {
        $found = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            $found[] = $this->relativeTo(self::SOURCE, $path);
        }

        self::assertContains('Enum/Currency.php', $found);
        self::assertContains('Support/ResponseHydrator.php', $found);
        self::assertGreaterThan(1, count($found));
    }

    /**
     * The matcher is the whole guard, so its own failure modes are pinned.
     *
     * The first block is what must be caught, in the spellings it would really
     * be written. The second is what must not be: the six docblock sentences in
     * src/ that state the method is not called, a match arm, and a switch —
     * each of which a grep either flags or would have to be widened past.
     */
    public function testTheMatcherSeparatesACallFromEveryOtherUseOfTheWord(): void
    {
        // Must be caught.
        self::assertSame([1], $this->defaultCallLines('Currency::default();'));
        self::assertSame([1], $this->defaultCallLines('$c = Currency::default();'));
        self::assertSame([1], $this->defaultCallLines('self::default();'));
        self::assertSame([1], $this->defaultCallLines('static::default();'));
        self::assertSame([1], $this->defaultCallLines('Currency::DEFAULT();'), 'PHP method names are case-insensitive.');
        self::assertSame([1], $this->defaultCallLines('Currency:: default ();'), 'Whitespace must not evade the guard.');
        self::assertSame([2, 4], $this->defaultCallLines("\$a = 1;\nCurrency::default();\n\$b = 2;\nX::default();\n"));

        // Must not be caught.
        self::assertSame(
            [],
            $this->defaultCallLines('/** Currency::default() is never called here. */ $x = 1;'),
            'The six accurate docblock sentences in src/ are the reason this is a token scan.',
        );
        self::assertSame(
            [],
            $this->defaultCallLines('// Never Currency::default().'),
            'A line comment is not a call either.',
        );
        self::assertSame(
            [],
            $this->defaultCallLines('$r = match ($token) { "1" => true, default => false };'),
            'A match arm carries no preceding double colon.',
        );
        self::assertSame(
            [],
            $this->defaultCallLines('switch ($x) { default: break; }'),
            'A switch label carries no preceding double colon.',
        );
        self::assertSame([], $this->defaultCallLines('$c = Currency::tryFrom($raw);'));
        self::assertSame([], $this->defaultCallLines('$v = self::DEFAULT_TIMEOUT;'), 'A constant is not a call.');
        self::assertSame([], $this->defaultCallLines('$x = $config->default();'), 'An instance call is not a static one.');
    }

    /**
     * A guard with no test of its own is not a guard, so the scanner is run
     * over a fixture tree that does contain a call.
     *
     * The fixture lives in a temporary directory and never under src/, and the
     * violation is one level down so a scanner that stopped recursing would
     * call this tree clean.
     */
    public function testTheScannerReportsAViolationInAFixtureTree(): void
    {
        $root = sys_get_temp_dir() . '/vpos-default-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Support';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($root . '/Clean.php', "<?php\n\n\$c = Currency::tryFrom(\$raw);\n");
            file_put_contents($nested . '/Dirty.php', "<?php\n\n\$c = Currency::default();\n");
            file_put_contents($nested . '/NotPhp.txt', "Currency::default();\n");

            self::assertSame(['Support/Dirty.php:3'], $this->defaultCallSitesIn($root));
        } finally {
            foreach (['/Clean.php', '/Support/Dirty.php', '/Support/NotPhp.txt'] as $file) {
                if (is_file($root . $file)) {
                    unlink($root . $file);
                }
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * Every static default() call site under $directory, as "path:line"
     * relative to $directory, sorted.
     *
     * A pure function of the path, so its own failure mode is testable against
     * a fixture directory rather than only against the real tree.
     *
     * @return list<string>
     */
    private function defaultCallSitesIn(string $directory): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $contents = file_get_contents($path);

            // Not skipped silently: an unreadable file is a hole in the guard,
            // and a hole in this guard looks exactly like a clean tree.
            self::assertIsString($contents, sprintf('Could not read %s.', $path));

            foreach ($this->defaultCallLines($contents) as $line) {
                $sites[] = $this->relativeTo($directory, $path) . ':' . $line;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * 1-based line numbers of every static `::default` in $php.
     *
     * $php is tokenised as a fragment when it carries no open tag, so the
     * matcher's own cases can be written as bare statements.
     *
     * @return list<int>
     */
    private function defaultCallLines(string $php): array
    {
        $source = str_starts_with($php, '<?php') ? $php : "<?php\n" . $php;
        $offset = $source === $php ? 0 : 1;

        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        $count = count($tokens);
        $lines = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_DOUBLE_COLON) {
                continue;
            }

            $next = $tokens[$i + 1];

            if (!is_array($next) || !in_array($next[0], [T_STRING, T_DEFAULT], true)) {
                continue;
            }

            if (strtolower($next[1]) !== 'default') {
                continue;
            }

            $lines[] = $next[2] - $offset;
        }

        sort($lines);

        return $lines;
    }

    /**
     * Absolute paths of every .php file under $directory, recursively, sorted.
     *
     * Deliberately not shared with the other guards in this suite, on the
     * reasoning tests/Money's guard records: a guard that borrows another
     * guard's walker fails open the day that walker is refactored for the other
     * guard's convenience.
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
