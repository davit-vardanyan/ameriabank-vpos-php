<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Request;

use Closure;
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\ActivateBindingRequest;
use DavitVardanyan\AmeriabankVpos\Request\CancelPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\ConfirmPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\DeactivateBindingRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetBindingsRequest;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\MakeBindingPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\PaymentDetailsRequest;
use DavitVardanyan\AmeriabankVpos\Request\RefundPaymentRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function str_contains;

/**
 * A request validates on construction, so an invalid one cannot exist to be
 * sent.
 *
 * All three rules exist because the gateway does not enforce them and its
 * failure modes are worse than an exception:
 *
 * - Timeout is not validated server-side at all. CONVENTIONS.md §4.12 records
 *   1201, 0 and −1 all being accepted, which means a caller who sends 0 gets
 *   whatever the gateway privately decides that means, silently.
 * - A PaymentType outside {5, 6} on a binding operation returns an HTTP 500
 *   carrying ASP.NET's unhandled-exception page (§4.2), which is not a
 *   structured error, cannot be retried, and is indistinguishable from a
 *   gateway fault.
 * - A blank required field produces no error anyone has observed. Unknown and
 *   empty fields are ignored silently (§4.12), so a blank PaymentID is a
 *   request that quietly asks about nothing.
 *
 * Every assertion here pins the message as well as the type. The type alone is
 * satisfied by any ValidationException, so a constructor that threw
 * blankValue('BackURL') when its PaymentType was wrong would pass a
 * type-only test while telling the caller to fix the wrong field.
 *
 * The whitespace-only rows are not decoration. `trim($value) === ''` and
 * `$value === ''` differ on exactly those rows, and a request whose PaymentID
 * is a space is the one a caller builds from a mis-parsed CSV.
 *
 * The membership of trim()'s default charlist was executed on PHP 8.3.28 rather
 * than assumed. It is `" \t\n\r\0\x0B"` — a form feed is not in it, so `"\f"`
 * is a non-blank value and appears in the accepted list below. The first draft
 * of this file had it among the blank rows on the strength of "it looks like
 * whitespace", and nine rows failed.
 */
#[CoversClass(ActivateBindingRequest::class)]
#[CoversClass(CancelPaymentRequest::class)]
#[CoversClass(ConfirmPaymentRequest::class)]
#[CoversClass(DeactivateBindingRequest::class)]
#[CoversClass(GetBindingsRequest::class)]
#[CoversClass(InitPaymentRequest::class)]
#[CoversClass(MakeBindingPaymentRequest::class)]
#[CoversClass(PaymentDetailsRequest::class)]
#[CoversClass(RefundPaymentRequest::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Currency::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(ValidationException::class)]
final class RequestValidationTest extends TestCase
{
    private const string BACK_URL = 'https://merchant.example.test/callback';

    private const string CARD_HOLDER_ID = 'holder-id-fake';

    /**
     * The blank strings a required field is rejected for.
     *
     * `"0"` is deliberately absent from this list and present in the accepted
     * one below. `empty("0")` is true and `trim("0") === ''` is false, and a
     * validator written with the first would reject a legitimate identifier
     * that happens to be the digit zero.
     *
     * @return array<string, array{string}>
     */
    public static function blankStrings(): array
    {
        return [
            'the empty string' => [''],
            'a single space' => [' '],
            'spaces and a tab' => ["  \t "],
            'a newline' => ["\n"],
            'a carriage return and a NUL' => ["\r\0"],
            'a vertical tab' => ["\x0B"],
        ];
    }

