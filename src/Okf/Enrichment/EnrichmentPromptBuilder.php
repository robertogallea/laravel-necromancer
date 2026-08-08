<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

use LaravelNecromancer\Okf\ArtifactConceptBuilder;

/**
 * Builds the privacy-bounded prompt sent to the AI provider for one
 * concept. Pure and deterministic. The privacy boundary is structural, not
 * a filter applied after the fact:
 *
 * - Artifact prompts are built from `facts`/`annotations` only, using
 *   ArtifactConceptBuilder::EXCLUDED_FACT_KEYS itself — the exact same
 *   constant, not a copy — so `source` (paths and hashes) and
 *   `route_metadata` (raw framework metadata) are never part of the
 *   payload in the first place, and can never drift from what an Artifact
 *   Concept's own body excludes.
 * - forAdr() has no parameter for the ADR's copied file content at all —
 *   it cannot leak what it was never given.
 * - Nothing here ever reads application configuration; the payload is
 *   built exclusively from manifest artifact data, which Necromancer never
 *   populates with config values (see the package's own privacy policy).
 */
final class EnrichmentPromptBuilder
{
    /**
     * @param  array<string, mixed>  $artifact
     */
    public function forArtifact(string $type, array $artifact): string
    {
        $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];

        return $this->render([
            'kind' => 'artifact',
            'type' => $type,
            'id' => (string) ($artifact['id'] ?? ''),
            'facts' => array_diff_key($artifact, array_flip(ArtifactConceptBuilder::EXCLUDED_FACT_KEYS)),
            'annotations' => $annotations,
        ]);
    }

    /**
     * @param  list<string>  $memberIds
     */
    public function forGroup(string $kind, string $value, array $memberIds): string
    {
        return $this->render([
            'kind' => $kind,
            'value' => $value,
            'members' => $memberIds,
        ]);
    }

    /**
     * @param  list<string>  $referencedByIds
     */
    public function forAdr(string $path, array $referencedByIds): string
    {
        return $this->render([
            'kind' => 'adr',
            'path' => $path,
            'referenced_by' => $referencedByIds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<PROMPT
        {$this->instructions()}

        Concept data (JSON):
        {$json}
        PROMPT;
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
        You are writing prose for a portable knowledge bundle documenting a Laravel application.
        You will receive one concept's structured data as JSON below — nothing else about the
        application is available to you.

        Produce:
        - description: one plain sentence suitable as a search-result summary.
        - narrative: a short paragraph (2-4 sentences) explaining what this concept is and why it
          likely exists, grounded only in the data given. Do not invent facts not present in the
          data. If the data is too sparse to say anything specific, say so plainly rather than
          guessing.
        PROMPT;
    }
}
