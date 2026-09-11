<?php

class Logger {
    private static float $start;
    private static float $prev;

    private static function time(): float {
        return microtime(true) * 1000; // sec * 1000 = ms
    }

    public static function init(): void {
        self::$start = self::time();
        self::$prev = self::$start;
        register_shutdown_function(function () { Logger::log('shutdown'); });
        Logger::log('--------------------------------');
    }

    public static function log(?string $message = null, $obj = null): void {
        $now = self::time();
        $message = number_format($now - self::$start, 3) . ' ' . number_format($now - self::$prev, 3) . ' ' . $message;

        @error_log($message . ' ' . var_export($obj, true));

        self::$prev = $now;
    }
}

Logger::init();
