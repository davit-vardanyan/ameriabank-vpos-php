<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;

/**
 * Captures funds previously authorised by a two-step payment.
 *
 * Model of record: `ConfirmPaymentRequest`.
 *
 * The model declares no `Currency` field, so the Amount's currency is not
 * carried on the wire for this operation. Nothing is invented to compensate:
 * the gateway ignores unknown request fields (CONVENTIONS.md §4.12), which
 * means an added `Currency` here would be silently discarded rather than
 * honoured, and emitting a field the specification of record does not declare
 * is a claim this package cannot support.
 *
 * `Username` and `Password` are absent by design; the transport merges them
 * from Credentials::userFields().
 *
 * Requiredness is not stated by the manifest — "Additional information" reads
 * "None." for every field of every model. Both fields here are required: the
 * operation addresses a payment and moves a sum, and has no meaning without
 * either.
 *
 * @todo unverified — see CONVENTIONS.md §13 (two-step payments are not permitted on the current sandbox client)
 */
final readonly class ConfirmPaymentRequest implements RequestInterface
{
    private const string OPERATION = 'ConfirmPayment';

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
     * Never retryable: this captures funds (CONVENTIONS.md §4.5). On a timeout
     * the transport raises IndeterminateStateException and the caller
     * reconciles through GetPaymentDetails rather than guessing.
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * The manifest's `ConfirmPaymentRequest` lists no `ClientID` — the model is
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
