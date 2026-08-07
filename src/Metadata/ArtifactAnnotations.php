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

    /**
     * Reconstructs an instance from already-validated Annotation Schema v1 data,
     * such as the "annotations" key of a serialized manifest artifact.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            domain: isset($data['domain']) ? (string) $data['domain'] : null,
            flow: isset($data['flow']) ? (string) $data['flow'] : null,
            capability: isset($data['capability']) ? (string) $data['capability'] : null,
            summary: isset($data['summary']) ? (string) $data['summary'] : null,
            risk: isset($data['risk']) ? Risk::from((string) $data['risk']) : null,
            externalServices: is_array($data['external_services'] ?? null) ? array_values($data['external_services']) : [],
            adrs: is_array($data['adrs'] ?? null) ? array_values($data['adrs']) : [],
        );
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
