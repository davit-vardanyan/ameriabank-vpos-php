<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to GetBindings: the merchant's stored card bindings.
 *
 * Model of record: `GetBindingsResponse` in
 * docs/api-reference/api-surface.json. Field names verbatim
 * (CONVENTIONS.md §4.8).
 *
 * **`CardBindingFileds` is the wire key.** The bank's C# model misspells both
 * the collection and its element type (`CardBindingFiled`), and both spellings
 * are reproduced without correction. Confirmed on the wire, not merely
 * declared: probes A11.4, A11.5, B6.1 and B6.2 all returned
 * `"CardBindingFileds":[]` as JSON, and probe A12 returned
 * `<CardBindingFileds />` as XML.
 *
 * That XML form is why the collection is nullable rather than defaulted to an
 * empty list. `<CardBindingFileds />` self-closes when empty and an XML parser
 * must not read that as absent (CONVENTIONS.md §4.12), so this package keeps
 * the three cases apart: null means the key was absent, an empty array means
 * the gateway sent an empty collection, and a populated list means bindings
 * exist. Collapsing absent into empty would assert the merchant has no bindings
 * on the strength of a key the gateway simply did not send.
 *
 * `ResponseCode` and `ResponseMessage` are the only non-nullable fields. The
 * manifest states no requiredness — "Additional information" reads "None." for
 * every field of every model.
 *
 * A populated collection has never been seen. Every probe above returned
 * `ResponseCode` "20", "Client payment type BindingMainRest is not available",
 * because bindings are not permitted on the sandbox client
 * (CONVENTIONS.md §13).
 *
 * @todo unverified — see CONVENTIONS.md §13 (a populated binding list has never been observed)
 */
final readonly class GetBindingsResponse
{
    /**
     * @param ResponseCode                  $responseCode    Wire key `ResponseCode`. String on this endpoint.
     * @param string                        $responseMessage Wire key `ResponseMessage`. No success word is observed on this endpoint, and the two that are observed elsewhere differ — `OK` from InitPayment, `Success` from RefundPayment — so nothing may be matched on this text.
     * @param list<CardBindingFiled>|null   $cardBindings    Wire key `CardBindingFileds` — the bank's spelling. Null when the key is absent; an empty list when the gateway sent an empty collection.
     */
    public function __construct(
        public ResponseCode $responseCode,
        public string $responseMessage,
        public ?array $cardBindings,
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
        return ResponseHydrator::getBindingsResponse($data);
    }
}
