<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use RuntimeException;

use function sprintf;

use Throwable;

/**
 * A non-idempotent operation failed in transport, so its outcome is unknown.
 *
 * The request may have reached the gateway and succeeded, or never arrived.
 * Retrying could capture or refund twice. Reconcile with GetPaymentDetails
 * before taking any further action.
 *
 * Deliberately NOT a subtype of TransportException: a caller that catches
 * transport failures and retries them must not be able to swallow this one by
 * accident. See CONVENTIONS.md §5.
 */
final class IndeterminateStateException extends RuntimeException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    public function __construct(
        string $message,
        private readonly string $operation,
        private readonly ?string $paymentId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function afterTransportFailure(
        string $operation,
        ?string $paymentId,
        Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'The %s request failed in transport and its outcome is unknown. '
                . 'Do not retry. Reconcile with GetPaymentDetails%s before acting.',
                $operation,
                $paymentId === null ? '' : sprintf(' for payment %s', $paymentId),
            ),
            $operation,
            $paymentId,
            $previous,
        );
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function paymentId(): ?string
    {
        return $this->paymentId;
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * The PaymentID survives, because it is the one thing a restored instance
     * needs: this exception's entire instruction is to reconcile with
     * GetPaymentDetails, and a queued job that lost the identifier cannot.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, [
            'operation' => $this->operation,
            'paymentId' => $this->paymentId,
        ]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
        $this->operation = ExceptionState::string($data, 'operation');
        $this->paymentId = ExceptionState::nullableString($data, 'paymentId');
    }
}
