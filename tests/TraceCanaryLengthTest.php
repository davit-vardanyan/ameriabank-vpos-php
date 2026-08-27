<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests;

use function count;
use function dirname;
use function file_get_contents;
use function ini_get;
use function is_array;
use function ksort;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function preg_match;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_STRING;

use function token_get_all;

/**
 * No canary this suite asserts absent from a stack trace is long enough to be
 * truncated out of one.
 *
 * ## The false pass this closes
 *
 * Several tests prove that a secret does not reach a rendered exception, and
 * they all have the same shape: put a known value where a leak would carry it,
 * render the trace, assert the value is not in the rendering. That assertion is
 * only evidence when the value *would* have been rendered had the leak been
 * real. Two separate INI settings decide whether it would:
 *
 * - `zend.exception_ignore_args` — On, no argument is rendered at all.
 * - `zend.exception_string_param_max_len` — a string argument longer than this
 *   is cut to that many bytes and followed by `...`.
 *
 * The first is the coarser trap and `phpunit.xml.dist` pins it off. This file
 * guards the second, which is quieter: with the setting left on and a canary
 * one byte too long, the negative assertion still passes — but it passes
 * because the engine truncated the canary, not because `#[SensitiveParameter]`
 * withheld it. Delete the attribute and the test stays green. The suite would
 * be reporting a protection it had stopped measuring.
 *
 * The boundary is exact and was measured rather than assumed: at
 * `zend.exception_string_param_max_len = N`, a string argument of **N bytes or
 * fewer** renders in full and one of N+1 bytes renders as N bytes plus `...`.
 * Hence `<=` below, not `<`.
 *
 * Bytes, not codepoints. The engine cuts the raw string, so eight `é` — sixteen
 * bytes — is truncated at a limit of fifteen even though it is eight
 * characters. `strlen()` is therefore the right measure and `mb_strlen()` is
 * not, which matters the day a canary stops being ASCII.
 *
 * ## Where the limit comes from
 *
 * `ini_get()`, so the guard reads the value actually in force rather than a
 * number written down twice. `phpunit.xml.dist` pins it, so under `composer
 * test` this is that pin; run the suite against some other configuration and
 * the guard measures that one instead. This is deliberate: a guard that
 * hardcodes the limit it is checking against cannot notice the limit changing,
 * which is the failure this whole file exists to prevent, one level up.
 *
 * It also means this test fails loudly under the hostile configuration rather
 * than passing quietly — a limit of `0` makes every canary too long, which is
 * the honest verdict, because under that limit none of the trace assertions in
 * this suite prove anything.
 *
 * ## How the subject list is derived
 *
 * Per `.claude/rules/autolearning.md` AL-003, the list is derived from its
 * source of truth at test time — here the test tree itself, walked recursively
 * — and never written down. A subject is any single-quoted literal in `tests/`
 * that **is** a canary token: `canary` with an optional token-safe prefix or
 * suffix and nothing else, which is what `isCanary()` below matches.
 *
 * Keying on the value rather than the constant name is what makes the
 * derivation complete. Three of the canaries in this suite sit in constants
 * named `PASSWORD`, which no name-based rule would select, and one is a bare
 * literal in a call argument, which no reflection over constants would see at
 * all. Keying on the value catches every shape at once, and it correctly
 * declines to select `CredentialsTest::PASSWORD` — twenty-four bytes, and
 * asserted absent only from dumps of a `Credentials`, never from a trace, so
 * the truncation limit does not apply to it.
 *
 * Matching the whole literal rather than searching inside it is the other half.
 * Assertion messages in this suite talk *about* canaries — "Both channels must
 * carry the same canary." — and a substring search sweeps that prose in and
 * reports a forty-one byte canary that does not exist. A canary is a single
 * token by construction, because it has to survive being matched inside a
 * rendered trace; prose is not. The shape is the discriminator.
 *
 * ## The rule is deliberately broader than the invariant
 *
 * Only canaries asserted absent from a *Throwable* rendering can be neutered by
 * this particular truncation. Several here are not: `ResponseHydratorTest`'s are
 * matched against hydrated DTO properties, and `RedactorTest`'s against PSR-3
 * log records. Neither channel truncates anything.
 *
 * They are guarded anyway, and the over-reach is the point rather than a
 * compromise. Telling the two apart needs the *channel* each canary is asserted
 * against, and the channel is not visible where the canary is written — the
 * haystack is routinely a local built several lines earlier, or a parameter of a
 * shared helper. Any rule cheap enough to write here would be a rule that
 * decides by file or by name, and both silently exempt the case they get wrong.
 * AL-003 is explicit that the failure mode to design against is the silent
 * exemption, so this guard over-approximates instead: every canary in the suite
 * is held to the limit, nothing is exempt, and there is no list to keep.
 *
 * The cost is one real constraint on canaries that do not strictly need it, and
 * it is small — canaries in this suite run to fourteen bytes. The benefit is
 * that a canary moved into a trace assertion later, which is an ordinary thing
 * to do, is already safe rather than newly broken in a way nothing would report.
 *
 * The convention that makes the derivation work is itself asserted below, so it
 * cannot quietly stop holding: a constant named for a canary whose value was not
 * a canary token would be invisible to the scan, and the second test is what
 * stops that from being a silent hole rather than a failing build.
 */