    /**
     * Every required text field of every request, with the field name the
     * rejection must carry.
     *
     * A closure per field rather than a table of constructor arguments: the
     * nine constructors take different shapes, and a table would have to encode
     * that shape as data, which is a second description of the same thing and
     * the kind that drifts.
     *
     * The closures return toArray() rather than the request, because a request
     * DTO exposes no accessor for the field it was given — CONVENTIONS.md §5
     * keeps the properties private and the wire array is the surface.
     * Constructing is what throws, so the blank rows never reach the return.
     *
     * @return array<string, array{Closure(string): array<string, int|string>, string}>
     */
    public static function requiredTextFields(): array
    {
        $amount = Amount::fromDecimalString('10.00', Currency::AMD);

        return [
            'InitPayment BackURL' => [
                static fn(string $value): array => (new InitPaymentRequest($amount, 1001, $value))->toArray(),
                'BackURL',
            ],
            'GetPaymentDetails PaymentID' => [
                static fn(string $value): array => (new PaymentDetailsRequest($value))->toArray(),
                'PaymentID',
            ],
            'ConfirmPayment PaymentID' => [
                static fn(string $value): array => (new ConfirmPaymentRequest($value, $amount))->toArray(),
                'PaymentID',
            ],
            'RefundPayment PaymentID' => [
                static fn(string $value): array => (new RefundPaymentRequest($value, $amount))->toArray(),
                'PaymentID',
            ],
            'CancelPayment PaymentID' => [
                static fn(string $value): array => (new CancelPaymentRequest($value))->toArray(),
                'PaymentID',
            ],
            'MakeBindingPayment CardHolderID' => [
                static fn(string $value): array => (new MakeBindingPaymentRequest(
                    $value,
                    $amount,
                    2002,
                    self::BACK_URL,
                    PaymentType::BindingMainRest,
                ))->toArray(),
                'CardHolderID',
            ],
            'MakeBindingPayment BackURL' => [
                static fn(string $value): array => (new MakeBindingPaymentRequest(
                    self::CARD_HOLDER_ID,
                    $amount,
                    2002,
                    $value,
                    PaymentType::BindingMainRest,
                ))->toArray(),
                'BackURL',
            ],
            'ActivateBinding CardHolderID' => [
                static fn(string $value): array => (new ActivateBindingRequest(
                    $value,
                    PaymentType::BindingMainRest,
                ))->toArray(),
                'CardHolderID',
            ],
            'DeactivateBinding CardHolderID' => [
                static fn(string $value): array => (new DeactivateBindingRequest(
                    $value,
                    PaymentType::BindingMainRest,
                ))->toArray(),
                'CardHolderID',
            ],
        ];
    }

    /**
     * Every required field, crossed with every blank string.
     *
     * The cross product is the point. Nine fields tested against only the empty
     * string would be green for nine `=== ''` checks, and the gateway would
     * then receive a PaymentID of one space and answer about nothing.
     *
     * Mutation demonstrated: unwrapping trim() in any constructor — `if
     * ($paymentId === '')` — fails that field's four whitespace rows while
     * leaving its empty-string row green.
     *
     * @return array<string, array{Closure(string): array<string, int|string>, string, string}>
     */
    public static function blankRequiredFields(): array
    {
        $rows = [];

        foreach (self::requiredTextFields() as $label => [$construct, $field]) {
            foreach (self::blankStrings() as $blankLabel => [$blank]) {
                $rows[$label . ' — ' . $blankLabel] = [$construct, $field, $blank];
            }
        }

        return $rows;
    }

    /**
     * @param Closure(string): array<string, int|string> $construct
     */
    #[DataProvider('blankRequiredFields')]
    public function testABlankRequiredFieldIsRejectedNamingThatField(
        Closure $construct,
        string $field,
        string $blank,
    ): void {
        try {
            $construct($blank);
            self::fail(sprintf('A blank %s must not construct.', $field));
        } catch (ValidationException $exception) {
            self::assertSame(
                sprintf('Field "%s" must not be blank.', $field),
                $exception->getMessage(),
                'The rejection names the field that is wrong, and nothing else. A type-only '
                . 'assertion here would pass for a constructor blaming a different field.',
            );
        }
    }

    /**
     * A value that is merely odd is not blank, and constructs.
     *
     * This is the other half of the rule, and without it every row above is
     * satisfied by a constructor that rejects everything. `"0"` is the row that
     * separates trim() from empty(); the padded value proves the check is on
     * blankness rather than on tidiness, since nothing here trims the stored
     * value.
     *
     * @return array<string, array{string}>
     */
    public static function nonBlankStrings(): array
    {
        return [
            'the digit zero, which empty() would reject' => ['0'],
            'a value with surrounding spaces' => ['  padded-payment-id  '],
            'a single character' => ['x'],
            'an Armenian identifier' => ['վճարում-1'],
            'a form feed, which trim() does not strip' => ["\f"],
        ];
    }

