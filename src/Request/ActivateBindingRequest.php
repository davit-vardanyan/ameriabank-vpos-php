<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

/**
 * Re-enables a card binding.
 *
 * Model of record: `ActivateBindingRequest`. The operation name is singular —
 * the plural form in the vendor PDF's table of contents returns 404
 * (CONVENTIONS.md §4.9).
 *
 * `ClientID`, `Username` and `Password` are absent by design; the transport
 * merges them from Credentials::merchantFields().
 *
 * `PaymentType` is checked against {5, 6} before dispatch, per §4.6. That
 * restriction is observed for GetBindings only — probes A1.1 and A1.3 exercised
 * this operation at PaymentType 6 and never at another value — so the guard
 * here rests on the documented rule rather than on a rejection anyone has seen.
 * It is applied anyway: the failure mode it prevents is an unparseable HTTP 500
 * (§4.2), and the two values it permits are the two the rule names.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. Both fields are required: the operation names
 * a binding and the scheme it belongs to.
 *
 * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the current sandbox client)
 */
final readonly class ActivateBindingRequest implements RequestInterface
{
    private const string OPERATION = 'ActivateBinding';

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
     * Never retryable. CONVENTIONS.md §4.5 does not tabulate this operation,
     * and the table's silence is not permission: activating a binding changes
     * state, so it is classed with the operations that must not be replayed
     * rather than with the read-only ones.
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * The manifest's `ActivateBindingRequest` lists `ClientID`, so the
     * transport merges Credentials::merchantFields().
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
