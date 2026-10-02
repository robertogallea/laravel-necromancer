<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands\Concerns;

use LaravelNecromancer\Relationships\ImpactAnalyzer;

/**
 * The start and --depth handling shared by the commands built on an
 * Impact, so their wording can't drift apart.
 */
trait ResolvesImpactStart
{
    /**
     * The single Artifact ID (or Domain/Flow/ADR ID) the input denotes, or
     * null after reporting an unknown or ambiguous input.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function resolveImpactStart(ImpactAnalyzer $analyzer, array $manifest, string $input): ?string
    {
        $candidates = $analyzer->startCandidates($manifest, $input);

        if ($candidates === []) {
            $this->error("No artifact matches '{$input}'. Pass an exact Artifact ID, a fully-qualified class name, or a referenced domain:/flow:/adr: ID.");

            return null;
        }

        if (count($candidates) > 1) {
            $this->error("'{$input}' matches several artifacts. Pass one of these Artifact IDs instead:");

            foreach ($candidates as $candidate) {
                $this->error("  {$candidate}");
            }

            return null;
        }

        return $candidates[0];
    }

    /**
     * The --depth option, or null after reporting a value below 1.
     */
    private function impactDepth(): ?int
    {
        $depth = (string) $this->option('depth');

        if (! ctype_digit($depth) || (int) $depth < 1) {
            $this->error('The --depth option must be an integer of at least 1.');

            return null;
        }

        return (int) $depth;
    }
}
