<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures;

use Illuminate\Routing\Route;
use Illuminate\Support\Arr;

/**
 * Simulates the Laravel 13.17+ Route::metadata()/getMetadata() API for tests,
 * since the framework version vendored for this package's own test suite
 * predates it. RouteCollector only ever checks method_exists($route, 'getMetadata'),
 * so a real method on a real Route subclass exercises the same code path
 * production code takes against the genuine Laravel 13.17+ API.
 */
final class NecromancerFakeMetadataRoute extends Route
{
    /** @var array<string, mixed> */
    private array $routeMetadata = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->routeMetadata = array_replace_recursive($this->routeMetadata, $metadata);

        return $this;
    }

    public function getMetadata(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->routeMetadata : Arr::get($this->routeMetadata, $key, $default);
    }
}
