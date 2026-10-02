<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\ImpactNode;
use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipResolver;
use LaravelNecromancer\Relationships\RelationshipType;
use ReflectionClass;

final class GoldenAnswerResolver
{
    /** @var list<Relationship>|null */
    private ?array $relationships = null;

    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest) {}

    /**
     * @param  string[]  $factKeys
     * @return array<string, array{value: mixed, trusted: bool}>
     */
    public function resolve(array $factKeys): array
    {
        $resolved = [];

        foreach ($factKeys as $key) {
            $value = $this->resolveKey($key);
            $trusted = $value !== null && $this->verifyAgainstRuntime($key, $value);
            $resolved[$key] = compact('value', 'trusted');
        }

        return $resolved;
    }

    private function resolveKey(string $key): mixed
    {
        $segments = explode('.', $key, 3);
        $type = $segments[0];
        $field = $segments[1] ?? null;
        $identifier = $segments[2] ?? null;

        $artifacts = (array) ($this->manifest['artifacts'] ?? []);

        return match (true) {
            $type === 'routes' && $field === 'named' => $this->namedRouteNames($artifacts),
            $type === 'routes' && $field === 'auth_required' => $this->authRequiredRouteNames($artifacts),
            $type === 'models' && $field === 'cast_keys' && $identifier !== null => $this->modelCastKeys($artifacts, $identifier),
            $type === 'models' && $field === 'observer_short_names' && $identifier !== null => $this->modelObserverShortNames($artifacts, $identifier),
            $type === 'models' && $field === 'with_observers' => $this->modelsWithObservers($artifacts),
            $type === 'models' && $field === 'with_casts' => $this->modelsWithCasts($artifacts),
            $type === 'models' && $field !== null && $identifier !== null => $this->artifactField('models', $artifacts, $field, $identifier),
            $type === 'jobs' && $field === 'named' => $this->namedArtifacts('jobs', $artifacts),
            $type === 'jobs' && $field !== null && $identifier !== null => $this->artifactField('jobs', $artifacts, $field, $identifier),
            $type === 'events' && $field === 'named' => $this->namedArtifacts('events', $artifacts),
            $type === 'events' && $field === 'listeners' => $this->relationshipTargetShortNames(RelationshipType::ListenedBy),
            $type === 'dispatches' && $field === 'targets' => $this->relationshipTargetShortNames(RelationshipType::Dispatches),
            $type === 'impact' && $field === 'labels' && $identifier !== null => $this->impactLabels($identifier),
            $type === 'policies' && $field === 'models' => $this->policyModelNames($artifacts),
            default => null,
        };
    }

    private function verifyAgainstRuntime(string $key, mixed $value): bool
    {
        $segments = explode('.', $key, 3);

        if ($segments[0] === 'routes' && ($segments[1] ?? null) === 'named') {
            try {
                $runtimeNames = array_keys(Route::getRoutes()->getRoutesByName());

                return array_diff((array) $value, $runtimeNames) === [];
            } catch (\Exception) {
                return true;
            }
        }

        if ($segments[0] === 'events' && ($segments[1] ?? null) === 'listeners') {
            try {
                return $this->dispatcherRegistersEveryListener();
            } catch (\Exception) {
                return true;
            }
        }

        if ($segments[0] === 'models' && isset($segments[2])) {
            $artifacts = (array) ($this->manifest['artifacts']['models'] ?? []);

            foreach ($artifacts as $model) {
                if ($this->shortName((string) ($model['class'] ?? '')) === $segments[2]) {
                    return class_exists((string) $model['class']);
                }
            }
        }

        return true;
    }

    /**
     * Whether the event dispatcher registers the listener of every
     * listened_by Relationship for its event.
     */
    private function dispatcherRegistersEveryListener(): bool
    {
        $rawListeners = Event::getRawListeners();

        foreach ($this->relationshipsOfType(RelationshipType::ListenedBy) as $relationship) {
            $registered = array_map(
                $this->listenerClass(...),
                (array) ($rawListeners[$this->endClass($relationship->from)] ?? []),
            );

            if (! in_array($this->endClass($relationship->to), $registered, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The class of a raw dispatcher listener ('Class', 'Class@method', or
     * [class, method]); null for a closure.
     */
    private function listenerClass(mixed $listener): ?string
    {
        if (is_string($listener)) {
            return explode('@', $listener, 2)[0];
        }

        if (is_array($listener) && isset($listener[0])) {
            return is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];
        }

        return null;
    }

    /** @return string[] */
    private function namedRouteNames(array $artifacts): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $r): ?string => filled($r['name'] ?? null) ? (string) $r['name'] : null,
                (array) ($artifacts['routes'] ?? [])
            )
        ));
    }

    /** @return string[] */
    private function authRequiredRouteNames(array $artifacts): array
    {
        return array_values(array_filter(
            array_map(
                function (array $r): ?string {
                    if (! filled($r['name'] ?? null)) {
                        return null;
                    }

                    $hasAuth = array_filter(
                        (array) ($r['middleware'] ?? []),
                        fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:')
                    );

                    return $hasAuth ? (string) $r['name'] : null;
                },
                (array) ($artifacts['routes'] ?? [])
            )
        ));
    }

    /** @return string[] */
    private function namedArtifacts(string $type, array $artifacts): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $item): ?string => isset($item['class']) ? $this->shortName((string) $item['class']) : null,
                (array) ($artifacts[$type] ?? [])
            )
        ));
    }

    /** @return string[] */
    private function modelsWithObservers(array $artifacts): array
    {
        return array_values(array_filter(array_map(
            function (array $model): ?string {
                return ! empty($model['observers']) ? $this->shortName((string) ($model['class'] ?? '')) : null;
            },
            (array) ($artifacts['models'] ?? [])
        )));
    }

    /** @return string[] */
    private function modelsWithCasts(array $artifacts): array
    {
        return array_values(array_filter(array_map(
            function (array $model): ?string {
                return ! empty($model['casts']) ? $this->shortName((string) ($model['class'] ?? '')) : null;
            },
            (array) ($artifacts['models'] ?? [])
        )));
    }

    /** @return string[]|null  null when model absent from manifest, [] when model present but no casts */
    private function modelCastKeys(array $artifacts, string $identifier): ?array
    {
        foreach ((array) ($artifacts['models'] ?? []) as $model) {
            if ($this->shortName((string) ($model['class'] ?? '')) === $identifier) {
                return array_keys((array) ($model['casts'] ?? []));
            }
        }

        return null;
    }

    /** @return string[]|null  null when model absent, [] when model present but no observers */
    private function modelObserverShortNames(array $artifacts, string $identifier): ?array
    {
        foreach ((array) ($artifacts['models'] ?? []) as $model) {
            if ($this->shortName((string) ($model['class'] ?? '')) === $identifier) {
                return array_values(array_map(
                    fn (string $fqcn): string => $this->shortName($fqcn),
                    (array) ($model['observers'] ?? [])
                ));
            }
        }

        return null;
    }

    /** @return string[] */
    private function policyModelNames(array $artifacts): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $p): ?string => isset($p['model']) ? $this->shortName((string) $p['model']) : null,
                (array) ($artifacts['policies'] ?? [])
            )
        ));
    }

    /**
     * The short class names of every target of the given Relationship type,
     * once each, in canonical Relationship order.
     *
     * @return string[]
     */
    private function relationshipTargetShortNames(RelationshipType $type): array
    {
        return array_values(array_unique(array_map(
            fn (Relationship $relationship): string => $this->shortName($this->endClass($relationship->to)),
            $this->relationshipsOfType($type),
        )));
    }

    /** @return list<Relationship> */
    private function relationshipsOfType(RelationshipType $type): array
    {
        $this->relationships ??= (new RelationshipResolver)->resolve($this->manifest);

        return array_values(array_filter(
            $this->relationships,
            fn (Relationship $relationship): bool => $relationship->type === $type,
        ));
    }

    /**
     * The label of every node the Impact of an artifact reaches at depth 1.
     *
     * @return string[]|null null when no collected artifact has that ID
     */
    private function impactLabels(string $id): ?array
    {
        if ($this->artifactById($id) === null) {
            return null;
        }

        $impact = (new ImpactAnalyzer)->analyze($this->manifest, $id, 1);

        return array_map(fn (ImpactNode $node): string => $impact->label($node->id), $impact->nodes);
    }

    /** @return array<string, mixed>|null */
    private function artifactById(string $id): ?array
    {
        foreach ((array) ($this->manifest['artifacts'] ?? []) as $items) {
            foreach ((array) $items as $item) {
                if (is_array($item) && ($item['id'] ?? null) === $id) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * The class of the artifact a Relationship end names, or the end itself
     * when it is an unresolved raw class.
     */
    private function endClass(string $end): string
    {
        return (string) ($this->artifactById($end)['class'] ?? $end);
    }

    private function artifactField(string $type, array $artifacts, string $field, string $identifier): mixed
    {
        foreach ((array) ($artifacts[$type] ?? []) as $item) {
            if ($this->shortName((string) ($item['class'] ?? '')) === $identifier) {
                return $item[$field] ?? null;
            }
        }

        return null;
    }

    private function shortName(string $class): string
    {
        if (class_exists($class)) {
            return (new ReflectionClass($class))->getShortName();
        }

        return basename(str_replace('\\', '/', $class));
    }
}
