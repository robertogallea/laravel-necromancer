<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Doctor\DimensionResult;
use LaravelNecromancer\Doctor\DoctorAnalyzer;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class DoctorCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:doctor
        {--json        : Output results as JSON}
        {--min-score=  : Exit non-zero when the overall score is below this threshold (for CI use)}
        {--only=       : Comma-separated dimension keys to include (e.g. route-clarity,model-expressiveness)}';

    protected $description = 'Show the AI readability score for the application';

    public function handle(ManifestReader $reader): int
    {
        $path = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($path);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        $analyzer = new DoctorAnalyzer($manifest['artifacts'] ?? []);
        $dimensions = $this->filterDimensions($analyzer->dimensions());
        $overall = $analyzer->overallScore($dimensions);

        if ($this->option('json')) {
            $this->line($this->renderJson($dimensions, $overall));
        } else {
            foreach (explode(PHP_EOL, $this->renderText($dimensions, $overall)) as $line) {
                $this->line($line);
            }
        }

        $minScore = $this->option('min-score');

        if (is_string($minScore) && $minScore !== '' && $overall < (int) $minScore) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  DimensionResult[]  $dimensions
     * @return DimensionResult[]
     */
    private function filterDimensions(array $dimensions): array
    {
        $only = $this->option('only');

        if (! is_string($only) || $only === '') {
            return $dimensions;
        }

        $keys = array_map('trim', explode(',', $only));

        return array_values(array_filter($dimensions, fn (DimensionResult $d): bool => in_array($d->key, $keys, true)));
    }

    /**
     * @param  DimensionResult[]  $dimensions
     */
    private function renderText(array $dimensions, int $overall): string
    {
        $lines = [];
        $lines[] = '  Laravel Necromancer — AI Readability Score';
        $lines[] = '  ──────────────────────────────────────────';
        $lines[] = '  Score: '.$overall.'%';
        $lines[] = '';

        foreach ($dimensions as $d) {
            $pct = $d->percentage();
            $bar = $this->progressBar($pct);
            $label = str_pad($d->label, 24);
            $lines[] = "  {$label}  {$bar}  {$pct}%  ({$d->detail})";
        }

        $lines[] = '';
        $lines[] = '  Tip: run necromancer:audit for a detailed findings list.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  DimensionResult[]  $dimensions
     */
    private function renderJson(array $dimensions, int $overall): string
    {
        return json_encode([
            'score' => $overall,
            'dimensions' => array_map(fn (DimensionResult $d): array => [
                'key' => $d->key,
                'label' => $d->label,
                'score' => $d->percentage(),
                'detail' => $d->detail,
                'weight' => $d->weight,
            ], $dimensions),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function progressBar(int $percentage): string
    {
        $filled = (int) round($percentage / 10);
        $empty = 10 - $filled;

        return str_repeat('█', $filled).str_repeat('░', $empty);
    }
}
