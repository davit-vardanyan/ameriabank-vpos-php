<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use function array_key_exists;
use function array_keys;
use function ctype_alnum;

use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;

use function file_get_contents;
use function in_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function preg_split;
use function sort;
use function sprintf;
use function str_contains;
use function str_split;
use function strtolower;

/**
 * The redactor's key set, held against the two things it is derived from.
 *
 * The set is derived from `docs/api-reference/api-surface.json` the way the
 * field-name guard next door does, plus the credential stems. Redactor cannot
 * do that at runtime — the manifest is export-ignored (CONVENTIONS.md §7) and
 * is not in an installed package — so it carries the rules and applies them to
 * the key it is handed, and this file is the half that closes the loop. It
 * reads the manifest at test time, derives CONVENTIONS.md §6's never-log set
 * from it, and asserts Redactor covers every name that derivation produces. A
 * sensitive field the bank adds upstream is then either matched by a rule
 * already, or it fails here — never silently unredacted.
 *
 * ## Why the derivation is copied rather than shared
 *
 * The word rules below are a copy of
 * tests/Support/NoSensitiveManifestFieldInMessageTest.php's, and that is
 * deliberate. This suite's guards each carry their own walker for the reason
 * tests/Money's records: a guard that borrows another guard's helper fails open
 * the day the helper is refactored for the other guard's convenience. It also
 * makes this a genuinely independent derivation rather than a restatement of
 * Redactor's own matcher, which would prove nothing.
 *
 * ## Where the two guards deliberately disagree
 *
 * That guard forbids a sensitive field *name* from appearing in an exception
 * message, and excludes `CardHolderID` and `Password` on the grounds that
 * CONVENTIONS.md §5 requires a message to name the field it rejected. This one
 * is about the *value*, where the opposite is true: naming a field is
 * compliant, printing its contents is not. So the set here is a superset, and
 * the extra entries are asserted below rather than left as an accident.
 *
 * The superset is wider than that difference alone accounts for, and the reason
 * is worth stating. §6 carries two obligations, not one: a never-log list — PAN,
 * `ExpDate`, `ApprovalCode`, `SSN` — and a standing prohibition on personal data
 * in a log. Only the first is derivable from field names, and it is what
 * SENSITIVE_WORD_RULES below derives. The second is a judgement about what a
 * field *holds*, which no manifest records: `ClientEmail`, `ClientName` and
 * `ProcessingIP` are declared plain strings and read like merchant metadata.
 * Probe case P3 is what establishes that they carry the cardholder's address,
 * name and IP. Those entries are therefore argued rather than derived, and each
 * is argued once, in Redactor's SECRET_RULES docblock.
 */
