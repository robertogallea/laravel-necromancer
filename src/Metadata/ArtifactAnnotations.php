<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use JsonSerializable;

/**
 * Immutable canonical representation of Annotation Schema v1.
 */
final readonly class ArtifactAnnotations implements JsonSerializable
{
    /**
     * @param  list<string>  $externalServices
     * @param  list<string>  $adrs
     */
    public function __construct(
        public ?string $domain = null,
        public ?string $flow = null,
        public ?string $capability = null,
        public ?string $summary = null,
        public ?Risk $risk = null,
        public array $externalServices = [],
        public array $adrs = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->jsonSerialize() === [];
    }

    /** @return array<string, string|list<string>> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'domain' => $this->domain,
            'flow' => $this->flow,
            'capability' => $this->capability,
            'summary' => $this->summary,
            'risk' => $this->risk?->value,
            'external_services' => $this->externalServices,
            'adrs' => $this->adrs,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
