<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class CommandPayload implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public string $signature,
        public string $description,
        public ?array $source,
        public array $aliases = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'signature' => $this->signature,
            'description' => $this->description,
        ];

        if (! empty($this->aliases)) {
            $data['aliases'] = array_values($this->aliases);
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
