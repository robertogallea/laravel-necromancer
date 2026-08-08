<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use Throwable;

final readonly class EventCollector
{
    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
     * @param  list<array{path: string, namespace: string}>|null  $listenerRoots
     */
    public function __construct(
        private Application $app,
        private ?array $roots = null,
        private ?array $listenerRoots = null,
    ) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $eventClasses = $this->eventClasses();
        $listenersByEvent = (new EventListenerMap(
            app: $this->app,
            eventClasses: $eventClasses,
            listenerClasses: $this->listenerClasses(),
        ))->listenersByEvent();

        $artifacts = [];

        foreach ($eventClasses as $class) {
            $artifact = $this->collectClass($class, $listenersByEvent[$class] ?? []);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * @return list<string>
     */
    private function eventClasses(): array
    {
        return (new ClassDiscovery($this->eventRoots()))->classes();
    }

    /**
     * @return list<string>
     */
    private function listenerClasses(): array
    {
        return (new ClassDiscovery($this->listenerDiscoveryRoots()))->classes();
    }

    /**
     * @param  list<string>  $listeners
     */
    private function collectClass(string $class, array $listeners): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return null;
        }

        $broadcastable = $reflection->implementsInterface(ShouldBroadcast::class);

        return StructuralArtifact::event(
            class: $class,
            listeners: $listeners,
            broadcastable: $broadcastable,
            channels: $broadcastable ? $this->broadcastChannels($reflection) : [],
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function broadcastChannels(ReflectionClass $reflection): array
    {
        $channels = [];

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $result = $instance->broadcastOn();

            foreach (is_array($result) ? $result : [$result] as $channel) {
                if ($channel === null) {
                    continue;
                }

                if (is_string($channel)) {
                    $channels[] = $channel;
                } elseif (is_object($channel) && isset($channel->name)) {
                    $channels[] = $channel->name;
                }
            }
        } catch (Throwable) {
            // channels stays []
        }

        return $channels;
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function eventRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        return [[
            'path' => $this->app->basePath('app/Events'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Events\\',
        ]];
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function listenerDiscoveryRoots(): array
    {
        if (is_array($this->listenerRoots)) {
            return $this->listenerRoots;
        }

        return [[
            'path' => $this->app->basePath('app/Listeners'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Listeners\\',
        ]];
    }
}
