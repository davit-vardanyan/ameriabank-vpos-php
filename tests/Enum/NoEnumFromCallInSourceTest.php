<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Enum;

use function array_map;
use function array_unique;
use function count;
use function dirname;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function strlen;

/**
 * CONVENTIONS.md §8 forbids Enum::from() anywhere in src/, and §4.6 says why.
 *
 * PaymentsEnum has gaps at 8, 9, 10, 15 and 16 that the bank will fill without
 * notice. OrderStatus and PaymentState are now partly observed — a completed
 * payment returned `"2"` with `payment_deposited`, and a refunded one `"4"` with
 * `payment_refunded` (probe cases P3 and P4.1b) — but that is two of seven
 * members each, and the remaining five of each, along with every Language
 * member, are still vendor-PDF sourced. from() throws
 * ValueError on an unknown value, so a single call would turn a routine
 * upstream addition into an uncaught fatal inside a caller's payment flow.
 * tryFrom() with a nullable field is the only permitted entry point.
 *
 * The matcher below cannot tell an enum's from() from any other class's
 * static from() — it bans every static call spelled '::from(' anywhere in
 * src/, regardless of what precedes the '::'. That is wider than "Enum", by
 * design: it is also the only way the guard stays cheap and fail-closed (see
 * testTheMatcherSeparatesFromFromTryFrom()). A value object such as Amount or
 * ResponseCode must therefore not name its wire-hydration factory from() —
 * tryFrom() is meaningless for a non-enum, so name it something like
 * fromWire() or fromMinorUnits() instead.
 *
 * A convention no test enforces is not enforced. This scans the shipped tree.
 */
