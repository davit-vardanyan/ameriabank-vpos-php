<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Support;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\OrderStatus;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentState;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Response\ActivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\BankInfo;
use DavitVardanyan\AmeriabankVpos\Response\CancelPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\CardBindingFiled;
use DavitVardanyan\AmeriabankVpos\Response\ConfirmPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\DeactivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetBindingsResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPaymentIdResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPendingTransactionsResponse;
use DavitVardanyan\AmeriabankVpos\Response\InitPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\MakeBindingPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use DavitVardanyan\AmeriabankVpos\Response\RefundPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * The one place a wire key is spelled. Decoded arrays in, response DTOs out.
 *
 * Every response DTO's fromWireArray() is a one-line delegation to a method
 * here, so each wire spelling — `CardBindingFileds`, `IsAvtive`, `rrn`, and the
 * two casing breaks `PaymentId` and `OrderId` — exists as a literal exactly
 * once in the package. A mapping written twice is the failure CONVENTIONS.md §2
 * and §4.8 exist to prevent: the second copy drifts, and a drifted key does not
 * raise anything, it just yields a silently null field.
 *
 * ## Introspection contract
 *
 * The manifest conformance test reads this file as the single source of truth
 * for which wire keys the SDK consumes, so the shape below is load-bearing:
 *
 * - There is exactly one public method per response model, and its name is
 *   lcfirst() of the model name — `paymentDetailsResponse` for
 *   `PaymentDetailsResponse`, `cardBindingFiled` for `CardBindingFiled`.
 *   Every other public method is not a model method; `getPendingTransactionsList`
 *   is the only one, and it maps no fields of its own.
 * - Inside a model method, **every string literal is a wire key**. Operation
 *   names live in the OP_* constants and rejection wording lives in the private
 *   helpers, precisely so that a literal appearing inside a model method can be
 *   read as a field name with no filtering. Keep it that way.
 * - IGNORED_FIELDS lists any manifest field deliberately not mapped. It is
 *   empty: every field of every in-scope response model is carried.
 *
 * ## Rules this hydrator applies
 *
 * - **Unknown keys are ignored.** Fields are read by name, so a field the bank
 *   adds tomorrow is simply not looked at. Probe A9 confirmed the gateway
 *   ignores unknown request fields; this reciprocates.
 * - **An absent key and a null value are the same thing: null.** The manifest
 *   declares no response field required — its "Additional information" column
 *   reads "None." throughout — so absence is not an error. Two body shapes have
 *   since been seen carrying every declared field, probe B2's failed lookup and
 *   probe case P3's completed payment, and neither is a promise: a field
 *   observed present twice is still a field nothing guarantees. The two
 *   exceptions are `ResponseCode` and `ResponseMessage`,
 *   which are this package's two non-nullable response fields — see
 *   readRequiredText() for why their absence throws rather than inventing
 *   a value.
 * - **Enum fields are stored twice**: the raw wire value and a nullable enum
 *   from tryFrom() (CONVENTIONS.md §4.6). from() is never called on wire data —
 *   there is no from() anywhere in src/, and a test enforces that textually.
 * - **A monetary field becomes an Amount only if the response's own `Currency`
 *   field resolves**. Currency::default() is never called here: it is an SDK
 *   assumption, and stamping AMD on a foreign-currency transaction would
 *   produce a wrong amount that looks right. The raw scalar is kept either way,
 *   so nothing is lost when the Amount is null.
 * - **A shape that cannot be represented throws** SerializationException, naming
 *   the field and never its value (CONVENTIONS.md §6). An array where a scalar
 *   belongs is such a shape; an amount of zero is not — see buildAmount().
 *
 * @internal
 */
final class ResponseHydrator
{
    /**
     * Manifest fields deliberately not mapped, as model => field => reason.
     *
     * Empty, and expected to stay that way: every field of every in-scope
     * response model in docs/api-reference/api-surface.json is carried
     * onto a property. The constant exists so that dropping one in future is a
     * written decision with a reason attached rather than an omission, and so
     * the conformance test has somewhere to check.
     *
     * @var array<string, array<string, string>>
     */
    public const array IGNORED_FIELDS = [];

