<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Enum;

/**
 * String-named payment state, as tabulated in vendor PDF Table 2.
 *
 * Members come from vendor PDF Table 2, and two of the seven names are now
 * confirmed to be real: probe case P3 returns `"PaymentState":"payment_deposited"`
 * and P4.1b returns `"payment_refunded"`, both spelled exactly as this enum
 * spells them.
 *
 * This is a **different field** from `OrderStatus`, not another spelling of it.
 * The two arrive together in the same `PaymentDetailsResponse` body — `"2"` beside
 * `payment_deposited`, `"4"` beside `payment_refunded` — so Table 2's pairing is
 * confirmed for those two rows, which is what orderStatus() below encodes. The
 * other five rows are still the PDF's word alone.
 *
 * **`Refunded` does not mean fully refunded**, for the reason OrderStatus gives:
 * probe case P4.1b carries it on a payment with most of its balance still
 * deposited. Read the amounts, not the state.
 *
 * Never call from() on a wire value — use tryFrom() and treat null as "value
 * this SDK does not yet know" (CONVENTIONS.md §4.6).
 */
enum PaymentState: string
{
    case Started = 'payment_started';
    case Approved = 'payment_approved';
    case Deposited = 'payment_deposited';
    case Void = 'payment_void';
    case Refunded = 'payment_refunded';
    case AutoAuthorized = 'payment_autoauthorized';
    case Declined = 'payment_declined';

    /**
     * The OrderStatus this payment state corresponds to, per vendor PDF Table 2.
     *
     * Exhaustive by construction: adding a case to this enum without adding the
     * matching arm here is a compile-time error, not a silent fallthrough.
     */
    public function orderStatus(): OrderStatus
    {
        return match ($this) {
            self::Started => OrderStatus::Registered,
            self::Approved => OrderStatus::Approved,
            self::Deposited => OrderStatus::Deposited,
            self::Void => OrderStatus::Void,
            self::Refunded => OrderStatus::Refunded,
            self::AutoAuthorized => OrderStatus::AutoAuthorized,
            self::Declined => OrderStatus::Declined,
        };
    }
}
