<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp;

use Laravel\Mcp\Server;
use LaravelNecromancer\Mcp\Tools\GetAffectedTestsTool;
use LaravelNecromancer\Mcp\Tools\GetArtifactTool;
use LaravelNecromancer\Mcp\Tools\GetImpactTool;
use LaravelNecromancer\Mcp\Tools\GetRelationshipsTool;
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

        The graph tools follow Relationships (route → controller, model → policy, event → listener,
        artifact → job it dispatches, and so on): `get_artifact` returns one artifact's full payload,
        `get_relationships` lists every Relationship it takes part in, `get_impact` lists
        everything reachable from it within a depth of 1 to 3, and `get_affected_tests` lists the
        tests affected by it or by changed file paths. They accept an Artifact ID or a
        fully-qualified class name, and all but `get_artifact` also accept a `domain:<v>`,
        `flow:<v>`, or `adr:<path>` ID to explore a Domain, Flow, or ADR. They answer from a stale or
        partial manifest and report it in their `warnings` list.

        After editing files, call `get_affected_tests` with the changed paths to learn which tests to run.
    MD;

    protected array $tools = [
        QueryRoutesTool::class,
        QueryModelsTool::class,
        QueryArtifactsTool::class,
        SearchArtifactsTool::class,
        GetArtifactTool::class,
        GetRelationshipsTool::class,
        GetImpactTool::class,
        GetAffectedTestsTool::class,
    ];
}
