<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A PSR-18 failure that implements both optional interfaces at once.
 *
 * Nothing forbids it: NetworkExceptionInterface and RequestExceptionInterface
 * are siblings, both extend ClientExceptionInterface, and both declare the same
 * getRequest(). A client that cannot tell a malformed request from a dead socket
 * could implement both and be within the specification.
 *
 * It exists to pin the tie-break. HttpTransport::dispatch() catches
 * NetworkExceptionInterface first, so this shape is retried rather than reported
 * as a configuration error; FailureRedactor must classify it the same way, or a
 * caller inspecting getPrevious() would be told the opposite of what the
 * transport decided.
 */
final class AmbiguousClientException extends RuntimeException implements NetworkExceptionInterface, RequestExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
