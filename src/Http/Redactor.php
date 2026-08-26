<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Http;

use function ctype_alnum;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function preg_match;

use SensitiveParameter;

use function str_contains;
use function str_repeat;
use function str_split;
use function strlen;
use function strtolower;
use function substr;

/**
 * Scrubs a PSR-3 context array before it reaches a logger.
 *
 * CONVENTIONS.md §6 requires this class by name: "A Redactor runs on every
 * PSR-3 log record: it masks Password and truncates CardNumber to
 * first-6/last-4 even though the API already masks it."
 *
 * ## Why it exists when nothing sensitive is logged
 *
 * Every log context this package writes is restricted to metadata — operation,
 * URL, HTTP status, attempt number, duration — so as of today the redactor has
 * nothing to scrub. That is not an argument for skipping it: it is the
 * guarantee that a body added to a log context *later* is masked rather than
 * published. The failure it defends against is silent. A decoded response array
 * put into a context during a debugging session carries `CardNumber`, `ExpDate`
 * and `ApprovalCode` under their wire spellings, and without this class it
 * would reach whatever the consumer's logger writes to.
 *
 * So the class is built for the input it does not yet get. There is no bypass,
 * no "raw" mode, and no way for a caller to disable it.
 *
 * ## What is sensitive, and how the set is derived
 *
 * Not a transcribed list of field names. `docs/api-reference/api-surface.json`
 * is the specification of record (CONVENTIONS.md §2), but it is export-ignored
 * (CONVENTIONS.md §7) and is not present in an installed package, so this class
 * cannot read it at runtime. Copying its field names in here would duplicate
 * that specification, and the copy would go stale the moment the bank adds
 * a field.
 *
 * Instead this class carries the *rules* — the same word rules
 * tests/Support/NoSensitiveManifestFieldInMessageTest.php derives
 * CONVENTIONS.md §6's never-log set with — and applies them to the key it is
 * actually handed. A rule matches by stem, so each covers the spelling and
 * separator variants of the family it names without any of them being written
 * down here.
 *
 * That is not self-healing, and reading it that way is the dangerous mistake. A
 * field the bank adds upstream is matched only if its name shares a stem with a
 * rule already below. `Cvv2` and `SecurityCode` share one with none of them and
 * would pass through in the clear on the strength of these tables alone.
 *
 * A conceded limitation is only useful if a reader can tell what is conceded, so
 * the open cases are named rather than gestured at. Besides `Cvv2` and
 * `SecurityCode`, the cardholder's address reaches a log in the clear under
 * every synonym of `ProcessingIP` — `client_ip`, `ipAddress`, `remote_addr` —
 * because the rule that covers it is conjunctive on `processing`, and it is
 * conjunctive because a bare `ip` stem is a substring of `Description` and
 * `TrxnDescription`, the field carrying the merchant's own submitted text.
 * What that rules out is a *shorter* stem, not a further one: `addr` and
 * `clientip` each match none of the manifest's field names, so a disjunctive
 * rule carrying them would reach all three keys with no collateral. The gap is
 * therefore closable under this very mechanism and is left open by choice
 * rather than by obstacle — the choice being that widening a rule past the
 * fields the manifest can name is a design question, and this task settled a
 * different one. None of those keys is a manifest field, so
 * the guarantee below is unaffected; what is exposed is a key a consumer or a
 * bridge package writes itself. tests/Http/RedactorTest.php pins this gap open,
 * so a future fix fails that test and brings whoever makes it back to this
 * paragraph.
 *
 * The guarantee is tests/Http/RedactorKeySetTest.php, and it is the stronger
 * claim. That file reads the manifest at test time and pins the *clear* list
 * exhaustively, not only the redacted one, so a field the manifest gains fails
 * the build whichever side it falls on — unmatched it breaks the clear list,
 * matched it breaks the redacted — and keeps failing until a human classifies
 * it. The rules do not cover whatever the bank adds; what they buy is that the
 * classification is usually already right by the time that human arrives.
 *
 * ## Matching: normalised substring, deliberately over-inclusive
 *
 * A key is lowercased and stripped of everything that is not a letter or a
 * digit, then each rule's stems are sought as substrings. One rule is a
 * conjunction of groups: every group must contribute a stem.
 *
 * Normalising is what makes one stem cover `CardNumber`, `card_number`,
 * `Card Number`, `CARD-NUMBER` and `cardnumber` alike — a log context key is not
 * necessarily a wire spelling, and a redactor that only recognised the wire
 * spelling would miss the framework-massaged copy of it.
 *
 * Substring rather than word-level matching is the one place this class differs
 * from the exception-message guard named above, and the difference is
 * deliberate. That guard fails a build when it fires, so a false positive there
 * costs a rewritten sentence and it matches on whole words to avoid them. This
 * class does not fail anything: a false positive here replaces one log value
 * with a marker, which costs a line of debugging output, while a false negative
 * publishes a PAN. The asymmetry is total, so the over-inclusive matcher wins.
 * The five keys the transport actually logs — operation, url, status, attempt,
 * duration — are asserted to survive it unchanged, which is what keeps
 * "over-inclusive" from meaning "useless".
 *
 * Keys are never rewritten. The §4.8 spellings — `CardBindingFileds`,
 * `IsAvtive`, `resposneCode`, `rrn`, `PaymentId`, `OrderId` — come back byte for
 * byte, in their original order. Only values change.
 *
 * ## Masking
 *
 * Two treatments, and the choice between them is made by the key:
 *
 * - A key naming a card number or a PAN keeps first-six/last-four, per §6. This
 *   is the one place a fragment of a sensitive value is deliberately preserved:
 *   the gateway already returns the PAN in that form — first-six, two mask
 *   characters, last-four, as probe case P3 observed — and a support ticket
 *   needs to be able to identify which card a payment used.
 * - Everything else sensitive is replaced wholesale by a fixed marker, the same
 *   `[redacted]` Credentials already puts where its password would be. A second
 *   marker spelling would mean a log reader has to learn two.
 *
 * The marker carries no information about what it replaced — not its length, not
 * a hash, not a prefix. It is the same string for a four-character password and
 * a four-kilobyte one, so nothing about the secret is recoverable from a log,
 * and nothing about it accumulates across records. Only the PAN case reveals a
 * length, because §6 asks for a fixed-position mask and a fixed-position mask on
 * a variable-length value is a length by construction.
 *
 * ## Values shorter than a PAN, and why there are two floors
 *
 * First-six/last-four on a short *raw* value is not a mask. On ten characters it
 * preserves all ten; on twelve it preserves ten of twelve. So a value of digits
 * alone is masked only when it is shaped like a card number — PAN_MIN_LENGTH to
 * PAN_MAX_LENGTH of them — and anything shorter gets the full marker.
 *
 * A value that already carries the gateway's own mask character is the other
 * case, and it is the one that actually arrives. `GetPaymentDetails` returns
 * `CardNumber` as first-six, two mask characters, last-four — twelve characters
 * in all, as probe case P3 observed. The arithmetic above does not apply to it.
 * Preserving ten of those twelve preserves nothing the gateway has not already
 * published, because the gateway masked positions seven to twelve itself; there
 * is nothing left to hide. So the only floor such a value needs is the length at
 * which the transformation is still well-formed — a prefix, at least one masked
 * character, and a suffix.
 *
 * The distinction is therefore already-masked versus raw, not a length.
 * PAN_MIN_LENGTH is raw-PAN reasoning, and a thirteen-character floor applied to
 * a field that never carries a raw PAN is what kept §6's first-six/last-four
 * promise from ever firing on real gateway data.
 *
 * Anything else under a card key gets the full marker: a non-string, a value
 * with separators, an empty string, a raw value under the raw floor, a masked
 * one under the masked floor. That direction cannot leak — the cost of being
 * wrong is a diagnostic that says `[redacted]` instead of the masked number.
 * `123456**7890` is the shape, and it is an illustrative literal rather than a
 * captured one; no observed card value is written down in shipped source.
 *
 * ## Everything else in a context
 *
 * - Nested arrays are walked, to a depth cap. A body reaching a log context
 *   arrives decoded (HttpTransport decodes with `assoc: true`), so its wire
 *   keys are nested keys, and a redactor that only looked at the top level
 *   would miss every one of them — the exact failure this class exists
 *   to prevent.
 * - A sensitive key whose value is an array is replaced wholesale rather than
 *   walked. Its contents are the secret.
 * - An object is replaced by `[object <class>]`. Any object may hold a PAN in a
 *   property, `__toString()` may render one, and this class has no way to know.
 *   The class name is kept because it is the diagnostic half of the value and
 *   carries none of the state. This means a Throwable passed under PSR-3's
 *   conventional `exception` key does not survive as an object; the transport
 *   does not pass one, and an internal redactor may take that trade where a
 *   general-purpose one could not.
 * - Scalars and null pass through untouched under a non-sensitive key. Anything
 *   else — a resource, an open file handle — becomes the marker, because it has
 *   no rendering this class can vouch for.
 *
 * Values are never scanned for card-shaped content under a non-sensitive key.
 * That was considered and rejected: a nineteen-digit run is also a nanosecond
 * timestamp and an order reference, and masking those would corrupt precisely
 * the metadata the transport does log. Redaction is keyed on the key.
 *
 * @internal
 */
