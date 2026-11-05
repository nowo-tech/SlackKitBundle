<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that keeps every record.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Messages logged so far.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (array $r): string => $r['message'], $this->records);
    }
}
