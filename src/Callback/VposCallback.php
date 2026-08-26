<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Callback;

use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

use function is_string;

use Psr\Http\Message\ServerRequestInterface;

use function trim;

/**
 * The five parameters the gateway puts on the BackURL redirect, carried as
 * identifiers and nothing else.
 *
 * **Everything in this object is unsigned, untrusted input.** The gateway
 * redirects the customer's browser to the merchant's BackURL with
 * `orderID`, `resposneCode`, `paymentID`, `opaque` and `description`, and it
 * attaches no HMAC, no signature, no nonce and no shared secret (CONVENTIONS.md
 * §4.10). Anyone who knows the callback URL can type `resposneCode=00` into it,
 * and the merchant's web server cannot tell that request apart from a genuine
 * redirect. A merchant who releases goods on the strength of a value in this
 * query string gives them away for free.
 *
 * The only way to learn what happened to a payment is a server-side
 * `GetPaymentDetails` round trip, made from the merchant's backend over the
 * merchant's own credentials — `Vpos::verify()`, or `payments()->details()`
 * directly. That answer comes from the gateway to the merchant, over TLS, and
 * is not routed through anything the customer's browser touched.
 *
 * So this class has no success accessor, and it is not an oversight to be
 * filled in later: there is nothing here that could implement one honestly.
 *
 * `resposneCode` and `description` *are* read and retained — throwing away a
 * diagnostic that support staff need is not a security improvement — but they
 * are reachable only through untrustedDiagnostics(), which returns an array.
 * That shape is chosen for how it reads at the call site. A merchant writing
 * `$callback->untrustedDiagnostics()['resposneCode'] === '00'` has written down,
 * in the expression itself, that the comparison proves nothing; the same test
 * spelled `$callback->responseCode() === '00'` looks like a fact. The security
 * property is not that the value cannot be read. It is that it cannot be read
 * while believing it means something.
 *
 * Nothing here builds the package's response-code value object, and nothing
 * reachable from here does either — its name is deliberately absent from this
 * whole directory, and a test greps for it. That type answers "did this
 * succeed", and every route from forged query data to that question is closed
 * by not opening it.
 *
 * The five keys are pinned literally and matched case-sensitively — including
 * `resposneCode`, which is the gateway's own misspelling and therefore the wire
 * format (CONVENTIONS.md §4.8). `responseCode`, `paymentId` and every case
 * variant are not accepted, and that is the intended trade: if the gateway ever
 * changes a spelling or its case, this package breaks loudly at the callback
 * rather than quietly reporting every payment as having no identifiers.
 *
 * All five arrived under exactly these spellings on the first *successful*
 * callback this package has ever seen, probe case P2, with `resposneCode`
 * hex-confirmed a second time. That corroborates the pinning; it does not
 * remove the exposure, since one capture cannot promise the next.
 *
 * The pinning is on the **keys**. Nothing here pins a value's case, and the
 * `paymentID` constant below records why that distinction is load-bearing.
 */
