<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;

/**
 * Registers a payment and obtains the PaymentID the payment page is keyed on.
 *
 * Model of record: `InitPaymentRequest` in
 * docs/api-reference/api-surface.json. Field names below are that
 * model's, verbatim (CONVENTIONS.md §4.8).
 *
 * `ClientID`, `Username` and `Password` are absent by design. The transport
 * merges them from Credentials::merchantFields() at dispatch; a request object
 * the caller constructs never holds a secret (CONVENTIONS.md §5, §6).
 *
 * `Currency` is not a constructor parameter. It is derived from the Amount, the
 * only object in the package that knows a currency, so the two cannot disagree
 * — an Amount in one currency carried alongside a `Currency` field naming
 * another is a money bug with no detectable symptom. It is therefore always
 * emitted rather than left to the server default described in CONVENTIONS.md
 * §4.12: every request this project has ever sent that carried a currency at
 * all carried "051" and every success-coded one of them was accepted, so
 * stating it is at least as well evidenced as omitting it, and it keeps the
 * serialised body a pure function of the object — which §4.4 requires, since
 * InitPayment may only be retried with a byte-identical body.
 *
 * Requiredness is not stated by the manifest: its "Additional information"
 * column reads "None." for every field of every model, and its "Description"
 * column is empty. `Amount`, `OrderID` and `BackURL` are required here because
 * the operation has no meaning without them — §4.4 keys idempotency on
 * (ClientID, OrderID), and §4.10 makes BackURL the only channel that returns
 * the customer. The rest are optional and omitted when null.
 */
final readonly class InitPaymentRequest implements RequestInterface
{
    /**
     * The manifest operation name, and the path segment under the REST base.
     */
    private const string OPERATION = 'InitPayment';

    private string $backUrl;

    private ?int $timeout;

    /**
     * A parameter is promoted unless a guard must run before it is assigned.
     *
     * That is the rule throughout src/Request/, and it is mechanical rather
     * than stylistic: promotion assigns on entry, which leaves no point at
     * which a guard could run first and so makes "the guard runs before
     * assignment" untestable — the same reasoning applied to Credentials, every
     * one of whose fields is checked. `BackURL` and `Timeout` are checked here,
     * so they are declared and assigned after their guards; the rest are
     * accepted as given, so promotion loses nothing a test could observe.
     * Rector's ClassPropertyAssignToConstructorPromotionRector enforces the
     * split from the other side — it promotes exactly the parameters no guard
     * reads, so the gate fails if this file drifts either way.
     *
     * `Timeout` is checked here because the gateway does not check it at all —
     * 1201, 0 and -1 are all accepted silently (CONVENTIONS.md §4.12), so a
     * typo becomes a payment page that expires at the wrong moment rather than
     * an error.
     *
     * `OrderID` carries no range check. No probe has established one, and
     * rejecting a value the gateway would have accepted is a worse failure than
     * forwarding it.
     *
     * **`$description` does not come back as `Description`.** It is sent under
     * that key and it is the merchant's own text, but GetPaymentDetails answers
     * with the *processor's* text in `Description` and returns what was sent here
     * in `TrxnDescription`. Probe case P1 sent `Probe order 4565037`; P3 read it
     * back from `TrxnDescription` while `Description` said
     * `Approved. - Payment post authorized`. A caller round-tripping their own
     * reference must read `$details->trxnDescription`.
     *
     * @param int         $orderId            Merchant order identifier; half of the idempotency key.
     * @param string      $backUrl            Where the gateway returns the customer. Untrusted on the way back — §4.10.
     * @param string|null $description        Free text for the payment page and the merchant's own records. Read it back from `PaymentDetailsResponse::$trxnDescription`, never from `$description`.
     * @param int|null    $timeout            Payment page lifetime in seconds, 1..1200.
     * @param int|null    $paymentServiceType Declared `integer` by the manifest, not `PaymentsEnum`; forwarded unnarrowed.
     *
     * @throws ValidationException
     */
    public function __construct(
        private Amount $amount,
        private int $orderId,
        string $backUrl,
        private ?string $description = null,
        private ?string $cardHolderId = null,
        private ?string $opaque = null,
        ?int $timeout = null,
        private ?int $paymentServiceType = null,
    ) {
        if (trim($backUrl) === '') {
            throw ValidationException::blankValue('BackURL');
        }

        if ($timeout !== null && ($timeout < 1 || $timeout > 1200)) {
            throw ValidationException::timeoutOutOfRange($timeout);
        }

        $this->backUrl = $backUrl;
        $this->timeout = $timeout;
    }

    public function operation(): string
    {
        return self::OPERATION;
    }

    /**
     * Idempotent, and the only operation in the package that is retryable while
     * also changing state: a repeat call returns the same PaymentID but
     * overwrites the earlier call's parameters (CONVENTIONS.md §4.4). Retrying
     * is therefore safe only with the exact bytes already sent.
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * The manifest's `InitPaymentRequest` lists `ClientID`, so the transport
     * merges Credentials::merchantFields(). §4.4 makes that load-bearing rather
     * than incidental: payment identity is keyed on (ClientID, OrderID), so the
     * merchant identifier is half the key this operation's idempotency
     * rests on.
     */
    public function requiresClientId(): bool
    {
        return true;
    }

    /**
     * Wire keys to scalar values, with absent optionals omitted rather than
     * sent as null.
     *
     * Key order follows the manifest's field order. Amount is a decimal string
     * because no IEEE 754 value may reach the wire (CONVENTIONS.md §4.7).
     *
     * The bytes this method emits have now been sent to Ameriabank and were
     * accepted. This docblock used to say the opposite, and correctly: probe
     * case P1, the first live payment, was hand-built and sent `"Amount": 10` as
     * a JSON integer, so nothing this method serialised had ever reached the
     * gateway. Case L1 changed that — this method produced `"Amount":"10.00"`,
     * a quoted decimal confirmed in hex on the captured request body, and the
     * gateway answered `ResponseCode` 1, `"OK"`. `OrderID` went beside it as a
     * bare JSON integer, so the mixed encoding is accepted exactly as it stands.
     *
     * That settles the encoding and not the precision: every fraction sent was
     * `.00`, no fractional amount has ever reached the gateway, and §13's
     * amount-precision entry is untouched by it.
     *
     * `Description` is written here and read back from a different key. See the
     * constructor's docblock.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $fields = [
            'Amount' => $this->amount->toDecimalString(),
            'OrderID' => $this->orderId,
            'BackURL' => $this->backUrl,
        ];

        if ($this->description !== null) {
            $fields['Description'] = $this->description;
        }

        $fields['Currency'] = $this->amount->currency()->value;

        if ($this->cardHolderId !== null) {
            $fields['CardHolderID'] = $this->cardHolderId;
        }

        if ($this->opaque !== null) {
            $fields['Opaque'] = $this->opaque;
        }

        if ($this->timeout !== null) {
            $fields['Timeout'] = $this->timeout;
        }

        if ($this->paymentServiceType !== null) {
            $fields['PaymentServiceType'] = $this->paymentServiceType;
        }

        return $fields;
    }
}
