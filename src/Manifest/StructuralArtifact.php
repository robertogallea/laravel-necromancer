<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use Illuminate\Support\Str;
use JsonSerializable;
use LaravelNecromancer\Manifest\ArtifactPayloads\CommandPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\EnumPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\EventPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\FormRequestPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\JobPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\ListenerPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\ModelPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\PolicyPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\RoutePayload;

final readonly class StructuralArtifact implements JsonSerializable
{
    private function __construct(
        public string $type,
        private JsonSerializable $payload,
    ) {}

    public function isRoute(): bool
    {
        return $this->type === 'routes';
    }

    public function isModel(): bool
    {
        return $this->type === 'models';
    }

    public function isFormRequest(): bool
    {
        return $this->type === 'form_requests';
    }

    public function routeName(): ?string
    {
        return $this->payload instanceof RoutePayload ? $this->payload->name : null;
    }

    public function modelClass(): ?string
    {
        return $this->payload instanceof ModelPayload ? $this->payload->class : null;
    }

    /**
     * @param  list<string>  $middleware
     * @param  list<array{name: string, optional: bool, constraint: string|null}>  $parameters
     * @param  list<array{ability: string, models: list<string>}>  $authorization
     */
    public static function route(
        ?string $name,
        string $method,
        string $uri,
        array $middleware = [],
        ?string $controller = null,
        ?string $action = null,
        ?SourceLocation $source = null,
        array $parameters = [],
        array $authorization = [],
    ): self {
        return new self('routes', new RoutePayload(
            name: $name,
            method: Str::upper($method),
            uri: $uri,
            middleware: array_values($middleware),
            controller: $controller,
            action: $action,
            parameters: array_values($parameters),
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            authorization: array_values($authorization),
        ));
    }

    /**
     * @param  list<string>  $fillable
     * @param  array<string, string>  $casts
     * @param  list<string>  $hidden
     * @param  list<string>  $scopes
     * @param  list<string>  $guarded
     * @param  list<array{type: string, related: string|null, method: string}>  $relationships
     * @param  list<string>  $observers
     * @param  list<string>  $globalScopes
     */
    public static function model(
        string $class,
        string $table,
        array $fillable = [],
        array $casts = [],
        array $relationships = [],
        array $hidden = [],
        bool $softDeletes = false,
        array $scopes = [],
        array $guarded = ['*'],
        ?SourceLocation $source = null,
        array $observers = [],
        array $globalScopes = [],
        ?string $policy = null,
        ?string $factory = null,
        ?string $customBuilder = null,
    ): self {
        return new self('models', new ModelPayload(
            class: $class,
            table: $table,
            fillable: array_values($fillable),
            casts: $casts,
            relationships: array_values($relationships),
            softDeletes: $softDeletes,
            guarded: array_values($guarded),
            hidden: array_values($hidden),
            scopes: array_values($scopes),
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            observers: array_values($observers),
            globalScopes: array_values($globalScopes),
            policy: $policy,
            factory: $factory,
            customBuilder: $customBuilder,
        ));
    }

    public static function job(
        string $class,
        ?string $queue = null,
        ?string $connection = null,
        string|int|null $tries = null,
        ?int $timeout = null,
        ?SourceLocation $source = null,
        array|int|null $backoff = null,
        ?int $maxExceptions = null,
    ): self {
        return new self('jobs', new JobPayload(
            class: $class,
            queue: $queue,
            connection: $connection,
            tries: $tries,
            timeout: $timeout,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            backoff: $backoff,
            maxExceptions: $maxExceptions,
        ));
    }

    /**
     * @param  list<string>  $listeners
     * @param  list<string>  $channels
     */
    public static function event(
        string $class,
        array $listeners = [],
        bool $broadcastable = false,
        array $channels = [],
        ?SourceLocation $source = null,
    ): self {
        return new self('events', new EventPayload(
            class: $class,
            listeners: array_values($listeners),
            broadcastable: $broadcastable,
            channels: array_values($channels),
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @param  list<string>  $handles
     */
    public static function listener(
        string $class,
        array $handles = [],
        bool $queued = false,
        ?SourceLocation $source = null,
    ): self {
        return new self('listeners', new ListenerPayload(
            class: $class,
            handles: array_values($handles),
            queued: $queued,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @param  array<string, string>  $rules
     */
    public static function formRequest(
        string $class,
        array $rules = [],
        ?SourceLocation $source = null,
        bool $stopOnFirstFailure = false,
        ?string $errorBag = null,
    ): self {
        return new self('form_requests', new FormRequestPayload(
            class: $class,
            rules: $rules,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            stopOnFirstFailure: $stopOnFirstFailure,
            errorBag: $errorBag,
        ));
    }

    /**
     * @param  list<string>  $aliases
     */
    public static function command(
        string $class,
        string $signature,
        string $description,
        ?SourceLocation $source = null,
        array $aliases = [],
    ): self {
        return new self('commands', new CommandPayload(
            class: $class,
            signature: $signature,
            description: $description,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            aliases: array_values($aliases),
        ));
    }

    /**
     * @param  list<array{name: string, value: string|int|null}>  $cases
     */
    public static function enum(
        string $class,
        ?string $backingType = null,
        array $cases = [],
        ?SourceLocation $source = null,
    ): self {
        return new self('enums', new EnumPayload(
            class: $class,
            backingType: $backingType,
            cases: array_values($cases),
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @param  list<string>  $methods
     */
    public static function policy(
        string $class,
        ?string $model = null,
        array $methods = [],
        ?SourceLocation $source = null,
    ): self {
        return new self('policies', new PolicyPayload(
            class: $class,
            model: $model,
            methods: array_values($methods),
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload->jsonSerialize();
    }
}
