<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\GraphQueryService;
use LaravelNecromancer\Mcp\Tools\Concerns\AnswersGraphQueries;

final class GetAffectedTestsTool extends Tool
{
    use AnswersGraphQueries;

    public function name(): string
    {
        return 'get_affected_tests';
    }

    public function description(): string
    {
        return 'List the tests affected by a changed artifact, Domain, Flow, or ADR, or by changed file paths: directly affected tests test a changed artifact, indirectly affected ones test something it reaches. Paths that are the source of no artifact are returned as unmapped.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()
                ->description('An exact Artifact ID, a fully-qualified class name, or a domain:<v>, flow:<v>, or adr:<path> ID. Pass this or paths, not both.'),
            'paths' => $schema->array()->items($schema->string())
                ->description('Changed file paths: relative, ./-prefixed, or absolute under the project. Pass this or artifact, not both.'),
            'depth' => $schema->integer()
                ->description('How many Relationships away to walk: 1 to '.GraphQueryService::MAX_DEPTH.' (default 2); larger values are clamped'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->respond($this->graphQueries()->affectedTests(
            $request->get('artifact'),
            $request->get('paths'),
            $request->get('depth'),
        ));
    }
}
