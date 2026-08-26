<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use function array_map;
use function array_pop;
use function array_unique;
use function count;
use function dirname;
use function in_array;
use function is_array;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function sort;
use function sprintf;
use function strlen;
use function strtolower;
use function substr_count;

use const T_BOOLEAN_AND;
use const T_BOOLEAN_OR;
use const T_COALESCE;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_ELSEIF;
use const T_IF;
use const T_IS_EQUAL;
use const T_IS_GREATER_OR_EQUAL;
use const T_IS_IDENTICAL;
use const T_IS_NOT_EQUAL;
use const T_IS_NOT_IDENTICAL;
use const T_IS_SMALLER_OR_EQUAL;
use const T_LOGICAL_AND;
use const T_LOGICAL_OR;
use const T_LOGICAL_XOR;
use const T_MATCH;
use const T_SPACESHIP;
use const T_STRING;
use const T_SWITCH;
use const T_VARIABLE;
use const T_WHILE;
use const T_WHITESPACE;

use function token_get_all;

/**
 * The HTTP status decides nothing, enforced against the shipped tree.
 *
 * CONVENTIONS.md §4.1 and §4.2 are the whole reason this file exists. An
 * authentication failure arrives as HTTP 200 with `ResponseCode` 20; an
 * unattempted payment queried through `GetPaymentDetails` arrives as HTTP 500
 * with a body that is valid JSON and perfectly deliberate; a wrong endpoint
 * name arrives as HTTP 404 carrying the same envelope as the 500. Every one of
 * those makes a status-driven branch wrong in a different direction, and none
 * of them fails loudly — a transport that shortcut on status would return a
 * refusal as a success and be wrong on live traffic within a day.
 *
 * HttpTransportTest already proves the property behaviourally: a 200 carrying
 * `ResponseCode` 20 throws, a 500 carrying a success body returns, and the
 * same fault envelope throws at both 404 and 500. Those tests kill every
 * status branch on the paths they exercise. This one covers what they
 * structurally cannot: a status branch on a path no test reaches — a shortcut
 * added inside `dispatch()` before the response is even decoded, or a status
 * check in a class added to `src/` later. The obvious check is `grep -rn
 * 'getStatusCode' src/Http/` showing the call used only in logging, and a grep
 * a human runs once is not an enforced convention.
 *
 * Two guards, and they are complementary:
 *
 * 1. The status is read in exactly one place in the whole of `src/`.
 * 2. No occurrence of a status-bearing expression anywhere in `src/` sits in a
 *    decision — the head of an `if`/`elseif`/`while`/`switch`/`match`, or either
 *    operand of a comparison or boolean operator.
 *
 * Together those say "read once, then carried as data". Passing the status as
 * an argument (to a log context, or to `GatewayFaultException`, which reports
 * it as diagnostic) is explicitly permitted, because that is the one use
 * CONVENTIONS.md allows.
 *
 * ## Why tokens rather than a grep
 *
 * The sibling guards in this suite match text, and say so: for `::from(` a false
 * positive costs one word to resolve and a miss reaches production. Here the
 * trade runs the other way. `src/Http/HttpTransport.php`'s own docblocks discuss
 * status branching at length — they have to, since explaining why the status is
 * ignored is half the file's value — and a textual matcher would either flag
 * every one of those sentences or be weakened until it flagged nothing. So the
 * scan runs over PHP's own token stream with comments and string literals
 * dropped, which is not cleverness in the sense that guard warns about: it
 * removes the two constructs that cannot contain a branch, and nothing else. The
 * matcher's own behaviour is pinned below, in both directions.
 *
 * The matcher is deliberately over-inclusive on the remaining edges — a status
 * selected *by* a ternary is flagged even though it decides nothing — because
 * every false positive here is a loud failure at the moment the line is written,
 * and every miss is a silent one on a payment.
 */
