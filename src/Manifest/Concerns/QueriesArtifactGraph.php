<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\Concerns;

use LaravelNecromancer\Manifest\GraphQueryService;

trait QueriesArtifactGraph
{
    /**
     * The graph queries over the configured manifest.
     */
    private function graphQueries(): GraphQueryService
    {
        return new GraphQueryService(
            (string) config('necromancer.output.manifest', base_path('necromancer.json')),
            app()->basePath(),
        );
    }
}
