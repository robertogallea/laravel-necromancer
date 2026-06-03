<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class EventPayload implements JsonSerializable
{
    /**
     * @param  list<string>  $listeners
     * @param  list<string>  $channels
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public array $listeners,
        public bool $broadcastable,
        public array $channels,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'listeners' => $this->listeners,
            'broadcastable' => $this->broadcastable,
        ];

        if ($this->broadcastable) {
            $data['channels'] = array_values($this->channels);
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
