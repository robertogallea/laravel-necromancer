<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Inference\ManifestSummarizer;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class InspectPayloadCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:inspect-payload
        {--privacy : Show the condensed summary payload instead of full JSON}';

    protected $description = 'Show what payload would be sent to the AI provider by necromancer:ask';

    public function handle(ManifestReader $reader): int
    {
        $manifestPath = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($manifestPath);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $privacy = (bool) $this->option('privacy');

        if ($privacy) {
            $payload = (new ManifestSummarizer)->summarize($manifest);
            $mode = 'Condensed summary (--privacy)';
        } else {
            $payload = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $mode = 'Full manifest JSON';
        }

        $bytes = strlen($payload);
        $kb = round($bytes / 1024, 1);
        $tokens = intval($bytes / 4);

        $artifactCounts = [];
        foreach ($manifest['artifacts'] ?? [] as $type => $items) {
            if (is_array($items) && count($items) > 0) {
                $artifactCounts[] = "{$type} (".count($items).')';
            }
        }

        $this->line('');
        $this->line('  <info>Necromancer — AI Payload Inspector</info>');
        $this->line('  '.str_repeat('─', 38));
        $this->line("  Mode: <comment>{$mode}</comment>");
        $this->line('');
        $this->line('  ─── Payload '.str_repeat('─', 26));
        $this->line($payload);
        $this->line('');
        $this->line('  ─── Metadata '.str_repeat('─', 25));
        $this->line("  Size:              {$kb} KB ({$bytes} bytes)");
        $this->line("  Estimated tokens:  ~{$tokens}");
        if ($artifactCounts !== []) {
            $this->line('  Artifact types:    '.implode(' · ', $artifactCounts));
        }
        $this->line('');

        return self::SUCCESS;
    }
}
