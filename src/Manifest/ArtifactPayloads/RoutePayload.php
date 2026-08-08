<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;
use LaravelNecromancer\Metadata\ArtifactAnnotations;

final readonly class RoutePayload implements JsonSerializable
{
    /**
     * @param  list<string>  $middleware
     * @param  list<array{name: string, optional: bool, constraint: string|null}>  $parameters
     * @param  list<array{ability: string, models: list<string>}>  $authorization
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $name,
        public string $method,
        public string $uri,
        public array $middleware,
        public ?string $controller,
        public ?string $action,
        public array $parameters,
        public ?array $source,
        public array $authorization = [],
        public array $metadata = [],
        public ArtifactAnnotations $annotations = new ArtifactAnnotations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'method' => $this->method,
            'uri' => $this->uri,
            'middleware' => $this->middleware,
            'controller' => $this->controller,
            'action' => $this->action,
        ];

        if (! empty($this->authorization)) {
            $data['authorization'] = $this->authorization;
        }

        if (! empty($this->parameters)) {
            $data['parameters'] = $this->parameters;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        if (! empty($this->metadata)) {
            $data['route_metadata'] = ['raw' => $this->metadata];
        }

        if (! $this->annotations->isEmpty()) {
            $data['annotations'] = $this->annotations->jsonSerialize();
        }

        return $data;
    }
}
