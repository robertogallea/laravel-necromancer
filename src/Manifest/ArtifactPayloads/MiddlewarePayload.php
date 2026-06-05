<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class MiddlewarePayload implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $alias,
        public string $class,
        public string $scope,
        public ?string $group,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'alias' => $this->alias,
            'class' => $this->class,
            'scope' => $this->scope,
            'group' => $this->group,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
