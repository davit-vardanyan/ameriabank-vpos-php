<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Client;

use DateTimeImmutable;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Request\GetPendingTransactionsRequest;
use DavitVardanyan\AmeriabankVpos\Response\GetPendingTransactionsResponse;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;

/**
 * Reporting over a date range.
 *
 * One method, deliberately, and the SOAP question behind that count is decided
 * rather than open. `GetTransactionList` has no REST equivalent and is deferred
 * from v1.0 — CONVENTIONS.md §13 records the deferral as blocked on the bank
 * rather than on the transport, since §4.11's SOAP configuration demonstrably
 * works. It joins this class if a REST equivalent arrives, and a method added
 * to an existing client is a smaller break for consumers than a client
 * introduced later, which is why this one starts thin.
 *
 * `GetPendingTransactions` is the REST replacement for the SOAP
 * `GetProblemTransactions` only.
 *
 * ## This is the one operation with no ResponseCode envelope
 *
 * The manifest's sample body is a bare JSON array of rows — no wrapper object,
 * no `ResponseCode`, no `ResponseMessage`, and nowhere to put them. So this
 * method returns a list rather than a response object, and no envelope class is
 * invented to hold fields the manifest does not declare. The transport already
 * accommodates it: a body carrying neither a response code nor a fault message
 * is returned as decoded.
 *
 * Nothing about this operation has ever been observed. No probe has called it,
 * so its failure shape — a response code, the ASP.NET fault envelope, or an
 * empty list — is unknown, and none is assumed here (CONVENTIONS.md §13).
 *
 * @todo unverified — see CONVENTIONS.md §13 (GetPendingTransactions has never been called)
 */
final readonly class ReportsClient
{
    /**
     * HttpTransport is `@internal` (CONVENTIONS.md §5), so this constructor is
     * too. Construct this through Vpos::reports().
     *
     * @internal
     */
    public function __construct(private HttpTransport $transport) {}

    /**
     * Lists the transactions left unresolved between two instants.
     *
     * Both bounds are DateTimeImmutable, never preformatted text. The manifest
     * types `StartDate` and `EndDate` as `date` rather than `string` (§4.12),
     * and formatting happens once, in GetPendingTransactionsRequest::toArray(),
     * so the wire format has exactly one home. A string parameter here would
     * force this SDK either to re-parse the caller's text or to forward a
     * format it cannot vouch for.
     *
     * No ordering check between the two bounds. An inverted range presumably
     * returns nothing, but "presumably" is not a rejection anyone has seen, and
     * refusing to dispatch a call the gateway might answer is the worse error.
     *
     * This is the one place in src/Client/ that names ResponseHydrator. The
     * collection form lives there and only there — hydrating the rows here
     * would put the element-shape check in a second place — and every other
     * client method reaches the same hydrator through its response DTO's
     * fromWireArray(). The name appears in a method body and nowhere else: no
     * public parameter or return type in src/Client/ mentions an `@internal`
     * type.
     *
     * @return list<GetPendingTransactionsResponse>
     *
     * @throws VposExceptionInterface on any failure of the exchange
     */
    public function pending(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ResponseHydrator::getPendingTransactionsList(
            $this->transport->send(new GetPendingTransactionsRequest($from, $to)),
        );
    }
}
