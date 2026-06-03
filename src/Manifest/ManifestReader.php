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

        return (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
