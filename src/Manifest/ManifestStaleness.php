<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Whether a manifest may be stale relative to the application's source
 * files. The one implementation shared by the commands (via ReadsManifest)
 * and the MCP graph tools, which warn instead of refusing (docs/adr/0023).
 */
final readonly class ManifestStaleness
{
    public function __construct(private string $basePath) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function isStale(array $manifest): bool
    {
        return $this->isStaleByHash($manifest) || $this->isStaleByMtime($manifest);
    }

    /**
     * Returns true when any artifact carries a stored hash that differs from the current file hash.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function isStaleByHash(array $manifest): bool
    {
        foreach ((array) ($manifest['artifacts'] ?? []) as $items) {
            foreach ((array) $items as $item) {
                $source = is_array($item['source'] ?? null) ? $item['source'] : null;

                if ($source === null || ! array_key_exists('hash', $source) || $source['hash'] === null) {
                    continue;
                }

                $absolutePath = $this->isAbsolutePath((string) $source['file'])
                    ? (string) $source['file']
                    : $this->path((string) $source['file']);

                if (! is_file($absolutePath)) {
                    return true;
                }

                if (md5_file($absolutePath) !== $source['hash']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function isStaleByMtime(array $manifest): bool
    {
        $generatedAt = $manifest['meta']['generated_at'] ?? null;

        if (! is_string($generatedAt)) {
            return false;
        }

        $threshold = strtotime($generatedAt);

        $sourcePaths = array_filter([
            $this->path('app'),
            $this->path('routes'),
            $this->path('database'),
        ], 'is_dir');

        foreach ($sourcePaths as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if ($file->getMTime() > $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Mirrors Application::basePath($path).
     */
    private function path(string $path): string
    {
        return $this->basePath.($path !== '' ? DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR) : '');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
