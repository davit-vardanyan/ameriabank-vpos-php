<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * One stored card binding, as carried inside a GetBindingsResponse.
 *
 * Model of record: `CardBindingFiled` in
 * docs/api-reference/api-surface.json — reachable only through that
 * manifest's `models` map, via `GetBindingsResponse`'s `referenced_models`
 * entry. No endpoint's field list mentions it.
 *
 * **The class name is misspelled on purpose.** The bank's C# model is
 * `CardBindingFiled`, the collection that holds it is `CardBindingFileds`, and
 * the active flag is `IsAvtive`. All three are the wire format, and
 * CONVENTIONS.md §4.8 forbids correcting them. The PHP property below is
 * `isActive` because a property name is this package's own, but the wire
 * spelling `IsAvtive` is recorded here and reproduced by the hydrator.
 *
 * Every field is nullable. The manifest states no requiredness — "Additional
 * information" reads "None." for every field of every model.
 *
 * Never observed with content. Bindings are not permitted on the sandbox client
 * (CONVENTIONS.md §13): every `GetBindings` probe — A11.4, A11.5, A12, B6.1,
 * B6.2 — returned `ResponseCode` "20", "Client payment type BindingMainRest is
 * not available", with `CardBindingFileds` present but empty. So the field
 * names below are declared by the manifest and have never carried a value.
 *
 * `CardPan` and `ExpDate` are card data. Carrying them is required — the
 * manifest declares them — but CONVENTIONS.md §6 forbids their values reaching
 * a log record or an exception message, and no example anywhere in this package
 * may show one.
 *
 * @todo unverified — see CONVENTIONS.md §13
 */
final readonly class CardBindingFiled
{
    /**
     * @param string|null $cardHolderId Wire key `CardHolderID`.
     * @param string|null $cardPan      Wire key `CardPan`. Card data — see CONVENTIONS.md §6.
     * @param string|null $expDate      Wire key `ExpDate`. Card data — see CONVENTIONS.md §6. Format undeclared, and unobserved on this model; GetPaymentDetails was observed carrying `Ym` (probe case P3), which is a hint about a sibling field and not a contract about this one.
     * @param bool|null   $isActive     Wire key `IsAvtive` — the bank's spelling, not a typo in this file.
     */
    public function __construct(
        public ?string $cardHolderId,
        public ?string $cardPan,
        public ?string $expDate,
        public ?bool $isActive,
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
     * Reachable directly, though in practice GetBindingsResponse builds these
     * from the `CardBindingFileds` collection.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function fromWireArray(array $data): self
    {
        return ResponseHydrator::cardBindingFiled($data);
    }
}