final class Redactor
{
    /**
     * What replaces a sensitive value.
     *
     * The same string Credentials uses for its password, asserted equal to it in
     * tests/Http/RedactorTest.php so the two cannot drift apart.
     *
     * Public because FailureRedactor writes it in place of a request body it
     * cannot decode, and a second spelling of the marker would mean a log reader
     * has to learn two. Both classes are @internal, so this is not package
     * surface.
     */
    public const string REDACTED = '[redacted]';

    /**
     * Leading characters a card number keeps — the issuer identification number.
     */
    private const int PAN_PREFIX = 6;

    /**
     * Trailing characters a card number keeps.
     */
    private const int PAN_SUFFIX = 4;

    /**
     * What the masked middle of a card number is written with. The character the
     * gateway itself uses, so a re-masked value is indistinguishable from one
     * that arrived masked.
     *
     * That sentence is literal and testable rather than a turn of phrase. A
     * gateway-masked `CardNumber` — first-six, two mask characters, last-four,
     * the twelve-character form probe case P3 observed — comes back out of
     * redact() byte for byte, and tests/Http/RedactorTest.php asserts exactly
     * that identity. Write the middle with any other character and the round
     * trip stops being one.
     */
    private const string MASK_CHARACTER = '*';

    private const int PAN_MIN_LENGTH = 13;

