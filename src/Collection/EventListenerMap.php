<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final readonly class EventListenerMap
{
    /**
     * @param  list<string>  $eventClasses
     * @param  list<string>  $listenerClasses
     */
    public function __construct(
        private Application $app,
        private array $eventClasses,
        private array $listenerClasses,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function listenersByEvent(): array
    {
        $listenersByEvent = $this->runtimeListenersByEvent();

        foreach ($this->listenerClasses as $listenerClass) {
            foreach ($this->handledEvents($listenerClass) as $eventClass) {
                if (! $this->knownEvent($eventClass)) {
                    continue;
                }

                $listenersByEvent[$eventClass][] = $listenerClass;
            }
        }

        return $this->normalizeMap($listenersByEvent);
    }

    /**
     * @return array<string, list<string>>
     */
    public function eventsByListener(): array
    {
        $eventsByListener = [];

        foreach ($this->listenersByEvent() as $eventClass => $listenerClasses) {
            foreach ($listenerClasses as $listenerClass) {
                $eventsByListener[$listenerClass][] = $eventClass;
            }
        }

        return $this->normalizeMap($eventsByListener);
    }

    /**
     * @return array<string, list<string>>
     */
    private function runtimeListenersByEvent(): array
    {
        $dispatcher = $this->app->make(DispatcherContract::class);

        if (! method_exists($dispatcher, 'getRawListeners')) {
            return [];
        }

        $listenersByEvent = [];

        foreach ($dispatcher->getRawListeners() as $eventClass => $listeners) {
            if (! is_string($eventClass) || str_contains($eventClass, '*') || ! $this->knownEvent($eventClass)) {
                continue;
            }

            foreach ((array) $listeners as $listener) {
                $listenerClass = $this->listenerClass($listener);

                if ($listenerClass !== null && $this->knownListener($listenerClass)) {
                    $listenersByEvent[$eventClass][] = $listenerClass;
                }
            }
        }

        return $listenersByEvent;
    }

    private function listenerClass(mixed $listener): ?string
    {
        if (is_string($listener)) {
            $class = str_contains($listener, '@')
                ? strstr($listener, '@', true)
                : $listener;

            return is_string($class) && class_exists($class) ? ltrim($class, '\\') : null;
        }

        if (is_array($listener)) {
            $target = $listener[0] ?? null;

            if (is_string($target) && class_exists($target)) {
                return ltrim($target, '\\');
            }

            if (is_object($target)) {
                return $target::class;
            }
        }

        if (is_object($listener) && ! $listener instanceof Closure) {
            return $listener::class;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function handledEvents(string $listenerClass): array
    {
        if (! class_exists($listenerClass)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($listenerClass);
        } catch (ReflectionException) {
            return [];
        }

        if ($reflection->isAbstract()) {
            return [];
        }

        foreach (['handle', '__invoke'] as $methodName) {
            if (! $reflection->hasMethod($methodName)) {
                continue;
            }

            $method = $reflection->getMethod($methodName);

            if (! $this->canReflectHandler($method)) {
                continue;
            }

            $parameter = $method->getParameters()[0] ?? null;

            return $parameter === null ? [] : $this->classesFromType($parameter->getType());
        }

        return [];
    }

    private function canReflectHandler(ReflectionMethod $method): bool
    {
        return $method->isPublic()
            && ! $method->isStatic()
            && count($method->getParameters()) > 0;
    }

    /**
     * @return list<string>
     */
    private function classesFromType(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $this->classFromNamedType($type);
        }

        if ($type instanceof ReflectionUnionType) {
            $classes = [];

            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType) {
                    $classes = [
                        ...$classes,
                        ...$this->classFromNamedType($unionType),
                    ];
                }
            }

            return array_values(array_unique($classes));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function classFromNamedType(ReflectionNamedType $type): array
    {
        if ($type->isBuiltin() || ! class_exists($type->getName())) {
            return [];
        }

        return [ltrim($type->getName(), '\\')];
    }

    private function knownEvent(string $eventClass): bool
    {
        return in_array(ltrim($eventClass, '\\'), $this->eventClasses, true);
    }

    private function knownListener(string $listenerClass): bool
    {
        return in_array(ltrim($listenerClass, '\\'), $this->listenerClasses, true);
    }

    /**
     * @param  array<string, list<string>>  $map
     * @return array<string, list<string>>
     */
    private function normalizeMap(array $map): array
    {
        foreach ($map as $key => $values) {
            $values = array_values(array_unique($values));
            sort($values);

            $map[$key] = $values;
        }

        ksort($map);

        return $map;
    }
}
