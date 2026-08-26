<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to GetPaymentId: the PaymentID belonging to a merchant OrderID.
 *
 * Model of record: `GetPaymentIdResponse` in
 * docs/api-reference/api-surface.json.
 *
 * **This is one of exactly two models in the package that breaks the casing
 * convention.** The payment identifier arrives as `PaymentId` here — lowercase
 * `d` — where every other model spells it `PaymentID`. The manifest's own JSON
 * sample for this endpoint confirms it: `{ "PaymentId": ..., "ResponseMessage":
 * ..., "ResponseCode": ... }`. The other break is `OrderId` on
 * GetPendingTransactionsResponse. CONVENTIONS.md §4.8 forbids normalising
 * either; hydrating this model with the key `PaymentID` would silently yield
 * null, and a null PaymentID is indistinguishable here from an order the
 * gateway does not know — the exact failure mode hand-written hydration exists
 * to eliminate.
 *
 * Field order below is the manifest's, which is also unusual: `ResponseMessage`
 * precedes `ResponseCode` on this model alone.
 *
 * `ResponseCode` and `ResponseMessage` are the only non-nullable fields. The
 * manifest states no requiredness — "Additional information" reads "None." for
 * every field of every model.
 *
 * @todo unverified — see CONVENTIONS.md §13 (no GetPaymentId probe has ever run)
 */
final readonly class GetPaymentIdResponse
{
    /**
     * @param string|null  $paymentId       Wire key `PaymentId` — lowercase `d`, on this model only. Not `PaymentID`.
     * @param string       $responseMessage Wire key `ResponseMessage`. No success word is observed on this endpoint, and the two that are observed elsewhere differ — `OK` from InitPayment, `Success` from RefundPayment — so nothing may be matched on this text.
     * @param ResponseCode $responseCode    Wire key `ResponseCode`. String on this endpoint.
     */
    public function __construct(
        public ?string $paymentId,
        public string $responseMessage,
        public ResponseCode $responseCode,
    ) {}

    /**
     * Hydrates this model from a decoded wire array.
     *
     * A one-line delegation. The wire-key mapping lives in ResponseHydrator
     * and nowhere else, so no spelling in this package exists in two places
     * to drift apart (CONVENTIONS.md §2, §4.8). Unknown keys are ignored, an
     * absent key yields null, and only a shape that cannot be represented
     * throws — never with the offending value in the message
     * (CONVENTIONS.md §6).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function fromWireArray(array $data): self
    {
        return ResponseHydrator::getPaymentIdResponse($data);
    }
}