    private const int PAN_MAX_LENGTH = 19;

    /**
     * What a raw value has to look like before any of it is preserved.
     *
     * Digits only: a PAN that arrived with separators, or a key holding
     * something that is not a card number at all, falls through to the full
     * marker rather than being partially published. Built from the bounds above
     * so there is one place to change them.
     */
    private const string PAN_PATTERN = '/\A[0-9]{'
        . self::PAN_MIN_LENGTH . ',' . self::PAN_MAX_LENGTH . '}\z/';

    /**
     * The same, for a value the gateway has already masked.
     *
     * Which of the two patterns applies is decided by whether the value contains
     * the mask character, never by trying one and falling back to the other: an
     * eleven-digit raw value satisfies this pattern's length too, and must still
     * get the full marker.
     *
     * This spells out the gateway's shape — a prefix of digits, a run of mask
     * characters, a suffix of digits — rather than accepting any mixture of the
     * two within a length range, and the difference is not cosmetic. The
     * justification for the shorter floor is that the gateway masked the middle
     * itself, so the characters this preserves are ones it already published.
     * That argument holds only where the mask *is* the middle. Under a character
     * class it did not hold: `1234567890*` is eleven characters of digits and a
     * mask, so it qualified, and first-six/last-four published nine of its ten
     * digits — a value that took the full marker before the floor was split. The
     * pattern now permits exactly the arrangement the argument covers.
     */
    private const string MASKED_PAN_PATTERN = '/\A[0-9]{' . self::PAN_PREFIX . '}'
        . '\\' . self::MASK_CHARACTER . '{1,'
        . (self::PAN_MAX_LENGTH - self::PAN_PREFIX - self::PAN_SUFFIX) . '}'
        . '[0-9]{' . self::PAN_SUFFIX . '}\z/';

