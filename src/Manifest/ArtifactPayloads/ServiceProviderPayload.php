<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ServiceProviderPayload implements JsonSerializable
{
    /**
     * @param  list<array{abstract: string, concrete: string}>  $bindings
     * @param  list<array{abstract: string, concrete: string}>  $singletons
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public bool $deferred,
        public array $bindings,
        public array $singletons,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'deferred' => $this->deferred,
            'bindings' => $this->bindings,
            'singletons' => $this->singletons,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
