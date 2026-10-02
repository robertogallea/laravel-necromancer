<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Commands\Concerns\ResolvesImpactStart;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ManifestScopeGuard;
use LaravelNecromancer\Relationships\Impact;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\ImpactDirection;
use LaravelNecromancer\Relationships\ImpactNode;

final class ImpactCommand extends Command
{
    use ReadsManifest;
    use ResolvesImpactStart;

    protected $signature = 'necromancer:impact
        {artifact        : An exact Artifact ID, a fully-qualified class name, or a domain:/flow:/adr: ID}
        {--depth=1       : How many Relationships away to walk (at least 1)}
        {--type=         : Only display these node types (comma-separated artifact types, domain, flow, adr)}
        {--json          : Output the Impact as JSON}
        {--allow-stale   : Analyze even if the manifest appears stale}
        {--allow-partial : Analyze even if the manifest scope is partial}';

    protected $description = 'Show the artifacts, Domains, Flows, and ADRs connected to an artifact through its Relationships';

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer): int
    {
        $depth = $this->impactDepth();
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

        $start = $this->resolveImpactStart($analyzer, $manifest, (string) $this->argument('artifact'));

        if ($start === null) {
            return self::FAILURE;
        }

        $impact = $analyzer->analyze($manifest, $start, $depth);
        $nodes = $impact->nodesOfTypes($types);

        if ($this->option('json')) {
            $this->line(json_encode(
                ['start' => $impact->start, 'depth' => $impact->depth, 'nodes' => $nodes],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->line("Impact of {$impact->label($impact->start)} ({$impact->start}), depth {$impact->depth}");

        if ($nodes === []) {
            $this->line('');
            $this->line($impact->nodes === []
                ? 'No relationships found.'
                : count($impact->nodes).' node(s) reachable, none of type '.implode(', ', $types).'.');

            return self::SUCCESS;
        }

        $this->renderNodes($impact, $nodes);

        return self::SUCCESS;
    }

    /**
     * @param  list<ImpactNode>  $nodes  the Impact's nodes after the --type filter
     */
    private function renderNodes(Impact $impact, array $nodes): void
    {
        $groupOrder = array_flip([...ImpactAnalyzer::nodeTypes(), 'unresolved']);
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
                    $line = '    '.$impact->label($node->id).($node->resolved() ? '' : ' (unresolved)').'  ';
                    $line .= $node->direction === ImpactDirection::Out ? "{$relationship} →" : "← {$relationship}";

                    if ($distance > 1) {
                        $line .= '  via '.$impact->label($node->viaFrom);
                    }

                    $this->line($line);
                }
            }
        }
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
        $known = ImpactAnalyzer::nodeTypes();
        $unknown = array_diff($types, $known);

        if ($unknown !== []) {
            $this->error('Unknown --type value(s): '.implode(', ', $unknown).'. Supported: '.implode(', ', $known).'.');

            return null;
        }

        return $types;
    }
}
