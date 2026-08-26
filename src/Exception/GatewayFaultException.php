<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use RuntimeException;

use function sprintf;

/**
 * The gateway refused to answer: an ASP.NET fault envelope, not a business answer.
 *
 * The body is `{"Message":"An error has occurred."}` — ASP.NET's unhandled
 * exception page — carrying no ResponseCode at all. Observed for well-formed
 * requests (CONVENTIONS.md §4.2): GetPaymentDetails on an order that was
 * registered but never attempted, and GetBindings with a PaymentType outside
 * {5, 6}. It is a deliberate refusal, so it is never retried, and it is never a
 * transport fault: the exchange completed.
 *
 * A sibling of ApiException, never a subclass. ApiException means the gateway
 * gave a business answer; a fault means it did not. Its constructor is final and
 * requires a response code, and no response code came off the wire — synthesising
 * one would publish a value on responseCode() that the gateway never sent.
 *
 * Carries the Message text, which is generic and safe. Never the raw response
 * body: a body may carry card data and an exception message reaches logs
 * (CONVENTIONS.md §5, §6).
 *
 * For GetPaymentDetails it means the gateway would not answer, and nothing more
 * may be read into it. The envelope has been observed against a payment that was
 * registered and never attempted, but that state does not predict it: the same
 * state has also been answered with a response code. What separates the two is
 * unestablished and no competing cause has been ruled out. A third outcome is on
 * the record since — HTTP 200 with `ResponseCode` `"00"`, against a payment that
 * actually completed (probe cases P3, P4.1b, P4.3b and P6) — so the space is not
 * exhausted by fault-or-`"550"`, and a fault is not what this endpoint returns
 * for a payment that went through. CONVENTIONS.md §13 holds the evidence; it is
 * not repeated here.
 *
 * @todo unverified — see CONVENTIONS.md §13
 */
final class GatewayFaultException extends RuntimeException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    /**
     * @param string $faultMessage The `Message` field lifted out of the decoded fault envelope. Never the envelope itself, and never any other part of the response (CONVENTIONS.md §5, §6).
     */
    public function __construct(
        string $message,
        private readonly string $operation,
        private readonly int $statusCode,
        private readonly string $faultMessage,
    ) {
        // No code and no previous: the SPL code channel stays 0 for every
        // exception in this package (see tests/Exception), and a fault has no
        // underlying throwable — the exchange completed and the body decoded.
        parent::__construct($message);
    }

    /**
     * The gateway's text is placed last because it carries its own terminating
     * period — `An error has occurred.` — and any clause after it renders a
     * doubled full stop.
     *
     * The status is reported because it is diagnostic — the same envelope has
     * been observed at 500 and at 404 — never because it decided anything.
     * Fault detection is shape-based: a decoded body carrying `Message` and no
     * `ResponseCode`. HTTP status carries no business meaning
     * (CONVENTIONS.md §4.1).
     *
     * @param string $faultMessage The decoded envelope's `Message` value, interpolated into the message. Never the envelope itself (CONVENTIONS.md §5, §6).
     */
    public static function fromFaultEnvelope(
        string $operation,
        int $statusCode,
        string $faultMessage,
    ): self {
        return new self(
            sprintf(
                '%s returned a gateway fault (HTTP %d) carrying no response code, so the '
                . 'request was not answered; do not retry it. The gateway reported: %s',
                $operation,
                $statusCode,
                $faultMessage === '' ? '(no message)' : $faultMessage,
            ),
            $operation,
            $statusCode,
            $faultMessage,
        );
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function faultMessage(): string
    {
        return $this->faultMessage;
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * A fault never has a cause to drop — the exchange completed and the body
     * decoded, so the constructor passes no `previous` — and chainDropped()
     * therefore answers false on every restored instance.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, [
            'operation' => $this->operation,
            'statusCode' => $this->statusCode,
            'faultMessage' => $this->faultMessage,
        ]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
        $this->operation = ExceptionState::string($data, 'operation');
        $this->statusCode = ExceptionState::int($data, 'statusCode');
        $this->faultMessage = ExceptionState::string($data, 'faultMessage');
    }
}
