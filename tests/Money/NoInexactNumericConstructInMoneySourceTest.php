<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Money;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function strlen;

/**
 * CONVENTIONS.md §8 forbids an inexact numeric type for a monetary value, and
 * this file makes it a hard constraint on src/Money/: no type declaration, no
 * cast under either spelling, no *val() conversion, no round, no
 * number_format, no %f or %e specifier, no fdiv. CONVENTIONS.md §4.7
 * separately keeps ext-bcmath out of require, so the bc* family is banned here
 * too — on dependency grounds rather than precision ones. BANNED below keeps
 * the two apart.
 *
 * A convention no test enforces is not enforced, and this one cannot be
 * enforced by the type system: a single cast anywhere in the parse would be
 * invisible at every call site, would round-trip correctly for "10.00", and
 * would misstate an amount only for the values a caller notices last.
 *
 * The scan is textual, case-insensitive, and does not strip comments or string
 * literals, so prose that spells one of these words trips it. That is the
 * intended trade, and it matches the plainest statement of the rule, which is
 * a bare grep for the same words: a false positive is a loud failure at the
 * moment someone writes the line and costs one rephrased sentence, while a
 * missed cast is silent and reaches a payment. src/Money/Amount.php is written
 * to step past these words for exactly this reason — see its class docblock.
 *
 * A textual scan is not a complete one. The guard's own test names some of the
 * constructs that slip past it, illustratively and not exhaustively: a
 * substring scan cannot be made exhaustive, so no list of its misses can be
 * closed. Read that before trusting a green run, and before widening this: the
 * ruling is that the next widening is a rewrite onto token_get_all(), not
 * another entry in BANNED.
 *
 * The directory walkers below are deliberately not shared with the ::from()
 * guard in tests/Enum. A guard that depends on another guard's helper fails
 * open the day that helper is refactored for the other guard's convenience.
 */
