<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Mcp\Tools\Concerns\AnswersGraphQueries;
use LaravelNecromancer\Relationships\AffectedTest;
use LaravelNecromancer\Relationships\AffectedTestFinder;
use LaravelNecromancer\Relationships\ImpactAnalyzer;

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
                ->description('How many Relationships away to walk: 1 to '.self::MAX_DEPTH.' (default 2); larger values are clamped'),
        ];
    }

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer, AffectedTestFinder $finder, Request $request): Response
    {
        $artifact = $request->get('artifact');
        $paths = $request->get('paths');

        if (($artifact !== null) === ($paths !== null)) {
            return $this->error('invalid_input', 'Pass exactly one of artifact or paths.');
        }

        $manifest = $this->loadManifest($reader);

        if ($manifest instanceof Response) {
            return $manifest;
        }

        $unmapped = [];

        if ($paths !== null) {
            $paths = array_map(fn (mixed $path): string => (string) $path, (array) $paths);
            ['starts' => $starts, 'unmapped' => $unmapped] = $finder->startsForPaths($manifest, $paths, app()->basePath());
        } else {
            $start = $this->resolveStart($analyzer, $manifest, (string) $artifact);

            if ($start instanceof Response) {
                return $start;
            }

            $starts = [$start];
        }

        $stale = $this->isManifestStale($manifest);
        $warnings = $this->manifestWarnings($manifest, $stale);

        if ($stale && $unmapped !== []) {
            $warnings[] = count($unmapped).' unmapped path(s) may be files created or moved since the scan. Run `php artisan necromancer:scan` to map them.';
        }

        $depth = $this->clampDepth($request->get('depth'), 2, $warnings);
        $tests = $finder->find($manifest, $starts, $depth);

        return Response::json([
            'directly_affected' => array_values(array_filter($tests, fn (AffectedTest $test): bool => $test->directlyAffected())),
            'indirectly_affected' => array_values(array_filter($tests, fn (AffectedTest $test): bool => ! $test->directlyAffected())),
            'unmapped' => $unmapped,
            'depth' => $depth,
            'warnings' => $warnings,
        ]);
    }
}
