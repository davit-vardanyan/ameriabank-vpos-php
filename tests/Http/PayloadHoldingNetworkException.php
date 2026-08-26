<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A PSR-18 network failure of the awkward kind, built to be hostile in the three
 * ways a real client is allowed to be.
 *
 * 1. **It keeps the payload in a property of its own.** Nothing in PSR-18 says a
 *    client may not, and several do — the request, the cURL options, the body it
 *    was about to write. The property is private and named nothing this package
 *    could guess, which is the point: what cannot be inspected cannot be
 *    scrubbed, so the only safe treatment of the object is to drop it rather
 *    than reference it. A stand-in that kept a reference would publish this
 *    property through print_r() exactly as it publishes everything else.
 * 2. **Its code is a string.** Throwable::getCode() is untyped and PDOException
 *    famously holds an SQLSTATE there, so a stand-in must survive a code it
 *    cannot pass to a constructor.
 * 3. **It has a previous of its own.** A chain this package did not build and
 *    cannot vouch for.
 *
 * Extends RuntimeException, which is inheritance — permitted in tests/ and,
 * per CONVENTIONS.md §5, nowhere in src/.
 */
final class PayloadHoldingNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly string $rawPayloadTheClientKept,
        int|string $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        // Assigned after the parent constructor because that constructor only
        // accepts an int. Exception::$code is protected, so a subclass may put
        // whatever it likes there — which is the behaviour being reproduced.
        $this->code = $code;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * Exists so the property cannot be removed as unused. No production code
     * reads it — a client's private state is precisely what this package cannot
     * reach.
     */
    public function rawPayload(): string
    {
        return $this->rawPayloadTheClientKept;
    }
}
