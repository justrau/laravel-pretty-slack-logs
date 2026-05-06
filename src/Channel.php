<?php

namespace JustRau\PrettySlackLogs;

use JustRau\PrettySlackLogs\Slack\BlockKitFormatter;
use JustRau\PrettySlackLogs\Slack\BlockKitHandler;
use Monolog\Level;
use Monolog\Logger;

class Channel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $webhookUrl = is_string($config['url'] ?? null) ? $config['url'] : '';
        $level = Level::fromName(is_string($config['level'] ?? null) ? $config['level'] : 'error');
        $name = is_string($config['name'] ?? null) ? $config['name'] : 'pretty-slack';

        $handler = new BlockKitHandler(
            webhookUrl: $webhookUrl,
            payloadFormatter: new BlockKitFormatter,
            level: $level,
        );

        return new Logger($name, [$handler]);
    }
}
