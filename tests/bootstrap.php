<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'KurseInformatike\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $base = str_starts_with($relative, 'Tests/')
        ? dirname(__DIR__) . '/tests/' . substr($relative, strlen('Tests/'))
        : dirname(__DIR__) . '/virtuale/src/' . $relative;
    $file = $base . '.php';
    if (is_file($file)) {
        require $file;
    }
});
