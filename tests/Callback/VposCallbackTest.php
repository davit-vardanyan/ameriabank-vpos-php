<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Callback;

use function array_diff_key;
use function array_keys;
use function array_merge;
use function array_values;
use function count;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

use function http_build_query;
use function is_string;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sort;
use function sprintf;
use function str_starts_with;

/**
 * What a BackURL callback parses into, and — mostly — what it refuses to parse.
 *
 * The gateway signs nothing (CONVENTIONS.md §4.10), so every assertion here is
 * about a value that an attacker may have typed. That shapes the tests in two
 * ways that are not the usual DTO-parsing shape:
 *
 * 1. **Refusals are asserted one condition at a time.** A single test that
 *    threw for "a bad callback" would pass while three of the four bad shapes
 *    silently parsed, and a callback that parses is a callback a merchant will
 *    branch on. Blank and absent, `paymentID` and `orderID`, are four separate
 *    cases and four separate assertions.
 * 2. **Nothing is repaired.** No trimming on the way out, no case folding on
 *    the way in, no empty-string-to-null normalisation. Each of those is
 *    asserted as a property, because each one is a change somebody would make
 *    as a tidy-up and each one would hide a real upstream change.
 *
 * The five key spellings are the load-bearing detail. `resposneCode` is the
 * gateway's own misspelling and therefore the wire format (CONVENTIONS.md
 * §4.8); `responseCode` is what a contributor "fixing" it would write, and
 * this file asserts that the corrected spelling reads as absent rather than as
 * a value.
 *
 * No credential appears below. The three placeholders used elsewhere in the
 * suite are not needed here at all — parsing a query string requires none.
 */
#[CoversClass(VposCallback::class)]
#[UsesClass(ValidationException::class)]
final class VposCallbackTest extends TestCase
{
    /**
     * The five parameters observed on a real BackURL redirect, at the exact
     * spellings the gateway sent them.
     *
     * **Hand-maintained, and it has to be.** A subject list is normally
     * derived from its source of truth, and a written one is right only where
     * that source cannot name the subject; this is that case.
     * `api-surface.json` — the specification of record under CONVENTIONS.md §2
     * — describes the REST *request models*, and the BackURL is not a REST
     * call: it is a browser redirect with no model to describe, so the
     * manifest does not carry these names and could not. They come from §4.8
     * and §4.10, which record them as observed on the wire, and `resposneCode`
     * in particular is hex-confirmed there.
     *
     * What *is* derived is the check that this list is complete:
     * testTheClassPinsExactlyTheFiveObservedWireKeys() reads the KEY_ constants
     * off the class and asserts they are exactly these five, so a sixth key
     * added to the source — or a spelling changed in it — fails the build
     * rather than going unasserted.
     *
     * The five values are deliberately unlike each other. If `opaque` echoed
     * the order identifier, as the real observed callback's did
     * (`opaque=OPAQUE-4565028`), a reader transposed with a writer would still
     * pass.
     *
     * **What is observed here is the five keys, not these five values.** Two of
     * the values are unlike anything the gateway has sent. The `paymentID` is
     * written uppercase and a real callback's is lowercase — probe case P2
     * echoed in lowercase the uppercase GUID probe case P1 had returned — and
     * the `description` is error text where P2's was `Operation Approved `,
     * trailing space included. Neither matters to what this class does, since it
     * pins keys and passes values through untouched, and that is exactly why the
     * fixture may stay as it is; a reader must not take it for a capture.
     * `VerifiedCallbackTest` holds the case behaviour.
     *
     * @var array<string, string>
     */
    private const array WIRE = [
        'paymentID' => 'C2E51643-0922-4442-A80C-30ADAE03BECC',
        'orderID' => '4565028',
        'opaque' => 'opaque-value-A',
        'resposneCode' => '0999',
        'description' => 'Internal server error',
    ];

    /**
     * The prefix every wire-key constant in the class carries.
     */
    private const string KEY_CONSTANT_PREFIX = 'KEY_';

    /**
     * Values a query parameter can hold that are not strings.
     *
     * `?paymentID[]=x` makes PHP's own query parser produce an array, so the
     * first two are not hypothetical. The scalars are: a PSR-7 implementation
     * is free to hand back whatever it parsed, and `0`, `false` and `[]` are
     * each *loosely* equal to null, which is exactly the class of value that
     * would slip through if the null test in the source were `==` rather than
     * `===`. Asserting the malformed diagnosis for them — rather than the blank
     * one — is what tells those two spellings apart.
     *
     * @return array<string, array{mixed}>
     */
    public static function nonStringValues(): array
    {
        return [
            'a nested array, as `?paymentID[]=x` produces' => [['x']],
            'an empty array, loosely equal to null' => [[]],
            'the integer zero, loosely equal to null' => [0],
            'false, loosely equal to null' => [false],
            'true' => [true],
            'a float' => [1.5],
        ];
    }

