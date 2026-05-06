<?php

use Illuminate\Http\Request;
use JustRau\PrettySlackLogs\Slack\BlockKitFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function pretendHttpContext(): void
{
    $app = app();

    (function (): void {
        $this->isRunningInConsole = false;
    })->call($app);
}

function bindRequest(string $method, string $url, array $headers = []): void
{
    $request = Request::create($url, $method, server: array_combine(
        array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)),
        array_values($headers),
    ));

    app()->instance('request', $request);
}

function makeHttpRecord(): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-05-06 20:58:00'),
        channel: 'pretty-slack',
        level: Level::Error,
        message: 'http boom',
        context: [],
    );
}

it('captures the HTTP request method, URL, and User-Agent in the source block', function () {
    pretendHttpContext();
    bindRequest('GET', 'http://example.test/__test/error?foo=bar', [
        'User-Agent' => 'pest-runner/1.0',
    ]);

    $payload = (new BlockKitFormatter)->format(makeHttpRecord());

    $blocks = $payload['attachments'][0]['blocks'];
    $sourceFields = collect($blocks)
        ->first(fn (array $b) => ($b['type'] ?? null) === 'section' && isset($b['fields']))['fields']
        ?? [];
    $rendered = collect($sourceFields)->pluck('text')->implode("\n");

    expect($rendered)
        ->toContain('*Request Method*')
        ->toContain('GET')
        ->toContain('/__test/error?foo=bar')
        ->toContain('pest-runner/1.0');
});

it('renders guest in the User field when no user is authenticated', function () {
    pretendHttpContext();
    bindRequest('GET', 'http://example.test/__test/guest');

    $payload = (new BlockKitFormatter)->format(makeHttpRecord());

    $sourceFields = collect($payload['attachments'][0]['blocks'])
        ->first(fn (array $b) => ($b['type'] ?? null) === 'section' && isset($b['fields']))['fields']
        ?? [];

    expect(collect($sourceFields)->pluck('text'))
        ->toContain("*User*\nguest");
});
