<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to CancelPayment.
 *
 * Model of record: `CancelPaymentResponse` in
 * docs/api-reference/api-surface.json. Three fields, verbatim
 * (CONVENTIONS.md §4.8).
 *
 * Structurally identical to ConfirmPaymentResponse and RefundPaymentResponse,
 * and deliberately not merged with them — see ConfirmPaymentResponse for why.
 *
 * The response says nothing about what state the order ended in. CancelPayment
 * is state-changing and never retried (CONVENTIONS.md §4.5); on a timeout the
 * caller gets IndeterminateStateException and reconciles through
 * GetPaymentDetails rather than calling again.
 *
 * `ResponseCode` and `ResponseMessage` are the only non-nullable fields. The
 * manifest states no requiredness — "Additional information" reads "None." for
 * every field of every model.
 *
 * Observed once, on probe case P5: a cancel against a payment that had already
 * been partially refunded, answering HTTP 200 with `ResponseCode` `"07"`,
 * `ResponseMessage` `Reversal is impossible for current transaction state`, and
 * the `Opaque` from InitPayment. All three declared fields arrived, matching the
 * manifest. A cancel that *succeeds* has still not been seen — the one payment
 * available to cancel had been refunded first, which is the state that refusal
 * names.
 */
final readonly class CancelPaymentResponse
{
    /**
     * @param ResponseCode $responseCode    Wire key `ResponseCode`. String on this endpoint; "00" is the documented success value, and probe case P5 observed the refusal `"07"` here.
     * @param string       $responseMessage Wire key `ResponseMessage`. The gateway's own text; observed carrying the reason a cancel was refused (P5). No success word has been seen on this endpoint, and the two that have been seen elsewhere differ — `OK` from InitPayment, `Success` from RefundPayment — so nothing may be matched on it.
     * @param string|null  $opaque          Wire key `Opaque`. Echoed back from InitPayment, on a refusal as much as on a success (P5).
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
        return ResponseHydrator::cancelPaymentResponse($data);
    }
}
