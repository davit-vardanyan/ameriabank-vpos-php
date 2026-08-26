<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Request;

use DateTimeImmutable;
use DateTimeInterface;
use DavitVardanyan\AmeriabankVpos\Contracts\RequestInterface;

/**
 * Lists transactions left in an unresolved state over a date range.
 *
 * Model of record: `GetPendingTransactionsRequest`. Its response is the one
 * model in the package that spells the order key `OrderId` rather than
 * `OrderID` (CONVENTIONS.md §4.8); that belongs to the response side.
 *
 * This is the REST replacement for the SOAP `GetProblemTransactions` only —
 * `GetTransactionList` has no REST equivalent in `api-surface.json`, and v1.0
 * ships without it rather than carry a second transport for one operation.
 * That is decided, not pending: CONVENTIONS.md §13 records the deferral, and
 * records that it waits on the bank rather than on anything this package
 * can build.
 *
 * `ClientID`, `Username` and `Password` are absent by design; the transport
 * merges them from Credentials::merchantFields().
 *
 * `StartDate` and `EndDate` are declared `date` by the manifest, not `string`
 * (CONVENTIONS.md §4.12), so they are accepted as DateTimeImmutable rather than
 * as text a caller has already formatted — a formatted string forces the SDK to
 * either re-parse it or forward a format it cannot vouch for.
 *
 * The wire format is the manifest's own JSON sample for this model, which
 * renders both fields as ISO 8601 with a UTC offset. DateTimeInterface::ATOM
 * produces exactly that shape without the sample's seven fractional digits,
 * which are an artefact of the Help page's clock rather than a requirement this
 * package can attest to. No probe has ever called this operation, so nothing
 * below is observed.
 *
 * No ordering check between the two. An inverted range presumably returns
 * nothing, but "presumably" is not a rejection anyone has seen, and refusing to
 * dispatch a call the gateway might answer is the worse error.
 *
 * The manifest states no requiredness — "Additional information" reads "None."
 * for every field of every model. Both fields are required: they are the whole
 * query.
 *
 * @todo unverified — see CONVENTIONS.md §13 (the date wire format has never been exercised)
 */
final readonly class GetPendingTransactionsRequest implements RequestInterface
{
    private const string OPERATION = 'GetPendingTransactions';

    public function __construct(private DateTimeImmutable $startDate, private DateTimeImmutable $endDate) {}

    public function operation(): string
    {
        return self::OPERATION;
    }

    /**
     * Read-only, so retryable (CONVENTIONS.md §4.5).
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * The manifest's `GetPendingTransactionsRequest` lists `ClientID`, so the
     * transport merges Credentials::merchantFields(). A date window addresses
     * nothing on its own; the merchant is what scopes it.
     */
    public function requiresClientId(): bool
    {
        return true;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'StartDate' => $this->startDate->format(DateTimeInterface::ATOM),
            'EndDate' => $this->endDate->format(DateTimeInterface::ATOM),
        ];
    }
}
