<?php

namespace JustRau\PrettySlackLogs\Slack;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class BlockKitFormatter
{
    private const MAX_BLOCK_TEXT = 2900;

    private const MAX_FIELD_TEXT = 1900;

    private const MAX_STACK_FRAMES = 6;

    private const MAX_PREVIOUS = 3;

    /**
     * Build the Slack webhook payload (legacy attachment + Block Kit blocks).
     *
     * @return array<string, mixed>
     */
    public function format(LogRecord $record): array
    {
        $exception = $record->context['exception'] ?? null;
        $exception = $exception instanceof Throwable ? $exception : null;

        $blocks = array_values(array_filter([
            $this->headerBlock($record, $exception),
            $this->envContextBlock(),
            $this->messageBlock($record, $exception),
            $this->locationBlock($exception),
            $this->sourceBlock(),
            $this->stackBlock($exception),
            $this->previousBlock($exception),
            $this->extraContextBlock($record),
            $this->footerBlock($record),
        ]));

        return [
            'attachments' => [
                [
                    'color' => $this->colorForLevel($record->level),
                    'blocks' => $blocks,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerBlock(LogRecord $record, ?Throwable $exception): array
    {
        $emoji = $this->emojiForLevel($record->level);
        $level = strtoupper($record->level->getName());
        $title = $exception !== null ? $exception::class : Str::limit($record->message, 100, '…');

        return [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $this->truncate("{$emoji} {$level} · {$title}", 150),
                'emoji' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envContextBlock(): array
    {
        $parts = [
            '*'.config('app.env').'*',
            config('app.name'),
        ];

        $commit = $this->gitCommit();
        $branch = $this->gitBranch();

        if ($commit !== null) {
            $parts[] = '`'.$commit.($branch !== null ? " ({$branch})" : '').'`';
        }

        $hostname = gethostname();

        if (is_string($hostname) && $hostname !== '') {
            $parts[] = 'host: `'.$hostname.'`';
        }

        return [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => implode(' · ', $parts),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageBlock(LogRecord $record, ?Throwable $exception): array
    {
        $message = $exception?->getMessage() ?: $record->message;
        $text = "*Message*\n```".$this->truncate($message, self::MAX_FIELD_TEXT).'```';

        return [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $text],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationBlock(?Throwable $exception): ?array
    {
        if ($exception === null) {
            return null;
        }

        $appFrame = $this->firstAppFrame($exception);
        $rawFrame = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'class' => null,
            'function' => null,
            'type' => null,
        ];

        $primary = $appFrame ?? $rawFrame;
        $primaryText = $this->formatFrame($primary);

        $fallback = $appFrame !== null ? $this->formatFrame($rawFrame) : null;

        $text = "*Location*\n`{$primaryText}`";

        if ($fallback !== null && $fallback !== $primaryText) {
            $text .= "\n_Thrown at_ `{$fallback}`";
        }

        return [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $text],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourceBlock(): ?array
    {
        if (app()->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];
            if ($argv === []) {
                return null;
            }

            $command = implode(' ', array_map(fn ($a) => (string) $a, $argv));

            return [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Console*\n```".$this->truncate($command, self::MAX_FIELD_TEXT).'```',
                ],
            ];
        }

        $request = Request::instance();
        $route = Route::current();
        $user = Auth::user();

        $fields = [
            $this->field('Request Method', $request->method()),
            $this->field('URL', $this->truncate($request->fullUrl(), 1500)),
            $this->field('Route', $route?->getName() ?? $route?->getActionName() ?? '—'),
            $this->field('IP', $request->ip() ?? '—'),
            $this->field('User', $user !== null ? "{$user->getAuthIdentifier()} ({$this->userLabel($user)})" : 'guest'),
            $this->field('User-Agent', $this->truncate((string) $request->userAgent(), 200)),
        ];

        return [
            'type' => 'section',
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stackBlock(?Throwable $exception): ?array
    {
        if ($exception === null) {
            return null;
        }

        $frames = $this->appFrames($exception, self::MAX_STACK_FRAMES);

        if ($frames === []) {
            return null;
        }

        $lines = array_map(fn (array $f) => $this->formatFrame($f), $frames);
        $text = "*Stack (app frames)*\n```".$this->truncate(implode("\n", $lines), self::MAX_FIELD_TEXT).'```';

        return [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $text],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previousBlock(?Throwable $exception): ?array
    {
        if ($exception === null || $exception->getPrevious() === null) {
            return null;
        }

        $lines = [];
        $previous = $exception->getPrevious();
        $count = 0;

        while ($previous !== null && $count < self::MAX_PREVIOUS) {
            $appFrame = $this->firstAppFrame($previous);
            $rawFrame = [
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
                'class' => null,
                'function' => null,
                'type' => null,
            ];

            $primary = $appFrame ?? $rawFrame;
            $primaryText = $this->formatFrame($primary);

            $entry = '*Caused by* `'.$previous::class.'`: '.$this->truncate($previous->getMessage(), 300);
            $entry .= "\n`{$primaryText}`";

            if ($appFrame !== null) {
                $thrownAt = $this->formatFrame($rawFrame);
                if ($thrownAt !== $primaryText) {
                    $entry .= "\n_Thrown at_ `{$thrownAt}`";
                }
            }

            $lines[] = $entry;
            $previous = $previous->getPrevious();
            $count++;
        }

        return [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $this->truncate(implode("\n\n", $lines), self::MAX_BLOCK_TEXT),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extraContextBlock(LogRecord $record): ?array
    {
        $context = $record->context;
        unset($context['exception']);

        if ($context === []) {
            return null;
        }

        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false) {
            return null;
        }

        return [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => "*Context*\n```".$this->truncate($json, self::MAX_FIELD_TEXT).'```',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function footerBlock(LogRecord $record): array
    {
        return [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => $record->datetime->format('Y-m-d H:i:s T'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $label, string $value): array
    {
        return [
            'type' => 'mrkdwn',
            'text' => "*{$label}*\n{$value}",
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstAppFrame(Throwable $exception): ?array
    {
        foreach ($exception->getTrace() as $frame) {
            if ($this->isAppFrame($frame)) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function appFrames(Throwable $exception, int $max): array
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            if (! $this->isAppFrame($frame)) {
                continue;
            }

            $frames[] = $frame;

            if (count($frames) >= $max) {
                break;
            }
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function isAppFrame(array $frame): bool
    {
        $file = $frame['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return false;
        }

        if (str_contains($file, '/vendor/')) {
            return false;
        }

        return ! in_array($this->relativePath($file), ['public/index.php', 'artisan', 'server.php'], true);
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function formatFrame(array $frame): string
    {
        $file = is_string($frame['file'] ?? null) ? $this->relativePath($frame['file']) : '[internal]';
        $line = isset($frame['line']) ? ':'.$frame['line'] : '';

        $callable = '';
        $class = $frame['class'] ?? null;
        $type = $frame['type'] ?? null;
        $function = $frame['function'] ?? null;

        if (is_string($function) && $function !== '') {
            $callable = ' — '.($class !== null ? $class.$type.$function : $function).'()';
        }

        return $file.$line.$callable;
    }

    private function relativePath(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 18).'… (truncated)';
    }

    private function colorForLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug, Level::Info => '#3b82f6',
            Level::Notice => '#0ea5e9',
            Level::Warning => '#f59e0b',
            Level::Error => '#ef4444',
            Level::Critical => '#dc2626',
            Level::Alert, Level::Emergency => '#7f1d1d',
        };
    }

    private function emojiForLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug => ':mag:',
            Level::Info => ':information_source:',
            Level::Notice => ':bell:',
            Level::Warning => ':warning:',
            Level::Error => ':rotating_light:',
            Level::Critical => ':fire:',
            Level::Alert => ':rotating_light:',
            Level::Emergency => ':sos:',
        };
    }

    private function userLabel(mixed $user): string
    {
        foreach (['email', 'name', 'username'] as $attribute) {
            $value = $user->{$attribute} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $user::class;
    }

    private function gitCommit(): ?string
    {
        return once(function (): ?string {
            $head = base_path('.git/HEAD');

            if (! is_readable($head)) {
                return null;
            }

            $content = trim((string) @file_get_contents($head));

            if (str_starts_with($content, 'ref: ')) {
                $ref = base_path('.git/'.substr($content, 5));

                if (! is_readable($ref)) {
                    return null;
                }

                $content = trim((string) @file_get_contents($ref));
            }

            return $content !== '' ? substr($content, 0, 7) : null;
        });
    }

    private function gitBranch(): ?string
    {
        return once(function (): ?string {
            $head = base_path('.git/HEAD');

            if (! is_readable($head)) {
                return null;
            }

            $content = trim((string) @file_get_contents($head));

            if (! str_starts_with($content, 'ref: refs/heads/')) {
                return null;
            }

            return substr($content, strlen('ref: refs/heads/'));
        });
    }
}
