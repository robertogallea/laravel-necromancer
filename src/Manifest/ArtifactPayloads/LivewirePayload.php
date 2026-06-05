<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class LivewirePayload implements JsonSerializable
{
    /**
     * @param  list<array{name: string, type: string|null}>  $properties
     * @param  list<string>  $actions
     * @param  list<string>  $listens
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public string $view,
        public array $properties,
        public array $actions,
        public array $listens,
        public ?array $source = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'view' => $this->view,
            'properties' => $this->properties,
            'actions' => $this->actions,
            'listens' => $this->listens,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
