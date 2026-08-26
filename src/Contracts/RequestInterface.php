<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Contracts;

/**
 * What the transport needs to know about a request, and nothing else.
 *
 * CONVENTIONS.md §5 permits an interface only where extension is genuinely
 * intended. This one is not an extension point for third parties — it is the
 * seam between the eleven request DTOs and HttpTransport::send(), and it exists
 * because the transport must answer four questions about a request it has never
 * heard of: where to POST it, whether a failed attempt may be repeated, which
 * credential set to merge into it, and what its body is. Without the interface
 * the transport would need a union of eleven class names in its signature, and
 * adding a twelfth operation would mean editing the transport — the one file
 * whose correctness is hardest to re-establish.
 *
 * It is deliberately not marked `@internal`. CONVENTIONS.md §5 puts that
 * annotation on `Http\` and `Support\`, and §7's placement rule names those
 * same two directories as the internal half and everything else — `Contracts\`
 * included — as public API. The reason is structural rather than clerical: the
 * eleven implementors are public surface, a caller holds one and passes it to a
 * public client method, so the type they hold cannot be internal. Marking it
 * `@internal` would tell a consumer's static analyser that a value it is
 * expected to construct and pass belongs to the package's private half.
 *
 * Implementations are `final readonly` classes constructed with named
 * arguments, per CONVENTIONS.md §5. Nothing here is a hook: none of these four
 * methods takes an argument, and none of them is a decision the caller gets to
 * influence — see isIdempotent() in particular.
 */
interface RequestInterface
{
    /**
     * The operation name, which is the last path segment of the REST endpoint.
     *
     * Spelled as the gateway spells it. CONVENTIONS.md §4.9: `ActivateBinding`
     * and `DeactivateBinding` are singular, and the plural forms the vendor
     * PDF's table of contents lists return 404.
     */
    public function operation(): string;

    /**
     * Whether a failed attempt at this request may be repeated.
     *
     * CONVENTIONS.md §4.5 fixes the answer per operation and states that it is
     * not user configurable, which is why it is a method on the request rather
     * than a transport option: there is no argument to pass and no setter to
     * call, so no caller can arrange for a capture, a refund, a cancellation or
     * a binding payment to be sent twice.
     *
     * A true is a promise about the operation, not about any particular
     * attempt. `InitPayment` returns true because the gateway keys it on
     * (ClientID, OrderID), but §4.4 records that a repeat call overwrites the
     * earlier call's parameters — so the retry is safe only if the transport
     * resends the byte-identical body it sent the first time. That obligation
     * belongs to the transport; this method only says that a retry is permitted
     * at all.
     */
    public function isIdempotent(): bool;

    /**
     * Whether the gateway expects `ClientID` alongside `Username` and
     * `Password` for this operation.
     *
     * True selects Credentials::merchantFields(), false selects userFields().
     * The answer is the manifest's: a request model in
     * `docs/api-reference/api-surface.json` that lists a `ClientID`
     * field returns true, and one that does not returns false. The split is not
     * a rule anyone can derive from the operation's meaning — the four
     * PaymentID-addressed operations omit it and the seven others carry it,
     * which reads as an accident of the bank's own models rather than a design
     * — so it is read off the specification of record and asserted against it
     * by tests/Request/RequestContractTest.php rather than reasoned about here.
     *
     * §4.12 records that the gateway ignores unknown request fields silently,
     * so sending `ClientID` where the model does not declare it would not be
     * reported by anything. That is precisely why this is pinned to the
     * manifest: the failure mode of getting it wrong in that direction is
     * complete silence.
     */
    public function requiresClientId(): bool;

    /**
     * The request body, as wire keys to wire-ready scalars.
     *
     * Keys are the manifest's spellings verbatim (CONVENTIONS.md §4.8). Values
     * are already in their wire form — an Amount is a decimal string, never a
     * float; an enum is its backing value; a null optional is omitted rather
     * than emitted as null.
     *
     * Credentials are absent by design and no implementation may add them: the
     * transport merges them at dispatch, so a request object a caller builds
     * never holds a secret (CONVENTIONS.md §5, §6).
     *
     * @return array<string, scalar>
     */
    public function toArray(): array;
}