    /**
     * How far into a nested context this walks before giving up and redacting.
     *
     * A context nested eight deep is already pathological, and an array that
     * contains itself by reference is not walkable at all — without a cap it is
     * an infinite recursion in the logging path, which would take down the
     * caller's request rather than log it. At the cap the whole sub-array
     * becomes the marker, so reaching the cap can only ever remove information,
     * never publish it.
     */
    private const int MAX_DEPTH = 8;

    /**
     * Keys whose value keeps first-six/last-four, as reason => rule.
     *
     * A rule is a list of groups; a key matches when every group contributes at
     * least one stem. The conjunction in 'card number' is what keeps
     * `CardHolderID` out of *this* set — a binding token has no first six digits
     * worth preserving — while the rule below still redacts it in full.
     *
     * @var array<string, list<list<string>>>
     */
    private const array PAN_RULES = [
        'PAN' => [['pan']],
        'card number' => [['card'], ['number', 'no', 'num']],
    ];

    /**
     * Keys whose value is replaced wholesale, as reason => rule.
     *
     * The first four are CONVENTIONS.md §6's never-log list less the PAN rules
     * above. The rest are there for reasons recorded one by one, because a
     * redaction set that grows without a reason per entry stops
     * being reviewable:
     *
     * - 'national identity' is §6's own instruction that `SSN` and
     *   `IdentifierType` are Armenian national identity data handled as
     *   credentials.
     * - 'password', 'username' and 'client id' are the three fields Credentials
     *   holds. Its `merchantFields()` keys are the authority, and
     *   tests/Http/RedactorKeySetTest.php asserts every one of them lands here.
     *   'passphrase' and 'secret' are not manifest fields; they cost nothing and
     *   cover the shape of a credential a bridge package might log.
     * - 'card binding token' covers `CardHolderID` and `BindingID`. The message
     *   guard in tests/Support/NoSensitiveManifestFieldInMessageTest.php
     *   deliberately does *not* treat `CardHolderID` as sensitive, and that
     *   remains right there: §5 requires an exception to name the field it
     *   rejected, and naming is not disclosing. Here it is the value, not the
     *   name, and `MakeBindingPaymentRequest` is `ClientID`
     *   + `Username` + `Password` + `CardHolderID` + `Amount` — the token is
     *   the handle by which a stored card is charged. The two guards disagree
     *   because they are guarding different things.
     * - 'email' covers `ClientEmail`, which is the cardholder's, is personal
     *   data under Armenian and EU law alike, and has no diagnostic value in a
     *   log that an order reference does not already carry.
     * - 'cardholder name' covers `ClientName`, which holds the cardholder's own
     *   name and not the merchant's (probe case P3). Same argument as the email
     *   above, on the same footing. It is conjunctive because it has to be: this
     *   matcher works on substrings, so a bare `name` stem would take
     *   `BankName`, `BankCountryName` and `Username` with it — redacting bank
     *   metadata a log needs, and claiming a credential under the wrong reason.
     *   The first group carries `cardholder` beside `client` so the rule reaches
     *   the spelling it is named after. `ClientName` is the only manifest field
     *   either stem selects, because the conjunction still demands `name` and
     *   `CardHolderID` has none; what the second stem buys is a key a bridge
     *   package or a consumer writes itself, such as `CardholderName` or
     *   `cardholder_name`, which the manifest cannot name and this rule would
     *   otherwise have missed under its own title.
     * - 'cardholder IP address' covers `ProcessingIP`, the address the payment
     *   was made from (probe case P3). It identifies a person and a place, §6
     *   forbids personal data in a log, and nothing a support ticket asks is
     *   answered by it. Conjunctive for the same kind of reason, and a sharper
     *   one: `ip` is a substring of `Description` and `TrxnDescription`, and
     *   `TrxnDescription` is where the gateway echoes the merchant's own
     *   submitted text, so a bare stem would redact the merchant's diagnostics.
     *
     * @var array<string, list<list<string>>>
     */
    private const array SECRET_RULES = [
        'expiry' => [['exp']],
        'approval code' => [['approval']],
        'SSN' => [['ssn', 'socialsecurity']],
        'national identity' => [['identifier', 'identity']],
        'password' => [['password', 'passphrase', 'secret']],
        'username' => [['username']],
        'client id' => [['client'], ['id']],
        'card binding token' => [['cardholder', 'binding'], ['id']],
        'email' => [['email']],
        'cardholder name' => [['client', 'cardholder'], ['name']],
        'cardholder IP address' => [['processing'], ['ip']],
    ];

