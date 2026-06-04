<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class EnumPayload implements JsonSerializable
{
    /**
     * @param  list<array{name: string, value: string|int|null}>  $cases
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public ?string $backingType,
        public array $cases,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'backing_type' => $this->backingType,
            'cases' => $this->cases,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