    private const string OP_INIT_PAYMENT = 'InitPayment';
    private const string OP_GET_PAYMENT_ID = 'GetPaymentId';
    private const string OP_GET_PENDING_TRANSACTIONS = 'GetPendingTransactions';
    private const string OP_CONFIRM_PAYMENT = 'ConfirmPayment';
    private const string OP_GET_PAYMENT_DETAILS = 'GetPaymentDetails';
    private const string OP_REFUND_PAYMENT = 'RefundPayment';
    private const string OP_CANCEL_PAYMENT = 'CancelPayment';
    private const string OP_GET_BINDINGS = 'GetBindings';
    private const string OP_ACTIVATE_BINDING = 'ActivateBinding';
    private const string OP_DEACTIVATE_BINDING = 'DeactivateBinding';
    private const string OP_MAKE_BINDING_PAYMENT = 'MakeBindingPayment';

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function initPaymentResponse(array $data): InitPaymentResponse
    {
        return new InitPaymentResponse(
            paymentId: self::readText($data, 'PaymentID', self::OP_INIT_PAYMENT),
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_INIT_PAYMENT),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_INIT_PAYMENT),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function getPaymentIdResponse(array $data): GetPaymentIdResponse
    {
        return new GetPaymentIdResponse(
            paymentId: self::readText($data, 'PaymentId', self::OP_GET_PAYMENT_ID),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_GET_PAYMENT_ID),
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_GET_PAYMENT_ID),
        );
    }

    /**
     * One element of the GetPendingTransactions collection, not the whole
     * answer. The operation returns a bare array with no envelope; see
     * getPendingTransactionsList().
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function getPendingTransactionsResponse(array $data): GetPendingTransactionsResponse
    {
        return new GetPendingTransactionsResponse(
            orderId: self::readIntOrText($data, 'OrderId', self::OP_GET_PENDING_TRANSACTIONS),
            clientName: self::readText($data, 'ClientName', self::OP_GET_PENDING_TRANSACTIONS),
            cardNumber: self::readText($data, 'CardNumber', self::OP_GET_PENDING_TRANSACTIONS),
            amountRaw: self::renderDecimal(self::readDecimalScalar($data, 'Amount', self::OP_GET_PENDING_TRANSACTIONS)),
            paymentDate: self::readText($data, 'PaymentDate', self::OP_GET_PENDING_TRANSACTIONS),
            errorMessage: self::readText($data, 'ErrorMessage', self::OP_GET_PENDING_TRANSACTIONS),
        );
    }

    /**
     * The collection form of GetPendingTransactions.
     *
     * This is not a model method and maps no field of its own: the operation's
     * samples are a bare JSON array and an `<ArrayOfGetPendingTransactionsResponse>`
     * wrapper, so there is no envelope object to model and none is invented.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<GetPendingTransactionsResponse>
     *
     * @throws SerializationException
     */
    public static function getPendingTransactionsList(array $rows): array
    {
        $transactions = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw SerializationException::unexpectedPayload(
                    self::OP_GET_PENDING_TRANSACTIONS,
                    'the transaction collection held an element that was not an object',
                );
            }

            $transactions[] = self::getPendingTransactionsResponse($row);
        }

        return $transactions;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function confirmPaymentResponse(array $data): ConfirmPaymentResponse
    {
        return new ConfirmPaymentResponse(
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_CONFIRM_PAYMENT),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_CONFIRM_PAYMENT),
            opaque: self::readText($data, 'Opaque', self::OP_CONFIRM_PAYMENT),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function refundPaymentResponse(array $data): RefundPaymentResponse
    {
        return new RefundPaymentResponse(
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_REFUND_PAYMENT),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_REFUND_PAYMENT),
            opaque: self::readText($data, 'Opaque', self::OP_REFUND_PAYMENT),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function cancelPaymentResponse(array $data): CancelPaymentResponse
    {
        return new CancelPaymentResponse(
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_CANCEL_PAYMENT),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_CANCEL_PAYMENT),
            opaque: self::readText($data, 'Opaque', self::OP_CANCEL_PAYMENT),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function activateBindingResponse(array $data): ActivateBindingResponse
    {
        return new ActivateBindingResponse(
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_ACTIVATE_BINDING),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_ACTIVATE_BINDING),
            cardHolderId: self::readText($data, 'CardHolderID', self::OP_ACTIVATE_BINDING),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function deactivateBindingResponse(array $data): DeactivateBindingResponse
    {
        return new DeactivateBindingResponse(
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_DEACTIVATE_BINDING),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_DEACTIVATE_BINDING),
            cardHolderId: self::readText($data, 'CardHolderID', self::OP_DEACTIVATE_BINDING),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function getBindingsResponse(array $data): GetBindingsResponse
    {
        return new GetBindingsResponse(
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_GET_BINDINGS),
            responseMessage: self::readRequiredText($data, 'ResponseMessage', self::OP_GET_BINDINGS),
            cardBindings: self::readCardBindings($data, 'CardBindingFileds', self::OP_GET_BINDINGS),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function cardBindingFiled(array $data): CardBindingFiled
    {
        return new CardBindingFiled(
            cardHolderId: self::readText($data, 'CardHolderID', self::OP_GET_BINDINGS),
            cardPan: self::readText($data, 'CardPan', self::OP_GET_BINDINGS),
            expDate: self::readText($data, 'ExpDate', self::OP_GET_BINDINGS),
            isActive: self::readFlag($data, 'IsAvtive', self::OP_GET_BINDINGS),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function bankInfo(array $data): BankInfo
    {
        return new BankInfo(
            bankName: self::readText($data, 'BankName', self::OP_GET_PAYMENT_DETAILS),
            bankCountryCode: self::readText($data, 'BankCountryCode', self::OP_GET_PAYMENT_DETAILS),
            bankCountryName: self::readText($data, 'BankCountryName', self::OP_GET_PAYMENT_DETAILS),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function paymentDetailsResponse(array $data): PaymentDetailsResponse
    {
        $currencyRaw = self::readText($data, 'Currency', self::OP_GET_PAYMENT_DETAILS);
        $currency = self::resolveCurrency($currencyRaw);
        $paymentTypeRaw = self::readIntOrText($data, 'PaymentType', self::OP_GET_PAYMENT_DETAILS);
        $paymentStateRaw = self::readText($data, 'PaymentState', self::OP_GET_PAYMENT_DETAILS);
        $orderStatusRaw = self::readText($data, 'OrderStatus', self::OP_GET_PAYMENT_DETAILS);
        $amount = self::readDecimalScalar($data, 'Amount', self::OP_GET_PAYMENT_DETAILS);
        $approvedAmount = self::readDecimalScalar($data, 'ApprovedAmount', self::OP_GET_PAYMENT_DETAILS);
        $depositedAmount = self::readDecimalScalar($data, 'DepositedAmount', self::OP_GET_PAYMENT_DETAILS);
        $refundedAmount = self::readDecimalScalar($data, 'RefundedAmount', self::OP_GET_PAYMENT_DETAILS);

        return new PaymentDetailsResponse(
            amountRaw: self::renderDecimal($amount),
            amount: self::buildAmount($amount, $currency),
            approvedAmountRaw: self::renderDecimal($approvedAmount),
            approvedAmount: self::buildAmount($approvedAmount, $currency),
            approvalCode: self::readText($data, 'ApprovalCode', self::OP_GET_PAYMENT_DETAILS),
            cardNumber: self::readText($data, 'CardNumber', self::OP_GET_PAYMENT_DETAILS),
            clientName: self::readText($data, 'ClientName', self::OP_GET_PAYMENT_DETAILS),
            clientEmail: self::readText($data, 'ClientEmail', self::OP_GET_PAYMENT_DETAILS),
            currencyRaw: $currencyRaw,
            currency: $currency,
            dateTime: self::readText($data, 'DateTime', self::OP_GET_PAYMENT_DETAILS),
            depositedAmountRaw: self::renderDecimal($depositedAmount),
            depositedAmount: self::buildAmount($depositedAmount, $currency),
            description: self::readText($data, 'Description', self::OP_GET_PAYMENT_DETAILS),
            mdOrderId: self::readText($data, 'MDOrderID', self::OP_GET_PAYMENT_DETAILS),
            merchantId: self::readText($data, 'MerchantId', self::OP_GET_PAYMENT_DETAILS),
            terminalId: self::readText($data, 'TerminalId', self::OP_GET_PAYMENT_DETAILS),
            orderId: self::readText($data, 'OrderID', self::OP_GET_PAYMENT_DETAILS),
            paymentStateRaw: $paymentStateRaw,
            paymentState: self::resolvePaymentState($paymentStateRaw),
            paymentTypeRaw: $paymentTypeRaw,
            paymentType: self::resolvePaymentType($paymentTypeRaw),
            primaryRc: self::readText($data, 'PrimaryRC', self::OP_GET_PAYMENT_DETAILS),
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_GET_PAYMENT_DETAILS),
            expDate: self::readText($data, 'ExpDate', self::OP_GET_PAYMENT_DETAILS),
            processingIp: self::readText($data, 'ProcessingIP', self::OP_GET_PAYMENT_DETAILS),
            orderStatusRaw: $orderStatusRaw,
            orderStatus: self::resolveOrderStatus($orderStatusRaw),
            cardHolderId: self::readText($data, 'CardHolderID', self::OP_GET_PAYMENT_DETAILS),
            bindingId: self::readText($data, 'BindingID', self::OP_GET_PAYMENT_DETAILS),
            refundedAmountRaw: self::renderDecimal($refundedAmount),
            refundedAmount: self::buildAmount($refundedAmount, $currency),
            opaque: self::readText($data, 'Opaque', self::OP_GET_PAYMENT_DETAILS),
            trxnDescription: self::readText($data, 'TrxnDescription', self::OP_GET_PAYMENT_DETAILS),
            rrn: self::readText($data, 'rrn', self::OP_GET_PAYMENT_DETAILS),
            actionCode: self::readText($data, 'ActionCode', self::OP_GET_PAYMENT_DETAILS),
            exchangeRate: self::renderDecimal(self::readDecimalScalar($data, 'ExchangeRate', self::OP_GET_PAYMENT_DETAILS)),
            bankInfo: self::readNestedBankInfo($data, 'BankInfo', self::OP_GET_PAYMENT_DETAILS),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function makeBindingPaymentResponse(array $data): MakeBindingPaymentResponse
    {
        $currencyRaw = self::readText($data, 'Currency', self::OP_MAKE_BINDING_PAYMENT);
        $currency = self::resolveCurrency($currencyRaw);
        $paymentTypeRaw = self::readIntOrText($data, 'PaymentType', self::OP_MAKE_BINDING_PAYMENT);
        $paymentStateRaw = self::readText($data, 'PaymentState', self::OP_MAKE_BINDING_PAYMENT);
        $orderStatusRaw = self::readText($data, 'OrderStatus', self::OP_MAKE_BINDING_PAYMENT);
        $amount = self::readDecimalScalar($data, 'Amount', self::OP_MAKE_BINDING_PAYMENT);
        $approvedAmount = self::readDecimalScalar($data, 'ApprovedAmount', self::OP_MAKE_BINDING_PAYMENT);
        $depositedAmount = self::readDecimalScalar($data, 'DepositedAmount', self::OP_MAKE_BINDING_PAYMENT);
        $refundedAmount = self::readDecimalScalar($data, 'RefundedAmount', self::OP_MAKE_BINDING_PAYMENT);

        return new MakeBindingPaymentResponse(
            paymentId: self::readText($data, 'PaymentID', self::OP_MAKE_BINDING_PAYMENT),
            responseCode: self::readResponseCode($data, 'ResponseCode', self::OP_MAKE_BINDING_PAYMENT),
            amountRaw: self::renderDecimal($amount),
            amount: self::buildAmount($amount, $currency),
            approvedAmountRaw: self::renderDecimal($approvedAmount),
            approvedAmount: self::buildAmount($approvedAmount, $currency),
            approvalCode: self::readText($data, 'ApprovalCode', self::OP_MAKE_BINDING_PAYMENT),
            cardNumber: self::readText($data, 'CardNumber', self::OP_MAKE_BINDING_PAYMENT),
            clientName: self::readText($data, 'ClientName', self::OP_MAKE_BINDING_PAYMENT),
            currencyRaw: $currencyRaw,
            currency: $currency,
            dateTime: self::readText($data, 'DateTime', self::OP_MAKE_BINDING_PAYMENT),
            depositedAmountRaw: self::renderDecimal($depositedAmount),
            depositedAmount: self::buildAmount($depositedAmount, $currency),
            description: self::readText($data, 'Description', self::OP_MAKE_BINDING_PAYMENT),
            mdOrderId: self::readText($data, 'MDOrderID', self::OP_MAKE_BINDING_PAYMENT),
            merchantId: self::readText($data, 'MerchantId', self::OP_MAKE_BINDING_PAYMENT),
            terminalId: self::readText($data, 'TerminalId', self::OP_MAKE_BINDING_PAYMENT),
            orderId: self::readText($data, 'OrderID', self::OP_MAKE_BINDING_PAYMENT),
            paymentStateRaw: $paymentStateRaw,
            paymentState: self::resolvePaymentState($paymentStateRaw),
            paymentTypeRaw: $paymentTypeRaw,
            paymentType: self::resolvePaymentType($paymentTypeRaw),
            primaryRc: self::readText($data, 'PrimaryRC', self::OP_MAKE_BINDING_PAYMENT),
            expDate: self::readText($data, 'ExpDate', self::OP_MAKE_BINDING_PAYMENT),
            processingIp: self::readText($data, 'ProcessingIP', self::OP_MAKE_BINDING_PAYMENT),
            orderStatusRaw: $orderStatusRaw,
            orderStatus: self::resolveOrderStatus($orderStatusRaw),
            cardHolderId: self::readText($data, 'CardHolderID', self::OP_MAKE_BINDING_PAYMENT),
            bindingId: self::readText($data, 'BindingID', self::OP_MAKE_BINDING_PAYMENT),
            refundedAmountRaw: self::renderDecimal($refundedAmount),
            refundedAmount: self::buildAmount($refundedAmount, $currency),
            opaque: self::readText($data, 'Opaque', self::OP_MAKE_BINDING_PAYMENT),
            trxnDescription: self::readText($data, 'TrxnDescription', self::OP_MAKE_BINDING_PAYMENT),
            rrn: self::readText($data, 'rrn', self::OP_MAKE_BINDING_PAYMENT),
            actionCode: self::readText($data, 'ActionCode', self::OP_MAKE_BINDING_PAYMENT),
            acsUrl: self::readText($data, 'AcsUrl', self::OP_MAKE_BINDING_PAYMENT),
            paReq: self::readText($data, 'PaReq', self::OP_MAKE_BINDING_PAYMENT),
            termUrl: self::readText($data, 'TermUrl', self::OP_MAKE_BINDING_PAYMENT),
        );
    }

    /**
     * A declared-string field. Absent and null are both null.
     *
     * An integer is accepted and stringified because the wire's types drift in
     * exactly that direction — CONVENTIONS.md §4.12 records four fields the
     * vendor PDF called integers arriving as strings — and the conversion is
     * exact. Nothing else is coerced: a float would have to be rendered at a
     * precision nobody has specified, and a boolean would have to be spelled,
     * so both throw rather than guess.
     *
     * An empty string stays an empty string and is never turned into null. That
     * rule now has a completed payment behind it and not only a failed lookup:
     * probe case P3 returns `ClientEmail`, `CardHolderID` and `BindingID` as
     * `""` in a body whose neighbouring fields are populated, so blankness there
     * is the gateway saying a field does not apply — a distinction null would
     * erase.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readText(array $data, string $key, string $operation): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw SerializationException::unexpectedPayload(
            $operation,
            sprintf('the %s field was not text', $key),
        );
    }

    /**
     * A declared-decimal field, validated once and returned as the scalar that
     * arrived.
     *
     * This is the only gate a monetary value passes through, and it is
     * deliberately the only one. A model method reads the key here exactly
     * once and hands the result to both renderDecimal() and buildAmount(), so
     * the two derivations cannot disagree about what arrived and neither has
     * to re-check the shape. Reading the key twice — once per derivation — is
     * what previously left buildAmount()'s rejection arm unreachable: the
     * earlier read had already thrown, so the later one was proving nothing and
     * only a reflective call could execute it.
     *
     * Anything outside {null, string, int, float} throws. A boolean would have
     * to be spelled and an array cannot be a decimal at all, so both are a
     * shape that cannot be represented.
     *
     * Which of the four arms the wire actually uses is no longer open: probe
     * case P3 returned every decimal field as a JSON float — `10.0`, `0.0` —
     * so the float arm is the live one and the rest are defensive.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readDecimalScalar(array $data, string $key, string $operation): float|int|string|null
    {
        $value = $data[$key] ?? null;

        if ($value === null || is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        throw SerializationException::unexpectedPayload(
            $operation,
            sprintf('the %s field was not a decimal scalar', $key),
        );
    }

    /**
     * The raw companion of a decimal field: what arrived, rendered as text.
     *
     * This is the only other place a float is touched, and it is not the
     * documented lossy conversion: no currency is in scope here, so there is no
     * exponent to format to. var_export() is used for its round-trip rendering
     * — the shortest decimal string that reads back as the same value, with the
     * zero fraction preserved, so a sample's `4.0` stays "4.0" rather than
     * becoming "4". The alternative, a plain string cast, renders at the
     * precision ini setting and can drop digits the gateway sent.
     *
     * It is deliberately not the input to buildAmount(). The two are
     * independent derivations of one scalar: this one is the shortest
     * round-trip of what arrived, that one is the rounding to the currency's
     * exponent. Feeding this string into the builder would make an over-precise
     * float — 1.2345678901234567 — render as a seventeen-place string that
     * Amount rejects positionally, turning a roundable amount into a null one.
     * That value is the only one that tells the two designs apart, and
     * ResponseHydratorTest executes it rather than leaving it asserted here:
     * 123 minor units from the scalar, null from this string. Infection cannot
     * reach the difference — routing one derivation into the other is a
     * refactor, not a mutation — so that one assertion is the only thing
     * holding the separation.
     *
     * The value is text either way: no arithmetic is ever performed on it.
     *
     * The float branch is the one the gateway exercises. Probe case P3 returned
     * `"DepositedAmount":10.0`, and this renders it "10.0" rather than "10" —
     * the zero fraction the gateway sent survives into the raw companion.
     */
    private static function renderDecimal(float|int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return var_export($value, true);
    }

    /**
     * The single documented lossy step, and the only place in this package
     * where a float may touch a monetary value.
     *
     * JSON decoding yields a PHP float for a decimal literal and PHP offers no
     * float-preserving decode mode, so by the time a response reaches here the
     * damage — if any — is already done. sprintf('%.<exponent>F', $value)
     * rounds it back to the currency's ISO 4217 scale, which is exact for any
     * value a two-decimal money column can hold. Uppercase F is deliberate: the
     * lowercase conversion is locale-aware and would emit a comma in a
     * comma-decimal locale, which Amount::fromDecimalString() rejects.
     *
     * **An int or a string never goes through sprintf.** Both convert exactly
     * on their own, and routing them through a float format would reintroduce
     * the very imprecision this method exists to bound — "10.005" would become
     * "10.01" and an integer beyond 2^53 would lose its low digits. The three
     * arms below are exhaustive over the parameter's type, so there is no
     * fourth: readDecimalScalar() has already rejected everything else, and the
     * caller passes its result rather than re-reading the key.
     *
     * A currency is required. When the response's own `Currency` field is
     * absent or unrecognised, the caller passes null and this returns null,
     * leaving the raw scalar as the record of what arrived. Currency::default()
     * is never called.
     *
     * An unrepresentable amount also returns null rather than throwing. Amount
     * rejects zero, a negative, and anything with more decimal places than the
     * currency's exponent — and a response may legitimately carry
     * `RefundedAmount` 0 on a payment that was never refunded. Throwing would
     * discard the whole response, including the ResponseCode the caller needs
     * to act on, over a field the raw property already preserves.
     *
     * That last case is observed, not anticipated: probe case P3 is a completed
     * payment carrying `"RefundedAmount":0.0` beside a usable `"Currency":"051"`
     * and three amounts that do build. The zero is the only one of the four that
     * comes back null here, and throwing on it would have discarded a
     * success-coded body over a field that means "nothing has been refunded".
     */
    private static function buildAmount(float|int|string|null $value, ?Currency $currency): ?Amount
    {
        if ($value === null || !$currency instanceof Currency) {
            return null;
        }

        if (is_float($value)) {
            $decimal = sprintf('%.' . $currency->exponent() . 'F', $value);
        } elseif (is_int($value)) {
            $decimal = (string) $value;
        } else {
            $decimal = $value;
        }

        try {
            return Amount::fromDecimalString($decimal, $currency);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * A declared-boolean field, of which `IsAvtive` is the only one.
     *
     * JSON carries a real boolean; XML carries the text .NET writes for one,
     * which is lowercase "true" or "false", and CONVENTIONS.md §4.12 confirms
     * `Accept: application/xml` is honoured, so both forms are reachable. "1"
     * and "0" are accepted for the same reason readText() accepts an integer.
     *
     * Anything else throws. This field has no raw companion on CardBindingFiled
     * — a value that cannot be read as a boolean has nowhere to go, and
     * returning null would report "the gateway did not say" about a binding
     * whose state it did in fact state.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readFlag(array $data, string $key, string $operation): ?bool
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $token = null;

        if (is_int($value)) {
            $token = (string) $value;
        }

        if (is_string($value)) {
            $token = strtolower($value);
        }

        return match ($token) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw SerializationException::unexpectedPayload(
                $operation,
                sprintf('the %s field was not a boolean', $key),
            ),
        };
    }

    /**
     * A field whose declared type and observed type disagree, carried as
     * whichever of the two arrived.
     *
     * `OrderId` is declared integer on GetPendingTransactionsResponse and
     * string on every other model that carries the order key; `PaymentType` is
     * declared as the PaymentsEnum, which JSON renders as its integer and XML
     * as the member name. Neither is coerced: narrowing to one type would turn
     * a value the SDK could simply have carried into a hydration failure.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readIntOrText(array $data, string $key, string $operation): int|string|null
    {
        $value = $data[$key] ?? null;

        if ($value === null || is_int($value) || is_string($value)) {
            return $value;
        }

        throw SerializationException::unexpectedPayload(
            $operation,
            sprintf('the %s field was neither an integer nor text', $key),
        );
    }

    /**
     * A non-nullable field, and the one place absence is fatal.
     *
     * The rule is never to throw on absence, and `ResponseCode` and
     * `ResponseMessage` are the two non-nullable fields. For those two the
     * rules meet: absence is unrepresentable, so the choice is between throwing
     * and inventing. Inventing a ResponseMessage the gateway never sent is the
     * worse failure by a wide margin — it would be indistinguishable from one
     * it did send — so the no-throw rule is read as governing the nullable
     * majority and this throws. The message names the field and nothing else
     * (CONVENTIONS.md §6).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readRequiredText(array $data, string $key, string $operation): string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw SerializationException::unexpectedPayload(
            $operation,
            sprintf('the required %s field was absent, null, or not text', $key),
        );
    }

    /**
     * `ResponseCode`, the other non-nullable field. See readRequiredText() for
     * why its absence throws.
     *
     * Both wire types are passed through untouched: InitPayment answers with an
     * integer and every other endpoint with a string (CONVENTIONS.md §4.3), and
     * ResponseCode carries the union end to end rather than normalising one
     * form into the other. An unrecognised code never throws — the value object
     * has no table to miss from.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readResponseCode(array $data, string $key, string $operation): ResponseCode
    {
        $value = $data[$key] ?? null;

        if (is_int($value) || is_string($value)) {
            return ResponseCode::fromWire($value);
        }

        throw SerializationException::unexpectedPayload(
            $operation,
            sprintf('the required %s field was absent, null, or neither an integer nor text', $key),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    private static function readNestedBankInfo(array $data, string $key, string $operation): ?BankInfo
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw SerializationException::unexpectedPayload(
                $operation,
                sprintf('the %s field was not an object', $key),
            );
        }

        return self::bankInfo($value);
    }

    /**
     * The binding collection, keeping three states apart.
     *
     * Null means the key was absent, an empty list means the gateway sent an
     * empty collection, and a populated list means bindings exist. Collapsing
     * the first into the second would assert the merchant has no bindings on
     * the strength of a key the gateway did not send — and CONVENTIONS.md §4.12
     * warns of the mirror-image error, reading a self-closing
     * `<CardBindingFileds />` as absent when it is an empty collection.
     *
     * A non-list array throws rather than being read as a single element. That
     * reading would be a guess about a representation nobody has observed; if
     * an XML decoder ever collapses a one-element collection into a bare map,
     * normalising it belongs in that decoder, where the document is still in
     * hand.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<CardBindingFiled>|null
     *
     * @throws SerializationException
     */
    private static function readCardBindings(array $data, string $key, string $operation): ?array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw SerializationException::unexpectedPayload(
                $operation,
                sprintf('the %s field was not a collection', $key),
            );
        }

        $bindings = [];

        foreach ($value as $element) {
            if (!is_array($element)) {
                throw SerializationException::unexpectedPayload(
                    $operation,
                    sprintf('the %s collection held an element that was not an object', $key),
                );
            }

            $bindings[] = self::cardBindingFiled($element);
        }

        return $bindings;
    }

    /**
     * Null for a code this SDK does not know, including the empty string —
     * which is what GetPaymentDetails actually returned on probe B2. Never
     * Currency::default().
     */
    private static function resolveCurrency(?string $raw): ?Currency
    {
        if ($raw === null) {
            return null;
        }

        return Currency::tryFrom($raw);
    }

    /**
     * Null for a state this SDK does not know. PaymentState is string-backed and
     * the field is declared string, so the raw value is offered to tryFrom()
     * unchanged.
     */
    private static function resolvePaymentState(?string $raw): ?PaymentState
    {
        if ($raw === null) {
            return null;
        }

        return PaymentState::tryFrom($raw);
    }

    /**
     * The guarded enum lookup for the one field whose wire type and enum
     * backing disagree.
     *
     * `OrderStatus` is declared string; OrderStatus is int-backed. The enum is
     * attempted only when the raw value is entirely ASCII digits. A blind cast
     * would turn "payment_deposited" into 0 — Registered — and silently report
     * a completed payment as unpaid, which is the single most expensive way
     * this hydrator could be wrong.
     *
     * The guard was written against an open question — whether the wire carries
     * "2" or "payment_deposited" — and the question turned out to be malformed.
     * A completed payment carries **both**, under two different keys: probe case
     * P3 returns `"OrderStatus":"2"` beside `"PaymentState":"payment_deposited"`,
     * and P4.1b returns `"4"` beside `"payment_refunded"`. So the two names never
     * compete for one field, and this guard's real work is narrower than it was
     * built for: it keeps a value the gateway might yet start spelling in words
     * from being cast to Registered. That is still worth having, and the cost of
     * having it is nothing.
     *
     * The raw string is kept on the DTO regardless, so a null enum costs the
     * caller nothing but the convenience.
     */
    private static function resolveOrderStatus(?string $raw): ?OrderStatus
    {
        if ($raw === null || !ctype_digit($raw)) {
            return null;
        }

        return OrderStatus::tryFrom((int) $raw);
    }

    /**
     * PaymentType is int-backed, and the wire carries the integer in JSON and
     * the member name in XML.
     *
     * A member name is never mapped back to a member. The names are the
     * gateway's own spelling of its C# enum, not a documented wire vocabulary,
     * and matching on them would make this SDK's reading of a payment type
     * depend on a string the bank has never promised to keep stable. A numeric
     * string is resolved, on the same ctype_digit guard as OrderStatus: it is
     * an exact match on the declared value, not an interpretation. The raw is
     * kept either way, so the name remains readable by the caller.
     *
     * The bank fills the gaps at 8, 9, 10, 15 and 16 without notice, so an
     * unknown integer resolves to null rather than throwing
     * (CONVENTIONS.md §4.6).
     */
    private static function resolvePaymentType(int|string|null $raw): ?PaymentType
    {
        if (is_int($raw)) {
            return PaymentType::tryFrom($raw);
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return PaymentType::tryFrom((int) $raw);
        }

        return null;
    }
}
