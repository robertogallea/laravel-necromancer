<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class PolicyPayload implements JsonSerializable
{
    /**
     * @param  list<string>  $methods
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public ?string $model,
        public array $methods,
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
            'methods' => array_values($this->methods),
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
