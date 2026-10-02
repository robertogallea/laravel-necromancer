<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ManifestScopeGuard;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\ImpactNode;

final class ImpactCommand extends Command
{
    use ReadsManifest;

    /**
     * Concept types --type accepts besides the artifact types.
     *
     * @var list<string>
     */
    private const CONCEPT_TYPES = ['domain', 'flow', 'adr'];

    protected $signature = 'necromancer:impact
        {artifact        : An exact Artifact ID or a fully-qualified class name}
        {--depth=1       : How many Relationships away to walk (at least 1)}
        {--type=         : Only display these node types (comma-separated artifact types, domain, flow, adr)}
        {--json          : Output the Impact as JSON}
        {--allow-stale   : Analyze even if the manifest appears stale}
        {--allow-partial : Analyze even if the manifest scope is partial}';

    protected $description = 'Show the artifacts, Domains, Flows, and ADRs connected to an artifact through its Relationships';

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer, ArtifactConceptBuilder $identity): int
    {
        $depth = $this->depth();
        $types = $this->displayTypes();

        if ($depth === null || $types === null) {
            return self::FAILURE;
        }

        try {
            $manifest = $reader->read($this->resolveManifestPath());
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $refusal = ManifestScopeGuard::refusal(
            $manifest,
            $this->isStale($manifest),
            (bool) $this->option('allow-stale'),
            (bool) $this->option('allow-partial'),
            'analyze anyway',
        );

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $input = (string) $this->argument('artifact');
        $candidates = $analyzer->startCandidates($manifest, $input);

        if ($candidates === []) {
            $this->error("No artifact matches '{$input}'. Pass an exact Artifact ID or a fully-qualified class name.");

            return self::FAILURE;
        }

        if (count($candidates) > 1) {
            $this->error("'{$input}' matches several artifacts. Pass one of these Artifact IDs instead:");

            foreach ($candidates as $candidate) {
                $this->line("  {$candidate}");
            }

            return self::FAILURE;
        }

        $impact = $analyzer->analyze($manifest, $candidates[0], $depth);
        $nodes = $types === [] ? $impact->nodes : array_values(array_filter(
            $impact->nodes,
            fn (ImpactNode $node): bool => in_array($node->type, $types, true),
        ));

        if ($this->option('json')) {
            $this->line(json_encode(
                ['start' => $impact->start, 'depth' => $impact->depth, 'nodes' => $nodes],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $labels = $this->labels($manifest, $identity);
        $this->line("Impact of {$this->label($impact->start, $labels)} ({$impact->start}), depth {$impact->depth}");

        if ($nodes === []) {
            $this->line('');
            $this->line('No relationships found.');

            return self::SUCCESS;
        }

        $this->renderNodes($nodes, $labels);

        return self::SUCCESS;
    }

    /**
     * @param  list<ImpactNode>  $nodes
     * @param  array<string, string>  $labels
     */
    private function renderNodes(array $nodes, array $labels): void
    {
        $groupOrder = array_flip([...ArtifactId::supportedTypes(), ...self::CONCEPT_TYPES, 'unresolved']);
        $byDistance = [];

        foreach ($nodes as $node) {
            $byDistance[$node->distance][$node->type ?? 'unresolved'][] = $node;
        }

        foreach ($byDistance as $distance => $groups) {
            uksort($groups, fn (string $a, string $b): int => $groupOrder[$a] <=> $groupOrder[$b]);
            $this->line('');
            $this->line("Depth {$distance}");

            foreach ($groups as $group => $groupNodes) {
                $this->line("  {$group}");

                foreach ($groupNodes as $node) {
                    $relationship = $node->via->type->value;
                    $line = '    '.$this->label($node->id, $labels).($node->resolved ? '' : ' (unresolved)').'  ';
                    $line .= $node->direction === 'out' ? "{$relationship} →" : "← {$relationship}";

                    if ($distance > 1) {
                        $line .= '  via '.$this->label($node->viaFrom, $labels);
                    }

                    $this->line($line);
                }
            }
        }
    }

    /**
     * Artifact ID → display label, using the package's per-type label
     * convention (route method + URI, class name, ...).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function labels(array $manifest, ArtifactConceptBuilder $identity): array
    {
        $labels = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                if (is_array($artifact) && is_string($artifact['id'] ?? null)) {
                    $labels[$artifact['id']] = $identity->identify($type, $artifact)['title'];
                }
            }
        }

        return $labels;
    }

    /**
     * Domain/Flow/ADR ids and unresolved ends are their own label.
     *
     * @param  array<string, string>  $labels
     */
    private function label(string $id, array $labels): string
    {
        $label = $labels[$id] ?? '';

        return $label !== '' ? $label : $id;
    }

    private function depth(): ?int
    {
        $depth = (string) $this->option('depth');

        if (! ctype_digit($depth) || (int) $depth < 1) {
            $this->error('The --depth option must be an integer of at least 1.');

            return null;
        }

        return (int) $depth;
    }

    /**
     * The --type filter, empty when absent, or null (after reporting) when
     * it names an unknown type.
     *
     * @return list<string>|null
     */
    private function displayTypes(): ?array
    {
        $option = $this->option('type');

        if (! is_string($option) || $option === '') {
            return [];
        }

        $types = array_values(array_filter(array_map('trim', explode(',', $option)), fn (string $type): bool => $type !== ''));
        $known = [...ArtifactId::supportedTypes(), ...self::CONCEPT_TYPES];
        $unknown = array_diff($types, $known);

        if ($unknown !== []) {
            $this->error('Unknown --type value(s): '.implode(', ', $unknown).'. Supported: '.implode(', ', $known).'.');

            return null;
        }

        return $types;
    }
}
