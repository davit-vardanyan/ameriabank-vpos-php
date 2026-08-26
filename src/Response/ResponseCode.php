<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Response;

use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;

use function in_array;

/**
 * The gateway's ResponseCode field, kept exactly as it arrived.
 *
 * The wire type varies by endpoint: InitPayment answers with an int, every
 * other endpoint with a string, and the success value differs with it
 * (CONVENTIONS.md §4.3). Narrowing the two to one type would break half the
 * API, so int|string is carried end to end — in the factory, in the property,
 * and in raw(). Nothing here casts one form to the other to compare them.
 *
 * This is public API. The dependency runs one way only: a caller who wants the
 * richer behaviour builds one of these out of ApiException::responseCode(), and
 * no class under Exception\ imports, type-hints, or calls this class
 * (CONVENTIONS.md §5).
 *
 * There is no code-to-description table. The gateway sends ResponseMessage on
 * every response and that text is authoritative; a table transcribed from the
 * vendor PDF would be a guess that could contradict it. The code table also has
 * roughly sixty entries in mutually incompatible shapes — "00", "0-1", "0100",
 * "0151017", "514" — and the bank adds more without notice, which is why this
 * is a value object and not a backed enum. An unrecognised code never throws.
 *
 * **A live run has since shown that a table would have been wrong twice over.**
 * Probe case P4.5 refused an over-refund with `"07"` and the message `Refund
 * amount exceeds deposited amount`; probe case P5 refused a cancel with the same
 * `"07"` and the message `Reversal is impossible for current transaction state`.
 * One code, two unrelated conditions, told apart only by the gateway's own text
 * — which the vendor PDF calls "System Error" for both. A table would have
 * published that third, wrong answer twice. It is for the same reason that no
 * code is mapped to a specific exception subclass: `"07"` names no single thing
 * to map.
 *
 * The constructor is private and the factory is named fromWire() rather than
 * the shorter name a value object would normally take: no static from() call
 * site is permitted anywhere in src/, and
 * tests/Enum/NoEnumFromCallInSourceTest.php enforces that textually. Its
 * pattern matches call sites only — a declaration would not trip it.
 */
