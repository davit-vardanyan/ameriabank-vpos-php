<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Http;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * The scrubbed stand-in for a PSR-18 failure that is neither network- nor
 * request-shaped.
 *
 * Plain ClientExceptionInterface exposes no getRequest(), so there is no request
 * here to hand back and nothing this class can redact by name. It stands in
 * anyway, and that is the point: a client is free to put whatever it likes in
 * its own properties — the request, the cURL options, the body it was about to
 * send — under names this package has never heard of. What cannot be inspected
 * cannot be scrubbed, so the object is dropped and only the four things that are
 * certainly safe are copied out of it: class name, message, code, and the call
 * site (file, line, and an argument-free trace).
 *
 * That is strictly less information than the original carried. It is also
 * strictly less than the original could leak, and the trade only goes one way:
 * a diagnostic that names the wrong vendor class costs a support round trip,
 * while a diagnostic carrying a password costs a credential.
 *
 * Like its two siblings, this is a site where a string this package did not
 * write becomes an exception message, so the named factories that assemble them
 * live here and the constructor is private. RedactedNetworkException records
 * the reasoning.
 *
 * @internal
 */
final class RedactedClientException extends RuntimeException implements ClientExceptionInterface
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
    ) {
        parent::__construct($message, $code);
    }

    /**
     * The stand-in for a failure that was inspected and carried no request.
     *
     * Asks $failure for its message and its class and nothing else, and keeps
     * no reference to it. The code arrives as an argument because whether a
     * Throwable's code is an int a stand-in can carry is FailureRedactor's
     * judgement, not this class's.
     *
     * @param ClientExceptionInterface $failure The client's own failure. Dropped, never stored.
     * @param int                      $code    The original's code, when it is an int a stand-in can carry.
     */
    public static function standingInFor(#[SensitiveParameter] ClientExceptionInterface $failure, int $code): self
    {
        return new self($failure->getMessage(), $code, $failure::class);
    }

    /**
     * The stand-in for a failure that could not be inspected at all.
     *
     * HttpTransport::redactedFailure() reaches this when building the ordinary
     * stand-in threw — a client whose getRequest(), or whose request's own
     * accessors, raised from inside a catch block. In that state the object has
     * already demonstrated that its accessors are not safe to call, so exactly
     * two are called: `::class`, which the VM answers without running userland
     * code, and getMessage(), which is final on both classes a userland
     * Throwable may extend.
     *
     * The code is reported as 0 rather than read. Throwable::getCode() is
     * untyped — a subclass may hold an SQLSTATE string there — and 0 is what a
     * stand-in already reports for a code it cannot carry (FailureRedactor's
     * codeOf()). Reading it here would be a third question put to an object
     * that has just refused to answer the first, in exchange for a diagnostic
     * integer.
     *
     * @param ClientExceptionInterface $failure The client's own failure. Dropped, never stored.
     */
    public static function forUninspectableFailure(#[SensitiveParameter] ClientExceptionInterface $failure): self
    {
        return self::standingInFor($failure, 0);
    }

    /**
     * The class of the PSR-18 exception this stands in for.
     */
    public function originalClass(): string
    {
        return $this->originalClass;
    }
}
