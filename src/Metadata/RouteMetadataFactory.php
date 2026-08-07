<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use Illuminate\Contracts\Config\Repository;

final readonly class RouteMetadataFactory
{
    public function __construct(private Repository $config) {}

    /**
     * @param  string|list<string>|null  $externalServices
     * @return array<string, array<string, mixed>>
     */
    public function forMetadata(
        ?string $domain = null,
        ?string $flow = null,
        ?string $capability = null,
        ?string $summary = null,
        Risk|string|null $risk = null,
        string|array|null $externalServices = null,
        ?string $adr = null,
        array $adrs = [],
    ): array {
        $fields = array_filter([
            'domain' => $domain,
            'flow' => $flow,
            'capability' => $capability,
            'summary' => $summary,
            'risk' => $risk instanceof Risk ? $risk->value : $risk,
            'external_services' => is_string($externalServices) ? [$externalServices] : $externalServices,
            'adr' => $adr,
            'adrs' => $adrs,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        if ($fields === []) {
            return [];
        }

        $namespace = $this->config->get('necromancer.route_metadata.namespace', 'necromancer');

        return [$namespace => $fields];
    }
}