    /**
     * Every key, so both readers are exercised over both kinds of parameter.
     *
     * Derived from the wire list above rather than written out again.
     *
     * @return array<string, array{string}>
     */
    public static function everyWireKey(): array
    {
        $cases = [];

        foreach (array_keys(self::WIRE) as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    /**
     * The two identifiers a callback cannot be read without.
     *
     * @return array<string, array{string}>
     */
    public static function requiredKeys(): array
    {
        return ['paymentID' => ['paymentID'], 'orderID' => ['orderID']];
    }

    /**
     * Case variants of the two required keys, one case per variant.
     *
     * Written out rather than generated, so each spelling that must not be
     * accepted is visible in the failure name. `paymentId` is the idiomatic-PHP
     * spelling a contributor would reach for, `PaymentID` is the spelling every
     * *response* model in this package uses (§4.8), and the fully-upper and
     * fully-lower forms are what a case-insensitive lookup would let through.
     *
     * @return array<string, array{string, string}>
     */
    public static function caseVariants(): array
    {
        $variants = [];

        foreach (['paymentID' => ['paymentId', 'PaymentID', 'PAYMENTID', 'paymentid'],
            'orderID' => ['orderId', 'OrderID', 'ORDERID', 'orderid']] as $canonical => $spellings) {
            foreach ($spellings as $spelling) {
                $variants[sprintf('%s as %s', $canonical, $spelling)] = [$canonical, $spelling];
            }
        }

        return $variants;
    }

    /**
     * Each of the five parameters is read at its own spelling, and lands on the
     * accessor that belongs to it.
     *
     * Five separate assertions rather than one object comparison: an object
     * comparison against an expected instance would be built by the same
     * constructor and would agree with itself if two fields were swapped.
     */
    public function testEveryObservedWireKeyIsReadAtItsExactSpelling(): void
    {
        $callback = VposCallback::fromQuery(self::WIRE);

        self::assertSame(self::WIRE['paymentID'], $callback->paymentId());
        self::assertSame(self::WIRE['orderID'], $callback->orderId());
        self::assertSame(self::WIRE['opaque'], $callback->opaque());
        self::assertSame(
            ['resposneCode' => self::WIRE['resposneCode'], 'description' => self::WIRE['description']],
            $callback->untrustedDiagnostics(),
            'The diagnostics array must be keyed by the wire spellings, `resposneCode` included (CONVENTIONS.md §4.8).',
        );
    }

    /**
     * The class pins exactly these five spellings, and no others.
     *
     * This is what keeps the hand-maintained list above honest. The subject
     * list is read off the class with reflection, so a sixth `KEY_` constant,
     * a renamed one, or a "corrected" `resposneCode` fails here even though no
     * test above mentions it — where a purely hand-written expectation would
     * silently exempt whatever it did not know about.
     */
    public function testTheClassPinsExactlyTheFiveObservedWireKeys(): void
    {
        $pinned = [];

        foreach ((new ReflectionClass(VposCallback::class))->getConstants() as $name => $value) {
            if (!str_starts_with($name, self::KEY_CONSTANT_PREFIX)) {
                continue;
            }

            self::assertTrue(is_string($value), sprintf('%s is not a string.', $name));

            $pinned[] = $value;
        }

        $expected = array_keys(self::WIRE);
        sort($pinned);
        sort($expected);

        self::assertSame(
            $expected,
            $pinned,
            'The class pins a different set of query keys than the five observed on the wire '
            . '(CONVENTIONS.md §4.8, §4.10). A key added, removed or respelled in the source must be '
            . 'classified here, not absorbed silently.',
        );
    }

    /**
     * The corrected spelling of the gateway's typo reads as absent.
     *
     * `responseCode` is not a synonym for `resposneCode` here, and this is the
     * assertion that says so. It is also the one that makes the trade
     * explicit: if the gateway ever fixes its own misspelling, this package
     * reports no diagnostic rather than quietly reading the new one, and a
     * merchant finds out from a null instead of from a wrong branch.
     */
    public function testTheCorrectedSpellingOfTheTypoIsNotAccepted(): void
    {
        $callback = VposCallback::fromQuery([
            'paymentID' => self::WIRE['paymentID'],
            'orderID' => self::WIRE['orderID'],
            'responseCode' => '00',
            'Description' => 'Approved',
        ]);

        self::assertSame(
            ['resposneCode' => null, 'description' => null],
            $callback->untrustedDiagnostics(),
            'A "corrected" responseCode, or a capitalised Description, must read as absent rather than as a value.',
        );
    }

    /**
     * A case variant of a required key does not satisfy it.
     *
     * The callback is rejected outright, because the canonical key is then
     * missing — the variant is not a second name for it. Asserted per variant,
     * so the failure names the spelling that was let through.
     */
    #[DataProvider('caseVariants')]
    public function testACaseVariantOfARequiredKeyIsRejected(string $canonical, string $variant): void
    {
        $query = self::WIRE;
        unset($query[$canonical]);
        $query[$variant] = 'A-VALUE-THAT-MUST-NOT-BE-READ';

        try {
            VposCallback::fromQuery($query);
            self::fail(sprintf('%s satisfied the required key %s.', $variant, $canonical));
        } catch (ValidationException $thrown) {
            self::assertSame(sprintf('Field "%s" must not be blank.', $canonical), $thrown->getMessage());
            self::assertStringNotContainsString('A-VALUE-THAT-MUST-NOT-BE-READ', $thrown->getMessage());
        }
    }

    /**
     * An absent required identifier is rejected, per identifier.
     */
    #[DataProvider('requiredKeys')]
    public function testAnAbsentRequiredIdentifierIsRejected(string $key): void
    {
        $query = self::WIRE;
        unset($query[$key]);

        try {
            VposCallback::fromQuery($query);
            self::fail(sprintf('A callback with no %s was accepted.', $key));
        } catch (ValidationException $thrown) {
            self::assertSame(sprintf('Field "%s" must not be blank.', $key), $thrown->getMessage());
        }
    }

    /**
     * An empty required identifier is rejected, per identifier.
     */
    #[DataProvider('requiredKeys')]
    public function testAnEmptyRequiredIdentifierIsRejected(string $key): void
    {
        try {
            VposCallback::fromQuery(array_merge(self::WIRE, [$key => '']));
            self::fail(sprintf('A callback with an empty %s was accepted.', $key));
        } catch (ValidationException $thrown) {
            self::assertSame(sprintf('Field "%s" must not be blank.', $key), $thrown->getMessage());
        }
    }

    /**
     * A whitespace-only required identifier is rejected, per identifier.
     *
     * Separate from the empty case on purpose. `?paymentID=%20` is what a
     * proxy, a copy-paste or an attacker produces, and a blankness test written
     * as `$value === ''` would accept it — leaving a callback that carries an
     * identifier consisting of a space, which the gateway will not recognise
     * and which no merchant will be able to look up.
     */
    #[DataProvider('requiredKeys')]
    public function testAWhitespaceOnlyRequiredIdentifierIsRejected(string $key): void
    {
        try {
            VposCallback::fromQuery(array_merge(self::WIRE, [$key => "  \t\n "]));
            self::fail(sprintf('A callback whose %s was only whitespace was accepted.', $key));
        } catch (ValidationException $thrown) {
            self::assertSame(sprintf('Field "%s" must not be blank.', $key), $thrown->getMessage());
        }
    }

    /**
     * A non-string value is refused as malformed, on every one of the five
     * parameters — required and optional alike.
     *
     * The diagnosis matters as much as the refusal. "Blank" would be a false
     * description of an array, and the difference is not cosmetic: a merchant
     * reading "must not be blank" looks for a missing parameter, while
     * "malformed: expected a single string query parameter" points at
     * `?paymentID[]=`, which is a different bug in a different place.
     *
     * It also pins the optional parameters as strict. An optional parameter is
     * optional in whether it appears, not in what shape it takes.
     */
    #[DataProvider('everyWireKey')]
    public function testANonStringValueIsRefusedAsMalformedOnEveryParameter(string $key): void
    {
        foreach (self::nonStringValues() as $label => [$value]) {
            try {
                VposCallback::fromQuery(array_merge(self::WIRE, [$key => $value]));
                self::fail(sprintf('%s carrying %s was accepted.', $key, $label));
            } catch (ValidationException $thrown) {
                self::assertSame(
                    sprintf('Field "%s" is malformed: expected a single string query parameter.', $key),
                    $thrown->getMessage(),
                    sprintf('%s carrying %s was misdiagnosed.', $key, $label),
                );
            }
        }
    }

    /**
     * An absent optional parameter reads as null.
     *
     * The minimal callback — two identifiers and nothing else — is also the
     * shape that proves the optional readers do not require their keys.
     */
    public function testAnAbsentOptionalParameterReadsAsNull(): void
    {
        $callback = VposCallback::fromQuery([
            'paymentID' => self::WIRE['paymentID'],
            'orderID' => self::WIRE['orderID'],
        ]);

        self::assertNull($callback->opaque());
        self::assertSame(['resposneCode' => null, 'description' => null], $callback->untrustedDiagnostics());
    }

    /**
     * An *empty* optional parameter is not normalised into an absent one.
     *
     * `description=` and no `description` at all are two different events, and
     * the empty string is the only evidence available about which happened. A
     * class that collapsed them would throw away the distinction — and, since
     * `description` is the gateway's error text, it would throw it away exactly
     * when something has gone wrong.
     */
    public function testAnEmptyOptionalParameterIsNotNormalisedToNull(): void
    {
        $callback = VposCallback::fromQuery(array_merge(self::WIRE, [
            'opaque' => '',
            'resposneCode' => '',
            'description' => '',
        ]));

        self::assertSame('', $callback->opaque());
        self::assertSame(['resposneCode' => '', 'description' => ''], $callback->untrustedDiagnostics());
    }

    /**
     * Identifiers come back exactly as they arrived, whitespace included.
     *
     * Blankness is the only thing this class checks, so trimming on the way out
     * would be it repairing a value it has just declared it does not validate —
     * and a repaired identifier that then fails at the gateway is harder to
     * diagnose than the one that was actually sent.
     */
    public function testIdentifiersAreReturnedVerbatim(): void
    {
        $callback = VposCallback::fromQuery(array_merge(self::WIRE, [
            'paymentID' => ' ' . self::WIRE['paymentID'] . ' ',
            'orderID' => "\t" . self::WIRE['orderID'],
        ]));

        self::assertSame(' ' . self::WIRE['paymentID'] . ' ', $callback->paymentId());
        self::assertSame("\t" . self::WIRE['orderID'], $callback->orderId());
    }

    /**
     * fromServerRequest() and fromQuery() agree, parameter for parameter.
     *
     * Both constructors have to stay one parsing rule. The risk is not that the
     * PSR-7 path is wrong today — it delegates — but that it stops delegating
     * later and grows a second copy of the five spellings, which is the copy
     * that would be "corrected".
     */
    public function testFromServerRequestAgreesWithFromQuery(): void
    {
        $request = new ServerRequest('GET', 'https://merchant.example/vpos/callback');
        $fromRequest = VposCallback::fromServerRequest($request->withQueryParams(self::WIRE));
        $fromQuery = VposCallback::fromQuery(self::WIRE);

        self::assertSame($fromQuery->paymentId(), $fromRequest->paymentId());
        self::assertSame($fromQuery->orderId(), $fromRequest->orderId());
        self::assertSame($fromQuery->opaque(), $fromRequest->opaque());
        self::assertSame($fromQuery->untrustedDiagnostics(), $fromRequest->untrustedDiagnostics());
        self::assertEquals($fromQuery, $fromRequest);
    }

    /**
     * The same thing again, from a redirect URL rather than from an array.
     *
     * This is the shape a merchant's framework actually hands over: the five
     * parameters percent-encoded in a query string, parsed by somebody else's
     * code. `description=Internal+server+error` is the observed value from
     * CONVENTIONS.md §4.10's own example redirect, and `+` decoding to a space
     * on the way through is part of what is being asserted — the array-based
     * tests above cannot see that step at all.
     */
    public function testAServerRequestCarryingTheObservedRedirectUrlParses(): void
    {
        $callback = VposCallback::fromServerRequest(new ServerRequest(
            'GET',
            'https://merchant.example/vpos/callback?' . http_build_query(self::WIRE),
        ));

        self::assertSame(self::WIRE['paymentID'], $callback->paymentId());
        self::assertSame(self::WIRE['orderID'], $callback->orderId());
        self::assertSame(self::WIRE['opaque'], $callback->opaque());
        self::assertSame('Internal server error', $callback->untrustedDiagnostics()['description']);
    }

    /**
     * A server request missing an identifier is refused just as an array is.
     *
     * Without this the PSR-7 constructor could grow its own lenient path and no
     * test above would notice, since every refusal case is expressed as an
     * array.
     */
    public function testAServerRequestMissingAnIdentifierIsRefused(): void
    {
        $query = array_diff_key(self::WIRE, ['paymentID' => null]);

        self::assertCount(4, $query);

        try {
            VposCallback::fromServerRequest(
                (new ServerRequest('GET', 'https://merchant.example/vpos/callback'))->withQueryParams($query),
            );
            self::fail('A server request with no paymentID was accepted.');
        } catch (ValidationException $thrown) {
            self::assertSame('Field "paymentID" must not be blank.', $thrown->getMessage());
        }
    }

    /**
     * The provider above really does carry the shapes it claims to.
     *
     * A provider that silently lost its cases would make the malformed-value
     * test vacuous — the loop would run zero times and the test would pass.
     */
    public function testTheNonStringProviderIsNotEmpty(): void
    {
        self::assertGreaterThanOrEqual(6, count(self::nonStringValues()));
        self::assertCount(5, array_values(self::everyWireKey()));
    }
}
