<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionException;

final readonly class JobCollector
{
    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
     */
    public function __construct(
        private Application $app,
        private ?array $roots = null,
    ) {}

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
            'path' => $this->app->basePath('app/Jobs'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Jobs\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return null;
        }

        if ($reflection->isAbstract() || ! $this->isJob($reflection)) {
            return null;
        }

        $queueAttr = AttributeReader::first($reflection, Queue::class);
        $connectionAttr = AttributeReader::first($reflection, Connection::class);
        $triesAttr = AttributeReader::first($reflection, Tries::class);
        $timeoutAttr = AttributeReader::first($reflection, Timeout::class);
        $backoffAttr = AttributeReader::first($reflection, Backoff::class);
        $maxExcAttr = AttributeReader::first($reflection, MaxExceptions::class);

        $rawTimeout = $timeoutAttr?->timeout ?? $this->scalarDefaultProperty($reflection, 'timeout');

        return StructuralArtifact::job(
            class: $class,
            queue: $queueAttr?->queue ?? $this->stringDefaultProperty($reflection, 'queue'),
            connection: $connectionAttr?->connection ?? $this->stringDefaultProperty($reflection, 'connection'),
            tries: $triesAttr?->tries ?? $this->scalarDefaultProperty($reflection, 'tries'),
            timeout: is_int($rawTimeout) ? $rawTimeout : null,
            source: (new SourceLocator)->forClass($reflection),
            backoff: $backoffAttr?->backoff ?? null,
            maxExceptions: $maxExcAttr?->maxExceptions ?? null,
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function isJob(ReflectionClass $reflection): bool
    {
        if ($reflection->implementsInterface(ShouldQueue::class)) {
            return true;
        }

        $traits = class_uses_recursive($reflection->getName());

        return count(array_intersect($traits, [
            \Illuminate\Foundation\Queue\Queueable::class,
            Queueable::class,
            InteractsWithQueue::class,
            Dispatchable::class,
        ])) > 0
            || str_contains($reflection->getName(), '\\Jobs\\');
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function stringDefaultProperty(ReflectionClass $reflection, string $property): ?string
    {
        $value = $this->scalarDefaultProperty($reflection, $property);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function scalarDefaultProperty(ReflectionClass $reflection, string $property): string|int|null
    {
        if (! $reflection->hasProperty($property)) {
            return null;
        }

        $reflectedProperty = $reflection->getProperty($property);

        if ($reflectedProperty->getDeclaringClass()->getName() !== $reflection->getName()) {
            return null;
        }

        $properties = $reflection->getDefaultProperties();
        $value = $properties[$property] ?? null;

        return is_string($value) || is_int($value) ? $value : null;
    }
}
