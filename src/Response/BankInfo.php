<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * Issuing-bank details nested inside a PaymentDetailsResponse.
 *
 * Model of record: `BankInfo` in docs/api-reference/api-surface.json.
 * It is reachable only through that manifest's `models` map — no endpoint's
 * field list mentions it, and `PaymentDetailsResponse` reaches it through its
 * `referenced_models` entry. Its three field names are that model's, verbatim
 * (CONVENTIONS.md §4.8).
 *
 * Every field is nullable. The manifest states no requiredness: its "Additional
 * information" column reads "None." for every field of every model and its
 * "Description" column is empty throughout, so nothing upstream distinguishes a
 * guaranteed field from an optional one.
 *
 * **Two of three members are populated on a real payment; `BankName` is not.**
 * Probe B2, a `GetPaymentDetails` on an order that never reached the payment
 * page, returned the nested object with all three members present and every one
 * an empty string. Probe case P3, a completed payment, returns the same three
 * members with `BankCountryCode` `"AM"` and `BankCountryName` `"Armenia"` — and
 * `BankName` still `""`. The key is never omitted on either shape.
 *
 * So the open question is now `BankName` alone, and it is narrower than "is this
 * object ever populated": a payment settled through an Armenian issuer came back
 * with the country identified and the bank unnamed. Whether any payment ever
 * carries a bank name is unknown.
 *
 * A second completed payment, on a second order, reproduced it: the object
 * arrived present and populated on the run CONVENTIONS.md §4.24 records, with
 * `BankName` still `""`. That corroborates the paragraph above rather than
 * narrowing it — the empty string is still the only value this member has ever
 * carried.
 *
 * fromWireArray() below carries no mapping of its own: it delegates to the
 * hydrator under Support\, where every wire key in this package is spelled
 * exactly once, so that a mapping transcribed twice cannot drift — the failure
 * CONVENTIONS.md §2 and §4.8 exist to prevent. These objects stay carriers.
 *
 * @todo unverified — see CONVENTIONS.md §13 (`BankName` only: it is the empty string on every observation, including a completed payment)
 */
final readonly class BankInfo
{
    /**
     * @param string|null $bankName        Wire key `BankName`. Observed as the empty string on every body so far, probe B2 and probe case P3's completed payment alike.
     * @param string|null $bankCountryCode Wire key `BankCountryCode`. Its code system is undeclared: observed as `"AM"` on probe case P3, which is what ISO 3166-1 alpha-2 would give for the `"Armenia"` in the sibling field, but the gateway declares nothing and one value is not a code system.
     * @param string|null $bankCountryName Wire key `BankCountryName`. Observed as `"Armenia"` on probe case P3 — the one member of this object seen carrying free text.
     */
    public function __construct(
        public ?string $bankName,
        public ?string $bankCountryCode,
        public ?string $bankCountryName,
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
     * Reachable directly, though in practice PaymentDetailsResponse builds this
     * from its nested `BankInfo` object.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function fromWireArray(array $data): self
    {
        return ResponseHydrator::bankInfo($data);
    }
}
