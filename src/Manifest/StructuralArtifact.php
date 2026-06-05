<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use Illuminate\Support\Str;
use JsonSerializable;
use LaravelNecromancer\Manifest\ArtifactPayloads\CommandPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\EnumPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\EventPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\FormRequestPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\GatePayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\JobPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\ListenerPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\LivewirePayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\MailablePayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\MiddlewarePayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\ModelPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\ObserverPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\PolicyPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\RoutePayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\RulePayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\ScheduledTaskPayload;
use LaravelNecromancer\Manifest\ArtifactPayloads\TestPayload;

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
            middleware: $middleware,
            controller: $controller,
            action: $action,
            parameters: $parameters,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            authorization: $authorization,
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
            fillable: $fillable,
            casts: $casts,
            relationships: $relationships,
            softDeletes: $softDeletes,
            guarded: $guarded,
            hidden: $hidden,
            scopes: $scopes,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
            observers: $observers,
            globalScopes: $globalScopes,
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
            listeners: $listeners,
            broadcastable: $broadcastable,
            channels: $channels,
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
            handles: $handles,
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
            aliases: $aliases,
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
            cases: $cases,
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
            methods: $methods,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @param  list<string>  $parameters
     * @param  array<string, mixed>|null  $source
     */
    public static function gate(
        string $ability,
        string $kind,
        array $parameters = [],
        ?array $source = null,
    ): self {
        return new self('gates', new GatePayload(
            ability: $ability,
            kind: $kind,
            parameters: $parameters,
            source: $source,
        ));
    }

    /**
     * @param  list<string>  $hooks
     * @param  array<string, mixed>|null  $source
     */
    public static function observer(
        string $class,
        ?string $model = null,
        array $hooks = [],
        bool $queued = false,
        ?SourceLocation $source = null,
    ): self {
        return new self('observers', new ObserverPayload(
            class: $class,
            model: $model,
            hooks: $hooks,
            queued: $queued,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @param  list<string>  $methods
     */
    public static function test(
        string $file,
        string $testType,
        ?string $class = null,
        ?string $subject = null,
        array $methods = [],
        ?SourceLocation $source = null,
    ): self {
        return new self('tests', new TestPayload(
            file: $file,
            testType: $testType,
            class: $class,
            subject: $subject,
            methods: $methods,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $source
     */
    public static function middleware(
        string $alias,
        string $class,
        string $scope,
        ?string $group = null,
        ?array $source = null,
    ): self {
        return new self('middleware', new MiddlewarePayload(
            alias: $alias,
            class: $class,
            scope: $scope,
            group: $group,
            source: $source,
        ));
    }

    /**
     * @param  list<array{name: string, type: string|null}>  $properties
     * @param  list<string>  $actions
     * @param  list<string>  $listens
     */
    public static function livewireComponent(
        string $class,
        string $view,
        array $properties = [],
        array $actions = [],
        array $listens = [],
        ?SourceLocation $source = null,
    ): self {
        return new self('livewire_components', new LivewirePayload(
            class: $class,
            view: $view,
            properties: $properties,
            actions: $actions,
            listens: $listens,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    public static function mailable(
        string $class,
        ?string $subject = null,
        bool $queued = false,
        ?string $queue = null,
        ?string $view = null,
        ?SourceLocation $source = null,
    ): self {
        return new self('mailables', new MailablePayload(
            class: $class,
            subject: $subject,
            queued: $queued,
            queue: $queue,
            view: $view,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    public static function validationRule(
        string $class,
        bool $implicit = false,
        ?string $description = null,
        ?SourceLocation $source = null,
    ): self {
        return new self('validation_rules', new RulePayload(
            class: $class,
            implicit: $implicit,
            description: $description,
            source: $source instanceof SourceLocation ? $source->jsonSerialize() : null,
        ));
    }

    public static function scheduledTask(
        string $command,
        string $expression,
        string $humanReadable,
        bool $withoutOverlapping = false,
        bool $runInBackground = false,
        bool $evenInMaintenance = false,
        ?string $timezone = null,
        ?string $description = null,
        ?SourceLocation $source = null,
    ): self {
        return new self('scheduled_tasks', new ScheduledTaskPayload(
            command: $command,
            expression: $expression,
            humanReadable: $humanReadable,
            withoutOverlapping: $withoutOverlapping,
            runInBackground: $runInBackground,
            evenInMaintenance: $evenInMaintenance,
            timezone: $timezone,
            description: $description,
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
