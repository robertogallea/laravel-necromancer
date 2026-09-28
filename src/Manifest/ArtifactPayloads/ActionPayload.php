<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ActionPayload implements JsonSerializable
{
    /**
     * @param  list<array{name: string, parameters: list<array{name: string, type: string|null}>, return_type: string|null}>  $entrypoints
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public array $entrypoints,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'entrypoints' => $this->entrypoints,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