    public function testANonBlankValueConstructs(): void
    {
        foreach (self::requiredTextFields() as $label => [$construct, $field]) {
            foreach (self::nonBlankStrings() as $valueLabel => [$value]) {
                self::assertSame(
                    $value,
                    $construct($value)[$field],
                    $label . ' with ' . $valueLabel . ' must construct, and must store the value it '
                    . 'was given untrimmed. Trimming would silently change an identifier the caller '
                    . 'chose.',
                );
            }
        }
    }

    /**
     * The Timeout boundary, all four corners plus the two beyond them.
     *
     * 1 and 1200 are inside and must construct; 0 and 1201 are outside and must
     * not. Testing only 0 and 1201 would be green for `< 0 || > 1201`, and
     * testing only 1 and 1200 would be green for no check at all. The pair of
     * pairs is what pins the interval.
     *
     * −1 is here because the gateway was observed to accept it (CONVENTIONS.md
     * §4.12), which is the clearest possible statement that server-side
     * validation is not going to catch this.
     *
     * @return array<string, array{int, bool}>
     */
    public static function timeouts(): array
    {
        return [
            'the low bound, inside' => [1, true],
            'the high bound, inside' => [1200, true],
            'a middling value' => [600, true],
            'one below the low bound' => [0, false],
            'one above the high bound' => [1201, false],
            'negative, which the gateway accepts' => [-1, false],
            'far above' => [86400, false],
        ];
    }

    /**
     * Mutation demonstrated: widening the guard to `$timeout < 0 || $timeout >
     * 1200` accepts 0 and fails the "one below the low bound" row; narrowing it
     * to `$timeout <= 1 || $timeout >= 1200` rejects both bounds and fails the
     * two inside rows.
     */
    #[DataProvider('timeouts')]
    public function testTimeoutIsAcceptedOnlyWithinOneToTwelveHundred(int $seconds, bool $accepted): void
    {
        $construct = static fn(): InitPaymentRequest => new InitPaymentRequest(
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 1001,
            backUrl: self::BACK_URL,
            timeout: $seconds,
        );

        if ($accepted) {
            self::assertSame($seconds, $construct()->toArray()['Timeout']);

            return;
        }

        try {
            $construct();
            self::fail(sprintf('Timeout %d is outside 1..1200 and must not construct.', $seconds));
        } catch (ValidationException $exception) {
            self::assertSame(
                sprintf(
                    'Timeout must be between 1 and 1200 seconds, got %d. The gateway accepts '
                    . 'out-of-range values silently, so this is enforced here.',
                    $seconds,
                ),
                $exception->getMessage(),
            );
        }
    }

    /**
     * A null Timeout is not out of range; it is a Timeout the caller did not
     * set.
     *
     * The range check must not fire on it, and toArray() must omit the key.
     * Both are asserted, because a guard written as `$timeout < 1` without the
     * null test throws on null under strict types and a guard written as `??
     * 0` would throw the range error for a field nobody supplied.
     *
     * The parameter is left at its default rather than passed as an explicit
     * null. The two are the same value by the time the constructor body runs,
     * and rector's RemoveNullNamedArgOnNullDefaultParamRector removes the
     * explicit form — so writing it would be a line the toolchain deletes on
     * the next run rather than a distinction anything can observe.
     */
    public function testANullTimeoutIsNeitherValidatedNorEmitted(): void
    {
        $fields = (new InitPaymentRequest(
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 1001,
            backUrl: self::BACK_URL,
        ))->toArray();

        self::assertArrayNotHasKey('Timeout', $fields);
    }

    /**
     * The three binding operations, and every PaymentType that is not 5 or 6.
     *
     * The allowed set is {MainRest, BindingMainRest} and it is narrower than
     * "any valid enum member" on purpose — CONVENTIONS.md §4.6 names those two
     * and only those, and §4.2 records that `GetBindings` with a PaymentType
     * outside the pair returns an HTTP 500 rather than a structured refusal.
     * Eleven members are rejected and two accepted, which is a rule about
     * values rather than about validity.
     *
     * `None` is the row worth naming: it is a real member with a real backing
     * value of 0, so a guard written as "is this a member of the enum" admits
     * it. It must not be admitted.
     *
     * @return array<string, array{Closure(PaymentType): object, string, PaymentType}>
     */
    public static function bindingOperationsWithUnsupportedTypes(): array
    {
        $operations = [
            'GetBindings' => static fn(PaymentType $type): object => new GetBindingsRequest($type),
            'ActivateBinding' => static fn(PaymentType $type): object => new ActivateBindingRequest(
                self::CARD_HOLDER_ID,
                $type,
            ),
            'DeactivateBinding' => static fn(PaymentType $type): object => new DeactivateBindingRequest(
                self::CARD_HOLDER_ID,
                $type,
            ),
        ];

        $rows = [];

        foreach ($operations as $operation => $construct) {
            foreach (PaymentType::cases() as $type) {
                if ($type->isBindingCapable()) {
                    continue;
                }

                $rows[$operation . ' with ' . $type->name] = [$construct, $operation, $type];
            }
        }

        return $rows;
    }

