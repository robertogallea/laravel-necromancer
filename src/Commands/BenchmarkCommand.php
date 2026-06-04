<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Benchmark\BenchmarkReport;
use LaravelNecromancer\Benchmark\BenchmarkRunner;
use LaravelNecromancer\Benchmark\FactChecker;
use LaravelNecromancer\Benchmark\GoldenAnswerResolver;
use LaravelNecromancer\Benchmark\JudgeClient;
use LaravelNecromancer\Benchmark\Renderers\JsonRenderer;
use LaravelNecromancer\Benchmark\Renderers\MarkdownRenderer;
use LaravelNecromancer\Benchmark\Renderers\TerminalRenderer;
use LaravelNecromancer\Benchmark\TaskSuite;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class BenchmarkCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:benchmark
        {--condition=* : Conditions to run: none,manual,necromancer. Default: all three.}
        {--type=*      : Task types to run: qa,codegen,mini. Default: all.}
        {--no-judge    : Skip the AI-as-judge pass (automated checks only)}
        {--model=      : Generation model override}
        {--judge=      : Judge model override}
        {--format=     : Output format: text|markdown|json (default: text)}
        {--output=     : Write the report to this file path}';

    protected $description = 'Benchmark the impact of Necromancer context on AI coding-assistant effectiveness';

    public function handle(ManifestReader $reader, AiDetector $aiDetector): int
    {
        $manifestPath = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($manifestPath);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        if (! $aiDetector->isAvailable()) {
            $this->error('laravel/ai is not installed. Run: composer require laravel/ai');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        $conditions = $this->resolveConditions();
        $types = $this->resolveTypes();
        $noJudge = (bool) $this->option('no-judge');
        $generationModel = $this->option('model') ?: config('necromancer.benchmark.generation_model');
        $judgeModel = $this->option('judge') ?: config('necromancer.benchmark.judge_model');
        $taskOverride = config('necromancer.benchmark.tasks') ?: null;

        $contextPaths = [
            'none' => '',
            'manual' => (string) config('necromancer.benchmark.manual_context_path', base_path('CLAUDE.md')),
            'necromancer' => (string) config('necromancer.output.context', base_path('NECROMANCER.md')),
        ];

        $taskCount = count((new TaskSuite($taskOverride ?: null))->tasks($types));
        $conditionCount = count($conditions);

        $this->line('');
        $this->line('  Laravel Necromancer — Benchmark');
        $this->line('  ────────────────────────────────');
        $this->line("  Tasks: {$taskCount}  ·  Conditions: {$conditionCount}  ·  Model: {$generationModel}".($noJudge ? '  ·  no judge' : "  ·  Judge: {$judgeModel}"));
        $this->line('');

        $runner = new BenchmarkRunner(
            taskSuite: new TaskSuite($taskOverride ?: null),
            resolver: new GoldenAnswerResolver($manifest),
            factChecker: new FactChecker,
            judgeClient: new JudgeClient,
        );

        $report = $runner->run([
            'conditions' => $conditions,
            'types' => $types,
            'noJudge' => $noJudge,
            'generationModel' => is_string($generationModel) && $generationModel !== '' ? $generationModel : null,
            'generationProvider' => config('necromancer.benchmark.generation_provider') ?: null,
            'judgeModel' => ! $noJudge && is_string($judgeModel) && $judgeModel !== '' ? $judgeModel : null,
            'judgeProvider' => ! $noJudge ? (config('necromancer.benchmark.judge_provider') ?: null) : null,
            'contextPaths' => $contextPaths,
        ]);

        foreach ($runner->warnings() as $warning) {
            $this->warn($warning);
        }

        $output = $this->renderReport($report);

        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $output);
            $this->info("Report written to {$outputPath}");
        } else {
            $this->line($output);
        }

        return self::SUCCESS;
    }

    /** @return string[] */
    private function resolveConditions(): array
    {
        $option = $this->option('condition');
        $conditions = is_array($option) && ! empty($option) ? $option : ['none', 'manual', 'necromancer'];

        return array_values(array_filter($conditions, fn (string $c): bool => in_array($c, ['none', 'manual', 'necromancer'], true)));
    }

    /** @return string[]|null */
    private function resolveTypes(): ?array
    {
        $option = $this->option('type');

        if (! is_array($option) || empty($option)) {
            return null;
        }

        return array_values(array_filter($option, fn (string $t): bool => in_array($t, ['qa', 'codegen', 'mini'], true)));
    }

    private function renderReport(BenchmarkReport $report): string
    {
        return match ($this->option('format')) {
            'markdown' => (new MarkdownRenderer)->render($report),
            'json' => (new JsonRenderer)->render($report),
            default => (new TerminalRenderer)->render($report),
        };
    }
}
