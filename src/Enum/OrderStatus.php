<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Enum;

/**
 * Numeric order status code, as tabulated in vendor PDF Table 2.
 *
 * Members come from vendor PDF Table 2. Two of the seven are now observed on the
 * wire, and observing them corrected the question this docblock used to ask.
 * It asked whether the wire "carries `"2"` or `"payment_deposited"`", and that
 * was a false choice: `OrderStatus` and `PaymentState` are two separate fields
 * of `PaymentDetailsResponse` and a completed payment carries both. Probe case
 * P3 returns `"OrderStatus":"2"` beside `"PaymentState":"payment_deposited"`,
 * and P4.1b returns `"4"` beside `"payment_refunded"`.
 *
 * So this enum's field carries the numeric form, as a quoted string —
 * `PaymentDetailsResponse.OrderStatus` is declared `string` in the API surface
 * manifest and arrives as one. Table 2's pairing is confirmed for those two
 * rows, which is what paymentState() below encodes; the other five rows are
 * still the PDF's word alone.
 *
 * **`Refunded` does not mean fully refunded.** Probe case P4.1b reports this
 * status after a partial refund, on a payment still carrying a `DepositedAmount`
 * of 6.0 against an `ApprovedAmount` of 10.0. A merchant branching on the status
 * alone, without reading `RefundedAmount` and `DepositedAmount`, will treat a
 * partly refunded order as a closed one.
 *
 * Never call from() on a wire value — use tryFrom() and treat null as "value
 * this SDK does not yet know" (CONVENTIONS.md §4.6).
 */
enum OrderStatus: int
{
    case Registered = 0;
    case Approved = 1;
    case Deposited = 2;
    case Void = 3;
    case Refunded = 4;
    case AutoAuthorized = 5;
    case Declined = 6;

    /**
     * The PaymentState this order status corresponds to, per vendor PDF Table 2.
     *
     * Exhaustive by construction: adding a case to this enum without adding the
     * matching arm here is a compile-time error, not a silent fallthrough.
     */
    public function paymentState(): PaymentState
    {
        return match ($this) {
            self::Registered => PaymentState::Started,
            self::Approved => PaymentState::Approved,
            self::Deposited => PaymentState::Deposited,
            self::Void => PaymentState::Void,
            self::Refunded => PaymentState::Refunded,
            self::AutoAuthorized => PaymentState::AutoAuthorized,
            self::Declined => PaymentState::Declined,
        };
    }
}
