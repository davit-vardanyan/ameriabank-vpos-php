<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Support;

use function array_flip;
use function array_intersect_key;

use Exception;

use function is_array;
use function is_int;
use function is_string;

use ReflectionProperty;
use Throwable;

/**
 * The engine-owned half of a package exception's `__serialize()` state.
 *
 * ## Why this exists
 *
 * The transport marks every parameter that can hold a request body, a response
 * body or a credential `#[\SensitiveParameter]`, which is what keeps those bytes
 * out of a stack frame. The engine implements that by replacing the argument in
 * the trace with a `SensitiveParameterValue`, and it refuses to serialize one:
 *
 * ```
 * Exception: Serialization of 'SensitiveParameterValue' is not allowed
 * ```
 *
 * A trace is part of an exception's default serialized state, so without this
 * class every exception the transport throws — including an ordinary response
 * code 20 decline — becomes unserialisable, and a merchant queueing a failed
 * payment job takes a fatal in the worker naming a PHP internal class and
 * nothing about payments. Defining `__serialize()` replaces the default state
 * wholesale, so the trace is never offered to the serialiser at all.
 *
 * ## What it carries and what it drops
 *
 * Kept, because a queued job decides what to do next from them: the message, the
 * throw site, and an argument-free trace. Each exception adds its own fields —
 * the operation, the response code with its `int|string` type intact
 * (CONVENTIONS.md §4.3), the response message.
 *
 * Dropped:
 *
 * - **`previous`.** For a transport failure that is a PSR-18 exception or a
 *   scrubbed stand-in for one, and both hand back the request they were sent,
 *   whose body is the merged credential payload (CONVENTIONS.md §5).
 *   Http\FailureRedactor records why even the stand-in is not vouched for
 *   across a boundary this class cannot see the far side of. That a chain was
 *   dropped is recorded rather than silently lost; see the `chainDropped` key.
 * - **Trace arguments.** The same positive filter Http\FailureRedactor applies to
 *   a third-party failure: what is left is the call path — file, line, function,
 *   class, type — which is the diagnostic half of a frame and holds none of the
 *   data. A key this package has never heard of is dropped rather than kept, so
 *   the cost of an unknown frame key is a thinner diagnostic and never a leak.
 * - **The SPL code channel.** Every exception in this package passes 0 and
 *   tests/Exception pins that, so there is nothing to carry. A restored object
 *   answers 0 from the property default, which is the same answer.
 *
 * ## Why nothing here throws
 *
 * `Config\Credentials::__unserialize()` refuses to restore, because a restored
 * Credentials would hold a redaction marker where a secret belongs and fail
 * authentication as if the merchant had typed the wrong password. An exception
 * has no such failure mode: a restored decline is still a usable decline. So a
 * payload with a missing or wrong-typed key yields a degraded object — an empty
 * message, an empty operation — rather than a TypeError inside `unserialize()`,
 * which would reintroduce the fatal this class exists to remove.
 *
 * @internal
 */
final class ExceptionState
{
    /**
     * The trace keys a serialized frame keeps.
     *
     * A positive filter, not a blacklist: `args` is the key that carries the
     * payload today, but a frame is a structure this package does not own, and
     * an unknown key that survives is a leak while one that is dropped is a
     * slightly thinner diagnostic.
     *
     * @var list<string>
     */
    private const array SAFE_FRAME_KEYS = ['file', 'line', 'function', 'class', 'type'];

    /**
     * The state array an exception's `__serialize()` returns.
     *
     * $own carries the fields the exception declares itself. Its keys must not
     * collide with the ones below, and none of them do: every exception in this
     * package names its fields `operation`, `responseCode`, `responseMessage`,
     * `faultMessage`, `statusCode`, `paymentId` or `causedByJson`.
     *
     * @param array<string, bool|int|string|null> $own
     *
     * @return array<string, mixed>
     */
    public static function capture(Exception $source, array $own): array
    {
        return [
            'message' => $source->getMessage(),
            'file' => $source->getFile(),
            'line' => $source->getLine(),
            'trace' => self::safeFrames($source->getTrace()),
            'chainDropped' => $source->getPrevious() instanceof Throwable,
            ...$own,
        ];
    }

    /**
     * Writes the engine-owned half back onto a freshly unserialized $target.
     *
     * `unserialize()` builds the object through the exception create_object
     * handler and never runs a constructor, so `file`, `line` and `trace` arrive
     * describing the *restore* site — a frame inside the consumer's queue worker,
     * whose arguments are the worker's own, not this package's to publish.
     * Overwriting them with the captured values is what makes `getFile()` and
     * `getTraceAsString()` agree with each other and with where the failure
     * actually happened.
     *
     * The trace is filtered again on the way in rather than trusted: a serialized
     * payload is bytes from somewhere, and a frame reaching print_r() is exactly
     * the shape this filter exists for.
     *
     * Reflection because there is no other door — `trace` is private to Exception
     * and `message`, `file` and `line` are protected, so no caller outside the
     * hierarchy can assign them. Confined to one method, as in Http\FailureRedactor.
     *
     * @param array<array-key, mixed> $data
     *
     * @return bool whether the captured exception carried a `previous` that was dropped
     */
    public static function restore(Exception $target, array $data): bool
    {
        self::write($target, 'message', self::string($data, 'message'));
        self::write($target, 'file', self::string($data, 'file'));
        self::write($target, 'line', self::int($data, 'line'));
        self::write($target, 'trace', self::frames($data));

        return self::bool($data, 'chainDropped');
    }

    /**
     * $data[$key] when it is a string, and the empty string otherwise.
     *
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * $data[$key] when it is a string, and null otherwise.
     *
     * Distinct from string() because a nullable field — a PaymentID that was not
     * known when the request failed — must restore as null rather than as an
     * empty string a caller might interpolate into a reconciliation call.
     *
     * @param array<array-key, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * $data[$key] when it is an int, and 0 otherwise.
     *
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * $data[$key] when it is true, and false otherwise.
     *
     * Identity, not equality: a truthy string out of a hand-edited payload is not
     * a flag this package set.
     *
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? null) === true;
    }

    /**
     * A response code with its wire type intact.
     *
     * CONVENTIONS.md §4.3: InitPayment answers int, every other endpoint
     * answers string, and failure code 20 arrives as both `int 20` and `string
     * "20"`. A round trip that coerced one into the other would publish a value
     * the gateway never sent, and `"00"` would become `0`.
     *
     * Falls back to the empty string, which is not a code the gateway issues, so
     * a corrupt payload reads as obviously absent rather than as a plausible one.
     *
     * @param array<array-key, mixed> $data
     */
    public static function responseCode(array $data, string $key): int|string
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * The `trace` entry of $data, filtered, or an empty trace when it is not one.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<array<array-key, mixed>>
     */
    private static function frames(array $data): array
    {
        $trace = $data['trace'] ?? null;

        return is_array($trace) ? self::safeFrames($trace) : [];
    }

    /**
     * @param array<array-key, mixed> $trace
     *
     * @return list<array<array-key, mixed>>
     */
    private static function safeFrames(array $trace): array
    {
        $safe = [];
        $keep = array_flip(self::SAFE_FRAME_KEYS);

        foreach ($trace as $frame) {
            if (is_array($frame)) {
                $safe[] = array_intersect_key($frame, $keep);
            }
        }

        return $safe;
    }

    private static function write(Exception $target, string $property, mixed $value): void
    {
        (new ReflectionProperty(Exception::class, $property))->setValue($target, $value);
    }
}
