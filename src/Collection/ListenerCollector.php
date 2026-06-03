<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionException;

final readonly class ListenerCollector
{
    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
     * @param  list<array{path: string, namespace: string}>|null  $eventRoots
     */
    public function __construct(
        private Application $app,
        private ?array $roots = null,
        private ?array $eventRoots = null,
    ) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $listenerClasses = $this->listenerClasses();
        $eventsByListener = (new EventListenerMap(
            app: $this->app,
            eventClasses: $this->eventClasses(),
            listenerClasses: $listenerClasses,
        ))->eventsByListener();

        $artifacts = [];

        foreach ($listenerClasses as $class) {
            $artifact = $this->collectClass($class, $eventsByListener[$class] ?? []);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * @return list<string>
     */
    private function listenerClasses(): array
    {
        return (new ClassDiscovery($this->listenerRoots()))->classes();
    }

    /**
     * @return list<string>
     */
    private function eventClasses(): array
    {
        return (new ClassDiscovery($this->eventDiscoveryRoots()))->classes();
    }

    /**
     * @param  list<string>  $handles
     */
    private function collectClass(string $class, array $handles): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return null;
        }

        if ($reflection->isAbstract()) {
            return null;
        }

        return StructuralArtifact::listener(
            class: $class,
            handles: $handles,
            queued: $reflection->implementsInterface(ShouldQueue::class),
            source: (new SourceLocator)->forClass($reflection),
        );
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function listenerRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        return [[
            'path' => $this->app->basePath('app/Listeners'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Listeners\\',
        ]];
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function eventDiscoveryRoots(): array
    {
        if (is_array($this->eventRoots)) {
            return $this->eventRoots;
        }

        return [[
            'path' => $this->app->basePath('app/Events'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Events\\',
        ]];
    }
}
