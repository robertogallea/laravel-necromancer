<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

use Illuminate\Support\Facades\Route;
use ReflectionClass;

final class GoldenAnswerResolver
{
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
            $type === 'models' && $field !== null && $identifier !== null => $this->artifactField('models', $artifacts, $field, $identifier),
            $type === 'jobs' && $field === 'named' => $this->namedArtifacts('jobs', $artifacts),
            $type === 'jobs' && $field !== null && $identifier !== null => $this->artifactField('jobs', $artifacts, $field, $identifier),
            $type === 'events' && $field === 'named' => $this->namedArtifacts('events', $artifacts),
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
