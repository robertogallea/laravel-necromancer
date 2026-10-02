<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands\Concerns;

use LaravelNecromancer\Manifest\ManifestStaleness;

trait ReadsManifest
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    private function warnIfStale(array $manifest): void
    {
        if ($this->isStale($manifest)) {
            $this->warn('Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function isStale(array $manifest): bool
    {
        return (new ManifestStaleness(app()->basePath()))->isStale($manifest);
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
