<?php

namespace Justrau\PrettySlackLogs\Tests;

use Justrau\PrettySlackLogs\Channel;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.channels.pretty-slack', [
            'driver' => 'custom',
            'via' => Channel::class,
            'url' => 'https://hooks.slack.test/services/AAA/BBB/CCC',
            'level' => 'error',
        ]);
    }
}
