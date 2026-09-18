<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps what it was told, so a test can assert that an
 * error was recorded for the operator even when the visitor was told nothing.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * PSR-3 declares the context as a plain array, so this signature has to
     * stay that wide even though every caller passes string keys.
     *
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** Everything written, flattened, so one assertion can search all of it. */
    public function text(): string
    {
        $out = '';
        foreach ($this->records as $record) {
            $out .= $record['level'] . ' ' . $record['message'] . ' '
                . json_encode($record['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }

        return $out;
    }
}
