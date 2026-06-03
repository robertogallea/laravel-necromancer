<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ModelPayload implements JsonSerializable
{
    /**
     * @param  list<string>  $fillable
     * @param  array<string, string>  $casts
     * @param  list<array{type: string, related: string|null, method: string}>  $relationships
     * @param  list<string>  $guarded
     * @param  list<string>  $hidden
     * @param  list<string>  $scopes
     * @param  array<string, mixed>|null  $source
     * @param  list<string>  $observers
     * @param  list<string>  $globalScopes
     */
    public function __construct(
        public string $class,
        public string $table,
        public array $fillable,
        public array $casts,
        public array $relationships,
        public bool $softDeletes,
        public array $guarded,
        public array $hidden,
        public array $scopes,
        public ?array $source,
        public array $observers = [],
        public array $globalScopes = [],
        public ?string $policy = null,
        public ?string $factory = null,
        public ?string $customBuilder = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'table' => $this->table,
            'fillable' => $this->fillable,
            'casts' => $this->casts,
            'relationships' => $this->relationships,
            'soft_deletes' => $this->softDeletes,
            'guarded' => $this->guarded,
        ];

        if (! empty($this->hidden)) {
            $data['hidden'] = array_values($this->hidden);
        }

        if (! empty($this->scopes)) {
            $data['scopes'] = array_values($this->scopes);
        }

        if (! empty($this->observers)) {
            $data['observers'] = array_values($this->observers);
        }

        if (! empty($this->globalScopes)) {
            $data['global_scopes'] = array_values($this->globalScopes);
        }

        if ($this->policy !== null) {
            $data['policy'] = $this->policy;
        }

        if ($this->factory !== null) {
            $data['factory'] = $this->factory;
        }

        if ($this->customBuilder !== null) {
            $data['custom_builder'] = $this->customBuilder;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
