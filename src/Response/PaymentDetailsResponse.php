<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\OrderStatus;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentState;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * The answer to GetPaymentDetails — the only trustworthy account of what
 * happened to a payment.
 *
 * Model of record: `PaymentDetailsResponse` in
 * docs/api-reference/api-surface.json, thirty fields, every name
 * reproduced verbatim (CONVENTIONS.md §4.8). This is the round trip
 * CONVENTIONS.md §4.10 requires after an unsigned BackURL callback: anyone can
 * forge `resposneCode=00` in a query string, so nothing but this response
 * decides an outcome.
 *
 * **Two spellings and one nested model are load-bearing.** `rrn` is lowercase
 * where every neighbouring field is PascalCase, and `MDOrderID`, `MerchantId`
 * and `TerminalId` disagree with each other about the casing of `Id`. All are
 * the wire format, and probe case P3 observed all four of those keys on a live
 * body rather than only in the manifest. `BankInfo` is a nested object reachable
 * only through the manifest's `models` map, and P3 observed it too.
 *
 * **Enum-typed fields are stored twice: the raw wire value, and a nullable
 * enum** (CONVENTIONS.md §4.6). Null on the enum means "a value this SDK does
 * not yet know", never "absent" — that is what the raw is for. The gateway adds
 * enum members without notice, so `from()` is never called on wire data
 * anywhere in this package.
 *
 * **Monetary fields are stored twice as well, for the same reason.** An Amount
 * is left null when the response's own `Currency` is absent or unrecognised,
 * and `Currency::default()` is never substituted for it: that default is an SDK
 * assumption rather than observed behaviour. Without a raw companion that rule
 * would silently discard the number: probe B2 returned `"Currency":""`
 * alongside four decimal fields, which is precisely the case where the Amount
 * cannot be built. Both shapes are on the record now. B2's blank currency is
 * one; probe case P3 is the other — a success-coded body carrying
 * `"Currency":"051"` beside the same four decimal fields, from which the typed
 * Amounts are built. P3 also shows the raw companion earning its place a second
 * way, for a reason that has nothing to do with the currency: its
 * `RefundedAmount` is `0.0`, and an Amount cannot be zero, so the typed property
 * is null on that body while the raw records what arrived. The raw is a string
 * because JSON decoding a decimal literal yields the platform's inexact numeric
 * type, and CONVENTIONS.md §4.7 forbids that type from touching a monetary
 * value.
 *
 * `ExchangeRate` is deliberately not an Amount. A rate is not money: it has no
 * currency, and its scale is not the ISO 4217 exponent of anything. It is kept
 * as text for the same reason the raw amounts are.
 *
 * **Every field but `ResponseCode` is nullable**, and the reason is requiredness
 * rather than ignorance. The manifest declares none of these fields required,
 * and two response shapes are now on the record which differ in what they carry.
 * Probe B2 is a failed lookup: all thirty fields present, every string empty,
 * every decimal `0.0`, `PaymentType` an integer 5, `ResponseCode` the string
 * "550". Probe case P3 is a completed payment: the same thirty fields, most of
 * them populated — `OrderID` `"4565037"`, `Currency` `"051"`, `OrderStatus`
 * `"2"`, `PaymentState` `"payment_deposited"` — while `ClientEmail`,
 * `CardHolderID`, `BindingID` and `BankInfo.BankName` are still the empty
 * string. Neither body carried a `ResponseMessage` key, which is why this model
 * has no `responseMessage` property: the manifest does not declare one and the
 * wire has not sent one on either shape. `Description` is this endpoint's only
 * diagnostic slot as a result — see the parameter note below.
 *
 * The manifest states no requiredness anywhere: its "Additional information"
 * column reads "None." for every field of every model, and its "Description"
 * column is empty throughout. That empty column is the manifest's own
 * documentation column and says nothing about the `Description` *field*, whose
 * observed semantics are recorded on the parameter below.
 *
 * `CardNumber`, `ExpDate` and `ApprovalCode` are declared fields and carrying
 * them is correct, and P3 is the first observation of real values in all three.
 * `ClientName` and `ProcessingIP` are personal data on the same footing. None of
 * those five values may reach a log record or an exception message
 * (CONVENTIONS.md §6), and no example in this package may show one.
 *
 * A constructor and nothing else; the wire mapping lives in one place, the
 * hydrator under Support\.
 */
