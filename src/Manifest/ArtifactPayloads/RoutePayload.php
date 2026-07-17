<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class RoutePayload implements JsonSerializable
{
    /**
     * @param  list<string>  $middleware
     * @param  list<array{name: string, optional: bool, constraint: string|null}>  $parameters
     * @param  list<array{ability: string, models: list<string>}>  $authorization
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>  $metadata
     * @param  array{domain?: string, flow?: string, capability?: string, summary?: string, risk?: string, external_services?: list<string>, adr?: string}  $necromancerMetadata
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
        public array $necromancerMetadata = [],
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

        if (! empty($this->metadata) || ! empty($this->necromancerMetadata)) {
            $data['route_metadata'] = array_filter([
                'raw' => $this->metadata,
                'necromancer' => $this->necromancerMetadata,
            ], fn (array $v): bool => ! empty($v));
        }

        return $data;
    }
}
