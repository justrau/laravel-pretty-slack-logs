<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::fake();
});

it('posts a Block Kit payload to the configured webhook on error level logs', function () {
    Log::channel('pretty-slack')->error('something failed', ['key' => 'value']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.test/services/AAA/BBB/CCC'
            && isset($request->data()['attachments'][0]['blocks']);
    });
});

it('does not post for sub-threshold log levels', function () {
    Log::channel('pretty-slack')->info('just an info');
    Log::channel('pretty-slack')->debug('just a debug');

    Http::assertNothingSent();
});

it('does not post when the webhook URL is empty', function () {
    config(['logging.channels.pretty-slack.url' => null]);

    Log::channel('pretty-slack')->error('still nothing');

    Http::assertNothingSent();
});
