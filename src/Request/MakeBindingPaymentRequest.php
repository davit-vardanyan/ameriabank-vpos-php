<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;

/**
 * Charges a card through a stored binding.
 *
 * Model of record: `MakeBindingPaymentRequest`.
 *
 * This is not a silent server-to-server charge. The response carries `AcsUrl`,
 * `PaReq` and `TermUrl` — a 3-D Secure challenge triple (CONVENTIONS.md §4.12)
 * — so a caller must still be able to put the cardholder in front of an
 * issuer page.
 *
 * `ClientID`, `Username` and `Password` are absent by design; the transport
 * merges them from Credentials::merchantFields().
 *
 * `PaymentType` is *not* restricted to {5, 6} here. §4.6 names that restriction
 * for GetBindings, ActivateBinding and DeactivateBinding, and this operation is
 * not among them; narrowing it on the strength of a family resemblance would
 * reject a charge the gateway might accept.
 *
 * `Currency` is derived from the Amount rather than accepted separately, for
 * the reason given in InitPaymentRequest: two independent sources for one
 * currency is a money bug with no visible symptom.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. `CardHolderID`, `Amount`, `OrderID`,
 * `BackURL` and `PaymentType` are required because the operation addresses a
 * binding, moves a sum, keys an order, returns a customer, and names a scheme.
 *
 * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the current sandbox client)
 */
final readonly class MakeBindingPaymentRequest implements RequestInterface
{
    private const string OPERATION = 'MakeBindingPayment';

    private string $cardHolderId;

    private string $backUrl;

    /**
     * `CardHolderID` and `BackURL` are declared rather than promoted because a
     * guard must run before either is assigned; the rest are promoted because
     * none does. That split is the rule across src/Request/ — see
     * InitPaymentRequest for why, and for which gate enforces it.
     *
     * `OrderID` carries no range check, for the reason given in
     * InitPaymentRequest.
     *
     * @throws ValidationException
     */
    public function __construct(
        string $cardHolderId,
        private Amount $amount,
        private int $orderId,
        string $backUrl,
        private PaymentType $paymentType,
        private ?string $description = null,
        private ?string $opaque = null,
    ) {
        if (trim($cardHolderId) === '') {
            throw ValidationException::blankValue('CardHolderID');
        }

        if (trim($backUrl) === '') {
            throw ValidationException::blankValue('BackURL');
        }

        $this->cardHolderId = $cardHolderId;
        $this->backUrl = $backUrl;
    }

    public function operation(): string
    {
        return self::OPERATION;
    }

    /**
     * Never retryable: this charges a card (CONVENTIONS.md §4.5).
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * The manifest's `MakeBindingPaymentRequest` lists `ClientID`, so the
     * transport merges Credentials::merchantFields(). Like InitPayment it
     * registers an order under an `OrderID`, and like InitPayment that
     * identifier is scoped to a merchant.
     */
    public function requiresClientId(): bool
    {
        return true;
    }

    /**
     * Wire keys to scalar values, in the manifest's field order, with absent
     * optionals omitted rather than sent as null.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $fields = [
            'CardHolderID' => $this->cardHolderId,
            'Amount' => $this->amount->toDecimalString(),
            'OrderID' => $this->orderId,
            'BackURL' => $this->backUrl,
            'PaymentType' => $this->paymentType->value,
        ];

        if ($this->description !== null) {
            $fields['Description'] = $this->description;
        }

        $fields['Currency'] = $this->amount->currency()->value;

        if ($this->opaque !== null) {
            $fields['Opaque'] = $this->opaque;
        }

        return $fields;
    }
}
