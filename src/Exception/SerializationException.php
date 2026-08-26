<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use JsonException;
use RuntimeException;

use function sprintf;

use Throwable;

/**
 * A response could not be decoded, or a request could not be encoded.
 *
 * Never include a raw response body in the message. A body may carry card data,
 * and exception messages reach logs. Describe the failure, not the payload.
 */
final class SerializationException extends RuntimeException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    /**
     * Answered from a field rather than from the chain.
     *
     * Derived once, at construction, because the chain does not survive
     * serialization: reading getPrevious() at call time would make
     * causedByJson() answer true before a round trip and false after one, for
     * the same failure. A flag that changes its answer in transit is worse than
     * no flag.
     */
    private readonly bool $causedByJson;

    public function __construct(
        string $message,
        private readonly string $operation,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->causedByJson = $previous instanceof JsonException;
    }

    /**
     * Names the cause's class, never its message: a decoder's exception text
     * can quote the offending payload, which CONVENTIONS.md §6 forbids in a
     * message. The full detail stays reachable through getPrevious().
     */
    public static function malformedJson(string $operation, JsonException $previous): self
    {
        return new self(
            sprintf('The %s response was not valid JSON: %s', $operation, $previous::class),
            $operation,
            $previous,
        );
    }

    /**
     * @param string $detail What was structurally wrong with the document. Never the document itself.
     */
    public static function malformedXml(string $operation, string $detail): self
    {
        return new self(
            sprintf('The %s response was not valid XML: %s', $operation, $detail),
            $operation,
        );
    }

    /**
     * @param string $reason Which field was missing or of the wrong type. Never the value received.
     */
    public static function unexpectedPayload(string $operation, string $reason): self
    {
        return new self(
            sprintf('The %s response had an unexpected shape: %s', $operation, $reason),
            $operation,
        );
    }

    /**
     * True when the failure was caused by a JsonException, in either direction:
     * encoding a request or decoding a response.
     *
     * Survives serialization; see the property.
     */
    public function causedByJson(): bool
    {
        return $this->causedByJson;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * A request body could not be encoded as JSON.
     *
     * The one reachable cause is a caller-supplied string that is not valid
     * UTF-8 — a Description, say, pasted from a Latin-1 source. Every value a
     * request DTO emits is already a wire-ready scalar, so nothing else in the
     * body can fail an encode.
     *
     * Names the cause's class, never its message: a JsonException from the
     * encoder quotes the offending payload, which CONVENTIONS.md §6 keeps out
     * of a message. The full detail stays reachable through getPrevious().
     */
    public static function requestNotEncodable(string $operation, JsonException $previous): self
    {
        return new self(
            sprintf('The %s request could not be encoded as JSON: %s', $operation, $previous::class),
            $operation,
            $previous,
        );
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * The dropped `previous` is a JsonException, whose trace holds the payload
     * the decoder choked on — a response body, which may carry a PAN, an ExpDate
     * and an ApprovalCode (CONVENTIONS.md §6). Http\FailureRedactor strips that
     * trace's arguments before the exception is attached; dropping the object
     * outright is the same decision one step further, taken because a
     * serialized exception travels to a store this package cannot see.
     *
     * causedByJson() is carried instead, so the one thing a caller asked the
     * chain for still has an answer.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, [
            'operation' => $this->operation,
            'causedByJson' => $this->causedByJson,
        ]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
        $this->operation = ExceptionState::string($data, 'operation');
        $this->causedByJson = ExceptionState::bool($data, 'causedByJson');
    }
}
