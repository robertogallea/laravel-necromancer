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
     * @param  string|null  $consumer  set only for a Contextual Binding (ADR 0026); a Global Binding omits the key
     */
    public function __construct(
        public string $abstract,
        public ?string $concrete,
        public ?string $concreteSource,
        public ?string $lifetime,
        public ?string $provider,
        public bool $deferred,
        public ?array $source,
        public ?string $consumer = null,
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

        if ($this->consumer !== null) {
            $data['consumer'] = $this->consumer;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
