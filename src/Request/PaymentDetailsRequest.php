<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

/**
 * Reads the authoritative state of a payment.
 *
 * Model of record: `PaymentDetailsRequest`. Note the name — the operation is
 * `GetPaymentDetails` but the model is not `GetPaymentDetailsRequest`, and this
 * package takes the model's spelling.
 *
 * This is the round trip CONVENTIONS.md §4.10 makes mandatory. The BackURL
 * callback is unsigned and anyone can forge `resposneCode=00`, so the outcome
 * of a payment is whatever this operation says it is and nothing else. It is
 * also the reconciliation path after an IndeterminateStateException (§4.5).
 *
 * `Username` and `Password` are absent by design; the transport merges them
 * from Credentials::userFields(), this model carrying no `ClientID`.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. `PaymentID` is required here because it is
 * the whole address of the call.
 */
final readonly class PaymentDetailsRequest implements RequestInterface
{
    private const string OPERATION = 'GetPaymentDetails';

    private string $paymentId;

    /**
     * Validated before assignment; the property is declared rather than
     * promoted so that ordering is observable.
     *
     * No format check. §4.12 records PaymentID as a 36-character uppercase
     * GUID, but that is an observation of the values seen, not a rule the bank
     * has published, and rejecting an identifier the gateway would have
     * honoured loses a payment.
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
     * Read-only, so retryable (CONVENTIONS.md §4.5).
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * The manifest's `PaymentDetailsRequest` lists no `ClientID` — the model is
     * `PaymentID`, `Username`, `Password` — so the transport merges
     * Credentials::userFields(), which is what the class docblock above
     * already promised.
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
