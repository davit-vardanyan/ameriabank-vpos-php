<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * The scrubbed stand-in for a PSR-18 NetworkExceptionInterface.
 *
 * Built by FailureRedactor, which is where the reasoning lives. Three points
 * belong here rather than there, because they are properties of the class:
 *
 * - It implements NetworkExceptionInterface and nothing else. A caller who
 *   catches that interface on getPrevious() still catches this, and a caller who
 *   catches RequestExceptionInterface still does not — which is the whole reason
 *   there are three of these classes instead of one implementing all three.
 * - It holds no reference to the exception it stands in for. A reference would
 *   be walked by print_r(), var_dump() and every error reporter that serialises
 *   an exception graph, which is the leak this class exists to close.
 * - Its previous is null. The original's own chain is dropped for the same
 *   reason: this package can vouch for what it copied out of one PSR-18
 *   exception, not for what an arbitrary Throwable further down holds.
 *
 * ## Why the message is assembled here rather than at the call site
 *
 * Every string this package can emit as an exception message lives behind a
 * named static factory, so that §6's redaction guarantee can be audited by
 * reading one place. This class is one of the three sites where that string is
 * not one this package wrote: `standingInFor()` copies the message out of
 * whichever PSR-18 client the consumer installed, about a request whose body
 * held the merchant's password. Exempting it would audit every message the
 * package authors and skip the only one it does not — so the read happens here,
 * inside the factory, and the constructor is private so there is no other door.
 *
 * @internal
 */
final class RedactedNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    /**
     * $message is marked sensitive because it is a foreign string: a client is
     * free to embed the request it failed on, and a constructor argument is a
     * stack-frame argument every graph-walking printer reads. FailureRedactor
     * overwrites this object's trace with the original's argument-free frames
     * immediately afterwards, so the marking is the second of two locks rather
     * than the only one.
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
     * Exactly two things are asked of $failure, and the engine guarantees both:
     * `::class` is read off the object by the VM and runs no userland code, and
     * getMessage() is declared final on Exception and final on Error, which are
     * the only two classes a userland Throwable may extend. Nothing else is
     * read here, and no reference to $failure survives the call.
     *
     * The code and the redacted request arrive as arguments rather than being
     * taken from $failure, because deciding what a code is worth carrying and
     * scrubbing a request are FailureRedactor's rules, made against its own
     * state. This class assembles; it does not redact.
     *
     * @param NetworkExceptionInterface $failure         The client's own failure. Dropped, never stored.
     * @param int                       $code            The original's code, when it is an int a stand-in can carry.
     * @param RequestInterface          $redactedRequest The failing request, already scrubbed by FailureRedactor.
     */
    public static function standingInFor(
        #[SensitiveParameter]
        NetworkExceptionInterface $failure,
        int $code,
        RequestInterface $redactedRequest,
    ): self {
        return new self($failure->getMessage(), $code, $failure::class, $redactedRequest);
    }

    /**
     * The request that failed, with its body redacted.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * The class of the PSR-18 exception this stands in for.
     *
     * The one piece of the original that neither getMessage() nor getCode()
     * carries, and the piece a merchant reads first: `GuzzleHttp\…\ConnectException`
     * and `Symfony\…\TransportException` fail for different reasons.
     */
    public function originalClass(): string
    {
        return $this->originalClass;
    }
}
