<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use function array_keys;

use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Http\Redactor;

use function fclose;
use function fopen;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use stdClass;

use function str_contains;
use function str_repeat;
use function strlen;

/**
 * The redactor's behaviour, one masking rule at a time.
 *
 * The key set it applies these rules to is guarded separately, against the
 * manifest and against Credentials, in RedactorKeySetTest.
 *
 * ## Canaries
 *
 * Every secret in this file is a short throwaway literal. That is not
 * incidental: this suite has recorded a real credential leak that a passing
 * test hid, because PHP truncates a string argument in a stack trace at
 * fifteen characters and the canary was longer than that — the assertion
 * looked at a prefix and found no match. `PW_CANARY` is nine characters, and a
 * test below asserts it stays under the limit so the lesson cannot be undone
 * by someone making the canary more realistic.
 *
 * The card numbers are the ISO test PAN 4111 1111 1111 1111, a sixteen-character
 * already-masked value built from a published test card, and `408306**1818` —
 * the twelve-character masked form probe case P3 captured from the sandbox. Only
 * the last of those three came off the gateway; the other two are constructed,
 * and so are the length variants in the floor providers below. None is a
 * credential (CONVENTIONS.md §6, which forbids a real credential in a fixture),
 * and none is a card number: the gateway masks positions seven to twelve itself,
 * so what is written down here is what the gateway has already published.
 *
 * The captured value is in this file and not in `src/` on purpose. It is what
 * proves the twelve-character behaviour against reality rather than against a
 * shape someone imagined, and tests are export-ignored (CONVENTIONS.md §7), so
 * it reaches no installed package.
 *
 * ## One test deliberately absent
 *
 * There is no test that the caller's context array survives unmodified. One was
 * written and removed: PHPStan reported its assertion as always true, which is
 * the stronger result. `redact()` takes its array by value and nothing in the
 * class takes a reference, so non-mutation is a property the type checker proves
 * at level 10 rather than one a test could fail to notice.
 */
#[CoversClass(Redactor::class)]
final class RedactorTest extends TestCase
{
    /**
     * Nine characters. Must stay under fifteen — see the class docblock.
     */
    private const string PW_CANARY = 'pw-canary';

    private const string TEST_PAN = '4111111111111111';

    /**
     * Sixteen characters, constructed. Not a value the gateway has ever sent.
     */
    private const string MASKED_PAN = '400000******0002';

    /**
     * Twelve characters, and the only literal here that came off the wire —
     * `GetPaymentDetails` returned it as `CardNumber` on probe case P3.
     */
    private const string GATEWAY_MASKED_PAN = '408306**1818';

    private const string MARKER = '[redacted]';

    /**
     * The headline case: a context carrying a card number and a
     * password comes back with both masked and the card keeping first-six and
     * last-four.
     */
    public function testACardNumberAndAPasswordAreBothMaskedAndTheCardKeepsSixAndFour(): void
    {
        $redacted = (new Redactor())->redact([
            'CardNumber' => self::TEST_PAN,
            'Password' => self::PW_CANARY,
        ]);

        self::assertSame('411111******1111', $redacted['CardNumber']);
        self::assertSame(self::MARKER, $redacted['Password']);
    }

    /**
     * The canary is short enough for the assertion above to be meaningful.
     *
     * PHP renders at most fifteen characters of a string argument in a stack
     * trace. A longer canary makes "the secret does not appear" true of a
     * truncated prefix while the secret is on the page — which is exactly what
     * happened here once.
     */
    public function testTheCanaryIsShorterThanPhpTruncatesTraceArgumentsAt(): void
    {
        self::assertLessThan(15, strlen(self::PW_CANARY));
    }

