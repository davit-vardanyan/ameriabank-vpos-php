<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to RefundPayment.
 *
 * Model of record: `RefundPaymentResponse` in
 * docs/api-reference/api-surface.json. Three fields, verbatim
 * (CONVENTIONS.md §4.8).
 *
 * Structurally identical to ConfirmPaymentResponse and CancelPaymentResponse,
 * and deliberately not merged with them — see ConfirmPaymentResponse for why.
 *
 * It carries no refunded total, and probe cases P4.1 and P4.3 confirm that
 * directly: two successful partial refunds, and neither response carried an
 * amount of any kind. A caller tracking a partial refund reads GetPaymentDetails
 * for the figures — where `RefundedAmount` accumulates and `DepositedAmount`
 * decrements to the remaining refundable balance, so no arithmetic is needed.
 * RefundPayment moves money and is never retried (CONVENTIONS.md §4.5).
 *
 * `ResponseCode` and `ResponseMessage` are the only non-nullable fields. The
 * manifest states no requiredness — "Additional information" reads "None." for
 * every field of every model.
 *
 * Observed on both payments that have gone through end to end, and on both
 * attempts to over-refund one. P4.1, P4.3, L4.1 and L4.3 succeeded with
 * `ResponseCode` `"00"` and `ResponseMessage` `Success`. P4.5 and L5.1 asked for
 * more than the remaining balance and were refused with `"07"` — P4.5 carrying
 * `Refund amount exceeds deposited amount`; no message is on record for L5.1.
 * The L cases were serialised and hydrated by this package rather than by a
 * hand-rolled probe. Every one of them carried the InitPayment `Opaque`, and
 * every one arrived under exactly the manifest's three field names.
 *
 * The cases are named and not counted. A bare total restated beside them reads
 * as the whole record and then goes stale on the next run, which is the drift
 * CONVENTIONS.md §4.7 stopped restating a count for.
 */
final readonly class RefundPaymentResponse
{
    /**
     * @param ResponseCode $responseCode    Wire key `ResponseCode`. String on this endpoint; "00" is the success value, observed as such on cases P4.1, P4.3, L4.1 and L4.3.
     * @param string       $responseMessage Wire key `ResponseMessage`. `Success` on this endpoint (P4.1, P4.3, L4.1, L4.3) where InitPayment says `OK` and GetPaymentId sent `""` — the success word varies by endpoint and may be absent, so nothing may be matched on it.
     * @param string|null  $opaque          Wire key `Opaque`. Echoed back from InitPayment, on a refusal as much as on a success (P4.5, L5.1).
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
        return ResponseHydrator::refundPaymentResponse($data);
    }
}
