<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use RuntimeException;

use function sprintf;

use Throwable;

/**
 * The gateway answered, and the answer was a failure.
 *
 * Non-final because the hierarchy is the extension point; the constructor is
 * final so every subclass shares one signature. See CONVENTIONS.md §5.
 *
 * responseCode() returns the raw wire value. Its type varies by endpoint —
 * int from InitPayment, string elsewhere (CONVENTIONS.md §4.3) — and this class
 * deliberately does not normalise it or depend on the ResponseCode
 * value object.
 *
 * The serialization hooks are declared here once and inherited by
 * AuthenticationException, DeclinedException and DuplicateOrderException: the
 * constructor is final, so no subclass can add a field they would need to know
 * about. Support\ExceptionState records what is dropped and why.
 */
class ApiException extends RuntimeException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    /**
     * @param string $responseMessage The gateway's ResponseMessage field. Never the raw response body, which may carry card data (CONVENTIONS.md §5, §6).
     */
    final public function __construct(
        string $message,
        private readonly int|string $responseCode,
        private readonly string $responseMessage,
        private readonly string $operation,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param string $responseMessage The gateway's ResponseMessage field, interpolated into the message. Never the raw response body (CONVENTIONS.md §5, §6).
     */
    public static function fromResponse(
        string $operation,
        int|string $responseCode,
        string $responseMessage,
    ): static {
        return new static(
            sprintf(
                '%s failed with response code %s: %s',
                $operation,
                $responseCode,
                $responseMessage === '' ? '(no message)' : $responseMessage,
            ),
            $responseCode,
            $responseMessage,
            $operation,
        );
    }

    public function responseCode(): int|string
    {
        return $this->responseCode;
    }

    public function responseMessage(): string
    {
        return $this->responseMessage;
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
     * Everything a queued job needs to decide what to do on retry, and nothing
     * a printer could follow back to a credential.
     *
     * The response code is passed through untouched so its `int|string` wire
     * type survives (CONVENTIONS.md §4.3).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, [
            'operation' => $this->operation,
            'responseCode' => $this->responseCode,
            'responseMessage' => $this->responseMessage,
        ]);
    }

    /**
     * Restores without a constructor, which is why the readonly fields can be
     * assigned here: `unserialize()` hands back an object whose properties are
     * uninitialized, and this method is declared in the scope that declares them.
     *
     * Deliberately does not throw, where Config\Credentials::__unserialize()
     * does. A restored decline is still a usable decline; throwing here would
     * reinstate the fatal these hooks exist to remove.
     *
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
        $this->operation = ExceptionState::string($data, 'operation');
        $this->responseCode = ExceptionState::responseCode($data, 'responseCode');
        $this->responseMessage = ExceptionState::string($data, 'responseMessage');
    }
}
