<?php

declare(strict_types=1);

namespace KurseInformatike\Shared\Storage;

final class FileStorage
{
    private string $root;

    public function __construct(string $root, private readonly string $relativeRoot)
    {
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Media storage is unavailable.');
        }
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new \RuntimeException('Media storage root cannot be resolved.');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function storeUploadedImage(string $tmpPath, string $bucket, string $extension): string
    {
        $bucket = preg_replace('/[^a-zA-Z0-9-]/', '', $bucket) ?: 'draft';
        $directory = $this->root . DIRECTORY_SEPARATOR . $bucket;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Media directory cannot be created.');
        }

        $filename = bin2hex(random_bytes(24)) . '.' . strtolower($extension);
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new \RuntimeException('Media file could not be stored.');
        }

        return trim($this->relativeRoot, '/') . '/' . $bucket . '/' . $filename;
    }

    public function absolutePath(string $relativePath): ?string
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        $prefix = trim(str_replace('\\', '/', $this->relativeRoot), '/');
        if ($normalized !== $prefix && !str_starts_with($normalized, $prefix . '/')) {
            return null;
        }
        $suffix = ltrim(substr($normalized, strlen($prefix)), '/');
        $candidate = realpath($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $suffix));
        if ($candidate === false || !$this->isInsideRoot($candidate)) {
            return null;
        }
        return $candidate;
    }

    public function delete(string $relativePath): bool
    {
        $path = $this->absolutePath($relativePath);
        if ($path === null || !is_file($path)) {
            return false;
        }
        return unlink($path);
    }

    public function duplicate(string $relativePath, string $bucket): string
    {
        $source = $this->absolutePath($relativePath);
        if ($source === null || !is_file($source)) {
            throw new \RuntimeException('Source media file is unavailable.');
        }
        $bucket = preg_replace('/[^a-zA-Z0-9-]/', '', $bucket) ?: 'copy';
        $directory = $this->root . DIRECTORY_SEPARATOR . $bucket;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Media directory cannot be created.');
        }
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(24)) . ($extension !== '' ? '.' . $extension : '');
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!copy($source, $destination)) {
            throw new \RuntimeException('Media file could not be copied.');
        }
        return trim($this->relativeRoot, '/') . '/' . $bucket . '/' . $filename;
    }

    private function isInsideRoot(string $path): bool
    {
        $normalizedRoot = strtolower(str_replace('\\', '/', $this->root)) . '/';
        $normalizedPath = strtolower(str_replace('\\', '/', $path));
        return str_starts_with($normalizedPath, $normalizedRoot);
    }
}