    /**
     * Mutation demonstrated: replacing `!$paymentType->isBindingCapable()` with
     * a check that the value is a valid enum member admits every one of these
     * rows, `None` included.
     *
     * @param Closure(PaymentType): object $construct
     */
    #[DataProvider('bindingOperationsWithUnsupportedTypes')]
    public function testABindingOperationRejectsEveryPaymentTypeOutsideTheAllowedPair(
        Closure $construct,
        string $operation,
        PaymentType $type,
    ): void {
        try {
            $construct($type);
            self::fail(sprintf('%s must not accept PaymentType %d.', $operation, $type->value));
        } catch (ValidationException $exception) {
            self::assertSame(
                sprintf(
                    'PaymentType %d is not accepted by %s. Allowed: 5, 6. Other values return an '
                    . 'unparseable HTTP 500 from the gateway.',
                    $type->value,
                    $operation,
                ),
                $exception->getMessage(),
                'The rejection names the operation that refused the value and the values it would '
                . 'have taken, because a caller cannot guess either.',
            );
            self::assertFalse(
                str_contains($exception->getMessage(), $type->name),
                'The member name is this SDK\'s vocabulary, not the wire\'s. The message states the '
                . 'value the gateway would have received.',
            );
        }
    }

    /**
     * Both allowed values construct on all three binding operations.
     *
     * Without this the eleven-row rejection test above is satisfied by a
     * constructor that rejects all thirteen.
     */
    public function testBothAllowedPaymentTypesConstructOnEveryBindingOperation(): void
    {
        foreach ([PaymentType::MainRest, PaymentType::BindingMainRest] as $type) {
            self::assertSame(
                $type->value,
                (new GetBindingsRequest($type))->toArray()['PaymentType'],
            );
            self::assertSame(
                $type->value,
                (new ActivateBindingRequest(self::CARD_HOLDER_ID, $type))->toArray()['PaymentType'],
            );
            self::assertSame(
                $type->value,
                (new DeactivateBindingRequest(self::CARD_HOLDER_ID, $type))->toArray()['PaymentType'],
            );
        }
    }

    /**
     * MakeBindingPayment is not one of the three, and does not apply the
     * restriction.
     *
     * CONVENTIONS.md §4.6 names `GetBindings`, `ActivateBinding` and
     * `DeactivateBinding` and stops there. Extending the guard to a fourth
     * operation would be this SDK inventing a rejection the bank has never
     * issued, and a client-side rejection of a request the gateway would have
     * accepted is not a safe default — it is an outage the caller cannot work
     * around.
     *
     * Pinned so that the asymmetry is a decision on the record rather than an
     * oversight someone later "fixes".
     */
    public function testMakeBindingPaymentDoesNotRestrictItsPaymentType(): void
    {
        $request = new MakeBindingPaymentRequest(
            cardHolderId: self::CARD_HOLDER_ID,
            amount: Amount::fromDecimalString('10.00', Currency::AMD),
            orderId: 2002,
            backUrl: self::BACK_URL,
            paymentType: PaymentType::Visa,
        );

        self::assertSame(3, $request->toArray()['PaymentType']);
    }

    /**
     * A ValidationException from a request constructor is catchable as this
     * package's marker interface and as nothing else's.
     *
     * `catch (VposExceptionInterface)` catching everything from this package
     * and nothing else is the whole design of CONVENTIONS.md §5's hierarchy,
     * and a request DTO is the first place a caller meets it.
     */
    public function testAConstructorRejectionIsCatchableThroughThePackageMarker(): void
    {
        $this->expectException(VposExceptionInterface::class);

        new PaymentDetailsRequest('   ');
    }
}