    /**
     * Nothing recognisable survives anywhere in the rendered output.
     *
     * Serialising the whole result and searching it catches a leak the
     * key-by-key assertions would miss — a secret copied into a neighbouring
     * key, or one left inside a nested structure.
     */
    public function testNoPartOfASecretSurvivesAnywhereInTheOutput(): void
    {
        $redacted = (new Redactor())->redact([
            'Username' => 'usr-canary',
            'Password' => self::PW_CANARY,
            'body' => ['ClientID' => 'cid-canary', 'CardNumber' => self::TEST_PAN],
        ]);

        $rendered = json_encode($redacted, JSON_THROW_ON_ERROR);

        self::assertFalse(str_contains($rendered, self::PW_CANARY));
        self::assertFalse(str_contains($rendered, 'usr-canary'));
        self::assertFalse(str_contains($rendered, 'cid-canary'));
        self::assertFalse(str_contains($rendered, self::TEST_PAN));
    }

    /**
     * The marker is the one Credentials already uses.
     *
     * Read from both classes rather than compared to a literal, so a second
     * marker spelling cannot be introduced on either side without this failing.
     */
    public function testTheMarkerIsTheOneCredentialsAlreadyUses(): void
    {
        $mine = new ReflectionClassConstant(Redactor::class, 'REDACTED');
        $theirs = new ReflectionClassConstant(Credentials::class, 'REDACTED');

        self::assertSame($theirs->getValue(), $mine->getValue());
        self::assertSame(self::MARKER, $mine->getValue());
    }

    /**
     * The marker says nothing about what it replaced, including its length.
     */
    public function testTheMarkerIsIdenticalWhateverTheSecretsLength(): void
    {
        $redactor = new Redactor();

        $short = $redactor->redact(['Password' => 'a']);
        $long = $redactor->redact(['Password' => str_repeat('a', 4096)]);

        self::assertSame($short['Password'], $long['Password']);
    }

    /**
     * The transport logs exactly these five keys. If the redactor mangled any
     * of them it would have made the transport's logging useless in exchange
     * for protecting nothing, which is the failure mode an over-inclusive
     * matcher risks.
     */
    public function testTheKeysTheTransportActuallyLogsPassThroughUnchanged(): void
    {
        $context = [
            'operation' => 'InitPayment',
            'url' => 'https://servicestest.ameriabank.am/VPOS/InitPayment',
            'status' => 200,
            'attempt' => 1,
            'duration' => 12.5,
        ];

        self::assertSame($context, (new Redactor())->redact($context));
    }

    /**
     * Keys come back byte for byte, in order, misspellings and all.
     *
     * CONVENTIONS.md §4.8: `CardBindingFileds`, `IsAvtive`, `resposneCode`,
     * `rrn`, `PaymentId` and `OrderId` are the wire format. A redactor that
     * "normalised" a key on the way out would corrupt a log into something
     * that no longer matches what was sent.
     */
    public function testWireSpellingsAreNeverCorrected(): void
    {
        $context = [
            'CardBindingFileds' => [],
            'IsAvtive' => true,
            'resposneCode' => '00',
            'rrn' => '123456789012',
            'PaymentId' => 'A1B2',
            'OrderId' => 4242,
        ];

        self::assertSame(
            ['CardBindingFileds', 'IsAvtive', 'resposneCode', 'rrn', 'PaymentId', 'OrderId'],
            array_keys((new Redactor())->redact($context)),
        );
    }

    /**
     * A decoded body arrives as a nested array (the transport decodes with
     * assoc: true), so the sensitive keys are nested keys. A top-level-only
     * redactor would publish every one of them.
     */
    public function testNestedStructuresAreRedactedAtDepth(): void
    {
        $redacted = (new Redactor())->redact([
            'response' => [
                'CardBindingFileds' => [
                    ['CardHolderID' => 'holder-1', 'CardPan' => self::MASKED_PAN, 'ExpDate' => '2512'],
                ],
            ],
        ]);

        $bindings = $this->arrayAt($this->arrayAt($redacted, 'response'), 'CardBindingFileds');
        $first = $this->arrayAt($bindings, 0);

        self::assertSame(self::MARKER, $first['CardHolderID']);
        self::assertSame('400000******0002', $first['CardPan']);
        self::assertSame(self::MARKER, $first['ExpDate']);
    }

