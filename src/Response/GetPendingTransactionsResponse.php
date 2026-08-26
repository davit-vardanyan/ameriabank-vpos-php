<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * One unresolved transaction returned by GetPendingTransactions.
 *
 * Model of record: `GetPendingTransactionsResponse` in
 * docs/api-reference/api-surface.json. Field names verbatim
 * (CONVENTIONS.md §4.8).
 *
 * **This model is one element, not the whole response.** The manifest's JSON
 * sample for the endpoint is a bare array of two of these objects, and its XML
 * sample wraps them in `<ArrayOfGetPendingTransactionsResponse>`. So the
 * operation answers with a collection whose element is this class, and the
 * collection has no envelope of its own: no wrapper object, no `ResponseCode`,
 * no `ResponseMessage`, nowhere to put them. No wrapper class is invented here
 * to hold fields the manifest does not declare — inventing one would be a
 * transcription error in the direction opposite to the one CONVENTIONS.md §2
 * warns about, and it would have to guess what the gateway does when the list
 * is empty or the credentials are wrong. Returning `list<self>` is the
 * hydrator's job; this class stays exactly what the manifest says it is.
 *
 * **Every field here is nullable, including the absence of the usual pair.**
 * The package-wide exemption from nullability covers `ResponseCode` and
 * `ResponseMessage`; it is vacuous for a model which declares neither, so
 * nothing is added to satisfy it. The manifest states no requiredness either —
 * "Additional information" reads "None." for every field of every model.
 *
 * `OrderId` is the second of exactly two casing breaks in the package: this
 * model spells it with a lowercase `d`, where every other model uses `OrderID`.
 * The other break is `PaymentId` on GetPaymentIdResponse. Neither is corrected
 * (CONVENTIONS.md §4.8).
 *
 * Nothing about this operation has been observed. No probe has ever called it,
 * so the notes below on types are all read off the manifest.
 *
 * @todo unverified — see CONVENTIONS.md §13 (no GetPendingTransactions probe has ever run; whether an error is reported as an empty array, a fault, or something else is unknown)
 */
final readonly class GetPendingTransactionsResponse
{
    /**
     * `OrderId` is declared `integer` here and `string` on every other model
     * that carries the order key, and the manifest's sample renders it
     * unquoted. The union carries whichever form arrives rather than coercing
     * one into the other: CONVENTIONS.md §4.12 already records four fields the
     * PDF called integers arriving as strings, so a string is the likelier
     * surprise, and narrowing to int would make it a hydration failure on a
     * value the SDK could simply have carried.
     *
     * `Amount` has no companion Amount object, unlike every other monetary
     * field in this package. This model declares no `Currency` field, so there
     * is no currency to construct one with, and this package never falls back
     * to `Currency::default()` — that default is an SDK assumption, not
     * observed behaviour, and stamping AMD on a foreign-currency transaction
     * would produce a wrong amount that looks right. The raw scalar is kept
     * instead, as a string: the manifest declares a decimal and its sample
     * renders `4.0`, so JSON decoding yields the platform's inexact numeric
     * type, which CONVENTIONS.md §4.7 forbids from touching money and which
     * therefore must not reach a property here.
     *
     * `PaymentDate` is declared `date`, not `string` (CONVENTIONS.md §4.12
     * flags the same typing on this operation's request), and the manifest's
     * sample renders an ISO 8601 timestamp with a UTC offset and seven
     * fractional digits. It is kept as text rather than parsed: parsing commits
     * to a format nobody has verified, and a parse failure would discard a
     * value the caller could have read.
     *
     * @param int|string|null $orderId      Wire key `OrderId` — lowercase `d`, on this model only. Not `OrderID`.
     * @param string|null     $clientName   Wire key `ClientName`. On GetPaymentDetails this holds the cardholder's own name, not the merchant's (probe case P3); this endpoint has never been called, so the same reading is assumed rather than observed. Personal data either way; never log it (CONVENTIONS.md §6).
     * @param string|null     $cardNumber   Wire key `CardNumber`. Card data — the gateway masks it, and CONVENTIONS.md §6 forbids its value reaching a log or an exception message regardless.
     * @param string|null     $amountRaw    Wire key `Amount`, kept as the raw scalar rendered as text. No currency accompanies it on this model.
     * @param string|null     $paymentDate  Wire key `PaymentDate`. Declared `date`; carried as text, unparsed.
     * @param string|null     $errorMessage Wire key `ErrorMessage`. The reason the transaction is pending. Not `ResponseMessage`; this model declares neither that nor `ResponseCode`.
     */
    public function __construct(
        public int|string|null $orderId,
        public ?string $clientName,
        public ?string $cardNumber,
        public ?string $amountRaw,
        public ?string $paymentDate,
        public ?string $errorMessage,
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
     * This hydrates one element. The operation answers with a bare array and no
     * envelope, so the collection form lives on the hydrator as
     * getPendingTransactionsList() rather than here: `fromWireArray()` means
     * one object built from one wire object, on this class as on every other.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws SerializationException
     */
    public static function fromWireArray(array $data): self
    {
        return ResponseHydrator::getPendingTransactionsResponse($data);
    }
}
