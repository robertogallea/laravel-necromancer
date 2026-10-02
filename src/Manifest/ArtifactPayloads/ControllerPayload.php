<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ControllerPayload implements JsonSerializable
{
    /**
     * @param  list<array{name: string, parameters: list<array{name: string, type: string|null}>, return_type: string|null, middleware: list<string>, routes: list<string>}>  $actions
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public array $actions,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'actions' => $this->actions,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