    /**
     * A list is walked through its integer keys. An integer key is never
     * sensitive on its own account, but what it holds may be.
     */
    public function testListsAreWalkedThroughIntegerKeys(): void
    {
        $redacted = (new Redactor())->redact([['Password' => self::PW_CANARY]]);

        $first = $this->arrayAt($redacted, 0);

        self::assertSame(self::MARKER, $first['Password']);
    }

    /**
     * A sensitive key holding an array is replaced whole, not walked. Its
     * contents are the secret.
     */
    public function testASensitiveKeyHoldingAnArrayIsReplacedWholesale(): void
    {
        $redacted = (new Redactor())->redact([
            'Password' => ['current' => self::PW_CANARY, 'previous' => 'old-pw'],
        ]);

        self::assertSame(self::MARKER, $redacted['Password']);
    }

    /**
     * The depth cap. At the cap the walk still works; one level deeper the whole
     * sub-array becomes the marker rather than being explored.
     *
     * The cap is read from the class instead of transcribed, so this pins the
     * boundary behaviour rather than a particular number.
     */
    public function testAnArrayAtTheDepthCapIsWalkedAndOneBeyondItIsReplaced(): void
    {
        $cap = (new ReflectionClassConstant(Redactor::class, 'MAX_DEPTH'))->getValue();

        self::assertIsInt($cap);

        $redactor = new Redactor();

        $atCap = $redactor->redact($this->wrap(['CardNumber' => self::TEST_PAN], $cap));
        $leaf = $this->descend($atCap, $cap);

        self::assertIsArray($leaf);
        self::assertSame('411111******1111', $leaf['CardNumber']);

        $beyond = $redactor->redact($this->wrap(['CardNumber' => self::TEST_PAN], $cap + 1));

        self::assertSame(self::MARKER, $this->descend($beyond, $cap + 1));
    }