final readonly class ResponseCode
{
    private function __construct(private int|string $raw) {}

    public static function fromWire(int|string $raw): self
    {
        return new self($raw);
    }

    /**
     * The value exactly as it arrived, with its original type.
     */
    public function raw(): int|string
    {
        return $this->raw;
    }

    /**
     * The value as a string, without normalising what arrived.
     *
     * A leading zero that arrived as a string survives: "00" stringifies to
     * "00" and integer 0 to "0". Those are different wire values and this
     * method must not conflate them, so nothing here routes through an integer.
     */
    public function asString(): string
    {
        return (string) $this->raw;
    }

    /**
     * True for exactly three forms, and false for everything else.
     *
     * - integer 1 — observed on InitPayment across probe phases A, B and C.
     * - string "1" — the same code arriving through the XML representation,
     *   which carries no types. Content negotiation on Accept: application/xml
     *   is confirmed working (CONVENTIONS.md §4.12), so this form is reachable
     *   even though the probes read JSON.
     * - string "00" — the success code for every endpoint other than
     *   InitPayment. No longer PDF-sourced: observed six times in the run that
     *   completed the first live payment — from GetPaymentDetails on probe cases
     *   P3, P4.1b, P4.3b and P6, and from RefundPayment on P4.1 and P4.3.
     *
     * Every other value, known or unknown, is not success. Deliberately
     * fail-closed: reporting a failure as success is the one misclassification
     * that causes an unpaid order to be treated as paid, so an unrecognised
     * code must fall through to false rather than be guessed at.
     *
     * The strict flag on the search below is load-bearing and must not be
     * dropped. Executed on PHP 8.3.28: without it, both "01" and "0" are
     * reported as members of this set, which is exactly the fail-open answer
     * this method exists to avoid.
     */
    public function isSuccess(): bool
    {
        return in_array($this->raw, [
            1,     // InitPayment, observed.
            '1',   // The same code, XML representation.
            '00',  // Every other endpoint, observed.
        ], true);
    }

    /**
     * True for integer 20 only.
     *
     * Both wire forms of 20 have been observed, and they did not mean the same
     * thing:
     *
     * - integer 20 — InitPayment, ResponseMessage "Incorrect Username and
     *   Password" (case A2). A credential rejection.
     * - string "20" — ActivateBinding, DeactivateBinding and GetBindings,
     *   ResponseMessage "Client payment type BindingMainRest is not available"
     *   (cases A1.1, A1.3, B6.1 and B6.2). An entitlement refusal, returned in
     *   runs whose credentials authenticated successfully elsewhere.
     *
     * So only the integer form is classified as an authentication failure. The
     * string form falls through toException() to a plain ApiException, whose
     * message carries the gateway's own ResponseMessage, so the entitlement
     * text reaches the caller either way.
     *
     * The asymmetry decides the unobserved cases. Adding a classification later
     * is not a breaking change, because everything built here extends
     * ApiException and a caller catching ApiException keeps working. Removing
     * one later is breaking, because a caller catching AuthenticationException
     * silently stops catching. Classifying only what was observed is the
     * reversible choice. The accepted cost is that an InitPayment
     * authentication failure arriving as a string through the XML
     * representation would degrade to ApiException — safe, merely less
     * specific.
     *
     * HTTP status carries no business meaning here — the gateway answers 200
     * for a rejected credential (CONVENTIONS.md §4.1) — so the code is the
     * only signal.
     */
    public function isAuthenticationFailure(): bool
    {
        return $this->raw === 20;
    }

    /**
     * Compares the raw values with ===, so integer 20 does not equal string
     * "20".
     *
     * That is the intended answer, not an oversight. A value object whose whole
     * purpose is to preserve what arrived should not paper over the difference
     * between the two wire types, and 20 is the case that proves the point: the
     * two forms arrived from different endpoints carrying different
     * ResponseMessages, and only the int one is an authentication failure (see
     * isAuthenticationFailure()).
     */
    public function equals(self $other): bool
    {
        return $this->raw === $other->raw;
    }

    /**
     * Builds the exception this failure code deserves.
     *
     * Authentication failures become AuthenticationException; every other
     * failure becomes a plain ApiException carrying the raw code, the
     * operation, and the gateway's ResponseMessage. Only integer 20 is an
     * authentication failure — see isAuthenticationFailure() for why string
     * "20" is not.
     *
     * A success code is a programming error at the call site rather than a
     * runtime condition, so it raises ConfigurationException instead of
     * returning a nonsensical ApiException. The message is built by a named
     * factory on that class, like every other message this package emits: an
     * invariant that every emitted string lives in src/Exception/ is auditable
     * by reading one directory, and a tendency is not.
     *
     * DeclinedException and DuplicateOrderException are deliberately not
     * mapped. This looks like an omission and is not:
     *
     * - No decline has ever been observed. A card payment has now completed on
     *   the sandbox — probe cases P1 through P6 — and it was *approved*, so it
     *   supplies no decline code either. Every decline code remains
     *   PDF-sourced, and the PDF has already been wrong about endpoint names,
     *   field types, enum membership, validation behaviour and the
     *   SOAP envelope.
     * - What that run did supply is a reason to classify less, not more. `"07"`
     *   came back twice with unrelated meanings — an over-refund on P4.5 and a
     *   refused cancel on P5 — told apart only by ResponseMessage. A code that
     *   names two conditions cannot be mapped to one exception.
     * - On duplicate orders the PDF is contradicted directly: probe A5
     *   re-registered an OrderID that probe A3 had already registered, and the
     *   gateway answered ResponseCode 1, "OK", returning A3's PaymentID — not
     *   the documented "01".
     * - The asymmetry decides it. Adding a classification later is not a
     *   breaking change, because both classes extend ApiException and a caller
     *   catching ApiException keeps working. Removing a wrong one later is
     *   breaking, because a caller catching DeclinedException silently stops
     *   catching. Not classifying is the reversible choice.
     *
     * Both classes stay in the hierarchy as catchable types.
     *
     * @param string $responseMessage The gateway's ResponseMessage field. Never the raw response body, which may carry card data (CONVENTIONS.md §5, §6).
     *
     * @throws ConfigurationException
     */
    public function toException(string $operation, string $responseMessage): ApiException
    {
        if ($this->isSuccess()) {
            throw ConfigurationException::successCodeHasNoException($operation, $this->raw);
        }

        if ($this->isAuthenticationFailure()) {
            return AuthenticationException::fromResponse($operation, $this->raw, $responseMessage);
        }

        return ApiException::fromResponse($operation, $this->raw, $responseMessage);
    }
}
