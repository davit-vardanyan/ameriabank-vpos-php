<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use LogicException;

use function sprintf;

use Throwable;

/**
 * The client was assembled incorrectly. Always a programming error, never a
 * runtime condition: a missing PSR-18 implementation, a blank credential.
 */
final class ConfigurationException extends LogicException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a round trip. See
     * VposExceptionInterface::chainDropped().
     */
    private ?bool $chainDropped = null;

    public static function noHttpClientFound(Throwable $previous): self
    {
        return new self(
            'No PSR-18 HTTP client could be discovered. Install one '
            . '(for example guzzlehttp/guzzle or symfony/http-client), '
            . 'or pass an implementation explicitly.',
            0,
            $previous,
        );
    }

    public static function noRequestFactoryFound(Throwable $previous): self
    {
        return new self(
            'No PSR-17 request factory could be discovered. Install a PSR-7 '
            . 'implementation (for example nyholm/psr7), or pass factories '
            . 'explicitly.',
            0,
            $previous,
        );
    }

    public static function noStreamFactoryFound(Throwable $previous): self
    {
        return new self(
            'No PSR-17 stream factory could be discovered. Install a PSR-7 '
            . 'implementation (for example nyholm/psr7), or pass factories '
            . 'explicitly.',
            0,
            $previous,
        );
    }

    /**
     * @param non-empty-string $field Name of the credential field. Never its value.
     */
    public static function blankCredential(string $field): self
    {
        return new self(sprintf('Credential field "%s" must not be blank.', $field));
    }

    /**
     * Restoring a serialized Credentials would yield a redacted, silently wrong object.
     */
    public static function credentialsNotUnserializable(): self
    {
        return new self(
            'Credentials cannot be unserialized. Serializing redacts the password, '
            . 'so a restored object would carry a marker where a secret belongs and '
            . 'would fail authentication as if the credentials were wrong. '
            . 'Construct Credentials from your configuration instead.',
        );
    }

    /**
     * Building an exception from a success code is a call-site error.
     *
     * @param string     $operation    Name of the API operation. Never a request or response body.
     * @param int|string $responseCode The raw wire value, rendered as it arrived. Its type varies by endpoint (CONVENTIONS.md §4.3) and is not narrowed here, so a leading zero survives: "00" is not 0.
     */
    public static function successCodeHasNoException(string $operation, int|string $responseCode): self
    {
        return new self(sprintf(
            'Response code %s from %s is a success code; an exception cannot be built from it.',
            $responseCode,
            $operation,
        ));
    }

    /**
     * The PSR-18 client refused the request before sending it.
     *
     * A RequestExceptionInterface means the request itself is malformed — an
     * invalid URI, a header the client will not serialise — which is a defect
     * in this package or in how it was configured, not a network failure. It is
     * never retried, because repeating a malformed request only repeats
     * the defect.
     *
     * Names the cause's class, never its message: a client's exception text can
     * embed the request it rejected, and CONVENTIONS.md §6 keeps a request body
     * out of a message.
     *
     * The class name arrives as its own argument because $previous is a redacted
     * stand-in whose class name would say only that something was redacted. See
     * Http\FailureRedactor for what is copied out of the original and why the
     * original itself is not attached.
     *
     * @param string       $operation    Name of the API operation. Never a request or response body.
     * @param class-string $failureClass Class of the underlying failure, for the message.
     * @param Throwable    $previous     The failure, or a scrubbed stand-in for it.
     */
    public static function requestRejectedByClient(string $operation, string $failureClass, Throwable $previous): self
    {
        return new self(
            sprintf(
                'The %s request was rejected by the HTTP client before it was sent: %s. '
                . 'The request itself is malformed, so it is not retried.',
                $operation,
                $failureClass,
            ),
            0,
            $previous,
        );
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * This class declares no fields of its own: the three discovery failures and
     * requestRejectedByClient() carry their detail in the message, and the
     * `previous` that named the failing component is exactly what may not cross
     * a serialization boundary. Support\ExceptionState records why.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ExceptionState::capture($this, []);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->chainDropped = ExceptionState::restore($this, $data);
    }
}
