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
 * ..., "ResponseCode": ... }`, and so does the wire, on case L6.1. The other
 * break is `OrderId` on
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
 * ## The success shape is observed now
 *
 * This model carried an unverified marker naming CONVENTIONS.md §13, on the
 * grounds that the endpoint had never been called. It has been called, so the
 * marker is gone rather than reworded. Case L6.1 asked for the
 * `PaymentID` of an order this package had registered, and the gateway answered
 * HTTP 200, `ResponseCode` `"00"`, `"ResponseMessage":""` and a `PaymentId`.
 * Three details of that body each turn a claim above from manifest-sourced into
 * observed:
 *
 * - The key really is `PaymentId`, lowercase `d`. CONVENTIONS.md §4.8's row for
 *   it is wire-confirmed rather than sample-confirmed.
 * - The identifier came back **lowercase**, siding with the BackURL callback
 *   rather than with InitPayment, which returns the same GUID uppercase. That is
 *   the third case channel CONVENTIONS.md §4.12 records, and this package
 *   normalises none of the three.
 * - `ResponseMessage` was the **empty string** on a call that succeeded — a
 *   third endpoint giving a third answer (CONVENTIONS.md §4.17). Do not read an
 *   empty message as a failure, and do not match a word on this field.
 *
 * One shape is still unseen: what the gateway answers for an `OrderID` it does
 * not know. Nothing here assumes one, which is why `paymentId` stays nullable.
 */
final readonly class GetPaymentIdResponse
{
    /**
     * @param string|null  $paymentId       Wire key `PaymentId` — lowercase `d`, on this model only. Not `PaymentID`. Returned lowercase on L6.1; nothing here normalises the case.
     * @param string       $responseMessage Wire key `ResponseMessage`. Observed as the **empty string** on a call that succeeded (L6.1), and the two words observed elsewhere differ anyway — `OK` from InitPayment, `Success` from RefundPayment — so nothing may be matched on this text and an empty value may not be read as a failure.
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
