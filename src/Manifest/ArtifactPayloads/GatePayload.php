<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class GatePayload implements JsonSerializable
{
    /**
     * @param  list<string>  $parameters
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $ability,
        public string $kind,
        public array $parameters,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'ability' => $this->ability,
            'kind' => $this->kind,
            'parameters' => $this->parameters,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
