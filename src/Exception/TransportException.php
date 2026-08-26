<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use RuntimeException;

use function sprintf;

use Throwable;

/**
 * The request never produced a usable response: DNS, TLS, connection or read
 * failure.
 *
 * Never thrown for an HTTP 5xx. The gateway returns 500 as a business answer
 * (CONVENTIONS.md §4.2), so a 5xx is not a transport fault and must never be
 * retried. How a 5xx is surfaced instead is decided by the transport task.
 */
final class TransportException extends RuntimeException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    public function __construct(
        string $message,
        private readonly string $operation,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Names the cause's class, never its message: a PSR-18 client's exception
     * text can embed a response-body excerpt, which CONVENTIONS.md §6 forbids
     * in a message.
     *
     * The class name is a parameter rather than `$previous::class` because the
     * two are no longer the same thing. The transport attaches a redacted
     * stand-in — a PSR-18 exception hands back the request it was sent, whose
     * body is the merged credential payload — and the stand-in's own class name
     * would say only that something was redacted. What a merchant needs in the
     * message is which client failed, so the caller passes it.
     *
     * @param class-string $failureClass Class of the underlying failure, for the message.
     * @param Throwable    $previous     The failure, or a scrubbed stand-in for it.
     */
    public static function requestFailed(string $operation, string $failureClass, Throwable $previous): self
    {
        return new self(
            sprintf('The %s request could not be completed: %s', $operation, $failureClass),
            $operation,
            $previous,
        );
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * The dropped `previous` is the whole reason these hooks exist: it is a
     * PSR-18 exception or the scrubbed stand-in Http\FailureRedactor builds for
     * one, and both hand back the request they were sent, whose body is the
     * merged credential payload (CONVENTIONS.md §5, §6).
     *
     * The failing client's class name is not lost with it — requestFailed()
     * already put it in the message, for exactly this reason.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, ['operation' => $this->operation]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
        $this->operation = ExceptionState::string($data, 'operation');
    }
}
