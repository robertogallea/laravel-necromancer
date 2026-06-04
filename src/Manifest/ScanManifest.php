<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use JsonException;
use JsonSerializable;
use LaravelNecromancer\Collection\CommandCollector;
use LaravelNecromancer\Collection\EnumCollector;
use LaravelNecromancer\Collection\EventCollector;
use LaravelNecromancer\Collection\FormRequestCollector;
use LaravelNecromancer\Collection\JobCollector;
use LaravelNecromancer\Collection\ListenerCollector;
use LaravelNecromancer\Collection\ModelCollector;
use LaravelNecromancer\Collection\ModelExclusionFilter;
use LaravelNecromancer\Collection\PolicyCollector;
use LaravelNecromancer\Collection\RouteCollector;
use LaravelNecromancer\Collection\RouteNoiseFilter;
use LaravelNecromancer\Collection\SafeInventoryCollector;
use LaravelNecromancer\Collection\TestCollector;
use stdClass;
use Throwable;

final readonly class ScanManifest implements JsonSerializable
{
    public function __construct(
        private Application $app,
        private RouteCollector $routeCollector,
        private ModelCollector $modelCollector,
        private FormRequestCollector $formRequestCollector,
        private JobCollector $jobCollector,
        private EventCollector $eventCollector,
        private ListenerCollector $listenerCollector,
        private CommandCollector $commandCollector,
        private PolicyCollector $policyCollector,
        private EnumCollector $enumCollector,
        private TestCollector $testCollector,
    ) {}

    /**
     * @return array{meta: array{generated_at: string, content_hash: string, necromancer_version: string, laravel_version: string, php_version: string, app_name: string, app_url: string, app_env: string}, artifacts: stdClass|array<string, list<array<string, mixed>>>}
     */
    public function jsonSerialize(): array
    {
        return $this->buildPayload();
    }

    /**
     * @throws JsonException
     */
    public function toJson(array $only = []): string
    {
        return json_encode($this->buildPayload($only), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $only
     * @return array{meta: array{generated_at: string, content_hash: string, necromancer_version: string, laravel_version: string, php_version: string, app_name: string, app_url: string, app_env: string}, artifacts: stdClass|array<string, list<array<string, mixed>>>}
     */
    public function buildPayload(array $only = []): array
    {
        $artifacts = $this->collectArtifacts($only);
        $contentHash = hash('sha256', json_encode($artifacts, JSON_THROW_ON_ERROR));

        return [
            'meta' => [
                'generated_at' => now()->toAtomString(),
                'content_hash' => $contentHash,
                'necromancer_version' => $this->necromancerVersion(),
                'laravel_version' => $this->app->version(),
                'php_version' => PHP_VERSION,
                'app_name' => (string) config('app.name', 'Laravel'),
                'app_url' => (string) config('app.url', 'http://localhost'),
                'app_env' => $this->app->environment(),
            ],
            'artifacts' => $artifacts === [] ? new stdClass : $artifacts,
        ];
    }

    private function necromancerVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'dev';
        }

        try {
            return InstalledVersions::getPrettyVersion('robertogallea/laravel-necromancer') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }

    /**
     * @param  list<string>  $only
     * @return array<string, list<array<string, mixed>>>
     */
    private function collectArtifacts(array $only = []): array
    {
        $routeExclusions = config('necromancer.exclude.routes', ['horizon.*', 'telescope.*', 'debugbar.*']);

        if (! is_array($routeExclusions)) {
            $routeExclusions = [];
        }

        $modelExclusions = config('necromancer.exclude.models', []);

        if (! is_array($modelExclusions)) {
            $modelExclusions = [];
        }

        $collectors = [
            'routes' => fn (): array => $this->routeCollector->collect(),
            'models' => fn (): array => $this->modelCollector->collect(),
            'requests' => fn (): array => $this->formRequestCollector->collect(),
            'jobs' => fn (): array => $this->jobCollector->collect(),
            'events' => fn (): array => $this->eventCollector->collect(),
            'listeners' => fn (): array => $this->listenerCollector->collect(),
            'commands' => fn (): array => $this->commandCollector->collect(),
            'policies' => fn (): array => $this->policyCollector->collect(),
            'enums' => fn (): array => $this->enumCollector->collect(),
            'tests' => fn (): array => $this->testCollector->collect(),
        ];

        if ($only !== []) {
            $collectors = array_filter(
                $collectors,
                fn (string $key): bool => in_array($key, $only, strict: true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $collected = array_map(fn (callable $fn): array => $fn(), $collectors);
        $artifacts = $collected !== [] ? array_merge(...array_values($collected)) : [];

        $inventory = (new SafeInventoryCollector(
            routeNoiseFilter: new RouteNoiseFilter(array_values($routeExclusions)),
            modelExclusionFilter: new ModelExclusionFilter(array_values($modelExclusions)),
        ))->collect(artifacts: $artifacts);

        return $inventory->toArray()['artifacts'];
    }
}
