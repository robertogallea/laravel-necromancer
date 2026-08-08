<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use JsonException;

final readonly class ManifestReader
{
    /**
     * @return array<string, mixed>
     *
     * @throws ManifestNotFoundException
     * @throws JsonException
     */
    public function read(string $path): array
    {
        if (! file_exists($path)) {
            throw new ManifestNotFoundException("Necromancer manifest not found at {$path}.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ManifestNotFoundException("Necromancer manifest could not be read at {$path}.");
        }

        /** @var array<string, mixed> $manifest */
        $manifest = (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! $this->isCurrentSchema($manifest)) {
            throw new ManifestNotFoundException("Necromancer manifest at {$path} predates schema v1 and is no longer supported. Run necromancer:scan to regenerate it.");
        }

        return $manifest;
    }

    /**
     * Whether a manifest is schema v1 — the only shape read() (and every
     * command built on it) accepts. Exposed separately from read() for
     * DiffCommand, which loads a manifest from `git show` rather than the
     * filesystem and needs to report the same rejection in its own error
     * style instead of catching an exception.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function isCurrentSchema(array $manifest): bool
    {
        return ($manifest['meta']['manifest_schema_version'] ?? null) === 1;
    }
}
