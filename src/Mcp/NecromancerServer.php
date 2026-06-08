<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp;

use Laravel\Mcp\Server;
use LaravelNecromancer\Mcp\Tools\QueryArtifactsTool;
use LaravelNecromancer\Mcp\Tools\QueryModelsTool;
use LaravelNecromancer\Mcp\Tools\QueryRoutesTool;
use LaravelNecromancer\Mcp\Tools\SearchArtifactsTool;

final class NecromancerServer extends Server
{
    protected string $name = 'laravel-necromancer';

    protected string $instructions = <<<'MD'
        Read-only access to the Necromancer manifest — a structured inventory of this
        Laravel application's routes, models, form requests, jobs, events, listeners,
        commands, policies, tests, and other structural artifacts.
        All tools are read-only and query the necromancer.json manifest file, not the live database.
        Run `php artisan necromancer:scan` to refresh the manifest.
    MD;

    protected array $tools = [
        QueryRoutesTool::class,
        QueryModelsTool::class,
        QueryArtifactsTool::class,
        SearchArtifactsTool::class,
    ];
}
