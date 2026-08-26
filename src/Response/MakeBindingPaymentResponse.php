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
 * The answer to MakeBindingPayment — a payment charged against a stored card.
 *
 * Model of record: `MakeBindingPaymentResponse` in
 * docs/api-reference/api-surface.json, thirty-one fields, every name
 * verbatim (CONVENTIONS.md §4.8), including the lowercase `rrn` this model
 * shares with PaymentDetailsResponse.
 *
 * **This is not a silent server-to-server charge.** The model carries `AcsUrl`,
 * `PaReq` and `TermUrl` — a 3-D Secure challenge triple (CONVENTIONS.md §4.12)
 * — so a binding payment can end with the cardholder being sent to an issuer's
 * access control server rather than with a completed charge. A caller that
 * ignores those three fields will report a challenge as a result.
 *
 * MakeBindingPayment charges a card and is never retried (CONVENTIONS.md §4.5).
 * On a timeout the transport raises IndeterminateStateException and the caller
 * reconciles through GetPaymentDetails.
 *
 * The field list is PaymentDetailsResponse's, minus `ClientEmail`,
 * `ExchangeRate`, `BankInfo` and `ResponseMessage`, plus the three 3-D Secure
 * fields. The two models are not merged and neither extends the other:
 * inheritance is forbidden in src/ (CONVENTIONS.md §5), they are two models
 * upstream, and the bank may change one without the other.
 *
 * The same two pairings apply as on PaymentDetailsResponse, and for the same
 * reasons — see that class for the full argument:
 *
 * - **Enum fields are stored twice**, raw plus a nullable enum resolved with
 *   tryFrom(). Null means "a value this SDK does not yet know"
 *   (CONVENTIONS.md §4.6).
 * - **Monetary fields are stored twice**, raw text plus a nullable Amount built
 *   only when this response's own `Currency` resolves. `Currency::default()` is
 *   never substituted, and no inexact numeric value ever reaches a property
 *   here (CONVENTIONS.md §4.7).
 *
 * **Every field but `ResponseCode` is nullable.** This model has no
 * `ResponseMessage`: the manifest does not declare one, which is why the usual
 * exempt pair is only half present here.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model.
 *
 * Never observed at all. Bindings are not permitted on the sandbox client and
 * no probe has ever called this operation (CONVENTIONS.md §13), so every type
 * and format below is declared rather than seen.
 *
 * Most of those fields are also PaymentDetailsResponse's, and that model's
 * docblocks now carry live observations of them. **Nothing observed there is
 * claimed here.** A sibling endpoint declaring the same field name is not
 * evidence that it fills it the same way, and the notes below say "observed on
 * GetPaymentDetails" where they say anything at all.
 *
 * `CardNumber` and `ExpDate` are declared fields, as are `ClientName` and
 * `ProcessingIP` — the cardholder's name and the address a payment was made
 * from. None of those four values may reach a log record or an exception message
 * (CONVENTIONS.md §6).
 *
 * @todo unverified — see CONVENTIONS.md §13 (MakeBindingPayment has never been called; the 3-D Secure triple has never been seen populated)
 */
