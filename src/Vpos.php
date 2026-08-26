<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Enum\Language;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

use function trim;

/**
 * Entry point for the Ameriabank vPOS client — construct one of these and
 * everything the package offers is reachable from it.
 *
 * ```php
 * $vpos = new Vpos(
 *     credentials: new Credentials('000000', 'placeholder-user', 'placeholder-pass'),
 *     environment: Environment::Test,
 * );
 *
 * // A failed InitPayment answers with an empty PaymentID rather than null, and
 * // paymentPageUrl() refuses a blank one instead of building a broken page.
 * $paymentId = $vpos->payments()->init($request)->paymentId ?? '';
 * $url       = $vpos->paymentPageUrl($paymentId, Language::Armenian);
 * $details   = $vpos->payments()->details($paymentId);
 * ```
 *
 * ## What this class is, and what it deliberately is not
 *
 * It is a composition root first: it owns one HttpTransport, hands the same
 * instance to three operation clients, and answers where to send the customer's
 * browser without touching the network. Beyond that it does exactly one thing —
 * verify() runs the callback round trip and pins the response's OrderID to the
 * callback's, so it does read one response field and enforce one rule on it, and
 * it does need the network to do so. That method is here because the check spans
 * an untrusted callback and a client's answer and belongs to neither; it is the
 * licence, not the precedent. No request is built here, no response is hydrated
 * here, and no other business rule lives here; every one of those has a home
 * already and a second home would be a second place to drift, so a method that
 * wants to read a second response field belongs behind a client.
 *
 * ## Credentials are never a caller's field
 *
 * `ClientID`, `Username` and `Password` are supplied once, here, and injected by
 * the transport at dispatch (CONVENTIONS.md §5). No request DTO a caller
 * constructs carries them, and the transport rejects a request body that does.
 *
 * ## No mutable state
 *
 * The class itself is `readonly`, so every property is immutable after
 * construction and no dynamic property can be attached. There is no setter, no
 * `withEnvironment()`, no runtime reconfiguration — a consumer that needs both
 * environments constructs two instances. That is not asceticism: the transport,
 * its redactor and all three clients are wired at construction, so a mutator
 * would have to either rebuild the graph and invalidate handles a caller is
 * already holding, or leave half the graph pointing at the old configuration.
 *
 * ## Environment has no default
 *
 * It is required, and second. Defaulting to Test means a misconfigured
 * deployment silently takes no money and finds out at reconciliation weeks
 * later; defaulting to Production means a developer who forgets it hits live
 * infrastructure. Forcing the choice has neither failure mode.
 *
 * ## Nothing here trusts a callback
 *
 * The BackURL is unsigned — no HMAC, no shared secret, so anyone can forge
 * `resposneCode=00` (CONVENTIONS.md §4.10). The only route to a payment's
 * outcome is a server-side round trip, and verify() is that round trip with the
 * order identity pinned to it. No method here or on any client accepts a
 * `resposneCode`, an `opaque`, or the package's response-code value object
 * built from caller input — that type's name is deliberately absent from this
 * file, and a test greps for it. The one method that takes a callback at all
 * takes the VposCallback marker type and reads two identifiers off it.
 */
