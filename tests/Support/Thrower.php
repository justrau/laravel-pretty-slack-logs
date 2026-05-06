<?php

namespace JustRau\PrettySlackLogs\Tests\Support;

use RuntimeException;
use Throwable;

class Thrower
{
    public static function plain(string $message = 'thrown from Thrower'): RuntimeException
    {
        return self::build($message);
    }

    public static function chained(string $rootMessage, string $wrapperMessage, ?string $wrapperClass = null): Throwable
    {
        $previous = self::build($rootMessage);
        $class = $wrapperClass ?? \DomainException::class;

        try {
            throw new $class($wrapperMessage, previous: $previous);
        } catch (Throwable $e) {
            return $e;
        }
    }

    private static function build(string $message): RuntimeException
    {
        try {
            throw new RuntimeException($message);
        } catch (RuntimeException $e) {
            return $e;
        }
    }
}
