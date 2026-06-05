<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

final readonly class GateCollector
{
    public function __construct(private Application $app) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        try {
            $gate = $this->app->make(\Illuminate\Auth\Access\Gate::class);
        } catch (Throwable) {
            return [];
        }

        $artifacts = [];

        try {
            $reflection = new ReflectionClass($gate);

            $abilitiesProp = $reflection->getProperty('abilities');
            $abilitiesProp->setAccessible(true);
            $abilities = $abilitiesProp->getValue($gate);

            foreach ($abilities as $ability => $callback) {
                $artifacts[] = $this->collectAbility((string) $ability, $callback);
            }
        } catch (Throwable) {
            // If reflection fails, skip abilities silently.
        }

        try {
            $reflection ??= new ReflectionClass($gate);

            $beforeProp = $reflection->getProperty('beforeCallbacks');
            $beforeProp->setAccessible(true);
            $beforeCallbacks = $beforeProp->getValue($gate);

            foreach ($beforeCallbacks as $callback) {
                $artifacts[] = StructuralArtifact::gate(
                    ability: '__before__',
                    kind: 'before_hook',
                    parameters: [],
                    source: null,
                );
            }
        } catch (Throwable) {
            // If reflection fails, skip before hooks silently.
        }

        try {
            $reflection ??= new ReflectionClass($gate);

            $afterProp = $reflection->getProperty('afterCallbacks');
            $afterProp->setAccessible(true);
            $afterCallbacks = $afterProp->getValue($gate);

            foreach ($afterCallbacks as $callback) {
                $artifacts[] = StructuralArtifact::gate(
                    ability: '__after__',
                    kind: 'after_hook',
                    parameters: [],
                    source: null,
                );
            }
        } catch (Throwable) {
            // If reflection fails, skip after hooks silently.
        }

        return $artifacts;
    }

    /**
     * @param  Closure|string  $callback
     */
    private function collectAbility(string $ability, mixed $callback): StructuralArtifact
    {
        if ($callback instanceof Closure) {
            return StructuralArtifact::gate(
                ability: $ability,
                kind: 'closure',
                parameters: $this->closureParameters($callback),
                source: null,
            );
        }

        // Class string (invokable class)
        return StructuralArtifact::gate(
            ability: $ability,
            kind: 'class',
            parameters: [],
            source: null,
        );
    }

    /**
     * @return list<string>
     */
    private function closureParameters(Closure $closure): array
    {
        try {
            $reflection = new ReflectionFunction($closure);
            $params = $reflection->getParameters();

            // Skip index 0 — that's the $user parameter
            $params = array_slice($params, 1);

            $names = [];

            foreach ($params as $param) {
                $type = $param->getType();

                if ($type instanceof ReflectionNamedType) {
                    $names[] = $type->getName();
                } else {
                    $names[] = $param->getName();
                }
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }
}
