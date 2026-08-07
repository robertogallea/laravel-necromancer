<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use ReflectionMethod;

final readonly class ObserverCollector
{
    /** @var list<string> */
    private const LIFECYCLE_HOOKS = [
        'created',
        'creating',
        'deleted',
        'deleting',
        'forceDeleted',
        'retrieved',
        'restored',
        'restoring',
        'saved',
        'saving',
        'updated',
        'updating',
    ];

    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
     * @param  array<string, string>  $modelMap  observer FQCN → model FQCN
     */
    public function __construct(
        private Application $app,
        private ?array $roots = null,
        private array $modelMap = [],
    ) {}

    /**
     * Return a new instance with the given model map applied.
     *
     * @param  array<string, string>  $modelMap  observer FQCN → model FQCN
     */
    public function withModelMap(array $modelMap): self
    {
        return new self(
            app: $this->app,
            roots: $this->roots,
            modelMap: $modelMap,
        );
    }

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $artifacts = [];

        foreach ((new ClassDiscovery($this->discoveryRoots()))->classes() as $class) {
            $artifact = $this->collectClass($class);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function discoveryRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        return [[
            'path' => $this->app->basePath('app/Observers'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Observers\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return null;
        }

        $hooks = $this->observerHooks($reflection);

        // Skip observers that define no recognised lifecycle hooks — they add
        // noise to the manifest with no information value (mirrors the pattern
        // used by PolicyCollector for abstract classes).
        if ($hooks === []) {
            return null;
        }

        return StructuralArtifact::observer(
            class: $class,
            model: $this->modelMap[$class] ?? null,
            hooks: $hooks,
            queued: $reflection->implementsInterface(ShouldQueue::class),
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function observerHooks(ReflectionClass $reflection): array
    {
        $hooks = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->isConstructor()
                || $method->getDeclaringClass()->getName() !== $reflection->getName()
                || str_starts_with($method->getName(), '__')) {
                continue;
            }

            if (in_array($method->getName(), self::LIFECYCLE_HOOKS, strict: true)) {
                $hooks[] = $method->getName();
            }
        }

        sort($hooks);

        return $hooks;
    }
}
