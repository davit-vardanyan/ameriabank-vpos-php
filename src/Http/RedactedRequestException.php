<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Http;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * The scrubbed stand-in for a PSR-18 RequestExceptionInterface.
 *
 * Identical in construction to RedactedNetworkException and deliberately not
 * related to it by inheritance: the two exist to be told apart. The transport
 * maps a RequestExceptionInterface to ConfigurationException and never retries
 * it, because the request itself is malformed; a NetworkExceptionInterface is
 * retried when the operation allows. A single stand-in implementing both
 * interfaces would answer `true` to both instanceof checks and quietly make
 * that distinction unavailable to a caller inspecting getPrevious().
 *
 * See FailureRedactor for what is copied and what is dropped.
 *
 * Like its two siblings, this is a site where a string this package did not
 * write becomes an exception message, so the named factory that assembles it
 * lives here and the constructor is private. RedactedNetworkException records
 * the reasoning.
 *
 * @internal
 */
final class RedactedRequestException extends RuntimeException implements RequestExceptionInterface
{
    /**
     * $message is a foreign string; see RedactedNetworkException for why that
     * makes it sensitive even though it is published by getMessage().
     */
    private function __construct(
        #[SensitiveParameter]
        string $message,
        int $code,
        private readonly string $originalClass,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * The stand-in for $failure, carrying $redactedRequest in its place.
     *
     * Asks $failure for its message and its class and nothing else, and keeps
     * no reference to it.
     *
     * @param RequestExceptionInterface $failure         The client's own failure. Dropped, never stored.
     * @param int                       $code            The original's code, when it is an int a stand-in can carry.
     * @param RequestInterface          $redactedRequest The rejected request, already scrubbed by FailureRedactor.
     */
    public static function standingInFor(
        #[SensitiveParameter]
        RequestExceptionInterface $failure,
        int $code,
        RequestInterface $redactedRequest,
    ): self {
        return new self($failure->getMessage(), $code, $failure::class, $redactedRequest);
    }

    /**
     * The request the client rejected, with its body redacted.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * The class of the PSR-18 exception this stands in for.
     */
    public function originalClass(): string
    {
        return $this->originalClass;
    }
}
