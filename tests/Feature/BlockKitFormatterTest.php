<?php

use Justrau\PrettySlackLogs\Slack\BlockKitFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function makeRecord(
    Level $level = Level::Error,
    string $message = 'something went wrong',
    array $context = [],
    string $channel = 'pretty-slack',
): LogRecord {
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-05-06 20:58:00'),
        channel: $channel,
        level: $level,
        message: $message,
        context: $context,
    );
}

function payloadBlocks(array $payload): array
{
    return $payload['attachments'][0]['blocks'];
}

function blockText(array $block): string
{
    if (isset($block['text']['text'])) {
        return $block['text']['text'];
    }

    if (isset($block['elements'])) {
        return implode("\n", array_map(fn ($e) => $e['text'] ?? '', $block['elements']));
    }

    return '';
}

it('produces a colored attachment per level', function (Level $level, string $expected) {
    $payload = (new BlockKitFormatter)->format(makeRecord(level: $level));

    expect($payload['attachments'][0]['color'])->toBe($expected);
})->with([
    'warning' => [Level::Warning, '#f59e0b'],
    'error' => [Level::Error, '#ef4444'],
    'critical' => [Level::Critical, '#dc2626'],
    'emergency' => [Level::Emergency, '#7f1d1d'],
]);

it('uses the exception class as the header title when present', function () {
    $exception = new RuntimeException('kaboom');
    $payload = (new BlockKitFormatter)->format(makeRecord(context: ['exception' => $exception]));

    $header = payloadBlocks($payload)[0];

    expect($header['type'])->toBe('header')
        ->and($header['text']['text'])->toContain('ERROR')
        ->and($header['text']['text'])->toContain('RuntimeException');
});

it('uses the log message as the header title when no exception is present', function () {
    $payload = (new BlockKitFormatter)->format(makeRecord(message: 'queue stalled'));

    expect(payloadBlocks($payload)[0]['text']['text'])->toContain('queue stalled');
});

it('renders a Caused by chain when previous exceptions exist', function () {
    $root = new PDOException('SQLSTATE driver fail');
    $wrapper = new DomainException('cannot load dashboard', previous: $root);

    $payload = (new BlockKitFormatter)->format(makeRecord(context: ['exception' => $wrapper]));

    $text = implode("\n", array_map(blockText(...), payloadBlocks($payload)));

    expect($text)
        ->toContain('Caused by')
        ->toContain('PDOException')
        ->toContain('SQLSTATE driver fail');
});

it('excludes the exception key from the context block but renders other context as JSON', function () {
    $payload = (new BlockKitFormatter)->format(makeRecord(
        context: [
            'exception' => new RuntimeException('x'),
            'user_id' => 42,
            'meta' => ['k' => 'v'],
        ],
    ));

    $contextBlock = collect(payloadBlocks($payload))
        ->first(fn (array $b) => str_contains(blockText($b), '*Context*'));

    expect($contextBlock)->not->toBeNull()
        ->and(blockText($contextBlock))
        ->toContain('"user_id": 42')
        ->toContain('"meta"')
        ->not->toContain('RuntimeException');
});

it('omits the context block entirely when only an exception was passed', function () {
    $payload = (new BlockKitFormatter)->format(makeRecord(
        context: ['exception' => new RuntimeException('x')],
    ));

    $hasContext = collect(payloadBlocks($payload))
        ->contains(fn (array $b) => str_contains(blockText($b), '*Context*'));

    expect($hasContext)->toBeFalse();
});

it('prefers the first non-vendor frame for the location', function () {
    $exception = new RuntimeException('x');
    $payload = (new BlockKitFormatter)->format(makeRecord(context: ['exception' => $exception]));

    $location = collect(payloadBlocks($payload))
        ->first(fn (array $b) => str_contains(blockText($b), '*Location*'));

    expect(blockText($location))->not->toContain('/vendor/');
});

it('truncates extremely long messages with a marker', function () {
    $payload = (new BlockKitFormatter)->format(makeRecord(message: str_repeat('A', 5000)));

    $messageBlock = collect(payloadBlocks($payload))
        ->first(fn (array $b) => str_contains(blockText($b), '*Message*'));

    expect(blockText($messageBlock))->toContain('… (truncated)');
});
