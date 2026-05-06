<?php

namespace Justrau\PrettySlackLogs\Slack;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class BlockKitHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly BlockKitFormatter $payloadFormatter,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->webhookUrl === '') {
            return;
        }

        try {
            Http::asJson()
                ->timeout(5)
                ->connectTimeout(2)
                ->post($this->webhookUrl, $this->payloadFormatter->format($record));
        } catch (Throwable) {
            // Swallow delivery errors; logging must never throw.
        }
    }
}
