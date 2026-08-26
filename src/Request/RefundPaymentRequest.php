<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;

/**
 * Returns funds to the cardholder, in full or in part.
 *
 * Model of record: `RefundPaymentRequest`.
 *
 * As with ConfirmPayment, the model declares no `Currency` field and none is
 * invented — see that class for why an undeclared field is not a safe addition.
 *
 * `Username` and `Password` are absent by design; the transport merges them
 * from Credentials::userFields().
 *
 * The manifest states no requiredness anywhere: "Additional information" reads
 * "None." for every field of every model. Both fields here are required —
 * a refund without a sum or an address is not a refund. A zero amount is
 * rejected by Amount itself, which probe A7.3 confirmed the gateway also
 * rejects.
 *
 * Two partial refunds against one completed payment are on the record, probe
 * cases P4.1 and P4.3, and they settle what this request's caller has to know.
 * `RefundedAmount` on GetPaymentDetails accumulates while `DepositedAmount`
 * decrements to the remaining refundable balance, so the sum still available to
 * refund is a field the gateway publishes rather than one the caller computes.
 * Asking for more than that balance is refused with `ResponseCode` `"07"` and
 * the message `Refund amount exceeds deposited amount` (P4.5) — a refusal, not a
 * fault, and one this package deliberately does not map to a dedicated exception
 * subclass, because `"07"` is also what a refused cancel returns for an entirely
 * different reason.
 */
final readonly class RefundPaymentRequest implements RequestInterface
{
    private const string OPERATION = 'RefundPayment';

    private string $paymentId;

    /**
     * `PaymentID` is declared rather than promoted because a guard must run
     * before it is assigned; `Amount` is promoted because none does. That split
     * is the rule across src/Request/ — see InitPaymentRequest for why, and for
     * which gate enforces it.
     *
     * @throws ValidationException
     */
    public function __construct(string $paymentId, private Amount $amount)
    {
        if (trim($paymentId) === '') {
            throw ValidationException::blankValue('PaymentID');
        }

        $this->paymentId = $paymentId;
    }

    public function operation(): string
    {
        return self::OPERATION;
    }

    /**
     * Never retryable: this moves money (CONVENTIONS.md §4.5).
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * The manifest's `RefundPaymentRequest` lists no `ClientID` — the model is
     * `PaymentID`, `Username`, `Password`, `Amount` — so the transport
     * merges Credentials::userFields().
     */
    public function requiresClientId(): bool
    {
        return false;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'PaymentID' => $this->paymentId,
            'Amount' => $this->amount->toDecimalString(),
        ];
    }
}
