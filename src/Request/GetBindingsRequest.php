<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

/**
 * Lists the card bindings registered for the merchant.
 *
 * Model of record: `GetBindingsRequest`. Its response carries the misspelled
 * collection `CardBindingFileds` (CONVENTIONS.md §4.8); that belongs to the
 * response side and is not corrected anywhere.
 *
 * `ClientID`, `Username` and `Password` are absent by design; the transport
 * merges them from Credentials::merchantFields().
 *
 * `PaymentType` is checked against {5, 6} before dispatch and not afterwards,
 * because there is no afterwards worth having: probes A11.1/.2/.3/.6 sent 0, 1,
 * 3 and 7 and each returned HTTP 500 carrying ASP.NET's unhandled-exception
 * page, which has no ResponseCode to read and which §4.2 forbids retrying or
 * treating as a transport fault. A11.4/.5 sent 5 and 6 and returned HTTP 200.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. `PaymentType` is required because it is the
 * only business field the model has, and because omitting it would reproduce
 * the same unparseable 500.
 *
 * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the current sandbox client)
 */
final readonly class GetBindingsRequest implements RequestInterface
{
    private const string OPERATION = 'GetBindings';

    private PaymentType $paymentType;

    /**
     * Validated before assignment; the property is declared rather than
     * promoted so that ordering is observable.
     *
     * @throws ValidationException
     */
    public function __construct(PaymentType $paymentType)
    {
        if (!$paymentType->isBindingCapable()) {
            throw ValidationException::unsupportedPaymentType(
                $paymentType->value,
                self::OPERATION,
                PaymentType::bindingCapableValues(),
            );
        }

        $this->paymentType = $paymentType;
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
     * The manifest's `GetBindingsRequest` lists `ClientID`, so the transport
     * merges Credentials::merchantFields(). Bindings belong to a merchant, and
     * all three binding operations are on that side of the split.
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
        return ['PaymentType' => $this->paymentType->value];
    }
}
