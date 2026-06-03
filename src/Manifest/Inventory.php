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

        foreach ($artifacts as $type => $items) {
            usort($items, fn (array $a, array $b): int => $this->canonicalKey($type, $a) <=> $this->canonicalKey($type, $b)
            );
            $artifacts[$type] = $items;
        }

        return [
            'configuration' => $this->configuration->jsonSerialize(),
            'artifacts' => $artifacts,
        ];
    }

    private function canonicalKey(string $type, array $item): string
    {
        if ($type === 'routes') {
            return (string) ($item['method'] ?? '').':'.($item['uri'] ?? '');
        }

        return (string) ($item['class'] ?? $item['signature'] ?? json_encode($item));
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
