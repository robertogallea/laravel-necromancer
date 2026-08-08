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
use LaravelNecromancer\Collection\GateCollector;
use LaravelNecromancer\Collection\JobCollector;
use LaravelNecromancer\Collection\ListenerCollector;
use LaravelNecromancer\Collection\LivewireCollector;
use LaravelNecromancer\Collection\MailableCollector;
use LaravelNecromancer\Collection\MiddlewareCollector;
use LaravelNecromancer\Collection\ModelCollector;
use LaravelNecromancer\Collection\ModelExclusionFilter;
use LaravelNecromancer\Collection\ObserverCollector;
use LaravelNecromancer\Collection\PolicyCollector;
use LaravelNecromancer\Collection\RouteCollector;
use LaravelNecromancer\Collection\RouteNoiseFilter;
use LaravelNecromancer\Collection\RuleCollector;
use LaravelNecromancer\Collection\SafeInventoryCollector;
use LaravelNecromancer\Collection\ScheduledTaskCollector;
use LaravelNecromancer\Collection\ServiceProviderCollector;
use LaravelNecromancer\Collection\TestCollector;
use LaravelNecromancer\Metadata\AnnotationConfigurationResolver;
use stdClass;
use Throwable;

final class ScanManifest implements JsonSerializable
{
    /** @var list<string> */
    private array $diagnostics = [];

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
        private ObserverCollector $observerCollector,
        private ScheduledTaskCollector $scheduledTaskCollector,
        private MiddlewareCollector $middlewareCollector,
        private LivewireCollector $livewireCollector,
        private GateCollector $gateCollector,
        private MailableCollector $mailableCollector,
        private RuleCollector $ruleCollector,
        private ServiceProviderCollector $serviceProviderCollector,
    ) {}

    /**
     * @return array{meta: array<string, mixed>, artifacts: stdClass|array<string, list<array<string, mixed>>>}
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
     * @return array{meta: array<string, mixed>, artifacts: stdClass|array<string, list<array<string, mixed>>>}
     */
    public function buildPayload(array $only = []): array
    {
        $artifacts = $this->collectArtifacts($only);
        $scope = $this->scope($only);
        $contentHash = hash('sha256', json_encode([
            'manifest_schema_version' => 1,
            'annotation_schema_version' => 1,
            'scope' => $scope,
            'artifacts' => $artifacts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'meta' => [
                'manifest_schema_version' => 1,
                'annotation_schema_version' => 1,
                'scope' => $scope,
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

    /** @return list<string> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Build a reverse-lookup map from observer FQCN to model FQCN using the
     * #[ObservedBy] attributes already captured in the model artifacts.
     *
     * @param  list<StructuralArtifact>  $modelArtifacts
     * @return array<string, string>
     */
    private function buildObserverModelMap(array $modelArtifacts): array
    {
        $map = [];

        foreach ($modelArtifacts as $modelArtifact) {
            $serialized = $modelArtifact->jsonSerialize();
            $modelClass = $serialized['class'] ?? null;

            if ($modelClass === null) {
                continue;
            }

            foreach ($serialized['observers'] ?? [] as $observerClass) {
                $map[$observerClass] = $modelClass;
            }
        }

        return $map;
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
        $this->diagnostics = [];
        $routeExclusions = config('necromancer.exclude.routes', ['horizon.*', 'telescope.*', 'debugbar.*']);

        if (! is_array($routeExclusions)) {
            $routeExclusions = [];
        }

        $routeUriExclusions = config('necromancer.exclude.route_uris', ['up']);

        if (! is_array($routeUriExclusions)) {
            $routeUriExclusions = [];
        }

        $modelExclusions = config('necromancer.exclude.models', []);

        if (! is_array($modelExclusions)) {
            $modelExclusions = [];
        }

        // Only collect models eagerly when observers are being requested, so the
        // reverse-lookup map (observer FQCN → model FQCN) can be built. When
        // observers are not requested (e.g. --only=routes) we skip this entirely.
        $observersRequested = $only === [] || in_array('observers', $only, strict: true);
        $eagerModelArtifacts = $observersRequested ? $this->modelCollector->collect() : [];
        $observerModelMap = $this->buildObserverModelMap($eagerModelArtifacts);
        $observerCollector = $this->observerCollector->withModelMap($observerModelMap);

        $collectors = [
            'routes' => function (): array {
                $artifacts = $this->routeCollector->collect();
                $this->diagnostics = $this->routeCollector->diagnostics();

                return $artifacts;
            },
            // Reuse the eagerly-collected model list when it was already fetched for the
            // observer map; otherwise collect on demand.
            'models' => fn (): array => $eagerModelArtifacts !== [] ? $eagerModelArtifacts : $this->modelCollector->collect(),
            'form_requests' => fn (): array => $this->formRequestCollector->collect(),
            'jobs' => fn (): array => $this->jobCollector->collect(),
            'events' => fn (): array => $this->eventCollector->collect(),
            'listeners' => fn (): array => $this->listenerCollector->collect(),
            'commands' => fn (): array => $this->commandCollector->collect(),
            'policies' => fn (): array => $this->policyCollector->collect(),
            'enums' => fn (): array => $this->enumCollector->collect(),
            'tests' => fn (): array => $this->testCollector->collect(),
            'observers' => fn (): array => $observerCollector->collect(),
            'scheduled_tasks' => fn (): array => $this->scheduledTaskCollector->collect(),
            'middleware' => fn (): array => $this->middlewareCollector->collect(),
            'livewire_components' => fn (): array => $this->livewireCollector->collect(),
            'gates' => fn (): array => $this->gateCollector->collect(),
            'mailables' => fn (): array => $this->mailableCollector->collect(),
            'validation_rules' => fn (): array => $this->ruleCollector->collect(),
            'service_providers' => fn (): array => $this->serviceProviderCollector->collect(),
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
            routeNoiseFilter: new RouteNoiseFilter(array_values($routeExclusions), array_values($routeUriExclusions)),
            modelExclusionFilter: new ModelExclusionFilter(array_values($modelExclusions)),
        ))->collect(artifacts: $artifacts);

        $identifiedArtifacts = $inventory->toArray()['artifacts'];

        // Exact-ID mappings are the sole annotation source for non-reflectable
        // artifact families (closures, test files, gates, scheduled tasks) and a
        // fill-only, registration-specific escape hatch for every other family.
        // They can only be resolved after every artifact has its canonical ID.
        $annotationConfig = config('necromancer.annotations', []);
        $configResolver = new AnnotationConfigurationResolver(is_array($annotationConfig) ? $annotationConfig : []);
        [$identifiedArtifacts, $configDiagnostics] = $configResolver->apply(
            $identifiedArtifacts,
            $this->scope($only)['artifact_types'],
        );
        $this->diagnostics = [...$this->diagnostics, ...$configDiagnostics];

        return $identifiedArtifacts;
    }

    /**
     * @param  list<string>  $only
     * @return array{complete: bool, artifact_types: list<string>}
     */
    private function scope(array $only): array
    {
        $types = ArtifactId::supportedTypes();

        if ($only !== []) {
            $types = array_values(array_intersect($types, $only));
        }

        sort($types, SORT_STRING);

        return [
            'complete' => $only === [],
            'artifact_types' => $types,
        ];
    }
}