#[CoversNothing]
final class NoStatusCodeDecisionInSourceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    /**
     * Variable names that carry an HTTP status. Compared case-insensitively:
     * PHP variable names are not, but a near-miss spelling is a hole in a guard
     * and costs nothing to close.
     *
     * @var list<string>
     */
    private const array STATUS_VARIABLES = ['$status', '$statuscode', '$httpstatus', '$responsestatus'];

    /**
     * The PSR-7 accessor itself. PHP method names are case-insensitive, so this
     * is matched that way too.
     */
    private const string STATUS_ACCESSOR = 'getstatuscode';

    /**
     * The status is read once, and the statement after that read is a log
     * record.
     *
     * Asserted as a set of files rather than as a count alone: a second read
     * added in a new class would keep any count-only assertion green if the
     * first one were removed in the same edit.
     */
    public function testTheHttpStatusIsReadInExactlyOnePlaceInTheWholeSourceTree(): void
    {
        self::assertSame(
            ['Http/HttpTransport.php:1'],
            $this->statusReadSitesIn(self::SOURCE),
            'The HTTP status carries no business meaning (CONVENTIONS.md §4.1, §4.2), '
            . 'so it is read exactly once — on the line that builds a log record '
            . '— and carried onward only as diagnostic data. A second read is a '
            . 'second chance to branch on it.',
        );
    }

    /**
     * The guard proper: no branch in `src/` reads the status.
     */
    public function testNoBranchInTheSourceTreeReadsTheHttpStatus(): void
    {
        self::assertSame(
            [],
            $this->decisionSitesIn(self::SOURCE),
            'Success, failure, fault and retry are decided by the shape of the '
            . 'decoded body, never by the HTTP status: an authentication failure '
            . 'arrives as 200, a semantic refusal as 500, and the same fault '
            . 'envelope at both 404 and 500 (CONVENTIONS.md §4.1, §4.2). Pass the '
            . 'status as an argument if it is needed as diagnostic data; never '
            . 'compare it.',
        );
    }

    /**
     * The matcher is the whole guard, so both of its failure modes are pinned.
     *
     * A guard that flags nothing and a guard that flags everything are equally
     * useless, and from the outside a green suite looks the same either way.
     */
    public function testTheMatcherSeparatesADecisionFromAnArgument(): void
    {
        // Must be caught: the status decides something.
        self::assertSame([1], $this->decisionLinesOf('if ($statusCode >= 400) { return null; }'));
        self::assertSame([1], $this->decisionLinesOf('if ($response->getStatusCode() === 200) { return null; }'));
        self::assertSame([1], $this->decisionLinesOf('$ok = $statusCode < 300;'));
        self::assertSame([1], $this->decisionLinesOf('return $statusCode === 200 ? $a : $b;'));
        self::assertSame([1], $this->decisionLinesOf('while ($statusCode !== 200) { break; }'));
        self::assertSame([1], $this->decisionLinesOf('$retry = !$statusCode;'));
        self::assertSame([1], $this->decisionLinesOf('$fault = $isJson && $statusCode > 499;'));
        self::assertSame([1], $this->decisionLinesOf('$c = $statusCode ?? 500;'));
        self::assertSame(
            [1],
            $this->decisionLinesOf('if (in_array($statusCode, [500, 404], true)) { return null; }'),
            'A status buried in a call inside a decision head still decides.',
        );
        self::assertSame(
            [1],
            $this->decisionLinesOf('if ($STATUSCODE === 500) { return null; }'),
            'A near-miss spelling must not evade the guard.',
        );
        self::assertSame([2], $this->decisionLinesOf("\$x = 1;\nmatch (\$statusCode) { default => null };"));
        self::assertSame([1, 3], $this->decisionLinesOf("if (\$statusCode > 0) {}\n\$y = 2;\nif (\$status < 9) {}"));

        // Must not be caught: the status is read, or carried as an argument.
        self::assertSame([], $this->decisionLinesOf('$statusCode = $response->getStatusCode();'));
        self::assertSame([], $this->decisionLinesOf("\$context = ['status' => \$statusCode];"));
        self::assertSame([], $this->decisionLinesOf('throw GatewayFault::from($operation, $statusCode, $text);'));
        self::assertSame([], $this->decisionLinesOf('return ["status" => $statusCode, "body" => $body];'));
        self::assertSame([], $this->decisionLinesOf('$this->log($level, $message, ["status" => $statusCode]);'));
        self::assertSame([], $this->decisionLinesOf('if ($attempt >= $this->maxAttempts) { return null; }'));
        self::assertSame(
            [],
            $this->decisionLinesOf('// if ($statusCode === 500) { return null; }'),
            'A comment explaining why the status is ignored must not trip the guard.',
        );
        self::assertSame(
            [],
            $this->decisionLinesOf('/** No branch reads it: if ($statusCode > 0) would be wrong. */'),
            'A docblock is the main reason this matcher runs over tokens.',
        );
        self::assertSame(
            [],
            $this->decisionLinesOf("\$m = 'if (\$statusCode === 500)';"),
            'A string literal is not code.',
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
        $root = sys_get_temp_dir() . '/vpos-status-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Http';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($root . '/Clean.php', "<?php\n\n\$statusCode = \$response->getStatusCode();\n");
            file_put_contents($nested . '/Dirty.php', "<?php\n\nif (\$statusCode >= 500) {\n}\n");
            file_put_contents($nested . '/NotPhp.txt', "if (\$statusCode >= 500) {}\n");

            self::assertSame(['Http/Dirty.php:3'], $this->decisionSitesIn($root));
        } finally {
            foreach (['/Clean.php', '/Http/Dirty.php', '/Http/NotPhp.txt'] as $file) {
                if (is_file($root . $file)) {
                    unlink($root . $file);
                }
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * The scan must actually reach the tree it claims to scan.
     *
     * Without this both guards above pass trivially the day someone moves
     * `src/`: an empty file list yields an empty hit list, which is
     * indistinguishable from a clean tree.
     */
    public function testTheScanReachesEveryDirectoryOfTheSourceTree(): void
    {
        $found = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            $found[] = $this->relativeTo(self::SOURCE, $path);
        }

        self::assertContains('Http/HttpTransport.php', $found);
        self::assertContains('Exception/GatewayFaultException.php', $found);

        $directories = array_unique(array_map(dirname(...), $found));

        self::assertGreaterThan(
            1,
            count($directories),
            'The scan found files in only one directory of src/ — a scanner that '
            . 'stopped recursing at the root would look identical to a clean tree.',
        );
    }

    /**
     * Every decision site under $directory, as "path:line" relative to
     * $directory, sorted.
     *
     * @return list<string>
     */
    private function decisionSitesIn(string $directory): array
    {
        return $this->sitesIn($directory, fn(string $php): array => $this->decisionLines($php));
    }

    /**
     * Every place the HTTP status is read, as "path:count" relative to
     * $directory, sorted.
     *
     * Reported per file with a count rather than per line, so that adding a
     * paragraph to a docblock above the call does not fail an unrelated test.
     * The count still fails if a second read appears in the same file.
     *
     * @return list<string>
     */
    private function statusReadSitesIn(string $directory): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $reads = count($this->statusReadLines($this->contentsOf($path)));

            if ($reads > 0) {
                $sites[] = $this->relativeTo($directory, $path) . ':' . $reads;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * @param callable(string): list<int> $matcher
     *
     * @return list<string>
     */
    private function sitesIn(string $directory, callable $matcher): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            foreach ($matcher($this->contentsOf($path)) as $line) {
                $sites[] = $this->relativeTo($directory, $path) . ':' . $line;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * 1-based line numbers of every `getStatusCode()` call in $php.
     *
     * @return list<int>
     */
    private function statusReadLines(string $php): array
    {
        $lines = [];

        foreach ($this->significantTokens($php) as $token) {
            if ($token['id'] === T_STRING && strtolower($token['text']) === self::STATUS_ACCESSOR) {
                $lines[] = $token['line'];
            }
        }

        return $lines;
    }

    /**
     * 1-based line numbers of every place in $php where a status-bearing
     * expression takes part in a decision.
     *
     * Two conditions, either of which is enough:
     *
     * - the expression sits anywhere inside the parenthesised head of an `if`,
     *   `elseif`, `while`, `switch` or `match` — including inside a nested call,
     *   which is why this is a stack of paren frames rather than a flag;
     * - the expression is directly beside a comparison, boolean or coalesce
     *   operator, which covers a decision made outside a control structure,
     *   such as `$ok = $statusCode < 300;`.
     *
     * @return list<int>
     */
    private function decisionLines(string $php): array
    {
        $tokens = $this->significantTokens($php);
        $decisionHeads = [T_IF, T_ELSEIF, T_WHILE, T_SWITCH, T_MATCH];

        /** @var list<bool> $parenFrames */
        $parenFrames = [];
        $previousId = 0;
        $lines = [];

        foreach ($tokens as $index => $token) {
            if ($token['text'] === '(') {
                $parenFrames[] = in_array($previousId, $decisionHeads, true);
            } elseif ($token['text'] === ')') {
                array_pop($parenFrames);
            } elseif ($this->readsTheStatus($token)) {
                if (in_array(true, $parenFrames, true) || $this->isOperandOfADecision($tokens, $index)) {
                    $lines[] = $token['line'];
                }
            }

            $previousId = $token['id'];
        }

        return $lines;
    }

    /**
     * Whether $token names an HTTP status.
     *
     * @param array{id: int, text: string, line: int} $token
     */
    private function readsTheStatus(array $token): bool
    {
        if ($token['id'] === T_VARIABLE) {
            return in_array(strtolower($token['text']), self::STATUS_VARIABLES, true);
        }

        return $token['id'] === T_STRING && strtolower($token['text']) === self::STATUS_ACCESSOR;
    }

    /**
     * Whether the token at $index sits beside an operator that makes a decision
     * out of it.
     *
     * Both neighbours are checked because an operator may precede (`!$status`,
     * `$flag ? $status`) or follow (`$status === 200`).
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function isOperandOfADecision(array $tokens, int $index): bool
    {
        $operatorIds = [
            T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL,
            T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP,
            T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR,
            T_LOGICAL_XOR, T_COALESCE,
        ];
        $operatorText = ['<', '>', '?', '!'];

        foreach ([$index - 1, $index + 1] as $neighbour) {
            $token = $tokens[$neighbour] ?? null;

            if ($token === null) {
                continue;
            }

            if (in_array($token['id'], $operatorIds, true) || in_array($token['text'], $operatorText, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $php as a list of code tokens, with whitespace, comments and docblocks
     * dropped and every token carrying the line it starts on.
     *
     * Single-character tokens come out of token_get_all() as bare strings with
     * no line number, so the line is tracked as the stream is walked. Dropping
     * comments is what lets `src/Http/HttpTransport.php` explain at length why
     * it does not branch on the status without tripping the guard that proves
     * it; string literals go for the same reason.
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
     * The open tag is on the same line as the snippet so that line numbers in a
     * fixture read as the fixture is written.
     *
     * @return list<int>
     */
    private function decisionLinesOf(string $snippet): array
    {
        return $this->decisionLines('<?php ' . $snippet);
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