    /**
     * A raw value shorter than the shortest card number keeps nothing.
     *
     * First-six/last-four on twelve *raw* characters preserves ten of them,
     * which is not a mask — nothing has published those digits yet. Anything not
     * shaped like a PAN — too short, too long, carrying separators, empty, or
     * not a string at all — takes the full marker.
     *
     * The twelve-digit row is the raw floor and it did not move when the masked
     * floor was lowered to eleven. A value that already carries the gateway's
     * mask character is the other case entirely, and it is held below.
     *
     * @param mixed $value the value stored under the card key
     */
    #[DataProvider('valuesThatAreNotPanShaped')]
    public function testAValueThatIsNotShapedLikeAPanIsRedactedInFull(mixed $value): void
    {
        $redacted = (new Redactor())->redact(['CardNumber' => $value]);

        self::assertSame(self::MARKER, $redacted['CardNumber']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function valuesThatAreNotPanShaped(): array
    {
        return [
            'twelve digits' => ['411111111111'],
            'twenty digits' => ['41111111111111111111'],
            'separated' => ['4111-1111-1111-1111'],
            'letters' => ['not-a-card-number'],
            'empty' => [''],
            'integer' => [4111111111111111],
            'null' => [null],
            'array' => [['4111111111111111']],
            'object' => [new stdClass()],
        ];
    }

    /**
     * Both ends of the accepted raw length, and an already-masked value.
     *
     * Thirteen and nineteen are the shortest and longest card numbers ISO 7812
     * allows. The masked row matters because re-masking must be idempotent
     * rather than destructive.
     */
    public function testEveryPanShapedValueKeepsFirstSixAndLastFour(): void
    {
        $redacted = (new Redactor())->redact([
            'CardNumber' => '4111111111111',
            'CardPan' => self::MASKED_PAN,
            'card number' => '4111111111111111111',
        ]);

        self::assertSame('411111***1111', $redacted['CardNumber']);
        self::assertSame('400000******0002', $redacted['CardPan']);
        self::assertSame('411111*********1111', $redacted['card number']);
    }

    /**
     * The exact form the gateway returns comes back byte for byte.
     *
     * `GetPaymentDetails` returns `CardNumber` as first-six, two mask
     * characters, last-four — twelve characters in all, as probe case P3
     * observed. Twelve is below PAN_MIN_LENGTH, so until the masked floor was
     * separated from the raw one this value took the full marker, and
     * CONVENTIONS.md §6's first-six/last-four promise had never once fired on
     * real gateway data.
     *
     * The assertion is identity, not merely that something changed. That is the
     * literal reading of Redactor's MASK_CHARACTER docblock — a re-masked value
     * is indistinguishable from one that arrived masked — and it only became
     * testable when a gateway-masked value started being masked at all.
     */
    public function testTheGatewaysOwnMaskedCardNumberSurvivesByteIdentical(): void
    {
        $redacted = (new Redactor())->redact(['CardNumber' => self::GATEWAY_MASKED_PAN]);

        self::assertSame(self::GATEWAY_MASKED_PAN, $redacted['CardNumber']);
    }

    /**
     * Each floor held from both sides, and the two floors held apart.
     *
     * A value carrying the gateway's mask character needs only enough length for
     * the transformation to be well-formed — a prefix, one masked character, a
     * suffix, so eleven — because the gateway has already published every
     * character first-six/last-four would preserve. Ten takes the marker. A value
     * of digits alone keeps the thirteen-character floor, because there the
     * ten-of-twelve arithmetic genuinely applies.
     *
     * The twelve-character rows are the pair that matters: the same length is
     * masked when it arrived masked and marked in full when it arrived raw. Only
     * the masked one is a captured value; the eleven- and ten-character rows are
     * constructed from it to sit either side of the floor.
     *
     * The last three rows hold what the floors do not. A masked value has a
     * ceiling as well as a floor — PAN_MAX_LENGTH, shared with the raw pattern —
     * and it has a character class: nineteen characters survive, twenty take the
     * marker, and thirteen take it too when one of them is a separator. All three
     * live in MASKED_PAN_PATTERN, which no mutation operator rewrites, so an
     * upper bound or a character class lost in a refactor would leave a green
     * suite and a redactor that publishes the first six characters of arbitrary
     * text stored under a card key.
     *
     * @param string $value    the value stored under the card key
     * @param string $expected what redact() must return for it
     */
    #[DataProvider('valuesEitherSideOfEachFloor')]
    public function testEachOfTheTwoFloorsIsHeldFromBothSides(string $value, string $expected): void
    {
        $redacted = (new Redactor())->redact(['CardNumber' => $value]);

        self::assertSame($expected, $redacted['CardNumber']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesEitherSideOfEachFloor(): array
    {
        return [
            'masked, ten, under the masked floor' => ['40830*1818', self::MARKER],
            'masked, eleven, on the masked floor' => ['408306*1818', '408306*1818'],
            'masked, twelve, the captured form' => [self::GATEWAY_MASKED_PAN, self::GATEWAY_MASKED_PAN],
            'masked, nineteen, on the ceiling' => ['408306*********1818', '408306*********1818'],
            'masked, twenty, over the ceiling' => ['408306**********1818', self::MARKER],
            'masked, thirteen, but separated' => ['4083-06**1818', self::MARKER],
            'raw, twelve, under the raw floor' => ['411111111111', self::MARKER],
            'raw, thirteen, on the raw floor' => ['4111111111111', '411111***1111'],

            // The masked floor is justified by the gateway having masked the
            // middle itself, so what first-six/last-four preserves is what it
            // already published. These three carry a mask character without the
            // mask being the middle, so that argument does not cover them: under
            // a bare character class each qualified on length alone and gave up
            // nine of its ten digits. Each took the full marker before the floor
            // was split in two and must go on taking it.
            'mask trailing, not the middle' => ['1234567890*', self::MARKER],
            'mask leading, not the middle' => ['*1234567890', self::MARKER],
            'mask one short of the suffix' => ['123456789*1', self::MARKER],
        ];
    }

    /**
     * The masked floor is a consequence of the shape, not a number beside it.
     *
     * There is no masked-floor constant to check. MASKED_PAN_PATTERN spells the
     * gateway's arrangement out — a prefix of digits, a run of mask characters,
     * a suffix of digits — so the shortest value it admits is a prefix, one mask
     * and a suffix, and the floor is enforced by construction rather than
     * asserted alongside. An earlier revision carried the floor as its own
     * derived constant; the shape subsumed it, and PHPStan reported it unused,
     * which is the tidier proof that nothing needed it.
     *
     * So this executes the shape rather than reading it. Both values are built
     * from PAN_PREFIX, PAN_SUFFIX and MASK_CHARACTER, so changing any of the
     * three moves what this test sends and the pattern has to move with it. The
     * rows in the provider above pin today's boundaries as literals; this says
     * what those numbers are *for*.
     *
     * The mask character is read from the class for the same reason. Written as
     * a literal `*`, this test would keep passing on a value the redactor no
     * longer recognises as already-masked.
     */
    public function testTheMaskedFloorIsTheShortestWellFormedMaskRatherThanAChosenNumber(): void
    {
        $prefix = $this->intConstant('PAN_PREFIX');
        $suffix = $this->intConstant('PAN_SUFFIX');
        $mask = $this->stringConstant('MASK_CHARACTER');

        $redactor = new Redactor();

        $onTheFloor = str_repeat('4', $prefix) . $mask . str_repeat('1', $suffix);
        $belowIt = str_repeat('4', $prefix - 1) . $mask . str_repeat('1', $suffix);

        self::assertSame(
            $onTheFloor,
            $redactor->redact(['CardNumber' => $onTheFloor])['CardNumber'],
            'A prefix, one masked character and a suffix is the shortest well-formed mask and must survive.',
        );

        self::assertSame(
            self::MARKER,
            $redactor->redact(['CardNumber' => $belowIt])['CardNumber'],
            'One character below the masked floor must take the full marker.',
        );
    }

    /**
     * A private int constant of Redactor.
     */
    private function intConstant(string $name): int
    {
        $value = (new ReflectionClassConstant(Redactor::class, $name))->getValue();

        self::assertIsInt($value);

        return $value;
    }

    /**
     * A private string constant of Redactor.
     */
    private function stringConstant(string $name): string
    {
        $value = (new ReflectionClassConstant(Redactor::class, $name))->getValue();

        self::assertIsString($value);

        return $value;
    }

    /**
     * A key is recognised however the surrounding code spelled it. A context key
     * is not necessarily a wire key: a framework, a bridge package or a
     * hand-written debugging line may have lowercased it or split the words.
     *
     * @param string $key the spelling under test
     */
    #[DataProvider('spellingsOfASensitiveKey')]
    public function testASensitiveKeyIsRecognisedInAnySpelling(string $key): void
    {
        $redacted = (new Redactor())->redact([$key => self::PW_CANARY]);

        self::assertSame(self::MARKER, $redacted[$key]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellingsOfASensitiveKey(): array
    {
        return [
            'wire' => ['Password'],
            'lowercase' => ['password'],
            'uppercase' => ['PASSWORD'],
            'snake case' => ['pass_word'],
            'kebab case' => ['pass-word'],
            'spaced' => ['Pass Word'],
            'punctuated' => ['pass.word!'],
            'prefixed' => ['request.Password'],
            'glued card number' => ['cardnumber'],
        ];
    }

    /**
     * The cardholder-name rule reaches the spelling it is named after.
     *
     * Its first group carries `cardholder` beside `client` for exactly this: the
     * manifest only ever spells the field `ClientName`, so a manifest-derived
     * guard cannot see these keys at all. They are what a bridge package or a
     * hand-written log line writes, and the rule would otherwise have missed the
     * phrase in its own title.
     *
     * @param string $key the spelling under test
     */
    #[DataProvider('spellingsOfACardholderName')]
    public function testTheCardholderNameRuleReachesTheSpellingItIsNamedAfter(string $key): void
    {
        $redacted = (new Redactor())->redact([$key => self::PW_CANARY]);

        self::assertSame(
            self::MARKER,
            $redacted[$key],
            'A key naming the cardholder reached a log in the clear.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellingsOfACardholderName(): array
    {
        return [
            'wire' => ['ClientName'],
            'the name the rule carries' => ['CardholderName'],
            'snake case' => ['cardholder_name'],
            'kebab case' => ['card-holder-name'],
            'spaced' => ['Card Holder Name'],
        ];
    }

    /**
     * The conceded synonym gap, pinned open on purpose.
     *
     * `Redactor`'s class docblock concedes that the cardholder's address reaches
     * a log in the clear under every synonym of `ProcessingIP`, because the rule
     * covering it is conjunctive on `processing` — and it is conjunctive because
     * a bare `ip` stem is a substring of `Description` and `TrxnDescription`,
     * the field carrying the merchant's own submitted text. That rules out a
     * shorter stem, not a further one: `addr` and `clientip` match no manifest
     * field, so this gap is closable under the existing mechanism and is left
     * open by choice, not by obstacle.
     *
     * This asserts the gap rather than the fix, which is deliberate. A concession
     * written only in prose drifts out of date silently; this one cannot. Whoever
     * closes the gap turns this test red, and the failure message sends them back
     * to the paragraph that has to be rewritten with it.
     *
     * None of these is a manifest field, so
     * tests/Http/RedactorKeySetTest.php's exhaustive pin is unaffected either
     * way.
     *
     * @param string $key the unreached spelling
     */
    #[DataProvider('unreachedAddressSpellings')]
    public function testTheConcededAddressSynonymGapIsStillOpen(string $key): void
    {
        $redacted = (new Redactor())->redact([$key => self::PW_CANARY]);

        self::assertSame(
            self::PW_CANARY,
            $redacted[$key],
            'The conceded synonym gap has been closed. That is an improvement, not a '
            . 'failure — now rewrite the concession in Redactor\'s class docblock, which '
            . 'still names this key as unreached, and delete this test.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreachedAddressSpellings(): array
    {
        return [
            'client ip' => ['client_ip'],
            'camel case address' => ['ipAddress'],
            'remote address' => ['remote_addr'],
        ];
    }

    /**
     * Scalars and null under an ordinary key are handed back as they came,
     * types included. A logger that received an integer status as a string would
     * have been given a different record from the one the transport wrote.
     */
    public function testScalarsAndNullPassThroughUnchanged(): void
    {
        $context = ['a' => 'text', 'b' => 7, 'c' => 1.5, 'd' => true, 'e' => false, 'f' => null];

        self::assertSame($context, (new Redactor())->redact($context));
    }

    /**
     * An object becomes its class name and nothing else.
     *
     * Any object may hold a PAN in a property or render one from __toString(),
     * and this class cannot tell. The name is the half of the value that is
     * diagnostic and carries none of the state.
     */
    public function testAnObjectIsReplacedByItsClassName(): void
    {
        $redacted = (new Redactor())->redact(['thing' => new stdClass()]);

        self::assertSame('[object stdClass]', $redacted['thing']);
    }

    /**
     * A resource has no rendering this class can vouch for, so it takes the
     * marker. This is the branch that catches every type not enumerated above.
     */
    public function testAResourceIsReplacedByTheMarker(): void
    {
        $handle = fopen('php://memory', 'rb');

        self::assertNotFalse($handle);

        try {
            $redacted = (new Redactor())->redact(['stream' => $handle]);

            self::assertSame(self::MARKER, $redacted['stream']);
        } finally {
            fclose($handle);
        }
    }

    /**
     * An empty context is an empty context.
     */
    public function testAnEmptyContextComesBackEmpty(): void
    {
        self::assertSame([], (new Redactor())->redact([]));
    }

    /**
     * $value wrapped in $times single-entry arrays.
     *
     * @return array<array-key, mixed>
     */
    private function wrap(mixed $value, int $times): array
    {
        for ($i = 0; $i < $times; $i++) {
            $value = ['level' => $value];
        }

        self::assertIsArray($value);

        return $value;
    }

    /**
     * Undoes $times wrappings.
     *
     * @param array<array-key, mixed> $data
     */
    private function descend(array $data, int $times): mixed
    {
        $value = $data;

        for ($i = 0; $i < $times; $i++) {
            self::assertIsArray($value);

            $value = $value['level'] ?? null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $data, int|string $key): array
    {
        $value = $data[$key] ?? null;

        self::assertIsArray($value);

        return $value;
    }
}