final readonly class VposCallback
{
    /**
     * Wire spelling: `orderID`. The merchant's own order identifier, echoed.
     */
    private const string KEY_ORDER_ID = 'orderID';

    /**
     * Wire spelling: `paymentID`. **The value arrives lowercase here**, whatever
     * InitPayment returned: probe case P1 answered with an uppercase GUID and
     * the callback of P2 echoed the identical identifier entirely in lowercase.
     * Neither form is normalised, per CONVENTIONS.md §4.8, so a merchant
     * comparing this against a stored PaymentID must fold the case or compare
     * the `orderID` instead — a case-sensitive comparison reports a mismatch on
     * a payment that is perfectly in order.
     *
     * Checked for blankness only, never for shape. CONVENTIONS.md §4.12 records
     * the 36-character uppercase GUID as an observation, not a contract, so a
     * length or pattern test here would reject a value the gateway is entitled
     * to send — and would reject it in the one place where the alternative is
     * simply to ask the gateway what it means. That restraint was tested by P2
     * and held: a case check written from the uppercase observation would have
     * thrown on the first real callback this package ever received.
     */
    private const string KEY_PAYMENT_ID = 'paymentID';

    /**
     * Wire spelling: `opaque`. Optional; whatever the merchant sent to
     * InitPayment, handed back unchanged and unauthenticated.
     */
    private const string KEY_OPAQUE = 'opaque';

    /**
     * Wire spelling: `resposneCode` — the gateway's typo, reproduced verbatim
     * per CONVENTIONS.md §4.8. Never "corrected" here or anywhere.
     */
    private const string KEY_RESPOSNE_CODE = 'resposneCode';

    /**
     * Wire spelling: `description`. Undocumented, optional, and not only an
     * error channel: it carries `Internal server error` on a failed callback and
     * `Operation Approved ` on a successful one (probe case P2) — with that
     * trailing space, which is the gateway's and is passed through untouched.
     *
     * It is also a third, unrelated field. It is neither the `Description` a
     * merchant sends to InitPayment nor the `Description` that comes back from
     * GetPaymentDetails; nothing the merchant wrote appears here.
     */
    private const string KEY_DESCRIPTION = 'description';

    /**
     * Private, so the two named constructors are the only way in.
     *
     * Both identifiers are non-blank by construction; both diagnostics are
     * exactly what arrived, including an empty string, which is distinguishable
     * from an absent parameter and is not normalised into one.
     */
    private function __construct(
        private string $paymentId,
        private string $orderId,
        private ?string $opaque,
        private ?string $resposneCode,
        private ?string $description,
    ) {}

    /**
     * Reads a raw query array — `$_GET`, or any framework's equivalent.
     *
     * `$_GET` is `array<array-key, mixed>`: PHP's query parser produces an
     * array, not a string, for `?paymentID[]=x`, and a PSR-7 implementation may
     * produce null or a nested array for the same input. A non-string is
     * therefore a case that occurs, and it is rejected rather than coerced.
     * Casting an array to string is a warning and a wrong value; treating it as
     * absent would silently absorb a query the gateway never produced, which is
     * the same failure mode the case-sensitive key matching above exists to
     * prevent. Rejecting says what happened.
     *
     * A missing, empty or whitespace-only `paymentID` or `orderID` is rejected
     * too: a callback with no identifiers has nothing to verify, and there is
     * no safe reading of it further down. Garbage from an attacker producing a
     * ValidationException here is the intended behaviour, not a rough edge.
     *
     * @param array<array-key, mixed> $query
     *
     * @throws ValidationException
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            paymentId: self::readRequired($query, self::KEY_PAYMENT_ID),
            orderId: self::readRequired($query, self::KEY_ORDER_ID),
            opaque: self::readOptional($query, self::KEY_OPAQUE),
            resposneCode: self::readOptional($query, self::KEY_RESPOSNE_CODE),
            description: self::readOptional($query, self::KEY_DESCRIPTION),
        );
    }

    /**
     * The same thing for a PSR-7 server request, so no framework is assumed.
     *
     * `psr/http-message` is already a dependency of this package, so this adds
     * none. It delegates rather than duplicating: one parsing rule, one place
     * the five spellings are written, and fromServerRequest() cannot drift away
     * from fromQuery() by construction.
     *
     * @throws ValidationException
     */
    public static function fromServerRequest(ServerRequestInterface $request): self
    {
        return self::fromQuery($request->getQueryParams());
    }

    /**
     * The gateway's payment identifier — the handle for the round trip that
     * actually answers what happened.
     *
     * Returned exactly as it arrived. Whitespace is not stripped: blankness is
     * the only property checked, so trimming would be this class quietly
     * repairing a value it has just declared it does not validate, and a
     * repaired identifier that then fails at the gateway is harder to diagnose
     * than the one that was sent.
     */
    public function paymentId(): string
    {
        return $this->paymentId;
    }

    /**
     * The merchant's own order identifier, as the callback reports it.
     *
     * Untrusted like everything else here. It is the merchant's own value, but
     * it arrived over a forgeable channel, so it identifies which order to look
     * up — it does not attest that this callback belongs to that order. The
     * cross-check against the verified response's OrderID is what does that.
     */
    public function orderId(): string
    {
        return $this->orderId;
    }

    /**
     * The merchant-supplied opaque value, echoed back. Null when absent.
     *
     * CONVENTIONS.md §4.4: a repeated InitPayment on the same OrderID
     * overwrites the earlier call's parameters, and this field is how that was
     * observed. It is a correlation aid, never an authenticator.
     */
    public function opaque(): ?string
    {
        return $this->opaque;
    }

    /**
     * The two forgeable values, behind a name that says so.
     *
     * Suitable for a log line or a support ticket. Not suitable for a branch
     * that releases goods, refunds money, or marks an order paid. Keys are the
     * wire spellings, `resposneCode` included, because renaming them here would
     * make this package the only place that spelling is wrong.
     *
     * The values are verbatim, and `description` in particular is worth handling
     * as text rather than as a token: probe case P2 sent `Operation Approved `
     * with a trailing space. It is not trimmed here — see the constant's
     * docblock — so a merchant who compares this string instead of logging it
     * will find that even the string they expected does not match.
     *
     * @return array{resposneCode: ?string, description: ?string}
     */
    public function untrustedDiagnostics(): array
    {
        return [
            self::KEY_RESPOSNE_CODE => $this->resposneCode,
            self::KEY_DESCRIPTION => $this->description,
        ];
    }

    /**
     * An identifier the callback cannot be used without.
     *
     * Absent, null, empty and whitespace-only are all blank and all rejected
     * identically — the distinction has no consequence, since none of them
     * names a payment. A non-string is rejected as malformed instead, because
     * "blank" would be a false description of an array.
     *
     * Only the field name reaches the message, never the value
     * (CONVENTIONS.md §6).
     *
     * @param array<array-key, mixed> $query
     * @param non-empty-string        $key
     *
     * @throws ValidationException
     */
    private static function readRequired(array $query, string $key): string
    {
        $value = $query[$key] ?? null;

        if ($value === null) {
            throw ValidationException::blankValue($key);
        }

        if (!is_string($value)) {
            throw ValidationException::malformedValue($key, 'a single string query parameter');
        }

        if (trim($value) === '') {
            throw ValidationException::blankValue($key);
        }

        return $value;
    }

    /**
     * A parameter that may legitimately be absent.
     *
     * Absent is null. Present is returned verbatim, empty string included: the
     * gateway sending `description=` is not the same event as the gateway
     * omitting it, and collapsing the two would discard the only signal
     * available about which happened.
     *
     * A non-string still throws. An optional parameter is optional in whether
     * it appears, not in what shape it takes, and a query the gateway could not
     * have produced is not made trustworthy by the fact that the impossible
     * part of it was a diagnostic. Note that this is deliberately stricter than
     * Support\ResponseHydrator, which stringifies an integer: that leniency
     * exists because CONVENTIONS.md §4.12 records the JSON response bodies
     * drifting between declared types. A query string has no types to drift
     * between — every parameter in it is text on the wire — so there is nothing
     * here to accommodate.
     *
     * @param array<array-key, mixed> $query
     * @param non-empty-string        $key
     *
     * @throws ValidationException
     */
    private static function readOptional(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw ValidationException::malformedValue($key, 'a single string query parameter');
        }

        return $value;
    }
}
