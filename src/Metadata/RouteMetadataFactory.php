<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

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
        array $adrs = [],
    ): array {
        $this->validate($domain, $flow, $capability, $summary, $risk, $externalServices, $adrs);

        $fields = array_filter([
            'domain' => $domain,
            'flow' => $flow,
            'capability' => $capability,
            'summary' => $summary,
            'risk' => $risk instanceof Risk ? $risk->value : $risk,
            'external_services' => is_string($externalServices) ? [$externalServices] : $externalServices,
            'adrs' => $adrs,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        if ($fields === []) {
            return [];
        }

        $namespace = $this->config->get('necromancer.route_metadata.namespace', 'necromancer');

        return [$namespace => $fields];
    }

    /** @param string|list<string>|null $externalServices */
    private function validate(
        ?string $domain,
        ?string $flow,
        ?string $capability,
        ?string $summary,
        Risk|string|null $risk,
        string|array|null $externalServices,
        array $adrs,
    ): void {
        foreach (['domain' => $domain, 'flow' => $flow, 'capability' => $capability, 'summary' => $summary] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException("Invalid route annotation {$field}: strings must not be empty.");
            }
        }

        if (is_string($risk) && Risk::tryFrom(trim($risk)) === null) {
            throw new InvalidArgumentException('Invalid route annotation risk: expected low, medium, high, or critical.');
        }

        $externalServiceValues = $externalServices === null
            ? []
            : (is_array($externalServices) ? $externalServices : [$externalServices]);

        foreach (['external_services' => $externalServiceValues, 'adrs' => $adrs] as $field => $values) {
            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException("Invalid route annotation {$field}: list items must be non-empty strings.");
                }
            }
        }
    }
}
