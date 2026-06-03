<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands\Concerns;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait ReadsManifest
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    private function warnIfStale(array $manifest): void
    {
        if ($this->isStaleByHash($manifest)) {
            $this->warn('Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh.');

            return;
        }

        $this->warnIfStaleByMtime($manifest);
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
                    : app()->basePath((string) $source['file']);

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
    private function warnIfStaleByMtime(array $manifest): void
    {
        $generatedAt = $manifest['meta']['generated_at'] ?? null;

        if (! is_string($generatedAt)) {
            return;
        }

        $threshold = strtotime($generatedAt);

        $sourcePaths = array_filter([
            app()->basePath('app'),
            app()->basePath('routes'),
            app()->basePath('database'),
        ], 'is_dir');

        foreach ($sourcePaths as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getMTime() > $threshold) {
                    $this->warn('Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh.');

                    return;
                }
            }
        }
    }

    private function resolveManifestPath(): string
    {
        $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
