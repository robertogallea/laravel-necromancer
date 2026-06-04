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
            $type === 'models' && $field !== null && $identifier !== null => $this->artifactField('models', $artifacts, $field, $identifier),
            $type === 'jobs' && $field !== null && $identifier !== null => $this->artifactField('jobs', $artifacts, $field, $identifier),
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