#[CoversClass(Redactor::class)]
#[UsesClass(Credentials::class)]
final class RedactorKeySetTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    /**
     * Stored under each manifest field name in turn. Its survival is what says
     * the field was not redacted.
     */
    private const string CANARY = 'canary';

    /**
     * The twelve-character masked form `GetPaymentDetails` returned as
     * `CardNumber` on probe case P3. Not a card number: the gateway masked
     * positions seven to twelve itself, so this is what it has already
     * published. RedactorTest holds the same value and says the same thing.
     */
    private const string GATEWAY_MASKED_PAN = '408306**1818';

    /**
     * The rules that select a sensitive field name, as reason => required words.
     *
     * Copied verbatim from that guard, including the comments' reasoning:
     * word-level and conjunctive, so `ExchangeRate` does not match the expiry
     * rule's `exp` and `CardHolderID` does not match the card-number rule.
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
     * Every manifest field CONVENTIONS.md §6's never-log list selects is
     * redacted.
     *
     * This is the assertion the derivation is for. It is stated against names
     * read from the specification of record at test time, so it cannot be
     * satisfied by editing a list in this file to agree with src/.
     */
    public function testEverySensitiveManifestFieldIsRedacted(): void
    {
        $redactor = new Redactor();

        foreach ($this->sensitiveManifestFields() as $field => $reason) {
            $redacted = $redactor->redact([$field => self::CANARY]);

            self::assertNotSame(
                self::CANARY,
                $redacted[$field],
                sprintf('The manifest field %s is %s data and reached a log unredacted.', $field, $reason),
            );
        }
    }

    /**
     * The derivation must select the fields §6 names, and only those.
     *
     * Without this the test above is green whenever the rules select nothing: an
     * empty set has no unredacted member. The five names are §6's own list,
     * transcribed from the project document rather than from the manifest, so
     * the two have to agree — and a sixth sensitive field appearing upstream
     * fails here and is put in front of a reviewer.
     */
    public function testTheDerivationSelectsExactlyTheFieldsSectionSixNames(): void
    {
        $selected = array_keys($this->sensitiveManifestFields());
        sort($selected);

        self::assertSame(['ApprovalCode', 'CardNumber', 'CardPan', 'ExpDate', 'SSN'], $selected);
    }

    /**
     * Every credential field Credentials actually holds is redacted.
     *
     * The names come from `merchantFields()` — the widest of its two field
     * arrays — rather than from a list here, so a fourth credential added to
     * that class fails this until Redactor covers it. The values are throwaway
     * literals; CONVENTIONS.md §6 forbids a real credential in a fixture.
     */
    public function testEveryCredentialFieldCredentialsHoldsIsRedacted(): void
    {
        $redactor = new Redactor();
        $credentials = new Credentials('cid-x', 'usr-x', 'pw-x');

        foreach (array_keys($credentials->merchantFields()) as $field) {
            $redacted = $redactor->redact([$field => self::CANARY]);

            self::assertSame(
                '[redacted]',
                $redacted[$field],
                sprintf('The credential field %s reached a log unredacted.', $field),
            );
        }
    }

    /**
     * The exact classification of the specification of record, pinned.
     *
     * Fourteen of the manifest's fields are redacted and the other forty-three
     * are not. Both halves are load-bearing, and the clear half does the harder
     * work. The redacted list is the guarantee: delete a rule and a field it
     * covered changes sides here.
     *
     * The clear list does two things. It stops the matcher from being widened
     * until it masks everything, which would protect nothing and destroy every
     * log this package writes — and, the half that matters more, it is the only
     * assertion that notices a *new* sensitive field arriving upstream. A field
     * the manifest gains that no rule happens to match lands silently in the
     * clear list, so this test fails on the day the field appears rather than on
     * the day it reaches a log. That is not hypothetical: `Cvv2` and
     * `SecurityCode` were run through Redactor and match no stem in
     * SECRET_RULES, so were the bank to add either, nothing else in this suite
     * would report it.
     *
     * The nine names here that §6's derivation does not select are deliberate,
     * and each is argued in Redactor's SECRET_RULES docblock: `IdentifierType`
     * is §6's own instruction about Armenian national identity data, `ClientID`
     * and `Username` are credentials, `CardHolderID` and `BindingID` are the
     * handles by which a stored card is charged, `ClientEmail` is the
     * cardholder's personal data, `ClientName` is the cardholder's own name
     * rather than the merchant's, and `ProcessingIP` is the address the payment
     * was made from. The last two are probe case P3's finding, and the
     * derivation below must go on not selecting them: §6's never-log list is
     * PAN, `ExpDate`, `ApprovalCode` and `SSN`, and widening
     * SENSITIVE_WORD_RULES to reach a personal-data field would make that list
     * mean something it does not say.
     */
    public function testTheManifestFieldsThisRedactsAreExactlyThese(): void
    {
        $redactor = new Redactor();
        $redacted = [];
        $clear = [];

        foreach ($this->manifestFieldNames() as $field) {
            if ($redactor->redact([$field => self::CANARY])[$field] === self::CANARY) {
                $clear[] = $field;

                continue;
            }

            $redacted[] = $field;
        }

        sort($redacted);
        sort($clear);

        self::assertSame([
            'ApprovalCode',
            'BindingID',
            'CardHolderID',
            'CardNumber',
            'CardPan',
            'ClientEmail',
            'ClientID',
            'ClientName',
            'ExpDate',
            'IdentifierType',
            'Password',
            'ProcessingIP',
            'SSN',
            'Username',
        ], $redacted);

        self::assertSame([
            'AcsUrl',
            'ActionCode',
            'Amount',
            'ApprovedAmount',
            'BackURL',
            'BankCountryCode',
            'BankCountryName',
            'BankInfo',
            'BankName',
            'CardBindingFileds',
            'Currency',
            'DateTime',
            'DepositedAmount',
            'Description',
            'EndDate',
            'ErrorMessage',
            'ExchangeRate',
            'IsAvtive',
            'MDOrderID',
            'MerchantId',
            'Message',
            'Opaque',
            'OrderID',
            'OrderId',
            'OrderStatus',
            'PaReq',
            'PaymentDate',
            'PaymentID',
            'PaymentId',
            'PaymentServiceType',
            'PaymentState',
            'PaymentType',
            'PrimaryRC',
            'RefundedAmount',
            'ResponseCode',
            'ResponseMessage',
            'StartDate',
            'Status',
            'TermUrl',
            'TerminalId',
            'Timeout',
            'TrxnDescription',
            'rrn',
        ], $clear);
    }

    /**
     * A whole decoded `GetPaymentDetails` body, field by field.
     *
     * The tests above hand Redactor one key at a time, which is not the input it
     * was built for. Its own docblock says so: a decoded body reaching a log
     * context is the failure it exists to prevent, and a body is nested, ordered
     * and thirty fields wide. This is that input.
     *
     * The field set is read from the manifest's `PaymentDetailsResponse` at test
     * time and nested through the `BankInfo` its `Type` column names, so it is
     * the shape the specification of record declares rather than one transcribed
     * here — a field the bank adds arrives in this body automatically and has to
     * be classified below before this passes again. The set is also the shape
     * probe case P3 returned: those thirty names, in that order, with `BankInfo`
     * carrying three.
     *
     * The body is logged the way a body would be logged — under a key, beside
     * the operation name the transport already writes — rather than handed in as
     * the context itself. That is not decoration: passed as the context, all
     * thirty fields would sit at depth zero, and a redactor that had stopped
     * applying its rules below the top level would still pass this. Nested, the
     * sensitive keys are one level down, which is where a decoded body's keys
     * actually are.
     *
     * The values are not P3's. Every field holds a canary naming itself, so
     * "survived" is byte-identity against that field's own canary rather than a
     * guess, and a value copied from one field into another is a failure rather
     * than a coincidence. `CardNumber` is the exception and holds the gateway's
     * twelve-character masked form, because that field is the one whose
     * treatment is neither survival nor the marker. CONVENTIONS.md §6 forbids a real credential in a fixture, and the
     * cardholder's name, email and IP that P3 actually carried are personal data
     * under §6's second obligation; none of the three is written down anywhere in
     * this repository.
     */
    public function testAWholeDecodedPaymentDetailsBodyIsRedactedFieldByField(): void
    {
        $body = $this->bodyFor('PaymentDetailsResponse');

        $context = (new Redactor())->redact([
            'operation' => 'GetPaymentDetails',
            'response' => $body,
        ]);

        self::assertSame(
            'GetPaymentDetails',
            $context['operation'],
            'The metadata the transport logs alongside a body did not survive.',
        );

        $redacted = $context['response'];

        self::assertIsArray($redacted);

        self::assertSame(
            array_keys($body),
            array_keys($redacted),
            'A decoded body came back with different keys, or in a different order.',
        );

        self::assertSame(
            self::GATEWAY_MASKED_PAN,
            $redacted['CardNumber'],
            "CONVENTIONS.md §6's first-six/last-four promise did not fire on the form the gateway "
            . 'actually returns (probe case P3).',
        );

        $marked = [];

        foreach ($redacted as $field => $value) {
            if ($value === Redactor::REDACTED) {
                $marked[] = (string) $field;

                continue;
            }

            self::assertSame(
                $body[$field],
                $value,
                sprintf('The field %s survived redaction but not unchanged.', (string) $field),
            );
        }

        sort($marked);

        self::assertSame([
            'ApprovalCode',
            'BindingID',
            'CardHolderID',
            'ClientEmail',
            'ClientName',
            'ExpDate',
            'ProcessingIP',
        ], $marked, 'The set of fields a decoded GetPaymentDetails body loses to the marker changed.');
    }

    /**
     * The cardholder's name is redacted in a row as well as in an envelope.
     *
     * `GetPendingTransactionsResponse` carries `ClientName` too, and it is the
     * one operation with no `ResponseCode` envelope: the body is a list of rows,
     * so every sensitive key sits under an integer key one level down. That is a
     * different composition from the body above, and an integer key is never
     * sensitive on its own account — the redaction has to come from inside the
     * row.
     *
     * Two identical rows, because a walker that only did the first would pass
     * with one. The field set is the manifest's, for the same reason as above.
     * CONVENTIONS.md §13 records that this endpoint has never been called, so
     * the shape here is the manifest's declaration and not an observation.
     */
    public function testTheCardholderNameIsRedactedInEveryPendingTransactionsRow(): void
    {
        $row = $this->bodyFor('GetPendingTransactionsResponse');
        $redacted = (new Redactor())->redact([$row, $row]);

        self::assertSame([0, 1], array_keys($redacted));

        foreach ([0, 1] as $index) {
            $actual = $redacted[$index];

            self::assertIsArray($actual);

            $marked = [];

            foreach ($actual as $field => $value) {
                if ($value === Redactor::REDACTED) {
                    $marked[] = (string) $field;

                    continue;
                }

                self::assertSame(
                    $row[$field],
                    $value,
                    sprintf('The field %s survived redaction but not unchanged, in row %d.', (string) $field, $index),
                );
            }

            self::assertSame(
                ['ClientName'],
                $marked,
                sprintf('Row %d of a pending-transactions body lost a different set of fields.', $index),
            );

            self::assertSame(self::GATEWAY_MASKED_PAN, $actual['CardNumber']);
        }
    }

    /**
     * A field whose name merely contains a hazardous stem stays in the clear.
     *
     * Both rules probe case P3 added are conjunctive, and neither is conjunctive
     * for tidiness. Redactor matches on substrings, so `[['ip']]` alone would
     * take `Description` and `TrxnDescription`, and `[['name']]` alone would take
     * `BankName` and `BankCountryName`. Simplifying either to a single stem is
     * the refactor a reader who has not met this test would make, and one of the
     * two casualties is worse than over-redaction: `TrxnDescription` is where the
     * gateway echoes the merchant's own submitted text, so redacting it removes
     * the merchant's own diagnostics from the merchant's own log.
     *
     * The subject list is derived — every manifest field whose normalised name
     * contains the stem — so a field the bank adds under either hazard is
     * classified here rather than silently exempted. The two stems themselves are
     * written down, because they are the second group of a rule in Redactor and a
     * manifest names fields, not stems.
     *
     * @param string       $stem         the stem a simplified rule would match on
     * @param list<string> $mustStayHere the manifest fields it must not take
     * @param string       $why          what redacting them would cost
     */
    #[DataProvider('stemsARuleMustNotBeSimplifiedTo')]
    public function testAFieldThatMerelyContainsAHazardousStemStaysInTheClear(
        string $stem,
        array $mustStayHere,
        string $why,
    ): void {
        $redactor = new Redactor();
        $clear = [];

        foreach ($this->manifestFieldsContaining($stem) as $field) {
            if ($redactor->redact([$field => self::CANARY])[$field] === self::CANARY) {
                $clear[] = $field;
            }
        }

        self::assertSame($mustStayHere, $clear, $why);
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function stemsARuleMustNotBeSimplifiedTo(): array
    {
        return [
            'ip' => [
                'ip',
                ['Description', 'TrxnDescription'],
                "Redactor's 'cardholder IP address' rule matched a description field. TrxnDescription "
                . "carries the merchant's own submitted text and Description the processor's; a bare 'ip' "
                . "stem takes both, and the merchant loses their own diagnostics.",
            ],
            'name' => [
                'name',
                ['BankCountryName', 'BankName'],
                "Redactor's 'cardholder name' rule matched a bank field. BankName and BankCountryName are "
                . "bank metadata a support ticket reads; a bare 'name' stem takes both, and claims Username "
                . 'under the wrong reason besides.',
            ],
        ];
    }

    /**
     * Every manifest field name whose normalised form contains $stem, sorted.
     *
     * Normalised the way Redactor normalises a key, because that is the form a
     * stem is sought in. Nothing in the manifest carries a separator today, so
     * this is the same list either way — it is written this way so it stays the
     * same list when something does.
     *
     * @return list<string>
     */
    private function manifestFieldsContaining(string $stem): array
    {
        $matching = [];

        foreach ($this->manifestFieldNames() as $name) {
            if (str_contains($this->normalise($name), $stem)) {
                $matching[] = $name;
            }
        }

        self::assertNotSame(
            [],
            $matching,
            sprintf('No manifest field contains the stem %s, so this guard has no subjects.', $stem),
        );

        return $matching;
    }

    /**
     * Lowercased, with everything that is not a letter or a digit removed.
     */
    private function normalise(string $name): string
    {
        $normalised = '';

        foreach (str_split(strtolower($name)) as $character) {
            if (ctype_alnum($character)) {
                $normalised .= $character;
            }
        }

        return $normalised;
    }

    /**
     * A decoded response body for $model, built from the manifest.
     *
     * A field whose `Type` names another model becomes a nested body, which is
     * how `BankInfo` gets into a `PaymentDetailsResponse` without this method
     * knowing that either name. An enum-typed field does not: an enum table's
     * rows are members and carry no `Type` column, so isModelName() rejects it.
     *
     * $seen is the recursion guard. Nothing in the manifest refers to itself
     * today; a model that did would otherwise hang the suite rather than fail it.
     *
     * @param list<string> $seen
     *
     * @return array<string, mixed>
     */
    private function bodyFor(string $model, array $seen = []): array
    {
        self::assertNotContains($model, $seen, sprintf('The manifest model %s refers to itself.', $model));

        $seen[] = $model;
        $body = [];

        foreach ($this->fieldsOf($model) as $name => $type) {
            if ($name === 'CardNumber') {
                $body[$name] = self::GATEWAY_MASKED_PAN;

                continue;
            }

            $body[$name] = $this->isModelName($type)
                ? $this->bodyFor($type, $seen)
                : $this->canaryFor($name);
        }

        return $body;
    }

    /**
     * A canary naming the field it is stored under.
     */
    private function canaryFor(string $field): string
    {
        return self::CANARY . '-' . $field;
    }

    /**
     * $model's fields, as field name => declared type, in manifest order.
     *
     * @return array<string, string>
     */
    private function fieldsOf(string $model): array
    {
        $models = $this->manifestModels();

        self::assertArrayHasKey($model, $models, sprintf('The manifest no longer declares %s.', $model));

        $declared = $models[$model];

        self::assertIsArray($declared);
        self::assertIsArray($declared['fields'] ?? null);
        self::assertNotSame([], $declared['fields'], sprintf('The manifest model %s declares no fields.', $model));

        $fields = [];

        foreach ($declared['fields'] as $field) {
            self::assertIsArray($field);
            self::assertIsString($field['Name'] ?? null);
            self::assertIsString($field['Type'] ?? null, sprintf('%s is an enum table, not a model.', $model));

            $fields[$field['Name']] = $field['Type'];
        }

        return $fields;
    }

    /**
     * Whether $name is a manifest model, as opposed to an enum table or a
     * declared type such as `string` that names nothing in the manifest.
     */
    private function isModelName(string $name): bool
    {
        $models = $this->manifestModels();

        if (!array_key_exists($name, $models)) {
            return false;
        }

        $model = $models[$name];

        self::assertIsArray($model);
        self::assertIsArray($model['fields'] ?? null);

        foreach ($model['fields'] as $field) {
            self::assertIsArray($field);

            if (array_key_exists('Type', $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sensitive manifest fields, as field name => the rule that selected it.
     *
     * @return array<string, string>
     */
    private function sensitiveManifestFields(): array
    {
        $sensitive = [];

        foreach ($this->manifestFieldNames() as $name) {
            $words = $this->wordsOf($name);

            foreach (self::SENSITIVE_WORD_RULES as $reason => $groups) {
                if ($this->matchesEveryGroup($words, $groups)) {
                    $sensitive[$name] = $reason;

                    break;
                }
            }
        }

        return $sensitive;
    }

    /**
     * @param list<string>       $words
     * @param list<list<string>> $groups
     */
    private function matchesEveryGroup(array $words, array $groups): bool
    {
        foreach ($groups as $group) {
            $found = false;

            foreach ($group as $word) {
                if (in_array($word, $words, true)) {
                    $found = true;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every distinct field name in the manifest's models, sorted.
     *
     * Enum tables are skipped: their rows are members, with Name/Value/
     * Description columns and no Type column, so a member spelled like a
     * sensitive word would not be a field.
     *
     * @return list<string>
     */
    private function manifestFieldNames(): array
    {
        $names = [];

        foreach ($this->manifestModels() as $model) {
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

        $sorted = array_keys($names);
        sort($sorted);

        return $sorted;
    }

    /**
     * The manifest's models, as model name => model.
     *
     * One reader for the specification of record, shared by the walkers in this
     * file and by nothing outside it. This file's docblock records why a guard
     * does not borrow another guard's helper; that argument is about two guards
     * in two files, and a second reader of the same document inside one class
     * would only be a second place for the path to go stale.
     *
     * @return array<array-key, mixed>
     */
    private function manifestModels(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw, sprintf('Could not read the manifest at %s.', self::MANIFEST));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['models'] ?? null);
        self::assertNotSame([], $decoded['models'], 'The manifest declares no models at all.');

        return $decoded['models'];
    }

    /**
     * A field name split on camel-case boundaries and underscores, lowercased.
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
}
