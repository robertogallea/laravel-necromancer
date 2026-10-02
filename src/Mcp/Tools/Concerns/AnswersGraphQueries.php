<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;
use LaravelNecromancer\Manifest\Concerns\QueriesArtifactGraph;
use LaravelNecromancer\Manifest\GraphQueryResult;

/**
 * Adapts GraphQueryService results to MCP responses for the graph tools
 * (get_artifact, get_relationships, get_impact, get_affected_tests): a
 * success is a JSON text response, and an error is an MCP error whose
 * body is {"error": <code>, "message": <string>}.
 */
trait AnswersGraphQueries
{
    use QueriesArtifactGraph;

    private function respond(GraphQueryResult $result): Response
    {
        return $result->isError ? Response::error($result->toJson()) : Response::text($result->toJson());
    }
}
