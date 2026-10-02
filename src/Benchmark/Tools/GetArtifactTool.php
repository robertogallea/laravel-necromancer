<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LaravelNecromancer\Manifest\Concerns\QueriesArtifactGraph;

final class GetArtifactTool implements CanActAsTool, Tool
{
    use QueriesArtifactGraph;

    public function name(): string
    {
        return 'get_artifact';
    }

    public function description(): string
    {
        return 'Return one artifact\'s full manifest payload, by exact Artifact ID or fully-qualified class name.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->required()
                ->description('An exact Artifact ID (e.g. models:App\\Models\\Order) or a fully-qualified class name'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->graphQueries()->artifact((string) ($request['artifact'] ?? ''))->toJson();
    }
}