    /**
     * A copy of $context with every sensitive value masked.
     *
     * Keys are preserved exactly, including the §4.8 misspellings. The input is
     * not modified: PHP hands arrays by value and nothing here takes a reference,
     * so a caller's context array is the same after this returns as before.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function redact(#[SensitiveParameter] array $context): array
    {
        return $this->redactArray($context, 0);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = $this->redactEntry($key, $value, $depth);
        }

        return $redacted;
    }

    /**
     * One entry's value, judged by its key first and its type second.
     *
     * An integer key — a list element — is never sensitive on its own account,
     * so the value is walked rather than masked. The sensitive thing in a list
     * of card bindings is the keys *inside* each element.
     */
    private function redactEntry(int|string $key, mixed $value, int $depth): mixed
    {
        if (is_string($key) && $this->matches($key, self::PAN_RULES)) {
            return $this->maskPan($value);
        }

        if (is_string($key) && $this->matches($key, self::SECRET_RULES)) {
            return self::REDACTED;
        }

        return $this->redactValue($value, $depth);
    }

    /**
     * A value under a key that is not itself sensitive.
     */
    private function redactValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return self::REDACTED;
            }

            return $this->redactArray($value, $depth + 1);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        return self::REDACTED;
    }

    /**
     * First-six/last-four when the value is shaped like a card number, the full
     * marker when it is not.
     */
    private function maskPan(mixed $value): string
    {
        if (!is_string($value)) {
            return self::REDACTED;
        }

        if (!$this->isMaskable($value)) {
            return self::REDACTED;
        }

        return substr($value, 0, self::PAN_PREFIX)
            . str_repeat(self::MASK_CHARACTER, strlen($value) - self::PAN_PREFIX - self::PAN_SUFFIX)
            . substr($value, -self::PAN_SUFFIX);
    }

    /**
     * Whether enough of $value survives the transformation for it to be a mask.
     *
     * Two floors, and the branch — not a pattern precedence — is what separates
     * them. A value carrying the gateway's mask character has had its middle
     * published already, and the shape MASKED_PAN_PATTERN spells out is its own
     * floor — a prefix, at least one masked character and a suffix; a value of digits
     * alone is a raw card number and keeps PAN_MIN_LENGTH.
     */
    private function isMaskable(string $value): bool
    {
        if (str_contains($value, self::MASK_CHARACTER)) {
            return preg_match(self::MASKED_PAN_PATTERN, $value) === 1;
        }

        return preg_match(self::PAN_PATTERN, $value) === 1;
    }

    /**
     * Whether $key matches any rule in $rules.
     *
     * @param array<string, list<list<string>>> $rules
     */
    private function matches(string $key, array $rules): bool
    {
        $normalised = $this->normalise($key);

        foreach ($rules as $groups) {
            if ($this->matchesEveryGroup($normalised, $groups)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<string>> $groups
     */
    private function matchesEveryGroup(string $normalised, array $groups): bool
    {
        foreach ($groups as $group) {
            if (!$this->matchesGroup($normalised, $group)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $group
     */
    private function matchesGroup(string $normalised, array $group): bool
    {
        foreach ($group as $stem) {
            if (str_contains($normalised, $stem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercased, with everything that is not a letter or a digit removed.
     *
     * This is what lets one stem cover `CardNumber`, `card_number`,
     * `Card Number` and `CARD-NUMBER`. Written as a filter rather than a regular
     * expression on purpose: preg_replace() returns null on failure, and the
     * only honest handlings of that null are a branch no test can reach or a
     * fallback that fails open.
     */
    private function normalise(string $key): string
    {
        $normalised = '';

        foreach (str_split(strtolower($key)) as $character) {
            if (ctype_alnum($character)) {
                $normalised .= $character;
            }
        }

        return $normalised;
    }
}
