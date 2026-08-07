<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use JsonException;
use JsonSerializable;

final readonly class Inventory implements JsonSerializable
{
    /**
     * @param  list<StructuralArtifact>  $artifacts
     */
    public function __construct(
        public ConfigurationSummary $configuration,
        public array $artifacts = [],
        private ArtifactId $artifactId = new ArtifactId,
    ) {}

    /**
     * @return array{configuration: array{keys: list<string>}, artifacts: array<string, list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        $artifacts = [];

        foreach ($this->artifacts as $artifact) {
            $artifacts[$artifact->type][] = $artifact->jsonSerialize();
        }

        ksort($artifacts);

        // Hook and scheduled-task IDs include registration order, so identifiers
        // must be assigned before the manifest is sorted for deterministic output.
        $artifacts = $this->artifactId->assign($artifacts);

        foreach ($artifacts as $type => $items) {
            usort($items, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $artifacts[$type] = $items;
        }

        return [
            'configuration' => $this->configuration->jsonSerialize(),
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @return array{configuration: array{keys: list<string>}, artifacts: array<string, list<array<string, mixed>>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
