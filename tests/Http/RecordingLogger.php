<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Http;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps every record so a test can read it back.
 *
 * A named class rather than an anonymous one because tests need the typed
 * `$records` property, and an anonymous class assigned to a `LoggerInterface`
 * property loses it. It extends AbstractLogger, which is inheritance —
 * permitted here and nowhere in src/, where CONVENTIONS.md §5's
 * final-by-default rule applies.
 *
 * Records are kept verbatim, *including* whatever the transport put in the
 * context. That is the point: a redaction test that inspected a scrubbed copy
 * would be asserting against its own fixture rather than against what a
 * consumer's logger would receive.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
