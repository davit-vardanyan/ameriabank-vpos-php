<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use LogicException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A PSR-18 network failure that refuses to hand back the request it was sent.
 *
 * PSR-18 declares `getRequest(): RequestInterface` and says nothing about what a
 * client may do instead of returning one. A wrapper that lost the request on the
 * way out, a decorator that rebuilds it lazily from state it no longer holds, a
 * client shutting down under it — each is free to throw from that accessor and
 * remain a valid implementation, and there is no way to ask an object whether
 * its accessor will behave short of calling it.
 *
 * Which matters because the transport calls it from inside a catch block.
 * Before that call was guarded, this shape sent a bare LogicException out of
 * HttpTransport::send() for an idempotent and a non-idempotent request alike:
 * `catch (VposExceptionInterface)` caught nothing (CONVENTIONS.md §5), and a
 * capture that failed in transport lost the IndeterminateStateException that
 * tells the caller to reconcile rather than retry (§4.5).
 *
 * It really holds the request, body and all — the payload is present and out of
 * reach, which is the situation being modelled. It is not held as a plain string
 * the way PayloadHoldingNetworkException holds one, and that is deliberate: this
 * object outlives its test inside the suite's own graph, and a raw credential
 * string on a retained property is reachable from the frame arguments of
 * unrelated tests. A PSR-7 body is a stream, which a graph-walking printer
 * reaches as a resource handle and stops at.
 *
 * Extends RuntimeException, which is inheritance — permitted in tests/ and,
 * per CONVENTIONS.md §5, nowhere in src/.
 */
final class UninspectableNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $withheldRequest)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        throw new LogicException('This client will not hand back the request it was sent.');
    }

    /**
     * The request the accessor above refuses to return.
     *
     * Exists so the property is not dead. No production code reads it — the
     * whole point of this shape is that the package asks once, is refused, and
     * is not allowed to look again.
     */
    public function withheldRequest(): RequestInterface
    {
        return $this->withheldRequest;
    }
}
