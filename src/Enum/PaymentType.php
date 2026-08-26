<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Enum;

/**
 * Payment type, mirroring the gateway's PaymentsEnum.
 *
 * Source: docs/api-reference/api-surface.json, model PaymentsEnum,
 * reflected from the bank's live C# model.
 *
 * The vendor PDF describes this as three values (5, 6, 7) in one place and four
 * (1, 3, 5, 6) in another. Both are partial views of this single set; there is
 * no conflict.
 *
 * Values 8, 9, 10, 15 and 16 are unassigned and the bank may fill them without
 * notice. Never call from() on a wire value — see CONVENTIONS.md §4.6.
 */
enum PaymentType: int
{
    case None = 0;
    case Arca = 1;
    case MasterCard = 2;
    case Visa = 3;
    case Reward = 4;
    case MainRest = 5;
    case BindingMainRest = 6;
    case PayPal = 7;
    case PayX = 11;
    case MirCard = 12;
    case ApplePay = 13;
    case EPGCardApplePay = 14;
    case Amex = 17;

    /**
     * GetBindings, ActivateBinding and DeactivateBinding accept only MainRest
     * and BindingMainRest. Every other value — including valid members of this
     * enum such as PayPal — returns HTTP 500 with an unparseable body, so the
     * check must happen before dispatch.
     *
     * Verified empirically for GetBindings only: probes A11.1/.2/.3/.6 sent
     * PaymentType 0, 1, 3 and 7 (PayPal) and got HTTP 500; A11.4/.5 sent 5 and
     * 6 and got HTTP 200. ActivateBinding and DeactivateBinding were probed
     * only at PaymentType 6 (A1.1, A1.3) — their rejection of other values is
     * not independently observed; see CONVENTIONS.md §4.6 for that claim.
     */
    public function isBindingCapable(): bool
    {
        return $this === self::MainRest || $this === self::BindingMainRest;
    }

    /**
     * @return list<int>
     */
    public static function bindingCapableValues(): array
    {
        return [self::MainRest->value, self::BindingMainRest->value];
    }
}
