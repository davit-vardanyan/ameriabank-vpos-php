<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Support;

use function array_key_exists;
use function array_keys;
use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function preg_replace;
use function preg_split;
use function scandir;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function token_get_all;

/**
 * CONVENTIONS.md §6: a PAN, an expiry date, an approval code or an SSN must
 * never reach a log record or an exception message.
 *
 * This guard replaces the hand-maintained token list in
 * tests/Exception/ExceptionHierarchyTest.php — not by deleting it, which would
 * lose coverage that list carries and this one cannot (see that file's
 * forbiddenTokenIn()), but by taking over the part that is derivable: the
 * field names. Those come from `docs/api-reference/api-surface.json` at test
 * time, so a sensitive field the bank adds upstream is covered on the next
 * manifest regeneration rather than on the next time somebody remembers to
 * extend a list.
 *
 * ## What is scanned, and what deliberately is not
 *
 * **Message templates and log calls only** — not every string literal in src/.
 * The distinction is the whole design, and getting it wrong makes the guard
 * unsatisfiable.
 *
 * ResponseHydrator necessarily contains `'CardNumber'`, `'CardPan'`,
 * `'ExpDate'` and `'ApprovalCode'` as literals: they are the wire keys, they
 * are the mandated mapping, and CONVENTIONS.md §4.8 forbids altering them. A
 * guard that banned the substring anywhere under src/ would demand exactly the
 * change §4.8 prohibits. What §6 forbids is the *value* reaching a message, or
 * the name being baked into a template — and every rejection the hydrator
 * raises is `sprintf('the %s field was …', $key)`, with the name supplied at
 * runtime. That is compliant, and it must keep passing.
 *
 * So the scan collects string literals appearing inside a message-carrying
 * call: sprintf() and its relatives, a PSR-3 log method, `new SomeException(…)`
 * and `SomeException::factory(…)`. It is a tokenizer scan, so a docblock that
 * discusses a card number — several in src/ do, including the one this file
 * describes — is not a message and is not read.
 *
 * ## What counts as sensitive
 *
 * The set is derived, not typed. Each manifest field name is split on camel
 * case and the words are matched against the five rules in
 * SENSITIVE_WORD_RULES — PAN, card number, expiry, approval code, SSN — which
 * are CONVENTIONS.md §6's own list. On the manifest as it stands that selects
 * `CardPan`, `CardNumber`, `ExpDate`, `ApprovalCode` and `SSN`, and a test
 * below asserts exactly that, so a rule edited into uselessness is visible.
 *
 * Two exclusions are deliberate and load-bearing:
 *
 * - **`CardHolderID` is not sensitive.** It is a binding token, not a PAN, and
 *   `ValidationException::blankValue('CardHolderID')` in src/Request/ is
 *   §6-compliant: it names a field, carries no value, and must not trip this.
 *   Matching on the word `Card` alone rather than `Card` plus `Number` would
 *   break it, which is why the rules are word-level and conjunctive.
 * - **`Password` is not in the set**, though it is a manifest field of every
 *   request model and though §6 treats it at least as gravely. §5 requires
 *   exception messages to *name* fields, and
 *   `ConfigurationException::blankCredential('Password')` in src/Config/ does
 *   precisely that. Banning the name would forbid the compliant form of the
 *   very message §5 asks for. Credentials are guarded where the risk actually
 *   is — Credentials redacts its own rendering, and that list still forbids a
 *   password-shaped *parameter* on any exception.
 *
 * ## Matching
 *
 * A literal is normalised by lowercasing and dropping everything that is not
 * alphanumeric, then the token is sought as a substring. Normalising is what
 * lets one derived token cover `Card Number`, `card_number` and `card-number`
 * as well as `CardNumber`. It admits a false positive — a sentence ending in a
 * word and beginning with another that happen to concatenate into a token — and
 * that is the same trade the two older guards in this suite document: a false
 * positive is a loud failure the moment the line is written and costs one
 * rephrased sentence, while a leaked PAN is silent and permanent.
 */
