<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;

/**
 * Recovers the PaymentID the gateway issued for a merchant order.
 *
 * Model of record: `GetPaymentIdRequest`. Its response is the one model in the
 * package that spells the identifier `PaymentId` rather than `PaymentID`
 * (CONVENTIONS.md §4.8); the request side carries only `OrderID`, in the
 * usual spelling.
 *
 * `ClientID`, `Username` and `Password` are absent by design; the transport
 * merges them from Credentials::merchantFields().
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. `OrderID` is required because it is the only
 * business field the model has.
 *
 * No range check on `OrderID`. No probe has established one, and rejecting a
 * value the gateway would have accepted loses a lookup that might have
 * succeeded.
 */
final readonly class GetPaymentIdRequest implements RequestInterface
{
    private const string OPERATION = 'GetPaymentId';

    public function __construct(private int $orderId) {}

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
     * The manifest's `GetPaymentIdRequest` lists `ClientID`, so the transport
     * merges Credentials::merchantFields(). It has to: this operation is
     * addressed by `OrderID`, which is only unique within a merchant, and §4.4
     * keys payment identity on (ClientID, OrderID).
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
        return ['OrderID' => $this->orderId];
    }
}
