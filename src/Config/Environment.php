<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Config;

use DavitVardanyan\AmeriabankVpos\Enum\Language;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;

/**
 * Which Ameriabank installation the SDK talks to, and every URL that follows
 * from that choice.
 *
 * This is an enum that does not live in `Enum/`, and the placement is
 * deliberate: `Enum/` holds types whose values appear on the wire — Currency,
 * Language, PaymentType, OrderStatus, PaymentState — while `Config/` holds
 * configuration types regardless of which PHP construct expresses them. The
 * environment is never serialised into a request; it decides where the request
 * is sent. It sits beside Credentials, which is the other half of what the
 * transport needs before it can dispatch anything. The split is CONVENTIONS.md
 * §7's placement rule — "`Enum/` holds wire-value types · `Config/` holds
 * configuration" — and not a per-class inventory, so it decides this case
 * without anybody having to keep a list of names current.
 *
 * The four URL methods are the only place in the package that knows a host
 * name. REST and SOAP live on different hosts in both environments
 * (CONVENTIONS.md §4.13), which is why reportingSoapUrl() is not derived
 * from restBaseUrl().
 *
 * Only the Test hosts have ever been reached. See the Production case.
 *
 * @todo unverified — see CONVENTIONS.md §13 (every Production URL)
 */
enum Environment: string
{
    /**
     * The sandbox. Both of its hosts have answered real probe traffic.
     */
    case Test = 'test';

    /**
     * The live gateway.
     *
     * No request has ever been sent to either production host. Both URLs are
     * transcribed from CONVENTIONS.md §4.13 and have not been observed to
     * resolve, let alone to accept a payment. They are structurally consistent
     * with the test hosts — the same paths under an un-prefixed name — but
     * consistency is not confirmation.
     *
     * @todo unverified — see CONVENTIONS.md §13
     */
    case Production = 'production';

    /**
     * The REST root, with its trailing slash.
     *
     * The slash is part of the value rather than something the callers append,
     * so that concatenation is the only operation any of them needs.
     */
    public function restBaseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://servicestest.ameriabank.am/VPOS/',
            self::Production => 'https://services.ameriabank.am/VPOS/',
        };
    }

    /**
     * The full URL of a REST operation.
     *
     * The doubled VPOS in `{base}api/VPOS/{operation}` is correct and must not
     * be tidied away. `docs/api-reference/api-surface.json` — the
     * specification of record, per CONVENTIONS.md §2 — lists the routes as
     * `POST api/VPOS/InitPayment` and so on, and those routes are relative to a
     * base that already ends in `/VPOS/`. InitPayment on the sandbox is
     * therefore `https://servicestest.ameriabank.am/VPOS/api/VPOS/InitPayment`.
     *
     * The operation name is not checked against a list of known operations.
     * Which operations exist is the transport's business, and a list here would
     * be a second place to update every time one is added.
     *
     * It is percent-encoded all the same. Every operation the manifest names is
     * plain ASCII letters, so the encoding is an identity transform on all of
     * them; it exists so that the answer to "can this argument reshape the URL"
     * is no by construction rather than no by inspection of the call sites.
     *
     * @param string $operation Bare operation name, e.g. InitPayment
     *
     * @throws ValidationException on a blank or whitespace-only operation
     */
    public function apiUrl(string $operation): string
    {
        if (trim($operation) === '') {
            throw ValidationException::blankValue('operation');
        }

        return $this->restBaseUrl() . 'api/VPOS/' . rawurlencode($operation);
    }

    /**
     * Where to send the customer's browser to pay.
     *
     * Shape from CONVENTIONS.md §4.13: `{base}Payments/Pay?id={PaymentID}&lang={am|ru|en}`.
     *
     * A blank PaymentID throws rather than producing `Pay?id=&lang=en`. This is
     * not a hypothetical input. A failed InitPayment answers with
     * `"PaymentID": ""` — an empty string, never null (CONVENTIONS.md §4.12) —
     * and HTTP 200 carries no business meaning (§4.1), so a caller who did not
     * check the response code holds a perfectly ordinary-looking empty string.
     * Refusing it here turns a customer sent to a broken page into an exception
     * raised on the server, in the request that made the mistake.
     *
     * Blankness is the only check. The 36-character uppercase GUID is what the
     * gateway has been observed to emit, not what it has contracted to emit,
     * and a format assertion built on that observation would reject a valid
     * PaymentID the day the bank changes its generator. That restraint has since
     * been vindicated by the gateway itself: the BackURL callback of probe case
     * P2 echoes the identifier P1 returned uppercase in **lowercase**, so a case
     * assertion here would already be rejecting an identifier the gateway sends.
     *
     * The ID is percent-encoded on the way in. The Language value is not: it
     * comes from a three-member enum whose values are `am`, `ru` and `en`.
     *
     * On Test, this URL renders. It did not for most of this package's life —
     * it redirected straight back to the BackURL with
     * `resposneCode=0999&description=Internal+server+error`, a sandbox fault
     * reported to the bank rather than a defect in the URL. The bank fixed it,
     * and a payment has since gone through the page end to end: registered by
     * probe case P1, paid on the rendered form, and answered by the BackURL
     * callback of P2 with `resposneCode=00`.
     *
     * ## The optional `type`
     *
     * `$type` appends `&type={backing int}` and is claimed to select the card
     * form directly — `13` opening Apple Pay, `5` the Visa/MasterCard/ArCa
     * form — with omission leaving the gateway to detect the device. Passing
     * null appends nothing at all rather than an empty `&type=`, because an
     * empty value is a value and this SDK has no idea how the page reads one.
     *
     * The parameter is in neither the vendor PDF nor `api-surface.json`; its
     * only source is a third-party Laravel package's documentation, which makes
     * it the weakest-sourced claim in this package. No probe has sent it — and
     * that is now a gap rather than an impossibility, since the page it would be
     * sent to renders. See CONVENTIONS.md §13 before relying on it.
     *
     * @throws ValidationException on a blank or whitespace-only PaymentID
     *
     * @todo unverified — see CONVENTIONS.md §13 (the `type` query parameter: now testable, still untested)
     */
    public function paymentPageUrl(string $paymentId, Language $language, ?PaymentType $type = null): string
    {
        if (trim($paymentId) === '') {
            throw ValidationException::blankValue('PaymentID');
        }

        return $this->restBaseUrl()
            . 'Payments/Pay?id=' . rawurlencode($paymentId)
            . '&lang=' . $language->value
            . ($type instanceof PaymentType ? '&type=' . $type->value : '');
    }

    /**
     * The SOAP reporting endpoint — GetTransactionList and
     * GetProblemTransactions.
     *
     * A different host from the REST base in both environments, and a complete
     * URL rather than a base: the service exposes its operations through the
     * SOAPAction header, not through the path (CONVENTIONS.md §4.11).
     */
    public function reportingSoapUrl(): string
    {
        return match ($this) {
            self::Test => 'https://testpayments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc',
            self::Production => 'https://payments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc',
        };
    }
}
