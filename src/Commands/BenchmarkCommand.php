<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Benchmark\BenchmarkDumpWriter;
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
use LaravelNecromancer\Integrations\BoostDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class BenchmarkCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:benchmark
        {--condition=* : Conditions to run: none,manual,necromancer. Default: all three.}
        {--type=*      : Task types to run: qa,codegen,mini. Default: all.}
        {--no-judge    : Skip the AI-as-judge pass (automated checks only)}
        {--no-dump     : Skip writing the per-run benchmark dump}
        {--model=      : Generation model override}
        {--judge=      : Judge model override}
        {--timeout=    : HTTP timeout in seconds for AI requests (default: 120)}
        {--format=     : Output format: text|markdown|json (default: text)}
        {--output=     : Write the report to this file path}';

    protected $description = 'Benchmark the impact of Necromancer context on AI coding-assistant effectiveness';

    public function handle(ManifestReader $reader, AiDetector $aiDetector, BoostDetector $boostDetector): int
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
        $timeoutOption = $this->option('timeout');
        $timeout = is_numeric($timeoutOption) ? (int) $timeoutOption : (int) config('necromancer.benchmark.timeout', 120);
        $taskOverride = config('necromancer.benchmark.tasks') ?: null;
        $dumpEnabled = $this->dumpEnabled();
        $dumpPath = $this->resolveDumpPath();
        $startedAt = now()->toISOString();

        $contextPaths = [
            'none' => '',
            'manual' => (string) config('necromancer.benchmark.manual_context_path', base_path('AGENTS.md')),
            'necromancer' => $this->resolveNecromancerContextPath($boostDetector),
        ];

        $allTasks = (new TaskSuite($taskOverride ?: null))->tasks($types);
        $taskCount = count($allTasks);

        $this->printConfiguration(
            manifestPath: $manifestPath,
            conditions: $conditions,
            contextPaths: $contextPaths,
            types: $types,
            allTasks: $allTasks,
            generationModel: is_string($generationModel) ? $generationModel : '',
            generationProvider: (string) (config('necromancer.benchmark.generation_provider') ?: 'default'),
            noJudge: $noJudge,
            judgeModel: is_string($judgeModel) ? $judgeModel : '',
            judgeProvider: (string) (config('necromancer.benchmark.judge_provider') ?: 'default'),
            timeout: $timeout,
            outputPath: is_string($this->option('output')) && $this->option('output') !== '' ? $this->option('output') : null,
            format: is_string($this->option('format')) && $this->option('format') !== '' ? $this->option('format') : 'text',
            dumpEnabled: $dumpEnabled,
            dumpPath: $dumpPath,
            boostActive: $boostDetector->isAvailable(),
        );

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
            'timeout' => $timeout,
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

        if ($dumpEnabled) {
            try {
                $dumpDirectory = (new BenchmarkDumpWriter)->write($report, [
                    'started_at' => $startedAt,
                    'manifest_path' => $manifestPath,
                    'conditions' => $conditions,
                    'types' => $types,
                    'generation_model' => is_string($generationModel) && $generationModel !== '' ? $generationModel : null,
                    'generation_provider' => config('necromancer.benchmark.generation_provider') ?: null,
                    'judge_enabled' => ! $noJudge,
                    'judge_model' => ! $noJudge && is_string($judgeModel) && $judgeModel !== '' ? $judgeModel : null,
                    'judge_provider' => ! $noJudge ? (config('necromancer.benchmark.judge_provider') ?: null) : null,
                    'timeout' => $timeout,
                    'context_paths' => $contextPaths,
                    'warnings' => $runner->warnings(),
                ], $dumpPath);
            } catch (\Throwable $e) {
                $this->error('Unable to write benchmark dump: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->info('Dump written to '.$this->relativePath($dumpDirectory));
        }

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $conditions
     * @param  array<string, string>  $contextPaths
     * @param  string[]|null  $types
     * @param  array<int, array<string, mixed>>  $allTasks
     */
    private function printConfiguration(
        string $manifestPath,
        array $conditions,
        array $contextPaths,
        ?array $types,
        array $allTasks,
        string $generationModel,
        string $generationProvider,
        bool $noJudge,
        string $judgeModel,
        string $judgeProvider,
        int $timeout,
        ?string $outputPath,
        string $format,
        bool $dumpEnabled,
        string $dumpPath,
        bool $boostActive,
    ): void {
        $sep = '  '.str_repeat('─', 58);
        $w = 16;

        $this->line('');
        $this->line('  Laravel Necromancer — Benchmark');
        $this->line($sep);
        $this->line('');

        // Manifest
        $rel = $this->relativePath($manifestPath);
        $this->line(sprintf('  %-*s %s', $w, 'Manifest', $rel));

        // Boost
        $this->line(sprintf('  %-*s %s', $w, 'Boost', $boostActive ? 'active' : 'inactive'));

        $this->line('');

        // Conditions + context files
        $this->line(sprintf('  %-*s', $w, 'Conditions'));

        $conditionLabels = ['none' => 'No context', 'manual' => 'Manual', 'necromancer' => 'Necromancer'];

        foreach ($conditions as $i => $condition) {
            $prefix = $i < count($conditions) - 1 ? '├─' : '└─';
            $label = $conditionLabels[$condition] ?? $condition;
            $file = $contextPaths[$condition] ?? '';

            if ($condition === 'none' || $file === '') {
                $fileInfo = 'no context injected';
            } else {
                $rel = $this->relativePath($file);
                $exists = file_exists($file);
                $fileInfo = $rel.($exists ? '  ✓' : '  ✗ (file not found — condition will use empty context)');
            }

            $this->line("  {$prefix} {$label}: {$fileInfo}");
        }

        $this->line('');

        // Tasks
        $counts = array_count_values(array_column($allTasks, 'type'));
        ksort($counts);
        $breakdown = implode(' · ', array_map(fn ($t, $n) => "{$t}: {$n}", array_keys($counts), $counts));
        $taskSummary = count($allTasks).($breakdown ? "  ({$breakdown})" : '');
        $this->line(sprintf('  %-*s %s', $w, 'Tasks', $taskSummary));

        // Type filter
        $typeLabel = $types === null ? 'all' : implode(', ', $types);
        $this->line(sprintf('  %-*s %s', $w, 'Types', $typeLabel));

        $this->line('');

        // Generation
        $genInfo = ($generationModel !== '' ? $generationModel : '(config default)')."  (provider: {$generationProvider})";
        $this->line(sprintf('  %-*s %s', $w, 'Generation', $genInfo));

        // Judge
        if ($noJudge) {
            $this->line(sprintf('  %-*s %s', $w, 'Judge', 'disabled  (--no-judge)'));
        } else {
            $judgeInfo = ($judgeModel !== '' ? $judgeModel : '(config default)')."  (provider: {$judgeProvider})";
            $this->line(sprintf('  %-*s %s', $w, 'Judge', $judgeInfo));
        }

        // Timeout
        $this->line(sprintf('  %-*s %ds', $w, 'Timeout', $timeout));

        $this->line('');

        // Report + dump destinations
        $reportInfo = $outputPath !== null
            ? $this->relativePath($outputPath)."  (format: {$format})"
            : "terminal  (format: {$format})";
        $dumpInfo = $dumpEnabled
            ? $this->relativePath($dumpPath).'  (automatic per-run dump)'
            : 'disabled';

        $this->line(sprintf('  %-*s %s', $w, 'Report', $reportInfo));
        $this->line(sprintf('  %-*s %s', $w, 'Dump', $dumpInfo));

        $this->line('');
        $this->line($sep);
        $this->line('');
    }

    private function relativePath(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function resolveNecromancerContextPath(BoostDetector $boostDetector): string
    {
        if ($boostDetector->isAvailable()) {
            $path = (string) config('necromancer.boost.skill_path', base_path('.ai/skills/necromancer.md'));
        } else {
            $path = (string) config('necromancer.output.context', base_path('NECROMANCER.md'));
        }

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }

    private function dumpEnabled(): bool
    {
        $configured = filter_var(config('necromancer.benchmark.dump.enabled', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return ($configured ?? true) && ! (bool) $this->option('no-dump');
    }

    private function resolveDumpPath(): string
    {
        $path = (string) config('necromancer.benchmark.dump.path', storage_path('app/necromancer/benchmarks'));

        return $this->isAbsolutePath($path) ? $path : base_path($path);
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