#[CoversNothing]
final class NoEnumFromCallInSourceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    /**
     * The guard itself.
     *
     * This bans every static from(), not only Enum::from() — see the class
     * docblock. A value object's wire-hydration factory must be named
     * something other than from(), e.g. fromWire() or fromMinorUnits().
     */
    public function testNoFromCallExistsAnywhereInSrc(): void
    {
        self::assertSame(
            [],
            $this->fromCallSitesIn(self::SOURCE),
            'No static from() is permitted anywhere in src/ — not only on an '
            . 'enum. On an enum, from() throws on an unknown wire value; use '
            . "tryFrom() with a nullable field instead — CONVENTIONS.md §4.6 and §8. "
            . 'On a value object (e.g. Amount, ResponseCode), from() is not a '
            . "recognised factory name at all — name it fromWire(), "
            . 'fromMinorUnits(), or similar instead.',
        );
    }

    /**
     * The scan must actually reach the tree it claims to scan.
     *
     * Without this the guard passes trivially the day someone moves src/ or
     * renames a directory: an empty file list yields an empty hit list, which
     * is indistinguishable from a clean tree. Two nested paths are asserted
     * rather than a bare count, because the scanner is recursive and a
     * non-recursive regression would still find the files at the root.
     *
     * Both pinned paths live under Enum/, the one subsystem this suite is
     * actually about — this test must not couple the enum guard to another
     * subsystem's file layout (a src/Exception/ reshuffle has no business
     * failing an enum test). The generic check below carries the rest of the
     * "reached the whole tree" burden without naming any other subsystem's
     * files: it asserts the scan descended into more than one directory,
     * which a scanner that stopped recursing at src/'s root could not do.
     */
    public function testTheScanReachesEveryDirectoryOfTheSourceTree(): void
    {
        $found = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            $found[] = $this->relativeTo(self::SOURCE, $path);
        }

        self::assertContains('Enum/PaymentType.php', $found);
        self::assertContains('Enum/OrderStatus.php', $found);

        $directories = array_unique(array_map(
            dirname(...),
            $found,
        ));

        self::assertGreaterThan(
            1,
            count($directories),
            'The scan found files in only one directory of src/ — a scanner '
            . 'that stopped recursing at the root would look identical to a '
            . 'clean tree.',
        );
    }

    /**
     * The matcher is the whole guard, so its own failure modes are pinned.
     *
     * tryFrom() is the trap: '::from(' is a substring concern, and a matcher
     * written as str_contains($php, 'from(') flags every correct call in the
     * package while a matcher anchored on the wrong side flags none of the
     * incorrect ones. Requiring '::' immediately before 'from' separates the
     * two — in '::tryFrom(' the characters after '::' are 't', 'r', 'y'.
     *
     * The scan is textual and does not strip comments or string literals, so a
     * docblock that spells a call out in full would trip it. That is the
     * intended trade, and the same one the exception suite makes for its 'pin'
     * token: a false positive is a loud failure at the moment someone writes
     * the line, and costs one word to resolve; a missed from() is silent and
     * reaches production. Do not "fix" it by parsing tokens — every line of
     * cleverness here is a line that can fail open. The existing docblocks in
     * src/ write "from()" without a class prefix precisely for this reason.
     */
    public function testTheMatcherSeparatesFromFromTryFrom(): void
    {
        // Must be caught.
        self::assertSame([1], $this->fromCallLines('PaymentType::from($wire);'));
        self::assertSame([1], $this->fromCallLines('self::from(5);'));
        self::assertSame([1], $this->fromCallLines('static::from($v);'));
        self::assertSame([1], $this->fromCallLines('Currency::From($v);'), 'PHP method names are case-insensitive.');
        self::assertSame([1], $this->fromCallLines('OrderStatus:: from ($v);'), 'Whitespace must not evade the guard.');
        self::assertSame([2, 4], $this->fromCallLines("a\nX::from(1);\nb\nY::from(2);\n"));

        // Must not be caught.
        self::assertSame([], $this->fromCallLines('PaymentType::tryFrom($wire);'));
        self::assertSame([], $this->fromCallLines('Currency::tryFrom((string) $v);'));
        self::assertSame([], $this->fromCallLines('DateTimeImmutable::createFromFormat($f, $s);'));
        self::assertSame([], $this->fromCallLines('$builder->from($table);'));
        self::assertSame([], $this->fromCallLines('* Never call from() on a wire value.'));
        self::assertSame([], $this->fromCallLines('use function array_from_thing;'));
    }

    /**
     * A guard with no test of its own is the defect that has already cost this
     * suite a review cycle, so the scanner is exercised against a fixture tree
     * that does contain a violation.
     *
     * The fixture lives in a temporary directory and never in src/: an
     * interrupted run must not be able to leave a throwaway file in the shipped
     * package. The nesting is deliberate — the violation is one level down, so
     * a scanner that stopped recursing would report a clean tree here.
     */
    public function testTheScannerReportsAViolationInAFixtureTree(): void
    {
        $root = sys_get_temp_dir() . '/vpos-from-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Enum';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($root . '/Clean.php', "<?php\n\nCurrency::tryFrom('051');\n");
            file_put_contents($nested . '/Dirty.php', "<?php\n\nPaymentType::from(\$wire);\n");
            file_put_contents($nested . '/NotPhp.txt', "PaymentType::from(1);\n");

            self::assertSame(['Enum/Dirty.php:3'], $this->fromCallSitesIn($root));
        } finally {
            foreach (['/Clean.php', '/Enum/Dirty.php', '/Enum/NotPhp.txt'] as $file) {
                if (is_file($root . $file)) {
                    unlink($root . $file);
                }
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * Every from() call site under $directory, as "path:line" relative to
     * $directory, sorted.
     *
     * Reported relative so the failure message reads "Enum/Currency.php:48"
     * rather than a path threaded back out through tests/Enum/../../.
     *
     * A pure function of the path, so its own failure mode is testable against
     * a fixture directory rather than only against the real tree.
     *
     * @return list<string>
     */
    private function fromCallSitesIn(string $directory): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $contents = file_get_contents($path);

            // Not skipped silently: an unreadable file is a hole in the guard,
            // and a hole in this guard is indistinguishable from a clean tree.
            self::assertIsString($contents, sprintf('Could not read %s.', $path));

            foreach ($this->fromCallLines($contents) as $line) {
                $sites[] = $this->relativeTo($directory, $path) . ':' . $line;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * 1-based line numbers of every '::from(' in $php.
     *
     * @return list<int>
     */
    private function fromCallLines(string $php): array
    {
        $matches = [];
        $count = preg_match_all('/::\s*from\s*\(/i', $php, $matches, PREG_OFFSET_CAPTURE);

        self::assertNotFalse($count, 'The from() pattern failed to compile.');

        if ($count === 0) {
            return [];
        }

        $lines = [];

        foreach ($matches[0] as $match) {
            $offset = $match[1];

            $lines[] = substr_count($php, "\n", 0, $offset) + 1;
        }

        return $lines;
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
