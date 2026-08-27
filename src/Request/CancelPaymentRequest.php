<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

/**
 * Voids an authorisation that has not been captured.
 *
 * Model of record: `CancelPaymentRequest`.
 *
 * `Username` and `Password` are absent by design; the transport merges them
 * from Credentials::userFields().
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. `PaymentID` is required because it is the
 * whole address of the call.
 *
 * ## What is observed, and what the marker still names
 *
 * The wire form this model emits has been sent and the gateway parsed it. Probe
 * case P5 and case L5.2 each addressed a payment that had already been captured
 * and refunded, and each came back a structured business refusal —
 * `ResponseCode` `"07"`, `"Reversal is impossible for current transaction
 * state"` — rather than a fault or a rejection of the request shape. L5.2 was
 * serialised by this package. So the request is not what is unverified.
 *
 * What has never happened is this operation succeeding, and the reason is not
 * this model either. Voiding an authorisation that has not been captured
 * requires the two-step flow, and two-step payments are not permitted on the
 * sandbox client that was available (CONVENTIONS.md §13) — so no payment has
 * ever been in the state this operation exists to leave.
 *
 * @todo unverified — see CONVENTIONS.md §13 (a *successful* cancel: two-step payments are not permitted on the sandbox client, so no authorisation has ever been left uncaptured to void — the refusal path is observed, on P5 and L5.2)
 */
final readonly class CancelPaymentRequest implements RequestInterface
{
    private const string OPERATION = 'CancelPayment';

    private string $paymentId;

    /**
     * Validated before assignment; the property is declared rather than
     * promoted so that ordering is observable.
     *
     * @throws ValidationException
     */
    public function __construct(string $paymentId)
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
     * Never retryable: this changes state (CONVENTIONS.md §4.5).
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * The manifest's `CancelPaymentRequest` lists no `ClientID` — the model is
     * `PaymentID`, `Username`, `Password` — so the transport merges
     * Credentials::userFields(). A PaymentID is already global, so nothing here
     * needs a merchant to scope it.
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
        return ['PaymentID' => $this->paymentId];
    }
}
