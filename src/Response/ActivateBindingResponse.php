<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to ActivateBinding.
 *
 * Model of record: `ActivateBindingResponse` in
 * docs/api-reference/api-surface.json. Three fields, verbatim
 * (CONVENTIONS.md §4.8). The endpoint name is singular — the PDF's plural
 * `ActivateBindings` returns 404 (CONVENTIONS.md §4.9).
 *
 * Structurally identical to DeactivateBindingResponse and kept separate for the
 * reason given in ConfirmPaymentResponse: they are two models upstream.
 *
 * Note that the response echoes `CardHolderID` but not the binding's state, so
 * nothing here confirms the binding is now active — that reading comes from
 * `IsAvtive` in a subsequent GetBindings.
 *
 * `ResponseCode` and `ResponseMessage` are the only non-nullable fields. The
 * manifest states no requiredness — "Additional information" reads "None." for
 * every field of every model.
 *
 * Observed only as a refusal: probe A1.1 called this operation and got
 * `ResponseCode` "20", "Client payment type BindingMainRest is not available",
 * because bindings are not permitted on the sandbox client (CONVENTIONS.md
 * §13). A successful body has never been seen.
 *
 * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the sandbox client)
 */
final readonly class ActivateBindingResponse
{
    /**
     * @param ResponseCode $responseCode    Wire key `ResponseCode`. String on this endpoint.
     * @param string       $responseMessage Wire key `ResponseMessage`. No success word is observed on this endpoint, and the two that are observed elsewhere differ — `OK` from InitPayment, `Success` from RefundPayment — so nothing may be matched on this text.
     * @param string|null  $cardHolderId    Wire key `CardHolderID`.
     */
    public function __construct(
        public ResponseCode $responseCode,
        public string $responseMessage,
        public ?string $cardHolderId,
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
        return ResponseHydrator::activateBindingResponse($data);
    }
}
