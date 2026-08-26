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
 * @todo unverified — see CONVENTIONS.md §13 (two-step payments are not permitted on the current sandbox client)
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