final readonly class Vpos
{
    /**
     * Wire protocol version this client targets.
     */
    public const string PROTOCOL_VERSION = '3.1';

    /**
     * The payment lifecycle client. One instance, built in the constructor.
     */
    private PaymentsClient $payments;

    /**
     * The stored-card bindings client. One instance, built in the constructor.
     */
    private BindingsClient $bindings;

    /**
     * The reporting client. One instance, built in the constructor.
     */
    private ReportsClient $reports;

    /**
     * Where the payment page URL comes from. Kept because paymentPageUrl()
     * needs it and the transport does not expose the one it holds.
     */
    private Environment $environment;

    /**
     * Wires the transport and the three clients.
     *
     * Everything after the environment is optional and mirrors HttpTransport's
     * own constructor, argument for argument. A null PSR-18 client or PSR-17
     * factory falls through to `php-http/discovery`, and a failed discovery
     * surfaces as ConfigurationException rather than as a bare
     * `Http\Discovery\Exception` — the transport translates it, so a consumer
     * catching VposExceptionInterface catches a missing HTTP client too.
     *
     * $maxAttempts is passed through unchanged and is bounded by the transport
     * at 1..5. It is not a retry policy: which operations may be retried at all
     * is fixed by CONVENTIONS.md §4.5 and is not configurable from here
     * or anywhere.
     *
     * Exactly one HttpTransport is constructed and the same instance goes to
     * all three clients, so all three share one logger, one redactor and one
     * attempt budget.
     *
     * @param int $maxAttempts Attempt budget for retryable operations only, 1..5
     *
     * @throws ConfigurationException when no PSR-18 client or PSR-17 factory can be discovered
     * @throws ValidationException    when $maxAttempts is outside 1..5
     */
    public function __construct(
        Credentials $credentials,
        Environment $environment,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        int $maxAttempts = 3,
    ) {
        $transport = new HttpTransport(
            $credentials,
            $environment,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $logger,
            $maxAttempts,
        );

        $this->environment = $environment;
        $this->payments = new PaymentsClient($transport);
        $this->bindings = new BindingsClient($transport);
        $this->reports = new ReportsClient($transport);
    }

    /**
     * The payment lifecycle: init, details, confirm, cancel, refund, and the
     * OrderID-to-PaymentID lookup.
     *
     * Returns the instance built in the constructor, so `$vpos->payments()
     * === $vpos->payments()`.
     */
    public function payments(): PaymentsClient
    {
        return $this->payments;
    }

    /**
     * Stored-card bindings: pay with one, list them, activate, deactivate.
     *
     * Not permitted on the current sandbox client, so nothing behind this has
     * ever returned a success body (CONVENTIONS.md §13).
     *
     * @todo unverified — see CONVENTIONS.md §13 (bindings are not permitted on the current sandbox client)
     */
    public function bindings(): BindingsClient
    {
        return $this->bindings;
    }

    /**
     * Reporting over a date range.
     *
     * One operation today, and that is a settled outcome rather than a pending
     * one. `GetTransactionList` is SOAP-only and is deferred from v1.0;
     * CONVENTIONS.md §13 records the deferral as blocked on the bank rather
     * than on the transport, and records the enquiry behind that — that a REST
     * equivalent has been asked for and no answer had come — as owner-supplied
     * and corroborated by nothing in this repository. A second method appears
     * here if and when that answer does.
     */
    public function reports(): ReportsClient
    {
        return $this->reports;
    }

    /**
     * Turns an untrusted BackURL callback into a verified payment state — the
     * one call a merchant handling a callback should make.
     *
     * ```php
     * $callback = VposCallback::fromQuery($_GET);
     * $details  = $vpos->verify($callback);
     * ```
     *
     * It asks the gateway, server-side over the merchant's own credentials,
     * what happened to the callback's PaymentID, and returns that answer. The
     * callback contributes an identifier and nothing else; no value from the
     * query string reaches the returned object, and none is compared against a
     * success condition here.
     *
     * ## Why the order cross-check exists
     *
     * This is the whole reason the method is not a one-line alias for
     * `payments()->details($callback->paymentId())`. The BackURL carries no
     * HMAC, no signature and no shared secret (CONVENTIONS.md §4.10), and a
     * PaymentID is not a secret either — it is handed to the customer's
     * browser. So an attacker can take a **genuine** PaymentID belonging to
     * somebody else's paid order, put it on the merchant's BackURL alongside
     * *their own* `orderID`, and the details round trip will answer,
     * truthfully, that a payment succeeded. A merchant who looks their order up
     * by the callback's `orderID` — which is the obvious thing to do, since
     * that is the merchant's own key — then ships goods against a stranger's
     * payment. Verifying the PaymentID without pinning it to the order
     * verifies nothing.
     *
     * So when the response names an `OrderID`, it must be *identical* to the
     * callback's or this throws. The comparison is strict and exact: no
     * trimming, no case folding, no numeric coercion. `'4565028'` and
     * `'04565028'` are different orders, and treating them as one would reopen
     * the hole the check exists to close. Neither side is normalised —
     * VposCallback stores the query value verbatim and the response carries
     * whatever the gateway sent.
     *
     * ## Absent and blank are different answers, and are treated differently
     *
     * When the response's `OrderID` is null the check is **skipped**, because a
     * check cannot be made against an absent value. That is still a real gap,
     * though a narrower one than it was: a completed payment has since been
     * observed, and it populates the field. Probe case P3 returns
     * `"OrderID":"4565037"` on a success-coded body, matching the `orderID` the
     * callback of P2 carried byte for byte — so the comparison branch below is
     * the one a real payment reaches, and it passes. One payment does not make a
     * guarantee, and a caller who has the order to hand should still compare it
     * against `$details->orderId`.
     *
     * When the response's `OrderID` is present but blank — empty, or
     * whitespace-only — this **refuses**, via
     * ValidationException::callbackOrderUnconfirmable(). A blank string is not an
     * absent field: it is a value the gateway chose to send, and treating it as
     * absent would silently remove order-identity protection. It is not a
     * hypothetical shape either — probe B2's failed lookup returned
     * `"OrderID":""` — and the hydrator passes an empty string through verbatim,
     * so `''` reaches here as `''` rather than as null. Failing closed is the
     * only answer that cannot be exploited. The refusal branch has still never
     * been reached in the life of this package, and now for a better reason than
     * before: the blank bodies were all failure-coded and raise inside details()
     * before reaching here, while the success-coded body that does reach here
     * carried a populated value.
     *
     * Blankness is judged the same way VposCallback judges it, so both ends of
     * the comparison agree on what "blank" means. That is the *only* place
     * trimming appears: a non-blank `OrderID` is still compared byte for byte,
     * untrimmed, per the paragraph above.
     *
     * The blank case gets its own factory rather than sharing
     * callbackOrderMismatch(), because "does not belong to the order it names"
     * would be false — nothing disagreed, the gateway named no order. Two
     * messages also let a merchant reading a log tell a gateway quirk from a
     * replay attempt; one message covering both would distinguish neither.
     *
     * ## The one request this method makes has never been made
     *
     * It sends `$callback->paymentId()` — the identifier as the *callback*
     * spelled it. The gateway does not spell it the same way in both channels:
     * InitPayment answered probe case P1 with an uppercase GUID and the BackURL
     * of P2 echoed the identical identifier in lowercase. Nothing is normalised
     * here, per CONVENTIONS.md §4.8, so what the callback said is what goes back.
     *
     * Every `GetPaymentDetails` call on record — P3, P4.1b, P4.3b, P6 — sent the
     * uppercase form, taken from the InitPayment response rather than from a
     * callback. So the gateway accepting the *lowercase* form is not established,
     * and it is the only request this method ever makes. Settling it takes one
     * probe. Until then a caller who wants certainty can pass the uppercase
     * PaymentID it stored at init time to `payments()->details()` and compare the
     * order itself, which is the check this method performs anyway.
     *
     * ## One entry point, and it takes the type
     *
     * There is no `verify(array $query)`, no `verifyFromGet()` and no optional
     * second parameter accepting raw query data. VposCallback *is* the
     * checkpoint — it is where the five wire spellings are pinned and where an
     * unusable callback is rejected — and an overload that skipped it would
     * remove the checkpoint's reason to exist.
     *
     * ## What a GatewayFaultException from here does and does not mean
     *
     * Everything `payments()->details()` can throw is thrown from here
     * unchanged, GatewayFaultException included. CONVENTIONS.md §4.2 records
     * that fault — HTTP 500 with the ASP.NET body `{"Message":"An error has
     * occurred."}` — observed from GetPaymentDetails against a payment that had
     * been registered and never attempted. §13 records that the pairing is
     * **not established**: probe B2 queried a payment in the same state and got
     * HTTP 200 carrying a business response code of `"550"` — an answer rather
     * than a fault — and what separates the two cases is unknown. No probe has
     * ever sent an unknown, foreign or malformed PaymentID to that endpoint
     * either, so no competing cause has been ruled out. A third outcome has since
     * been observed and it is the ordinary one: a payment that actually completed
     * answers HTTP 200 with `"00"` and a populated body (probe cases P3, P4.1b,
     * P4.3b, P6).
     *
     * A fault therefore means the gateway would not answer, and nothing more may
     * be read into it. In particular it is not evidence that the payment did not
     * happen, and it is not evidence that it did. Retrying is not the response —
     * §4.2 forbids retrying a 5xx, since it is indistinguishable from a client
     * input error. Reconcile later, and release nothing in the meantime.
     *
     * @param VposCallback $callback Parsed BackURL parameters. Untrusted; used for its PaymentID.
     *
     * @throws ValidationException     when the response's OrderID differs from the callback's, or is present but blank
     * @throws GatewayFaultException   when the gateway answers the fault envelope instead of a response code
     * @throws VposExceptionInterface  on any other failure of the details round trip
     *
     * @todo unverified — see CONVENTIONS.md §13 (this method's own request has never been made: it sends the callback's lowercase PaymentID, and every GetPaymentDetails call on record sent the uppercase form)
     */
    public function verify(VposCallback $callback): PaymentDetailsResponse
    {
        $details = $this->payments->details($callback->paymentId());
        $orderId = $details->orderId;

        if ($orderId === null) {
            return $details;
        }

        if (trim($orderId) === '') {
            throw ValidationException::callbackOrderUnconfirmable();
        }

        if ($orderId !== $callback->orderId()) {
            throw ValidationException::callbackOrderMismatch();
        }

        return $details;
    }

    /**
     * Where to send the customer's browser to pay.
     *
     * Lives here rather than on a client because it performs no API call — it
     * is a pure function of the environment, the PaymentID a prior
     * `payments()->init()` returned, and the two display choices below.
     *
     * The URL is not rebuilt here. Environment::paymentPageUrl() owns the
     * shape, the blank-ID refusal and the percent-encoding, and it stays the
     * one place that knows a host name.
     *
     * A blank PaymentID throws rather than producing a broken page. That is not
     * hypothetical: a failed InitPayment answers with `"PaymentID": ""` — empty
     * string, never null (§4.12) — and HTTP 200 carries no business meaning
     * (§4.1), so a caller who skipped the response code holds an
     * ordinary-looking empty string.
     *
     * ## $type is the weakest-sourced claim in this package
     *
     * It appends `&type={backing int}` and is claimed to select the card form
     * directly: `13` opens Apple Pay, `5` the Visa/MasterCard/ArCa form, and
     * omitting it leaves the gateway to detect the device. That claim comes
     * from a third-party Laravel package's documentation — not from the vendor
     * PDF, not from `api-surface.json`, and not from any probe, none of which
     * has ever sent it.
     *
     * The reason none had used to be that none could: the sandbox payment page
     * did not render. That reason is gone — the page rendered and a payment
     * completed through it (probe cases P1 through P6) — so `type` is now
     * testable and simply untested. The claim itself is exactly as
     * weakly sourced as it was. Read CONVENTIONS.md §13 before depending on it.
     *
     * @param string       $paymentId PaymentID from a prior init(); uppercase 36-character GUID as InitPayment returns it, though nothing here checks the case and a BackURL callback spells the same identifier in lowercase
     * @param Language     $language  Payment page interface language
     * @param ?PaymentType $type      Card form to open, or null to let the gateway detect
     *
     * @throws ValidationException on a blank or whitespace-only PaymentID
     *
     * @todo unverified — see CONVENTIONS.md §13 (the `type` query parameter: now testable, still untested)
     */
    public function paymentPageUrl(
        string $paymentId,
        Language $language = Language::English,
        ?PaymentType $type = null,
    ): string {
        return $this->environment->paymentPageUrl($paymentId, $language, $type);
    }
}
