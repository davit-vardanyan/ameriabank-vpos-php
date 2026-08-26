<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

/**
 * Disables a card binding.
 *
 * Model of record: `DeactivateBindingRequest`. The operation name is singular —
 * the plural form in the vendor PDF's table of contents returns 404
 * (CONVENTIONS.md §4.9).
 *
 * `ClientID`, `Username` and `Password` are absent by design; the transport
 * merges them from Credentials::merchantFields().
 *
 * `PaymentType` is checked against {5, 6} before dispatch, on the same footing
 * as ActivateBindingRequest — documented in §4.6, observed only for GetBindings.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. Both fields are required: the operation names
 * a binding and the scheme it belongs to.
 *
 * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the current sandbox client)
 */
final readonly class DeactivateBindingRequest implements RequestInterface
{
    private const string OPERATION = 'DeactivateBinding';

    private string $cardHolderId;

    private PaymentType $paymentType;

    /**
     * Validated before assignment; properties are declared rather than promoted
     * so that ordering is observable.
     *
     * @throws ValidationException
     */
    public function __construct(string $cardHolderId, PaymentType $paymentType)
    {
        if (trim($cardHolderId) === '') {
            throw ValidationException::blankValue('CardHolderID');
        }

        if (!$paymentType->isBindingCapable()) {
            throw ValidationException::unsupportedPaymentType(
                $paymentType->value,
                self::OPERATION,
                PaymentType::bindingCapableValues(),
            );
        }

        $this->cardHolderId = $cardHolderId;
        $this->paymentType = $paymentType;
    }

    public function operation(): string
    {
        return self::OPERATION;
    }

    /**
     * Never retryable, for the reason given in ActivateBindingRequest: §4.5 does
     * not tabulate this operation, and it changes state.
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * The manifest's `DeactivateBindingRequest` lists `ClientID`, so the
     * transport merges Credentials::merchantFields(). Its activating twin
     * declares the same, which is what one would expect of a pair — but each is
     * read from its own model, not inferred from the other.
     */
    public function requiresClientId(): bool
    {
        return true;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'CardHolderID' => $this->cardHolderId,
            'PaymentType' => $this->paymentType->value,
        ];
    }
}
