<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Enum;

use DavitVardanyan\AmeriabankVpos\Enum\OrderStatus;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Both halves of vendor PDF Table 2, and the mapping between them.
 *
 * Two of the seven rows are now observed on the wire, and they arrive as **two
 * fields in one body** rather than as two spellings of one: probe case P3
 * carries `"OrderStatus":"2"` beside `"PaymentState":"payment_deposited"`, and
 * P4.1b carries `"4"` beside `"payment_refunded"`. Table 2's pairing therefore
 * holds for Deposited and Refunded. The other five rows, and the mapping between
 * them, are still the PDF's word alone.
 *
 * What these tests pin is the SDK's internal consistency rather than the bank's
 * behaviour: converting a status to its state and back must not silently
 * relabel a refund as a void, whichever rows turn out to be real.
 */
#[CoversClass(OrderStatus::class)]
#[CoversClass(PaymentState::class)]
final class OrderStatusTest extends TestCase
{
    /**
     * The seven pairs of vendor PDF Table 2, with both backing values.
     *
     * Every pair is listed because a transposition is invisible from a sample:
     * swapping Void and Refunded in both directions at once leaves the round
     * trip intact — Void still returns Void — and leaves a test that checks
     * only Deposited entirely green, while every refund in the caller's ledger
     * is reported as a cancellation. Only a pinned table catches that.
     *
     * Note that OrderStatus::Registered pairs with PaymentState::Started, not
     * with a like-named case. It is the one pair whose names differ, so it is
     * also the one a transcription is most likely to get wrong.
     *
     * @return array<string, array{OrderStatus, PaymentState, int, string}>
     */
    public static function pairs(): array
    {
        return [
            'Registered' => [OrderStatus::Registered, PaymentState::Started, 0, 'payment_started'],
            'Approved' => [OrderStatus::Approved, PaymentState::Approved, 1, 'payment_approved'],
            'Deposited' => [OrderStatus::Deposited, PaymentState::Deposited, 2, 'payment_deposited'],
            'Void' => [OrderStatus::Void, PaymentState::Void, 3, 'payment_void'],
            'Refunded' => [OrderStatus::Refunded, PaymentState::Refunded, 4, 'payment_refunded'],
            'AutoAuthorized' => [OrderStatus::AutoAuthorized, PaymentState::AutoAuthorized, 5, 'payment_autoauthorized'],
            'Declined' => [OrderStatus::Declined, PaymentState::Declined, 6, 'payment_declined'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unknownOrderStatusCodes(): array
    {
        return [
            'one past the last member' => [7],
            'two digits' => [99],
            'negative' => [-1],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownPaymentStates(): array
    {
        return [
            'unknown state' => ['payment_unknown'],
            'unprefixed' => ['deposited'],
            'wrong case' => ['PAYMENT_DEPOSITED'],
            'numeric spelling' => ['2'],
            'empty' => [''],
        ];
    }

    #[DataProvider('pairs')]
    public function testOrderStatusConvertsToItsPaymentState(
        OrderStatus $status,
        PaymentState $state,
        int $code,
        string $name,
    ): void {

        self::assertSame(
            $state,
            $status->paymentState(),
            sprintf('OrderStatus::%s must map to PaymentState::%s.', $status->name, $state->name),
        );
    }

    #[DataProvider('pairs')]
    public function testPaymentStateConvertsToItsOrderStatus(
        OrderStatus $status,
        PaymentState $state,
        int $code,
        string $name,
    ): void {

        self::assertSame(
            $status,
            $state->orderStatus(),
            sprintf('PaymentState::%s must map to OrderStatus::%s.', $state->name, $status->name),
        );
    }

    /**
     * The round trip is asserted in addition to the two directions, not instead.
     *
     * On its own it is satisfied by any bijection, including one that has the
     * whole table rotated by a position. Its value is catching the asymmetric
     * case: one direction edited without the other, which the two tests above
     * would each report as a single wrong pair, while this reports it as data
     * loss.
     */
    #[DataProvider('pairs')]
    public function testConvertingBothWaysReturnsTheOriginal(
        OrderStatus $status,
        PaymentState $state,
        int $code,
        string $name,
    ): void {

        self::assertSame($status, $status->paymentState()->orderStatus());
        self::assertSame($state, $state->orderStatus()->paymentState());
    }

    /**
     * The backing values are pinned alongside the pairing.
     *
     * The mapping is between members; the wire carries the values. Renumbering
     * Void to 4 and Refunded to 3 would keep every mapping assertion above
     * green while inverting the meaning of the two codes the gateway actually
     * sends.
     */
    #[DataProvider('pairs')]
    public function testTheBackingValuesAreThoseOfTableTwo(
        OrderStatus $status,
        PaymentState $state,
        int $code,
        string $name,
    ): void {
        self::assertSame($code, $status->value);
        self::assertSame($name, $state->value);
    }

    /**
     * The provider is hand-maintained, so it needs a completeness check.
     *
     * Checked against both enums, because either could gain a case alone. The
     * match expressions in src/ are exhaustive with no default arm, so an
     * unpaired case is a compile-time error there — but only once someone calls
     * the converter, and this is what makes sure the suite does.
     */
    public function testThePairProviderCoversEveryCaseOfBothEnums(): void
    {
        $statuses = [];
        $states = [];

        foreach (self::pairs() as [$status, $state]) {
            $statuses[] = $status->name;
            $states[] = $state->name;
        }

        $declaredStatuses = array_map(static fn(OrderStatus $case): string => $case->name, OrderStatus::cases());
        $declaredStates = array_map(static fn(PaymentState $case): string => $case->name, PaymentState::cases());

        sort($statuses);
        sort($states);
        sort($declaredStatuses);
        sort($declaredStates);

        self::assertSame($declaredStatuses, $statuses, 'pairs() must list every OrderStatus case, and no other.');
        self::assertSame($declaredStates, $states, 'pairs() must list every PaymentState case, and no other.');
    }

    /**
     * The mapping must be a bijection, not merely total.
     *
     * Two order statuses collapsing onto one payment state is the failure the
     * exhaustive match cannot catch: it compiles, it is total, and it loses a
     * distinction the caller is relying on. Asserted as the set of results
     * being duplicate-free rather than pair by pair.
     *
     * No count is asserted alongside: the two lists are built by iterating
     * cases(), so their length is that of cases() by construction, and PHPStan
     * says so — assertCount() on either is an error at level 10, not an
     * assertion. Deduplication is the only fact here that is not already true
     * by the shape of the loop.
     */
    public function testTheMappingIsOneToOneInBothDirections(): void
    {
        $states = [];

        foreach (OrderStatus::cases() as $status) {
            $states[] = $status->paymentState()->name;
        }

        $statuses = [];

        foreach (PaymentState::cases() as $state) {
            $statuses[] = $state->orderStatus()->name;
        }

        self::assertSame($states, array_values(array_unique($states)), 'Two order statuses share one payment state.');
        self::assertSame($statuses, array_values(array_unique($statuses)), 'Two payment states share one order status.');
    }

    /**
     * The manifest declares PaymentDetailsResponse.OrderStatus as a string, and
     * probe case P3 confirms it arrives quoted — `"2"`. What the *set* of
     * values is remains the PDF's claim, and the bank adds members without
     * notice, so an unrecognised value must degrade to null rather than throw.
     */
    #[DataProvider('unknownOrderStatusCodes')]
    public function testUnknownOrderStatusCodesDegradeToNull(int $code): void
    {
        self::assertNull(
            OrderStatus::tryFrom($code),
            sprintf('OrderStatus must not claim to know the code %d.', $code),
        );
    }

    #[DataProvider('unknownPaymentStates')]
    public function testUnknownPaymentStatesDegradeToNull(string $name): void
    {
        self::assertNull(
            PaymentState::tryFrom($name),
            sprintf('PaymentState must not claim to know the state "%s".', $name),
        );
    }
}
