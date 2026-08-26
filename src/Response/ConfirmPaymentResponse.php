<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to ConfirmPayment, the capture half of a two-step payment.
 *
 * Model of record: `ConfirmPaymentResponse` in
 * docs/api-reference/api-surface.json. Three fields, verbatim
 * (CONVENTIONS.md §4.8).
 *
 * Structurally identical to CancelPaymentResponse and RefundPaymentResponse.
 * The three are separate classes rather than one shared type because they are
 * three separate models upstream: the bank may add a field to one without
 * touching the others, and a shared class would have to be split at that point
 * — a breaking change for every caller that type-hinted it. Every model the
 * manifest declares gets its own class here.
 *
 * Note what this response does not carry: no amount, no order status, nothing
 * that would let a caller confirm what was captured. Reconciliation is a
 * GetPaymentDetails round trip, which is also the instruction
 * IndeterminateStateException carries (CONVENTIONS.md §4.5).
 *
 * `ResponseCode` and `ResponseMessage` are the only non-nullable fields;
 * `Opaque` is nullable like every other declared field. The manifest states no
 * requiredness — "Additional information" reads "None." for every field of
 * every model.
 *
 * Never observed. Two-step payments are not permitted on the sandbox client
 * (CONVENTIONS.md §13), so no ConfirmPayment call has ever returned a body.
 *
 * @todo unverified — see CONVENTIONS.md §13 (ConfirmPayment cannot be exercised)
 */
final readonly class ConfirmPaymentResponse
{
    /**
     * @param ResponseCode $responseCode    Wire key `ResponseCode`. String on this endpoint; "00" is the documented success value.
     * @param string       $responseMessage Wire key `ResponseMessage`. No success word is observed on this endpoint, and the two that are observed elsewhere differ — `OK` from InitPayment, `Success` from RefundPayment — so nothing may be matched on this text.
     * @param string|null  $opaque          Wire key `Opaque`. Echoed back from InitPayment; §4.4 verified the echo, which is how parameter overwrite on a repeat InitPayment was proved.
     */
    public function __construct(
        public ResponseCode $responseCode,
        public string $responseMessage,
        public ?string $opaque,
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
        return ResponseHydrator::confirmPaymentResponse($data);
    }
}
