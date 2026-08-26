<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to InitPayment: the PaymentID the payment page is keyed on.
 *
 * Model of record: `InitPaymentResponse` in
 * docs/api-reference/api-surface.json. Field names below are that
 * model's, verbatim (CONVENTIONS.md §4.8).
 *
 * This is the one response model whose `ResponseCode` the manifest declares
 * `integer` rather than `string`, and the manifest's own JSON sample renders it
 * unquoted. Success is 1 here and "00" everywhere else (CONVENTIONS.md §4.3),
 * which is why ResponseCode is a value object carrying int|string and never
 * an enum.
 *
 * `ResponseCode` is not nullable. Response fields in this package are nullable
 * because the manifest declares no field required, and only `ResponseCode` and
 * `ResponseMessage` are exempt: this is the one operation the probes exercised
 * repeatedly, and all 24 accepted requests across phases A, B and C carried
 * both, as did probe case P1 — the registration of the first payment this
 * package has watched complete end to end. `PaymentID` is nullable despite
 * CONVENTIONS.md §4.12 recording it as "empty string, never null on failure" —
 * an empty string is a value the hydrator can carry, an absent key is not, and
 * the exemption does not reach it.
 *
 * A constructor and nothing else. The wire mapping lives in one place, the
 * hydrator under Support\; see BankInfo for why.
 */
final readonly class InitPaymentResponse
{
    /**
     * @param string|null  $paymentId       Wire key `PaymentID` — uppercase ID, as everywhere except GetPaymentIdResponse. An uppercase GUID of 36 characters (CONVENTIONS.md §4.12), and uppercase here specifically: the BackURL callback echoes the same identifier in lowercase (probe cases P1 and P2). Never compare the two case-sensitively.
     * @param ResponseCode $responseCode    Wire key `ResponseCode`. Integer on this endpoint; 1 is success, as observed on probe case P1.
     * @param string       $responseMessage Wire key `ResponseMessage`. The gateway's own diagnostic text — "OK" on success here (P1), where RefundPayment says "Success" (P4.1). The success word varies by endpoint; nothing may be matched on it.
     */
    public function __construct(
        public ?string $paymentId,
        public ResponseCode $responseCode,
        public string $responseMessage,
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
        return ResponseHydrator::initPaymentResponse($data);
    }
}
