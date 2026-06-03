<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use LaravelNecromancer\Manifest\ConfigurationSummary;
use LaravelNecromancer\Manifest\Inventory;
use LaravelNecromancer\Manifest\StructuralArtifact;

final readonly class SafeInventoryCollector
{
    public function __construct(
        private RouteNoiseFilter $routeNoiseFilter = new RouteNoiseFilter,
        private ModelExclusionFilter $modelExclusionFilter = new ModelExclusionFilter,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<StructuralArtifact>  $artifacts
     */
    public function collect(array $configuration = [], array $artifacts = []): Inventory
    {
        $artifacts = array_values(array_filter(
            $artifacts,
            fn (StructuralArtifact $artifact): bool => $this->routeNoiseFilter->allows($artifact)
                && $this->modelExclusionFilter->allows($artifact),
        ));

        return new Inventory(
            configuration: ConfigurationSummary::fromArray($configuration),
            artifacts: $artifacts,
        );
    }
}
