<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['virtuale/src', 'virtuale/bootstrap', 'virtuale/api', 'database', 'tests'];
$failed = false;

foreach ($directories as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $code);
        if ($code !== 0) {
            $failed = true;
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
        $output = [];
    }
}

exit($failed ? 1 : 0);