#[CoversNothing]
final class NoSensitiveManifestFieldInMessageTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    private const string SOURCE = __DIR__ . '/../../src';

    /**
     * The rules that select a sensitive field name, as reason => required words.
     *
     * Each entry is a list of alternative groups; a field matches when every
     * group has at least one of its words among the field's camel-case words. A
     * single-group rule is a plain "contains one of these words"; the
     * two-group 'card number' rule is a conjunction, and that conjunction is
     * what keeps `CardHolderID` out.
     *
     * Word-level, never substring: `ExchangeRate` splits to Exchange|Rate and
     * so does not match the expiry rule's `exp`, while a substring test would
     * flag it. `ApprovedAmount` splits to Approved|Amount and does not match
     * `approval` — an approved amount is a sum of money, not an approval code.
     *
     * @var array<string, list<list<string>>>
     */
    private const array SENSITIVE_WORD_RULES = [
        'PAN' => [['pan']],
        'card number' => [['card'], ['number', 'no', 'num']],
        'expiry' => [['exp', 'expdate', 'expiry', 'expiration', 'expires']],
        'approval code' => [['approval']],
        'SSN' => [['ssn', 'socialsecurity']],
    ];

    /**
     * Functions whose string arguments are message text.
     *
     * @var list<string>
     */
    private const array MESSAGE_FUNCTIONS = ['sprintf', 'vsprintf', 'printf'];

    /**
     * PSR-3's eight levels plus log(). Matched only after an object operator,
     * so a method of this package named error() would not be mistaken for one —
     * and if it were, being scanned is the safe direction.
     *
     * @var list<string>
     */
    private const array LOG_METHODS = [
        'log', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency',
    ];

    /**
     * The guard itself.
     */
    public function testNoSensitiveManifestFieldNameAppearsInAMessageOrLogCall(): void
    {
        self::assertSame(
            [],
            $this->violationsIn(self::SOURCE, $this->sensitiveFields()),
            'CONVENTIONS.md §6: a PAN, an expiry date, an approval code and an SSN may '
            . 'never reach a log record or an exception message. Name the field at '
            . "runtime instead — sprintf('the %s field was …', \$key) — as every "
            . 'rejection in ResponseHydrator already does.',
        );
    }

    /**
     * The derivation must select the fields CONVENTIONS.md §6 names, and only
     * those.
     *
     * Without this the guard is green whenever the rules select nothing: an
     * empty forbidden set finds no violations, which is indistinguishable from
     * a clean tree. The five names are §6's own list, transcribed from the
     * project document rather than from the manifest, so the two have to agree
     * for this to pass — which is the point. If the manifest ever gains a sixth
     * sensitive field, this fails and the reviewer is asked to look at it,
     * rather than the guard silently widening.
     */
    public function testTheDerivationSelectsExactlyTheFieldsSectionSixNames(): void
    {
        $selected = array_keys($this->sensitiveFields());
        sort($selected);

        self::assertSame(
            ['ApprovalCode', 'CardNumber', 'CardPan', 'ExpDate', 'SSN'],
            $selected,
            'The manifest-derived sensitive set no longer matches CONVENTIONS.md §6. If '
            . 'the manifest gained a field, widen §6 or the rules deliberately.',
        );
    }

    /**
     * The names §6 permits in a message must not be selected.
     *
     * `CardHolderID` is a binding token and src/Request/ names it in a blank
     * -value rejection; `Password` is a credential whose *name* §5 requires
     * ConfigurationException to state. Both are manifest fields, both sit next
     * to the sensitive ones, and a rule loosened by one word would take either.
     */
    public function testTheDerivationDoesNotSelectTheNamesSectionFivePermits(): void
    {
        $selected = $this->sensitiveFields();

        self::assertArrayNotHasKey('CardHolderID', $selected, 'A CardHolderID is a binding token, not a PAN.');
        self::assertArrayNotHasKey('Password', $selected, 'CONVENTIONS.md §5 requires the field to be named; §6 forbids the value.');
        self::assertArrayNotHasKey('ApprovedAmount', $selected, 'An approved amount is a sum of money, not an approval code.');
        self::assertArrayNotHasKey('ExchangeRate', $selected, 'A word-level rule must not read "Exchange" as "Exp".');
        self::assertArrayNotHasKey('CardBindingFileds', $selected);
    }

    /**
     * The scan must reach the tree it claims to scan, and must reach past its
     * root.
     *
     * An empty file list yields an empty violation list, which looks exactly
     * like a clean tree. Both pinned paths are the two files that carry the
     * cases this guard is really about: the hydrator, whose compliant runtime
     * templates must keep passing, and a request whose `CardHolderID`
     * rejection must not be mistaken for one.
     */
    public function testTheScanReachesTheSourceTree(): void
    {
        $found = [];

        foreach ($this->phpFilesIn(self::SOURCE) as $path) {
            $found[] = $this->relativeTo(self::SOURCE, $path);
        }

        self::assertContains('Support/ResponseHydrator.php', $found);
        self::assertContains('Request/ActivateBindingRequest.php', $found);
        self::assertGreaterThan(1, count($found));
    }

    /**
     * The compliant forms in src/ today, asserted as compliant.
     *
     * These are not hypotheticals: each is a line this package actually
     * contains, and each is the shape a careless widening of the guard would
     * break. The hydrator's runtime template is the one that matters most —
     * §4.8 forbids renaming the wire keys, so if the guard flagged the template
     * there would be no legal way to satisfy it.
     */
    public function testTheCompliantFormsThisPackageUsesAreNotFlagged(): void
    {
        $sensitive = $this->sensitiveFields();

        self::assertSame([], $this->violationLinesIn(
            "<?php sprintf('the %s field was not text', \$key);",
            $sensitive,
        ), 'A runtime-supplied field name is the compliant form.');

        self::assertSame([], $this->violationLinesIn(
            "<?php throw ValidationException::blankValue('CardHolderID');",
            $sensitive,
        ), 'A CardHolderID is a binding token, not card data.');

        self::assertSame([], $this->violationLinesIn(
            "<?php throw ConfigurationException::blankCredential('Password');",
            $sensitive,
        ), 'CONVENTIONS.md §5 requires the credential field to be named.');

        self::assertSame([], $this->violationLinesIn(
            "<?php \$v = self::readText(\$data, 'CardNumber', self::OP);",
            $sensitive,
        ), 'A wire key outside a message call is the mandated mapping — CONVENTIONS.md §4.8.');

        self::assertSame([], $this->violationLinesIn(
            "<?php /** Carrying CardPan and ExpDate is required; their values may not be logged. */",
            $sensitive,
        ), 'A docblock is not a message; the tokenizer must not read one.');
    }

    /**
     * The matcher must catch what it exists to catch.
     *
     * Every row is a way the leak would really be written: a name baked into a
     * template, a value interpolated into one, a log call, an exception
     * constructed directly, and the same name spaced or underscored so a naive
     * substring would miss it.
     */
    public function testTheMatcherFlagsEveryShapeOfLeak(): void
    {
        $sensitive = $this->sensitiveFields();

        self::assertSame([1], $this->violationLinesIn(
            "<?php sprintf('the CardNumber field was not text', \$key);",
            $sensitive,
        ));

        self::assertSame([1], $this->violationLinesIn(
            "<?php sprintf('ExpDate %s is malformed', \$value);",
            $sensitive,
        ));

        self::assertSame([1], $this->violationLinesIn(
            "<?php \$this->logger->warning('ApprovalCode rejected', \$context);",
            $sensitive,
        ));

        self::assertSame([1], $this->violationLinesIn(
            "<?php throw new ValidationException('SSN is not a valid identifier');",
            $sensitive,
        ));

        self::assertSame([1], $this->violationLinesIn(
            "<?php throw ValidationException::malformedValue('CardPan', 'a masked pan');",
            $sensitive,
        ));

        self::assertSame([1], $this->violationLinesIn(
            "<?php sprintf('the card number was rejected');",
            $sensitive,
        ), 'Normalisation covers a spaced spelling.');

        self::assertSame([1], $this->violationLinesIn(
            "<?php sprintf('card_number rejected');",
            $sensitive,
        ), 'Normalisation covers an underscored spelling.');

        self::assertSame([2, 4], $this->violationLinesIn(
            "<?php\nsprintf('CardPan');\n\$x = 1;\nsprintf('ExpDate');\n",
            $sensitive,
        ));
    }

    /**
     * A guard with no test of its own is not a guard, so the scanner is run
     * over a fixture tree that does contain a leak.
     *
     * The fixture lives in a temporary directory and never under src/: an
     * interrupted run must not leave a throwaway file in the shipped package.
     * The violation is one level down, so a scanner that stopped recursing
     * would call this tree clean.
     */
    public function testTheScannerReportsAViolationInAFixtureTree(): void
    {
        $root = sys_get_temp_dir() . '/vpos-message-guard-' . bin2hex(random_bytes(8));
        $nested = $root . '/Exception';

        self::assertTrue(mkdir($nested, 0o700, true));

        try {
            file_put_contents($root . '/Clean.php', "<?php\n\nsprintf('the %s field was not text', \$key);\n");
            file_put_contents($nested . '/Dirty.php', "<?php\n\nsprintf('the CardNumber was rejected');\n");
            file_put_contents($nested . '/NotPhp.txt', "sprintf('the CardNumber was rejected');\n");

            self::assertSame(
                ['Exception/Dirty.php:3: CardNumber'],
                $this->violationsIn($root, $this->sensitiveFields()),
            );
        } finally {
            foreach (['/Clean.php', '/Exception/Dirty.php', '/Exception/NotPhp.txt'] as $file) {
                if (is_file($root . $file)) {
                    unlink($root . $file);
                }
            }

            rmdir($nested);
            rmdir($root);
        }
    }

    /**
     * The sensitive manifest fields, as field name => the rule that selected it.
     *
     * @return array<string, string>
     */
    private function sensitiveFields(): array
    {
        $sensitive = [];

        foreach ($this->manifestFieldNames() as $name) {
            $words = $this->wordsOf($name);

            foreach (self::SENSITIVE_WORD_RULES as $reason => $groups) {
                $matched = true;

                foreach ($groups as $group) {
                    $found = false;

                    foreach ($group as $word) {
                        if (in_array($word, $words, true)) {
                            $found = true;

                            break;
                        }
                    }

                    if (!$found) {
                        $matched = false;

                        break;
                    }
                }

                if ($matched) {
                    $sensitive[$name] = $reason;

                    break;
                }
            }
        }

        return $sensitive;
    }

    /**
     * Every distinct field name in the manifest's models.
     *
     * Enum tables are skipped: their rows are members, with Name/Value/
     * Description columns and no Type column, so a member spelled like a
     * sensitive word would not be a field.
     *
     * @return list<string>
     */
    private function manifestFieldNames(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw, sprintf('Could not read the manifest at %s.', self::MANIFEST));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['models'] ?? null);

        $names = [];

        foreach ($decoded['models'] as $model) {
            self::assertIsArray($model);
            self::assertIsArray($model['fields'] ?? null);

            $isModel = false;
            $fields = [];

            foreach ($model['fields'] as $field) {
                self::assertIsArray($field);
                self::assertIsString($field['Name'] ?? null);

                if (array_key_exists('Type', $field)) {
                    $isModel = true;
                }

                $fields[] = $field['Name'];
            }

            if (!$isModel) {
                continue;
            }

            foreach ($fields as $field) {
                $names[$field] = true;
            }
        }

        self::assertNotSame([], $names, 'The manifest yielded no field names at all.');

        return array_keys($names);
    }

    /**
     * A field name split on camel-case boundaries and underscores, lowercased.
     *
     * `CardHolderID` becomes card|holder|id, `ExpDate` becomes exp|date, and
     * `SSN` stays ssn — the run-of-capitals boundary only breaks before a
     * capital that starts a lowercase word, so an acronym is not shredded into
     * letters.
     *
     * @return list<string>
     */
    private function wordsOf(string $name): array
    {
        $words = preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|_/', $name);

        self::assertNotFalse($words, sprintf('The word-splitting pattern failed on %s.', $name));

        $lowered = [];

        foreach ($words as $word) {
            $lowered[] = strtolower($word);
        }

        return $lowered;
    }

    /**
     * Every leak under $directory as "path:line: Field", relative to
     * $directory, sorted.
     *
     * A pure function of the path, so its own failure mode is testable against
     * a fixture directory rather than only against the real tree.
     *
     * @param array<string, string> $sensitive
     *
     * @return list<string>
     */
    private function violationsIn(string $directory, array $sensitive): array
    {
        $sites = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            $contents = file_get_contents($path);

            // Not skipped silently: an unreadable file is a hole in the guard,
            // and a hole in this guard looks exactly like a clean tree.
            self::assertIsString($contents, sprintf('Could not read %s.', $path));

            foreach ($this->messageLiteralsIn($contents) as [$line, $literal]) {
                foreach ($this->fieldsNamedIn($literal, $sensitive) as $field) {
                    $sites[] = $this->relativeTo($directory, $path) . ':' . $line . ': ' . $field;
                }
            }
        }

        $sites = array_keys(array_flip($sites));
        sort($sites);

        return $sites;
    }

    /**
     * The 1-based lines of $php on which a message call names a sensitive
     * field, sorted and deduplicated.
     *
     * @param array<string, string> $sensitive
     *
     * @return list<int>
     */
    private function violationLinesIn(string $php, array $sensitive): array
    {
        $lines = [];

        foreach ($this->messageLiteralsIn($php) as [$line, $literal]) {
            if ($this->fieldsNamedIn($literal, $sensitive) !== []) {
                $lines[$line] = true;
            }
        }

        $found = array_keys($lines);
        sort($found);

        return $found;
    }

    /**
     * The sensitive fields $literal names, sorted.
     *
     * @param array<string, string> $sensitive
     *
     * @return list<string>
     */
    private function fieldsNamedIn(string $literal, array $sensitive): array
    {
        $normalised = $this->normalise($literal);
        $named = [];

        foreach (array_keys($sensitive) as $field) {
            if (str_contains($normalised, $this->normalise($field))) {
                $named[] = $field;
            }
        }

        sort($named);

        return $named;
    }

    /**
     * Lowercased, with everything that is not a letter or a digit removed.
     *
     * This is what makes one derived token cover `Card Number`, `card_number`
     * and `card-number` as well as `CardNumber`.
     */
    private function normalise(string $text): string
    {
        $stripped = preg_replace('/[^a-z0-9]+/', '', strtolower($text));

        self::assertIsString($stripped, 'The normalisation pattern failed.');

        return $stripped;
    }

    /**
     * Every string literal in $php that sits inside a message-carrying call, as
     * [line, value].
     *
     * A call qualifies when its callee is sprintf() or a relative, a PSR-3 log
     * method reached through an object operator, or anything named `*Exception`
     * — constructed with `new` or called statically. Literals are collected to
     * the matching close parenthesis at any nesting depth, so the common
     * `SomeException::factory(sprintf(…))` shape is covered once by each and
     * deduplicated by the caller.
     *
     * @return list<array{int, string}>
     */
    private function messageLiteralsIn(string $php): array
    {
        $tokens = [];

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        $count = count($tokens);
        $literals = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (!$this->isMessageCall($tokens, $i)) {
                continue;
            }

            // Advance to the argument list.
            $k = $i;

            while ($k < $count && $tokens[$k] !== '(') {
                $k++;
            }

            $depth = 0;

            for (; $k < $count; $k++) {
                if ($tokens[$k] === '(') {
                    $depth++;

                    continue;
                }

                if ($tokens[$k] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }

                $inner = $tokens[$k];

                if (is_array($inner) && $inner[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = [$inner[2], $this->literalValue($inner[1])];
                }
            }
        }

        return $literals;
    }

    /**
     * Whether the T_STRING at $index is the callee of a message-carrying call.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function isMessageCall(array $tokens, int $index): bool
    {
        $name = $tokens[$index];

        if (!is_array($name)) {
            return false;
        }

        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $lowered = strtolower($name[1]);

        if ($next === '(' && in_array($lowered, self::MESSAGE_FUNCTIONS, true)) {
            return true;
        }

        if (
            $next === '('
            && is_array($previous)
            && $previous[0] === T_OBJECT_OPERATOR
            && in_array($lowered, self::LOG_METHODS, true)
        ) {
            return true;
        }

        if (!str_ends_with($name[1], 'Exception')) {
            return false;
        }

        if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
            return true;
        }

        return is_array($previous) && $previous[0] === T_NEW;
    }

    /**
     * The value of a T_CONSTANT_ENCAPSED_STRING token.
     *
     * Single quotes are unescaped properly; a double-quoted literal's inner
     * text is taken as-is, which is enough here because normalisation discards
     * every escape character anyway.
     */
    private function literalValue(string $token): string
    {
        $inner = substr($token, 1, strlen($token) - 2);

        if ($token[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return $inner;
    }

    /**
     * Absolute paths of every .php file under $directory, recursively, sorted.
     *
     * Deliberately not shared with the other guards in this suite. A guard that
     * borrows another guard's walker fails open the day that walker is
     * refactored for the other guard's convenience — the reasoning
     * tests/Money's guard records, and it applies here unchanged.
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
