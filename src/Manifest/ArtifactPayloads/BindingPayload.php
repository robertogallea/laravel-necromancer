<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class BindingPayload implements JsonSerializable
{
    /**
     * @param  'class'|'return_type'|'instance'|'attribute'|null  $concreteSource
     * @param  'transient'|'singleton'|'scoped'|'instance'|null  $lifetime
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $abstract,
        public ?string $concrete,
        public ?string $concreteSource,
        public ?string $lifetime,
        public ?string $provider,
        public bool $deferred,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'abstract' => $this->abstract,
            'concrete' => $this->concrete,
            'concrete_source' => $this->concreteSource,
            'lifetime' => $this->lifetime,
            'provider' => $this->provider,
            'deferred' => $this->deferred,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