#[CoversNothing]
final class TraceCanaryLengthTest extends TestCase
{
    /**
     * Every canary is short enough that a leak would show up in the trace.
     */
    public function testEveryCanaryIsShortEnoughToSurviveTraceTruncation(): void
    {
        $limit = $this->configuredMaxLength();
        $canaries = $this->canariesInTheTestTree();

        self::assertNotSame(
            [],
            $canaries,
            'No canary was found in tests/. Either the scan broke or the naming convention '
            . 'changed; a guard over an empty subject list is a vacuous pass and proves nothing.',
        );

        foreach ($canaries as $value => $where) {
            self::assertLessThanOrEqual(
                $limit,
                strlen($value),
                sprintf(
                    'The canary %s is %d bytes and zend.exception_string_param_max_len is %d, so it '
                    . 'would be truncated out of any stack trace that carried it. Every assertion that '
                    . 'this value is absent from a rendered exception would then pass because of the '
                    . 'truncation rather than because the value was withheld. Shorten the canary. '
                    . 'Seen in: %s.',
                    $value,
                    strlen($value),
                    $limit,
                    $where,
                ),
            );
        }
    }

    /**
     * A constant named for a canary holds a value the scan above can find.
     *
     * The first test selects by value. This one keeps that selector honest from
     * the other side: naming a constant `*CANARY*` and giving it a value with no
     * `canary` in it would hide it from the scan entirely, and the hole would be
     * silent. Here it is a failing build instead.
     */
    public function testEveryConstantNamedForACanaryHoldsOne(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $file) {
            foreach ($this->stringConstantsIn($file) as $name => $value) {
                if (!str_contains(strtolower($name), 'canary')) {
                    continue;
                }

                if ($this->isCanary($value)) {
                    continue;
                }

                $offenders[] = sprintf('%s = %s in %s', $name, $value, $this->relative($file));
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A constant named for a canary must hold a canary token, because '
            . 'testEveryCanaryIsShortEnoughToSurviveTraceTruncation() selects its subjects by value. '
            . 'A constant that breaks the convention would be exempt from the length guard without '
            . 'anything saying so.',
        );
    }