final readonly class PaymentDetailsResponse
{
    /**
     * `PaymentType` is the one enum field whose raw is not a string. The
     * manifest's JSON sample renders it unquoted and probes B2 and P3 both
     * observed `int(5)`, while the XML representation — which CONVENTIONS.md §4.12
     * confirms is honoured — renders the member *name*, `None`, not its number.
     * Both forms are therefore reachable and the union carries either
     * without coercion.
     *
     * `OrderStatus` is declared `string` while the SDK's enum is int-backed
     * (its members come from the vendor PDF's Table 2). The enum is attempted
     * only when the raw is entirely numeric; a value such as
     * `payment_deposited` leaves it null and keeps the raw, because casting
     * that string to an integer yields 0, which is `Registered` — a completed
     * payment silently reported as unpaid.
     *
     * **`OrderStatus` and `PaymentState` are two fields, not two spellings of
     * one.** That is worth stating because this package once carried the
     * opposite question — whether the status "arrives as `"2"` or as
     * `payment_deposited`" — and the question was malformed. Probe case P3
     * returns both keys in the same body: `"OrderStatus":"2"` beside
     * `"PaymentState":"payment_deposited"`, and after a refund P4.1b returns
     * `"4"` beside `"payment_refunded"`. The numeric one resolves to this
     * package's int-backed OrderStatus and the named one to PaymentState, so
     * the `ctype_digit` guard sends each to the right enum and neither raw is
     * ever offered to the other's.
     *
     * @param string|null       $amountRaw          Wire key `Amount`, the raw scalar as text.
     * @param Amount|null       $amount             Wire key `Amount`, paired with `Currency`. Null when the currency is absent or unrecognised.
     * @param string|null       $approvedAmountRaw  Wire key `ApprovedAmount`, the raw scalar as text. The authorised total, and the one monetary field observed not to move: `10.0` on probe cases P3, P4.1b and P4.3b alike, across two partial refunds. This — not `DepositedAmount` — is what a merchant compares against the amount they asked for.
     * @param Amount|null       $approvedAmount     Wire key `ApprovedAmount`, paired with `Currency`.
     * @param string|null       $approvalCode       Wire key `ApprovalCode`. Never log it (CONVENTIONS.md §6).
     * @param string|null       $cardNumber         Wire key `CardNumber`. Masked by the gateway — first-six, two mask characters, last-four, twelve characters in all, as probe case P3 observed. Never log it (CONVENTIONS.md §6).
     * @param string|null       $clientName         Wire key `ClientName`. The cardholder's own name, not the merchant's, as probe case P3 observed. Personal data; never log it (CONVENTIONS.md §6).
     * @param string|null       $clientEmail        Wire key `ClientEmail`. The cardholder's; never log it (CONVENTIONS.md §6). Observed as the empty string on P3.
     * @param string|null       $currencyRaw        Wire key `Currency`. An ISO 4217 numeric code as text; observed empty on probe B2 and as `"051"` on probe case P3.
     * @param Currency|null     $currency           Wire key `Currency`, resolved. Null for a code this SDK does not know — including the empty string.
     * @param string|null       $dateTime           Wire key `DateTime`. Format undeclared; observed as `d/m/Y H:i:s` on probe case P3. Carried as text, unparsed — a format seen once is not a contract.
     * @param string|null       $depositedAmountRaw Wire key `DepositedAmount`, the raw scalar as text. The remaining refundable balance, not the captured total: it *decrements*, `10.0` then `6.0` then `3.0` across probe cases P3, P4.1b and P4.3b. Compare against `ApprovedAmount` for the captured figure.
     * @param Amount|null       $depositedAmount    Wire key `DepositedAmount`, paired with `Currency`.
     * @param string|null       $description        Wire key `Description`. **Not the text the merchant submitted** — the gateway overwrites it with the processor's own diagnostic (`Approved. - Payment post authorized` on probe case P3, `Approved. - Refunded payment back to client card` after a refund on P4.1b). The submitted text comes back in `TrxnDescription`. This endpoint sends no `ResponseMessage`, so this is its only diagnostic slot, on a success-coded body as much as on a failing one.
     * @param string|null       $mdOrderId          Wire key `MDOrderID` — uppercase `ID`. Observed as a UUID and byte-for-byte identical to `rrn` on probe case P3.
     * @param string|null       $merchantId         Wire key `MerchantId` — lowercase `d`, unlike its neighbour above. Confirmed on the wire by probe case P3, so the spelling is load-bearing on the same footing as CONVENTIONS.md §4.8's list and must never be "corrected".
     * @param string|null       $terminalId         Wire key `TerminalId` — lowercase `d`. Confirmed on the wire by probe case P3.
     * @param string|null       $orderId            Wire key `OrderID` — uppercase `ID`, and declared `string` here (CONVENTIONS.md §4.12), unlike GetPendingTransactionsResponse's `OrderId`. Observed populated and quoted on probe case P3, matching the `orderID` the callback of P2 carried; observed as the empty string on probe B2's failed lookup.
     * @param string|null       $paymentStateRaw    Wire key `PaymentState`. Observed as `payment_deposited` on probe case P3 and `payment_refunded` on P4.1b — the vendor PDF's Table 2 names are real, and they arrive alongside `OrderStatus`, not instead of it.
     * @param PaymentState|null $paymentState       Wire key `PaymentState`, resolved. Null for a state this SDK does not know.
     * @param int|string|null   $paymentTypeRaw     Wire key `PaymentType`. An integer in JSON, the member name in XML.
     * @param PaymentType|null  $paymentType        Wire key `PaymentType`, resolved. Null for a member this SDK does not know — the bank fills the gaps at 8, 9, 10, 15 and 16 without notice.
     * @param string|null       $primaryRc          Wire key `PrimaryRC`. Declared `string` (CONVENTIONS.md §4.12) and observed as one — `"0"` on probe case P3, quoted, on an approved payment.
     * @param ResponseCode      $responseCode       Wire key `ResponseCode`. Declared `string` on this endpoint (CONVENTIONS.md §4.3, §4.12); observed as "550" on probe B2 and as "00" on probe case P3.
     * @param string|null       $expDate            Wire key `ExpDate`. Card data; never log it (CONVENTIONS.md §6). Format observed as `Ym` on probe case P3 — a format, not a value.
     * @param string|null       $processingIp       Wire key `ProcessingIP` — uppercase `IP`. The address the payment was made from, as probe case P3 observed. Personal data; never log it (CONVENTIONS.md §6).
     * @param string|null       $orderStatusRaw     Wire key `OrderStatus`. Declared `string` (CONVENTIONS.md §4.12) and observed as a numeric string — `"2"` on probe case P3, `"4"` on P4.1b.
     * @param OrderStatus|null  $orderStatus        Wire key `OrderStatus`, resolved. Null = a value the SDK does not yet know, including any non-numeric one.
     * @param string|null       $cardHolderId       Wire key `CardHolderID` — uppercase `ID`.
     * @param string|null       $bindingId          Wire key `BindingID` — uppercase `ID`.
     * @param string|null       $refundedAmountRaw  Wire key `RefundedAmount`, the raw scalar as text. It accumulates across partial refunds: `0.0` then `4.0` then `7.0` on probe cases P3, P4.1b and P4.3b, against a `DepositedAmount` decrementing by the same steps. No arithmetic is needed to reconcile the two — the gateway publishes both.
     * @param Amount|null       $refundedAmount     Wire key `RefundedAmount`, paired with `Currency`. Null when the raw is `0.0`, since an Amount cannot be zero — the shape probe case P3 returned.
     * @param string|null       $opaque             Wire key `Opaque`. Echoed from InitPayment; the echo is what proved CONVENTIONS.md §4.4's parameter overwrite. The channels it has been observed on are named rather than counted: the InitPayment request (L1), the BackURL callback (P2, L2), this response (P3, L3), the RefundPayment responses on both success and refusal (P4.1, P4.3, P4.5, L4.1, L4.3), and the CancelPayment response (P5). One run carried a single value through the first four of those, byte-identical each time. A browser also carried it across the payment page between L1 and L2, but nothing read `Opaque` *at* the page, so the page is a hop the value survived and not a channel it was observed on.
     * @param string|null       $trxnDescription    Wire key `TrxnDescription`. **This is where the merchant's submitted `Description` comes back**, verbatim: probe case P1 sent `Probe order 4565037` and P3 returned it here unchanged, while the response's own `Description` had been overwritten with processor text.
     * @param string|null       $rrn                Wire key `rrn` — lowercase, on this model and MakeBindingPaymentResponse only. The acquirer's retrieval reference number. Observed as a UUID identical to `MDOrderID` on probe case P3, not the hyphen-separated shape the vendor PDF samples: a merchant reconciling on both is reconciling on one value twice.
     * @param string|null       $actionCode         Wire key `ActionCode`. Declared `string` and observed as one — `"0"` on the approved payment of probe case P3.
     * @param string|null       $exchangeRate       Wire key `ExchangeRate`, the raw scalar as text. Not an Amount: a rate carries no currency. Observed as `0.0` on probe case P3, which is what building an Amount from it would have had to make sense of.
     * @param BankInfo|null     $bankInfo           Wire key `BankInfo`. A nested object; observed present with empty members on probe B2 and partly populated on probe case P3 — `BankCountryCode` and `BankCountryName` carry values there, `BankName` is still the empty string.
     */
    public function __construct(
        public ?string $amountRaw,
        public ?Amount $amount,
        public ?string $approvedAmountRaw,
        public ?Amount $approvedAmount,
        public ?string $approvalCode,
        public ?string $cardNumber,
        public ?string $clientName,
        public ?string $clientEmail,
        public ?string $currencyRaw,
        public ?Currency $currency,
        public ?string $dateTime,
        public ?string $depositedAmountRaw,
        public ?Amount $depositedAmount,
        public ?string $description,
        public ?string $mdOrderId,
        public ?string $merchantId,
        public ?string $terminalId,
        public ?string $orderId,
        public ?string $paymentStateRaw,
        public ?PaymentState $paymentState,
        public int|string|null $paymentTypeRaw,
        public ?PaymentType $paymentType,
        public ?string $primaryRc,
        public ResponseCode $responseCode,
        public ?string $expDate,
        public ?string $processingIp,
        public ?string $orderStatusRaw,
        public ?OrderStatus $orderStatus,
        public ?string $cardHolderId,
        public ?string $bindingId,
        public ?string $refundedAmountRaw,
        public ?Amount $refundedAmount,
        public ?string $opaque,
        public ?string $trxnDescription,
        public ?string $rrn,
        public ?string $actionCode,
        public ?string $exchangeRate,
        public ?BankInfo $bankInfo,
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
        return ResponseHydrator::paymentDetailsResponse($data);
    }
}
