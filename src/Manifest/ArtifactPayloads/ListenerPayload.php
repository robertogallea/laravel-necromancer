<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ListenerPayload implements JsonSerializable
{
    /**
     * @param  list<string>  $handles
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public array $handles,
        public bool $queued,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'handles' => $this->handles,
            'queued' => $this->queued,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
