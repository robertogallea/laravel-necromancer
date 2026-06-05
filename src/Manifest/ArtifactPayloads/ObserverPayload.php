<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ObserverPayload implements JsonSerializable
{
    /**
     * @param  list<string>  $hooks
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public ?string $model,
        public array $hooks,
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
            'model' => $this->model,
            'hooks' => $this->hooks,
            'queued' => $this->queued,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
