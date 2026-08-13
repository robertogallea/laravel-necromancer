<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\Concerns;

use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

trait LoadsManifestArtifacts
{
    /**
     * @return array<string, mixed>
     */
    private function loadArtifactsByType(ManifestReader $reader): array
    {
        try {
            $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));

            return (array) ($reader->read($path)['artifacts'] ?? []);
        } catch (ManifestNotFoundException) {
            return [];
        }
    }
}