    /**
     * Whether a literal is a canary token rather than prose that mentions one.
     *
     * The whole value must be the token: `canary` with an optional token-safe
     * prefix or suffix. `pw-canary`, `ed-canary` and `pw-canary-1234` match;
     * `Both channels must carry the same canary.` does not, and must not, or the
     * guard reports assertion messages as over-long canaries.
     */
    private function isCanary(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]*canary[A-Za-z0-9_-]*$/i', $value) === 1;
    }

    /**
     * The truncation limit actually in force, read rather than restated.
     */
    private function configuredMaxLength(): int
    {
        $raw = ini_get('zend.exception_string_param_max_len');

        self::assertIsString(
            $raw,
            'zend.exception_string_param_max_len could not be read, so the limit this guard '
            . 'measures against is unknown and the guard cannot run.',
        );

        return (int) $raw;
    }

    /**
     * Every distinct canary value in the test tree, mapped to where it was seen.
     *
     * @return array<string, string>
     */
    private function canariesInTheTestTree(): array
    {
        $found = [];

        foreach ($this->testFiles() as $file) {
            foreach ($this->stringLiteralsIn($file) as $value) {
                if (!$this->isCanary($value)) {
                    continue;
                }

                $seen = $found[$value] ?? '';
                $where = $this->relative($file);

                if ($seen === '' || !str_contains($seen, $where)) {
                    $found[$value] = $seen === '' ? $where : $seen . ', ' . $where;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Every `.php` file under `tests/`, walked recursively, except this one.
     *
     * Recursive by obligation, not by convenience: CONVENTIONS.md §7 requires a
     * structural guard to walk the tree rather than one level of it, because a
     * guard that stops seeing a directory does not fail — it stops guarding.
     *
     * This file is the one exclusion, and it is derived from `__FILE__` rather
     * than written down, so it cannot drift or be widened by accident. It earns
     * the exclusion by being the only file in `tests/` that says `canary` in a
     * string literal without holding one: the failure messages below have to
     * name the thing they are about. Scanning itself, the guard reports its own
     * prose as an over-long canary — which is a true statement about the bytes
     * and a false one about the suite.
     *
     * @return list<string>
     */
    private function testFiles(): array
    {
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.php')) {
                continue;
            }

            if ($entry->getPathname() === __FILE__) {
                continue;
            }

            $files[] = $entry->getPathname();
        }

        return $files;
    }

    /**
     * Every single-quoted string literal in a file, decoded to its real value.
     *
     * Single-quoted only. A canary is a fixed token and every one in this suite
     * is written that way; a double-quoted literal can interpolate, and a value
     * this guard cannot resolve statically is a value it must not silently treat
     * as short enough. Restricting the scan keeps a wrong answer out rather than
     * letting one in.
     *
     * @return list<string>
     */
    private function stringLiteralsIn(string $file): array
    {
        $values = [];

        foreach ($this->tokensOf($file) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $decoded = $this->decodeSingleQuoted($token[1]);

            if ($decoded !== null) {
                $values[] = $decoded;
            }
        }

        return $values;
    }

    /**
     * Every `const NAME = 'value';` pair in a file.
     *
     * @return array<string, string>
     */
    private function stringConstantsIn(string $file): array
    {
        $constants = [];
        $tokens = $this->tokensOf($file);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_CONST) {
                continue;
            }

            $name = null;

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if ($next === ';') {
                    break;
                }

                if (is_array($next) && $next[0] === T_STRING) {
                    $name = $next[1];

                    continue;
                }

                if ($next === '=' || !is_array($next)) {
                    continue;
                }

                if ($next[0] === T_CONSTANT_ENCAPSED_STRING && $name !== null) {
                    $decoded = $this->decodeSingleQuoted($next[1]);

                    if ($decoded !== null) {
                        $constants[$name] = $decoded;
                    }

                    break;
                }
            }
        }

        return $constants;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function tokensOf(string $file): array
    {
        $source = file_get_contents($file);

        self::assertIsString($source, sprintf('%s could not be read.', $file));

        return token_get_all($source);
    }

    /**
     * The value of a single-quoted literal, or null if it is not one.
     */
    private function decodeSingleQuoted(string $token): ?string
    {
        if (!str_starts_with($token, "'") || !str_ends_with($token, "'")) {
            return null;
        }

        $inner = substr($token, 1, -1);

        return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }

    private function relative(string $file): string
    {
        $root = dirname(__DIR__) . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