#[CoversNothing]
final class NoInexactNumericConstructInMoneySourceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src/Money';

    /**
     * The banned constructs, as the token reported and the pattern matching it.
     *
     * Two different bans live in this one list, and the distinction matters
     * when it is next edited.
     *
     * Most entries guard against inexact arithmetic. 'float' and 'double' are
     * bare substrings on purpose: each covers the type declaration, the cast
     * and the *val() function in one pattern, and a pattern narrow enough to
     * distinguish them would also be narrow enough to miss a spelling nobody
     * thought of. 'double' is not a separate hazard from 'float' — (double) and
     * doubleval() are exact aliases of (float) and floatval(), so banning one
     * spelling and not the other was an oversight, not a scope decision.
     * Likewise 'round' catches round() and every inflection of it in prose,
     * and fdiv() and the %e specifier are unambiguous producers of an inexact
     * value.
     *
     * The bc* entries are not that. bcmath returns strings and is not a
     * precision hazard at all; those entries guard a *dependency*, because
     * ext-bcmath is deliberately absent from require (CONVENTIONS.md §4.7) and
     * a call to it here would be a silent new extension requirement on every
     * consumer. bcadd was already banned on that ground, so the rest of the
     * family is listed for consistency: there is no reading of the rule under
     * which bcadd is forbidden and bcmul is fine.
     *
     * The %f and %e patterns allow the width, precision, sign, padding and
     * space flags a specifier may carry, so "%.2f", "%'.10f" and "% f" are all
     * caught alongside "%f". Both patterns carry /i, because %E and %F are real
     * specifiers producing the same inexact value their lowercase spellings do,
     * and a pattern widened to catch them that reads only one case catches
     * neither. They must not catch the "%d" this file's neighbour uses for
     * integers.
     *
     * @var array<string, non-empty-string>
     */
    private const array BANNED = [
        'bcadd' => '/bcadd/i',
        'bcdiv' => '/bcdiv/i',
        'bcmul' => '/bcmul/i',
        'bcsub' => '/bcsub/i',
        'double' => '/double/i',
        'fdiv' => '/fdiv/i',
        'float' => '/float/i',
        'number_format' => '/number_format/i',
        'round' => '/round/i',
        '%e' => '/%[0-9.+\-\'$ ]*e/i',
        '%f' => '/%[0-9.+\-\'$ ]*f/i',
    ];

    /**
     * The guard itself.
     *
     * What it covers is the list in BANNED: those constructs cannot appear
     * under src/Money/, in code or in prose. It covers the constructs it names
     * and nothing else, so a green run is evidence that those constructs are
     * absent — it is not a proof that no inexact value is produced here.
     *
     * Some of what it does not cover, so that a green run is not read as more
     * than it is. Arithmetic division — $count / 100 — produces an inexact
     * value and is invisible here, because "/" appears in every docblock and
     * comment in the file and a substring ban on it would fire on all of them.
     * A bare literal with a decimal point — $x = 1.0; — is invisible for the
     * same reason: a pattern for it matches "§4.7" and "PHP 8.3" in this
     * project's own prose. The two compose into a construct that passes this
     * guard and PHPStan level 10 together, as review of this guard
     * demonstrated with (int) ($digits / 100 * 100). Exponent notation — $v =
     * 1e3; — carries neither a decimal point nor a slash, so neither of those
     * two covers it. Integer overflow promotes silently: PHP_INT_MAX + 1 is an
     * inexact value with no cast, no call and no literal to match on, and it
     * is the pointed miss in a class whose whole subject is that magnitude.
     *
     * That enumeration is illustrative, not exhaustive, and must not be read as
     * the set. sqrt(), pow() and abs() on a negative literal are uncaught too,
     * and so is whatever spelling nobody has thought of yet. A substring scan
     * cannot be made exhaustive, so a list of its misses that sounds complete
     * would be the same failure as a docblock explaining an engine mechanism it
     * cannot hold: a claim with nothing keeping it honest.
     *
     * The owner's ruling on that gap: it is not closed by adding more strings.
     * The next widening switches to token_get_all() and inspects T_DNUMBER and
     * T_DIV as tokens, which distinguishes a division from a slash in a
     * sentence and a literal from a section number — and which would also end
     * this guard's standing dependence on src/Money/'s own docblocks never
     * naming the things they describe. Until someone does that work, the gap is
     * held by review and by AmountTest's round-trip counts, not by this file.
     */
    public function testNoInexactNumericConstructAppearsAnywhereInTheMoneySource(): void
    {
        self::assertSame(
            [],
            $this->bannedTokenSitesIn(self::SOURCE),
            'Monetary values are integer minor units and a decimal string built '
            . 'by integer and string arithmetic — CONVENTIONS.md §4.7 and §8. Nothing '
            . 'under src/Money/ may name an inexact numeric type, cast to one, '
            . 'format one, or reach for bcmath, and that includes prose in a '
            . 'docblock: the scan is textual and cannot tell a comment from a '
            . 'cast. Rephrase the sentence.',
        );
    }

    /**
     * The scan must reach the file it claims to scan.
     *
     * Without this the guard passes trivially the day src/Money/ is moved or
     * renamed: an empty file list yields an empty hit list, which is
     * indistinguishable from a clean tree.
     */
    public function testTheScanReachesTheAmountSource(): void
    {
        $found = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            $found[] = $this->relativeTo(self::SOURCE, $path);
        }

        self::assertContains('Amount.php', $found);
    }

    /**
     * The matcher is the whole guard, so its own failure modes are pinned.
     *
     * Every banned construct appears here in the spelling it would really be
     * written in, and each is asserted with its line and token so a pattern
     * that matches by accident — say, one that flagged everything — cannot be
     * mistaken for one that works.
     */
    public function testTheMatcherFlagsEveryBannedConstruct(): void
    {
        self::assertSame(['1:float'], $this->bannedTokenLines('public function rate(): float {}'));
        self::assertSame(['1:float'], $this->bannedTokenLines('$value = (float) $digits;'));
        self::assertSame(['1:float'], $this->bannedTokenLines('$value = ( float ) $digits;'));
        self::assertSame(['1:float'], $this->bannedTokenLines('$value = floatval($digits);'));
        self::assertSame(['1:float'], $this->bannedTokenLines('$value = FLOAT;'), 'Matching is case-insensitive.');
        self::assertSame(['1:double'], $this->bannedTokenLines('$value = (double) $digits;'));
        self::assertSame(['1:double'], $this->bannedTokenLines('$value = doubleval($digits);'));
        self::assertSame(['1:double'], $this->bannedTokenLines('$value = DOUBLE;'), 'Matching is case-insensitive.');
        self::assertSame(['1:fdiv'], $this->bannedTokenLines('$value = fdiv($digits, 100);'));
        self::assertSame(['1:round'], $this->bannedTokenLines('$value = round($value, 2);'));
        self::assertSame(['1:number_format'], $this->bannedTokenLines('$s = number_format($v, 2, ".", "");'));
        self::assertSame(['1:bcadd'], $this->bannedTokenLines('$s = bcadd($a, $b, 2);'));
        self::assertSame(['1:bcsub'], $this->bannedTokenLines('$s = bcsub($a, $b, 2);'));
        self::assertSame(['1:bcmul'], $this->bannedTokenLines('$s = bcmul($a, $b, 2);'));
        self::assertSame(['1:bcdiv'], $this->bannedTokenLines('$s = bcdiv($a, $b, 2);'));
        self::assertSame(['1:%f'], $this->bannedTokenLines('$s = sprintf("%f", $v);'));
        self::assertSame(['1:%f'], $this->bannedTokenLines('$s = sprintf("%.2f", $v);'));
        self::assertSame(['1:%f'], $this->bannedTokenLines('$s = sprintf("%\'.10f", $v);'));
        self::assertSame(['1:%e'], $this->bannedTokenLines('$s = sprintf("%e", $v);'));
        self::assertSame(['1:%e'], $this->bannedTokenLines('$s = sprintf("%.3e", $v);'));
        self::assertSame(
            ['1:%f'],
            $this->bannedTokenLines('$s = sprintf("%F", $v);'),
            '%F is a real specifier and produces the same inexact value %f does.',
        );
        self::assertSame(
            ['1:%e'],
            $this->bannedTokenLines('$s = sprintf("%E", $v);'),
            '%E is a real specifier and produces the same inexact value %e does.',
        );
        self::assertSame(
            ['1:%f'],
            $this->bannedTokenLines('$s = sprintf("% f", $v);'),
            'The space flag is a flag like any other and must not open a way past the pattern.',
        );
        self::assertSame(['2:float', '4:round'], $this->bannedTokenLines("a\n(float) \$v;\nb\nround(\$v);\n"));
    }

    /**
     * The constructs the money source legitimately uses must not be flagged.
     *
     * "%d" is the trap: a %f or %e pattern written loosely enough to accept
     * flags will also accept a digit specifier, and banning sprintf() outright
     * would ban the one formatting call src/Money/Amount.php actually makes.
     *
     * The last two rows are not "legitimate" — they are the documented gap,
     * asserted rather than described so that closing it is a visible change to
     * this file rather than a silent one. A slash is not banned because every
     * docblock in src/Money/ contains several, and the reviewer's division is
     * the construct that gets through as a result. Both rows flip the day this
     * guard is rewritten onto token_get_all(), which is the point.
     */
    public function testTheMatcherPassesTheConstructsTheMoneySourceActuallyUses(): void
    {
        self::assertSame([], $this->bannedTokenLines('$s = sprintf("at most %d decimal places", $exponent);'));
        self::assertSame([], $this->bannedTokenLines('$count = (int) $digits;'));
        self::assertSame([], $this->bannedTokenLines('$digits = str_pad($fraction, $exponent, "0", STR_PAD_RIGHT);'));
        self::assertSame([], $this->bannedTokenLines('$digits = ltrim($digits, "0");'));
        self::assertSame([], $this->bannedTokenLines('return strcmp($digits, $largest) > 0;'));
        self::assertSame([], $this->bannedTokenLines('$whole = intdiv($count, 100);'));

        self::assertSame(
            [],
            $this->bannedTokenLines(' * A scale of 2 means the count / 100 is the major unit, and 1 / 100 is one.'),
            'A slash in prose is not a violation; banning "/" as a substring would fire on every docblock.',
        );

        self::assertSame(
            [],
            $this->bannedTokenLines('$count = (int) ($digits / 100 * 100);'),
            'The documented gap: arithmetic division is not caught by a textual scan. See the guard test.',
        );
    }

    /**
     * A guard with no test of its own is not a guard, so the scanner is
     * exercised against a fixture tree that does contain a violation.
     *
     * The fixture lives in a temporary directory and never under src/: an
     * interrupted run must not be able to leave a throwaway file in the shipped
     * package. The violation is one level down, so a scanner that stopped
     * recursing would report this tree clean.
     */
    public function testTheScannerReportsAViolationInAFixtureTree(): void
    {
        $root = sys_get_temp_dir() . '/vpos-money-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Nested';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($root . '/Clean.php', "<?php\n\n\$count = (int) \$digits;\n");
            file_put_contents($nested . '/Dirty.php', "<?php\n\n\$value = (float) \$digits;\n");
            file_put_contents($nested . '/NotPhp.txt', "(float) \$digits;\n");

            self::assertSame(['Nested/Dirty.php:3:float'], $this->bannedTokenSitesIn($root));
        } finally {
            foreach (['/Clean.php', '/Nested/Dirty.php', '/Nested/NotPhp.txt'] as $file) {
                if (is_file($root . $file)) {
                    unlink($root . $file);
                }
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * Every banned construct under $directory, as "path:line:token" relative to
     * $directory, sorted.
     *
     * A pure function of the path, so its own failure mode is testable against
     * a fixture directory rather than only against the real tree.
     *
     * @return list<string>
     */
    private function bannedTokenSitesIn(string $directory): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $contents = file_get_contents($path);

            // Not skipped silently: an unreadable file is a hole in the guard,
            // and a hole in this guard looks exactly like a clean tree.
            self::assertIsString($contents, sprintf('Could not read %s.', $path));

            foreach ($this->bannedTokenLines($contents) as $hit) {
                $sites[] = $this->relativeTo($directory, $path) . ':' . $hit;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * Every banned construct in $php as "line:token", sorted.
     *
     * @return list<string>
     */
    private function bannedTokenLines(string $php): array
    {
        $hits = [];

        foreach (self::BANNED as $token => $pattern) {
            $matches = [];
            $count = preg_match_all($pattern, $php, $matches, PREG_OFFSET_CAPTURE);

            self::assertNotFalse($count, sprintf('The %s pattern failed to compile.', $token));

            foreach ($matches[0] as $match) {
                $hits[] = (substr_count($php, "\n", 0, $match[1]) + 1) . ':' . $token;
            }
        }

        sort($hits);

        return $hits;
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
