<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Client;

use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Request\ActivateBindingRequest;
use DavitVardanyan\AmeriabankVpos\Request\DeactivateBindingRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetBindingsRequest;
use DavitVardanyan\AmeriabankVpos\Request\MakeBindingPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Response\ActivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\DeactivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetBindingsResponse;
use DavitVardanyan\AmeriabankVpos\Response\MakeBindingPaymentResponse;

/**
 * Stored-card bindings: charge one, list them, and switch one on or off.
 *
 * Same shape as PaymentsClient — build, send, hydrate, return — and the same
 * division of labour. In particular the {5, 6} restriction CONVENTIONS.md §4.6
 * puts on GetBindings, ActivateBinding and DeactivateBinding is **not**
 * re-checked here: the request DTOs reject a non-binding `PaymentType` in their
 * own constructors, before anything is dispatched. Repeating the check would
 * give the rule two homes, and the copy that drifts is the one nobody reads.
 *
 * That validation is client-side because there is nothing useful on the other
 * side of it: probes A11.1/.2/.3/.6 sent PaymentType 0, 1, 3 and 7 to
 * GetBindings and each came back as HTTP 500 carrying ASP.NET's
 * unhandled-exception page — no ResponseCode to read, and §4.2 forbids retrying
 * it or calling it a transport fault.
 *
 * ## The method that is not called list()
 *
 * `all()` returns the bindings. `list` is a language construct; it parses as a
 * method name but reads badly at the call site.
 *
 * ## Nothing here is verified against a live gateway
 *
 * CONVENTIONS.md §13: bindings are not permitted on the current sandbox client,
 * so endpoints 8–11 have never returned a success body. Everything below is
 * built to the manifest.
 *
 * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the current sandbox client)
 */
final readonly class BindingsClient
{
    /**
     * HttpTransport is `@internal` (CONVENTIONS.md §5), so this constructor is
     * too. Construct this through Vpos::bindings().
     *
     * @internal
     */
    public function __construct(private HttpTransport $transport) {}

    /**
     * Charges a card the customer has already bound.
     *
     * Takes the DTO because the model carries eight business fields, counted
     * from the manifest.
     *
     * Never retried (§4.5) — it charges a card. And it is not the silent
     * server-to-server capture the name suggests: §4.12 records that the
     * response carries `AcsUrl`, `PaReq` and `TermUrl`, a 3-D Secure challenge
     * triple the caller must still put in front of the cardholder.
     *
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function pay(MakeBindingPaymentRequest $request): MakeBindingPaymentResponse
    {
        return MakeBindingPaymentResponse::fromWireArray($this->transport->send($request));
    }

    /**
     * Lists the bindings registered for the merchant under one payment type.
     *
     * The collection arrives under the wire key `CardBindingFileds` and each
     * element's active flag under `IsAvtive`. Both misspellings are the wire
     * format and neither is corrected (§4.8); the hydrator maps them to
     * idiomatic property names.
     *
     * @throws ValidationException    when $type is not a binding-capable PaymentType
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function all(PaymentType $type): GetBindingsResponse
    {
        return GetBindingsResponse::fromWireArray(
            $this->transport->send(new GetBindingsRequest($type)),
        );
    }

    /**
     * Re-enables a binding.
     *
     * Singular endpoint name — the plural form in the vendor PDF's table of
     * contents returns 404 (§4.9).
     *
     * @throws ValidationException    when $cardHolderId is blank or $type is not binding-capable
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function activate(string $cardHolderId, PaymentType $type): ActivateBindingResponse
    {
        return ActivateBindingResponse::fromWireArray(
            $this->transport->send(new ActivateBindingRequest($cardHolderId, $type)),
        );
    }

    /**
     * Disables a binding without deleting it.
     *
     * @throws ValidationException    when $cardHolderId is blank or $type is not binding-capable
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function deactivate(string $cardHolderId, PaymentType $type): DeactivateBindingResponse
    {
        return DeactivateBindingResponse::fromWireArray(
            $this->transport->send(new DeactivateBindingRequest($cardHolderId, $type)),
        );
    }
}
