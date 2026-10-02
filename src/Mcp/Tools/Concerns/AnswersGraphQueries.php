<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ManifestStaleness;
use LaravelNecromancer\Relationships\ImpactAnalyzer;

/**
 * Manifest loading, start resolution, warnings, and errors shared by the
 * MCP graph tools (get_artifact, get_relationships, get_impact,
 * get_affected_tests). A stale or partial manifest is answered with
 * warnings rather than refused (docs/adr/0023); every other failure is an
 * MCP error whose body is {"error": <code>, "message": <string>}.
 */
trait AnswersGraphQueries
{
    /**
     * The deepest walk returned over MCP: the answer lands in the agent's
     * context window (docs/adr/0023).
     */
    private const MAX_DEPTH = 3;

    /**
     * The configured manifest, or a manifest_not_found error when it is
     * missing or predates schema v1.
     *
     * @return array<string, mixed>|Response
     */
    private function loadManifest(ManifestReader $reader): array|Response
    {
        $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));

        try {
            return $reader->read($path);
        } catch (ManifestNotFoundException) {
            return $this->error('manifest_not_found', 'No current Necromancer manifest was found (it is missing or predates schema v1). Run `php artisan necromancer:scan` to generate it.');
        }
    }

    /**
     * The single Artifact ID (or referenced Domain/Flow/ADR ID) the input
     * denotes, or an ambiguous/not_found error.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function resolveStart(ImpactAnalyzer $analyzer, array $manifest, string $input): string|Response
    {
        $candidates = $analyzer->startCandidates($manifest, $input);

        if ($candidates === []) {
            return $this->error('not_found', "No artifact matches '{$input}'. Pass an exact Artifact ID, a fully-qualified class name, or a referenced domain:/flow:/adr: ID.");
        }

        if (count($candidates) > 1) {
            return $this->error('ambiguous', "'{$input}' matches several artifacts. Pass one of the candidate Artifact IDs instead.", ['candidates' => $candidates]);
        }

        return $candidates[0];
    }

    /**
     * The requested depth (or $default) clamped to 1–MAX_DEPTH, adding a
     * warning to $warnings when it was clamped.
     *
     * @param  list<string>  $warnings
     */
    private function clampDepth(mixed $requested, int $default, array &$warnings): int
    {
        $depth = (int) ($requested ?? $default);
        $clamped = max(1, min(self::MAX_DEPTH, $depth));

        if ($clamped !== $depth) {
            $warnings[] = "Requested depth {$depth} was clamped to {$clamped}; {$this->name()} accepts a depth from 1 to ".self::MAX_DEPTH.'.';
        }

        return $clamped;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function isManifestStale(array $manifest): bool
    {
        return (new ManifestStaleness(app()->basePath()))->isStale($manifest);
    }

    /**
     * One warning when the manifest appears stale, and one when its scope
     * is not complete. Pass $stale when it is already known, so the source
     * files are not hashed twice.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function manifestWarnings(array $manifest, ?bool $stale = null): array
    {
        $warnings = [];

        if ($stale ?? $this->isManifestStale($manifest)) {
            $warnings[] = 'Manifest may be stale — source files have changed since it was generated, so recent edits may be missing. Run `php artisan necromancer:scan` to refresh.';
        }

        $scope = is_array($manifest['meta']['scope'] ?? null) ? $manifest['meta']['scope'] : [];

        if (! (bool) ($scope['complete'] ?? false)) {
            $types = array_filter((array) ($scope['artifact_types'] ?? []), is_string(...));
            $covered = $types === [] ? 'did not cover every artifact type' : 'covered only '.implode(', ', $types);
            $warnings[] = "Manifest scope is partial — the scan {$covered}, so Relationships to other artifact types are missing. Run a full `php artisan necromancer:scan`.";
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function error(string $code, string $message, array $extra = []): Response
    {
        return Response::error(json_encode(
            ['error' => $code, 'message' => $message, ...$extra],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
