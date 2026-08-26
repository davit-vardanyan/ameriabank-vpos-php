<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use InvalidArgumentException;

use function sprintf;

/**
 * A value this package refuses: a request rejected before dispatch, or a
 * response whose order identity cannot be reconciled with the callback that
 * named it.
 *
 * Every factory here is the first kind except two — callbackOrderMismatch() and
 * callbackOrderUnconfirmable(), which fire after Vpos::verify() has dispatched
 * GetPaymentDetails and read the answer. They are this class's only
 * post-dispatch throws, and they are validation rather than an ApiException
 * because the gateway refused nothing: it answered, and the answer does not
 * confirm the order the caller was told about.
 *
 * Client-side validation exists because the gateway does not validate
 * consistently: it accepts Timeout values of 1201, 0 and -1 (CONVENTIONS.md
 * §4.12), and answers an out-of-range PaymentType with an unparseable HTTP 500
 * (§4.2). Catching these locally produces an actionable error instead.
 */
final class ValidationException extends InvalidArgumentException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    public static function timeoutOutOfRange(int $seconds): self
    {
        return new self(sprintf(
            'Timeout must be between 1 and 1200 seconds, got %d. The gateway '
            . 'accepts out-of-range values silently, so this is enforced here.',
            $seconds,
        ));
    }

    /**
     * Reports the rejected count, where malformedValue() reports only a field
     * name and an expectation.
     *
     * The asymmetry is the rule, not an exception to it: a value of known shape
     * may appear in a message, a value of unknown shape may not. A minor unit
     * count is a caller-supplied integer whose shape is fixed by the signature,
     * so echoing it cannot surface anything but a number. malformedValue()
     * takes arbitrary strings whose content is unknown to it, and
     * CONVENTIONS.md §6 forbids a PAN, an ExpDate, an ApprovalCode or an SSN
     * from ever reaching a message — which reaches logs.
     */
    public static function amountNotPositive(int $minorUnitCount): self
    {
        return new self(sprintf('Amount must be greater than zero, got %d minor units.', $minorUnitCount));
    }

    /**
     * @param list<int> $allowed
     */
    public static function unsupportedPaymentType(int $given, string $operation, array $allowed): self
    {
        return new self(sprintf(
            'PaymentType %d is not accepted by %s. Allowed: %s. Other values '
            . 'return an unparseable HTTP 500 from the gateway.',
            $given,
            $operation,
            implode(', ', $allowed),
        ));
    }

    /**
     * @param non-empty-string $field Name of the field. Never its value.
     */
    public static function blankValue(string $field): self
    {
        return new self(sprintf('Field "%s" must not be blank.', $field));
    }

    /**
     * @param non-empty-string $field Name of the field. Never its value.
     */
    public static function malformedValue(string $field, string $expectation): self
    {
        return new self(sprintf('Field "%s" is malformed: expected %s.', $field, $expectation));
    }

    /**
     * How many times a retryable operation may be attempted, out of range.
     *
     * Reports the rejected count, on the same reasoning as amountNotPositive():
     * a caller-supplied integer whose shape is fixed by the signature cannot
     * surface anything but a number.
     *
     * The bound is the transport's, and only the count is configurable at all.
     * *Which* operations may be retried is fixed per operation by
     * CONVENTIONS.md §4.5 and answered by RequestInterface::isIdempotent(), so
     * no value of this setting can cause a capture, a refund, a cancellation or
     * a binding payment to be sent twice.
     */
    public static function maxAttemptsOutOfRange(int $attempts): self
    {
        return new self(sprintf(
            'The maximum attempt count must be between 1 and 5, got %d. Only how '
            . 'many times a retryable operation is attempted is configurable; '
            . 'which operations may be retried at all is not.',
            $attempts,
        ));
    }

    /**
     * A request object built a credential field into its own body.
     *
     * CONVENTIONS.md §5: ClientID, Username and Password are merged by the
     * transport and must never appear in a request DTO the caller constructs.
     * The eleven DTOs in this package honour that, but
     * Contracts\RequestInterface is public surface and a consumer may implement
     * it, so the transport checks rather than trusts. §4.12 records that the
     * gateway ignores unknown request fields in silence, which is exactly why a
     * silent overwrite would never be reported by anything downstream.
     *
     * @param string $field Name of the credential field found in the body. Never its value.
     */
    public static function credentialFieldInRequestBody(string $operation, string $field): self
    {
        return new self(sprintf(
            'The %s request body declared the credential field "%s". Credentials are '
            . 'merged by the transport and must never be built into a request object.',
            $operation,
            $field,
        ));
    }

    /**
     * The verified response names a different order than the callback did.
     *
     * Raised by Vpos::verify() when GetPaymentDetails answers with an `OrderID`
     * that is not identical to the callback's `orderID`. That is a real attack
     * and not a mismatch of bookkeeping: the BackURL carries no signature
     * (CONVENTIONS.md §4.10), so an attacker can replay a *genuine* `paymentID`
     * from somebody else's order while supplying their own `orderID`, and a
     * merchant who looks their order up by the callback's value then ships
     * goods against a payment made for something else.
     *
     * Only the two field names are reported, and this factory therefore takes
     * no parameters at all. Every other factory here that echoes a value echoes
     * a locally computed one — a second count, a minor-unit count, an attempt
     * budget — whose shape is fixed by its own signature. The callback's
     * `orderID` is neither: CONVENTIONS.md §6 makes BackURL parameters
     * untrusted input, and an exception message reaches logs, so embedding it
     * would hand an attacker a writable line in the merchant's log. The
     * response's `OrderID` is withheld for symmetry, since printing one side of
     * a comparison and not the other invites a reader to assume the printed
     * side is the wrong one.
     *
     * Which side is authoritative is stated in the message rather than left to
     * the reader: the callback is the untrusted side, the GetPaymentDetails
     * response is the answer.
     */
    public static function callbackOrderMismatch(): self
    {
        return new self(
            'The callback\'s "orderID" and the GetPaymentDetails response\'s "OrderID" '
            . 'disagree, so this callback does not belong to the order it names. The '
            . 'callback is unsigned and untrusted; the response is authoritative. '
            . 'Neither value is reported here — the callback\'s is attacker-controlled '
            . 'and this message reaches logs.',
        );
    }

    /**
     * The verified response named no order at all, so the callback's order
     * identity could not be confirmed.
     *
     * Raised by Vpos::verify() when GetPaymentDetails answers with an `OrderID`
     * that is present but blank — empty or whitespace-only. That is a distinct
     * condition from callbackOrderMismatch() and deliberately carries a distinct
     * message: nothing disagreed, because the gateway supplied no order identity
     * to disagree with. Reporting it as a mismatch would state something false —
     * the callback may well belong to the order it names, and nothing here can
     * tell.
     *
     * It refuses rather than skips. A null `OrderID` skips the cross-check,
     * because a check cannot be made against an absent value; a blank one is a
     * value the gateway chose to send, and treating it as absent would silently
     * remove order-identity protection. A present-but-empty `OrderID` is not
     * hypothetical at this endpoint — probe B2's failed lookup returned exactly
     * that — so this path is reachable and failing closed is the only answer
     * that cannot be exploited. A completed payment has since been observed
     * returning a populated `OrderID` (probe case P3), so the shape this refuses
     * is not the one a settled payment produces; that makes the refusal cheap,
     * not unnecessary.
     *
     * Splitting the branch is also what makes a log readable: a merchant seeing
     * this message knows the gateway answered thinly, and a merchant seeing
     * callbackOrderMismatch() knows two identifiers actually disagreed, which is
     * a possible replay. One message covering both would have to be vague enough
     * to distinguish neither.
     *
     * Like callbackOrderMismatch(), this factory takes no parameters at all. The
     * callback's `orderID` is attacker-controlled — CONVENTIONS.md §6 makes
     * BackURL parameters untrusted input — and an exception message reaches
     * logs, so embedding it would hand an attacker a writable line in the
     * merchant's log. The response's side is blank by definition and so has
     * nothing to report.
     */
    public static function callbackOrderUnconfirmable(): self
    {
        return new self(
            'The GetPaymentDetails response carried a blank "OrderID", so this '
            . 'callback\'s order identity could not be confirmed against it and the '
            . 'call is refused rather than trusted. Nothing disagreed — the gateway '
            . 'named no order. The callback is unsigned and untrusted, so its '
            . '"paymentID" alone says nothing about which order was paid. Compare '
            . 'your own order record against the response yourself, or treat the '
            . 'payment as unconfirmed. The callback\'s value is not reported here — '
            . 'it is attacker-controlled and this message reaches logs.',
        );
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * This class declares no fields of its own, and no factory here takes a
     * `previous`: neither a field rejected before dispatch nor an order identity
     * the gateway's own answer leaves unconfirmed has an underlying exception to
     * wrap. So chainDropped() answers false on every restored instance, which is
     * the truth rather than a default.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, []);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
    }
}
