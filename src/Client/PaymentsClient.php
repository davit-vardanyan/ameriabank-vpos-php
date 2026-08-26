<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Client;

use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\CancelPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\ConfirmPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\GetPaymentIdRequest;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Request\PaymentDetailsRequest;
use DavitVardanyan\AmeriabankVpos\Request\RefundPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Response\CancelPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\ConfirmPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPaymentIdResponse;
use DavitVardanyan\AmeriabankVpos\Response\InitPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use DavitVardanyan\AmeriabankVpos\Response\RefundPaymentResponse;

/**
 * The payment lifecycle: register an order, read its outcome, then capture,
 * cancel or refund it.
 *
 * Six operations, and every method here has the same four-step body — build the
 * request, send it, hydrate the answer, return a typed object. Nothing else
 * belongs in one. The transport already decides retry eligibility from the
 * request's own isIdempotent() and its NEVER_RETRY list (CONVENTIONS.md §4.5),
 * the request DTOs already validate their own fields, and the hydrator already
 * owns every wire spelling (§4.8). A client that re-decided any of those would
 * be a second place to be wrong.
 *
 * ## Why some methods take primitives and others take a DTO
 *
 * The rule is mechanical, not a matter of taste: a method takes primitives when
 * its request model in docs/api-reference/api-surface.json carries two or fewer
 * business fields, credentials excluded. On this client that puts `InitPayment`
 * (nine fields) on a DTO and the other five on primitives. Nobody chose case by
 * case, so nobody has to defend a case.
 *
 * ## Why nothing on this client takes a callback
 *
 * CONVENTIONS.md §4.10: the BackURL carries `orderID`, `resposneCode`,
 * `paymentID`, `opaque` and `description`, and it is unsigned — no HMAC, no
 * shared secret, so anyone can forge `resposneCode=00`. The only route to a
 * payment's outcome is a server-side round trip, and Vpos::verify() is that
 * round trip with the order identity pinned to it; details() is the same round
 * trip without the pin. A merchant handling a callback calls verify(). Nothing
 * in this class takes a callback at all — the VposCallback checkpoint type
 * reaches the entry point instead, which is where the order cross-check lives.
 *
 * Nothing here may take a callback parameter or build a ResponseCode from
 * caller input, and a reflection test in tests/Client asserts that much
 * structurally — three forbidden parameter names compared case-insensitively
 * and one forbidden parameter type — rather than trusting this paragraph.
 *
 * ## What a caller catches
 *
 * Everything this class can raise implements VposExceptionInterface, so one
 * catch covers the package and nothing else (CONVENTIONS.md §5). The types
 * behind it are ValidationException for a field this SDK rejects before
 * dispatch, ApiException and its subclasses for a business refusal,
 * GatewayFaultException for the ASP.NET envelope that carries no response code
 * (§4.2), TransportException for an exchange that never completed,
 * IndeterminateStateException for one that never completed on an operation that
 * may have moved money, ConfigurationException for a misconfigured client, and
 * SerializationException for a body that cannot be mapped.
 *
 * IndeterminateStateException is deliberately not a TransportException. A
 * confirm(), cancel() or refund() that times out may already have moved money;
 * §4.5 says reconcile through details() and never guess.
 */
final readonly class PaymentsClient
{
    /**
     * HttpTransport is `@internal` (CONVENTIONS.md §5), so this constructor is
     * too: a public one would export a type consumers must not depend on.
     * Construct this through Vpos::payments().
     *
     * No hydrator is injected. ResponseHydrator's methods are all static and it
     * holds no state, so there is no handle to pass; each response DTO's
     * fromWireArray() is the public one-line delegation to it, and that is the
     * seam used below.
     *
     * @internal
     */
    public function __construct(private HttpTransport $transport) {}

    /**
     * Registers an order and returns the PaymentID the payment page is keyed on.
     *
     * §4.4: the gateway keys this on (ClientID, OrderID) and a repeat call
     * returns the same PaymentID — but the later call's parameters overwrite the
     * earlier ones. It is therefore the one state-changing operation the
     * transport may retry, and only by resending the bytes it already sent.
     *
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function init(InitPaymentRequest $request): InitPaymentResponse
    {
        return InitPaymentResponse::fromWireArray($this->transport->send($request));
    }

    /**
     * Reads a payment's server-side state.
     *
     * The only trustworthy answer to "was I paid?". §4.10 makes the BackURL
     * forgeable, so a callback is a prompt to call this, never a result.
     *
     * @throws ValidationException    when $paymentId is blank
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function details(string $paymentId): PaymentDetailsResponse
    {
        return PaymentDetailsResponse::fromWireArray(
            $this->transport->send(new PaymentDetailsRequest($paymentId)),
        );
    }

    /**
     * Captures an authorised payment, in full or in part.
     *
     * $amount is an Amount and never a float: §4.7 forbids a PHP float from
     * touching money, and the DTO renders it as a decimal string for the wire.
     *
     * Never retried (§4.5) — it captures funds. A transport failure raises
     * IndeterminateStateException; reconcile with details().
     *
     * @throws ValidationException    when $paymentId is blank
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function confirm(string $paymentId, Amount $amount): ConfirmPaymentResponse
    {
        return ConfirmPaymentResponse::fromWireArray(
            $this->transport->send(new ConfirmPaymentRequest($paymentId, $amount)),
        );
    }

    /**
     * Cancels a payment before it is captured.
     *
     * Never retried (§4.5) — it changes state.
     *
     * @throws ValidationException    when $paymentId is blank
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function cancel(string $paymentId): CancelPaymentResponse
    {
        return CancelPaymentResponse::fromWireArray(
            $this->transport->send(new CancelPaymentRequest($paymentId)),
        );
    }

    /**
     * Returns money for a captured payment, in full or in part.
     *
     * Never retried (§4.5) — it moves money.
     *
     * The response carries no amounts at all, so a caller tracking a partial
     * refund calls details() afterwards. There it will find `RefundedAmount`
     * accumulating and `DepositedAmount` decrementing to the balance still
     * refundable — observed across probe cases P3, P4.1b and P4.3b — so neither
     * figure has to be computed here. Over-refunding is refused with
     * `ResponseCode` `"07"` (P4.5) rather than faulting.
     *
     * @throws ValidationException    when $paymentId is blank
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function refund(string $paymentId, Amount $amount): RefundPaymentResponse
    {
        return RefundPaymentResponse::fromWireArray(
            $this->transport->send(new RefundPaymentRequest($paymentId, $amount)),
        );
    }

    /**
     * Looks a PaymentID up from the merchant's own OrderID.
     *
     * Named for what it does rather than for the endpoint. `paymentId()` would
     * read as an accessor on this client, which is the one thing it is not.
     *
     * The answer arrives under the wire key `PaymentId` — this is the only model
     * in the package spelling it with a lowercase `d`, and it is not corrected
     * (§4.8).
     *
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function paymentIdForOrder(int $orderId): GetPaymentIdResponse
    {
        return GetPaymentIdResponse::fromWireArray(
            $this->transport->send(new GetPaymentIdRequest($orderId)),
        );
    }
}