final readonly class MakeBindingPaymentResponse
{
    /**
     * @param string|null       $paymentId          Wire key `PaymentID` — uppercase `ID`, unlike GetPaymentIdResponse's `PaymentId`.
     * @param ResponseCode      $responseCode       Wire key `ResponseCode`. Declared `string` on this endpoint (CONVENTIONS.md §4.3).
     * @param string|null       $amountRaw          Wire key `Amount`, the raw scalar as text.
     * @param Amount|null       $amount             Wire key `Amount`, paired with `Currency`. Null when the currency is absent or unrecognised.
     * @param string|null       $approvedAmountRaw  Wire key `ApprovedAmount`, the raw scalar as text. On GetPaymentDetails this is the authorised total and does not move as refunds are taken against it; unobserved here.
     * @param Amount|null       $approvedAmount     Wire key `ApprovedAmount`, paired with `Currency`.
     * @param string|null       $approvalCode       Wire key `ApprovalCode`. Never log it (CONVENTIONS.md §6).
     * @param string|null       $cardNumber         Wire key `CardNumber`. Never log it (CONVENTIONS.md §6).
     * @param string|null       $clientName         Wire key `ClientName`. On GetPaymentDetails this holds the cardholder's own name, not the merchant's; the same is assumed here and neither is observed on this endpoint. Personal data; never log it (CONVENTIONS.md §6).
     * @param string|null       $currencyRaw        Wire key `Currency`. An ISO 4217 numeric code as text.
     * @param Currency|null     $currency           Wire key `Currency`, resolved. Null for a code this SDK does not know.
     * @param string|null       $dateTime           Wire key `DateTime`. Format undeclared and unobserved on this endpoint; GetPaymentDetails was observed carrying `d/m/Y H:i:s` (probe case P3), which is a hint and not a contract. Carried as text, unparsed.
     * @param string|null       $depositedAmountRaw Wire key `DepositedAmount`, the raw scalar as text. On GetPaymentDetails this is the remaining refundable balance rather than the captured total; unobserved here.
     * @param Amount|null       $depositedAmount    Wire key `DepositedAmount`, paired with `Currency`.
     * @param string|null       $description        Wire key `Description`. On GetPaymentDetails the gateway overwrites this with processor text and echoes the merchant's own submission in `TrxnDescription` (probe case P3). Whether this endpoint does the same is unobserved; do not read it as the text you sent.
     * @param string|null       $mdOrderId          Wire key `MDOrderID` — uppercase `ID`.
     * @param string|null       $merchantId         Wire key `MerchantId` — lowercase `d`.
     * @param string|null       $terminalId         Wire key `TerminalId` — lowercase `d`.
     * @param string|null       $orderId            Wire key `OrderID` — uppercase `ID`.
     * @param string|null       $paymentStateRaw    Wire key `PaymentState`.
     * @param PaymentState|null $paymentState       Wire key `PaymentState`, resolved.
     * @param int|string|null   $paymentTypeRaw     Wire key `PaymentType`. An integer in JSON, the member name in XML.
     * @param PaymentType|null  $paymentType        Wire key `PaymentType`, resolved.
     * @param string|null       $primaryRc          Wire key `PrimaryRC`.
     * @param string|null       $expDate            Wire key `ExpDate`. Card data; never log it (CONVENTIONS.md §6).
     * @param string|null       $processingIp       Wire key `ProcessingIP` — uppercase `IP`. On GetPaymentDetails this is the address the payment was made from; the same is assumed here and neither is observed on this endpoint. Personal data; never log it (CONVENTIONS.md §6).
     * @param string|null       $orderStatusRaw     Wire key `OrderStatus`. Declared `string`.
     * @param OrderStatus|null  $orderStatus        Wire key `OrderStatus`, resolved. Null for any non-numeric value, which is never cast to an integer.
     * @param string|null       $cardHolderId       Wire key `CardHolderID` — uppercase `ID`.
     * @param string|null       $bindingId          Wire key `BindingID` — uppercase `ID`.
     * @param string|null       $refundedAmountRaw  Wire key `RefundedAmount`, the raw scalar as text.
     * @param Amount|null       $refundedAmount     Wire key `RefundedAmount`, paired with `Currency`.
     * @param string|null       $opaque             Wire key `Opaque`. Echoed from InitPayment.
     * @param string|null       $trxnDescription    Wire key `TrxnDescription`. On GetPaymentDetails this is where the merchant's submitted `Description` comes back verbatim (probe case P3); unobserved here.
     * @param string|null       $rrn                Wire key `rrn` — lowercase, on this model and PaymentDetailsResponse only.
     * @param string|null       $actionCode         Wire key `ActionCode`.
     * @param string|null       $acsUrl             Wire key `AcsUrl`. The issuer's 3-D Secure access control server; present means a challenge, not a charge.
     * @param string|null       $paReq              Wire key `PaReq`. The 3-D Secure payer authentication request, posted to the AcsUrl.
     * @param string|null       $termUrl            Wire key `TermUrl`. Where the access control server returns the cardholder.
     */
    public function __construct(
        public ?string $paymentId,
        public ResponseCode $responseCode,
        public ?string $amountRaw,
        public ?Amount $amount,
        public ?string $approvedAmountRaw,
        public ?Amount $approvedAmount,
        public ?string $approvalCode,
        public ?string $cardNumber,
        public ?string $clientName,
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
        public ?string $acsUrl,
        public ?string $paReq,
        public ?string $termUrl,
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
        return ResponseHydrator::makeBindingPaymentResponse($data);
    }
}
