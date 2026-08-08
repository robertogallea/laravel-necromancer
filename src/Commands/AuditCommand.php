<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Checks\BroadcastableEventsWithNoChannelCheck;
use LaravelNecromancer\Audit\Checks\ClosureRoutesCheck;
use LaravelNecromancer\Audit\Checks\EmptyCommandDescriptionsCheck;
use LaravelNecromancer\Audit\Checks\EventsWithNoListenersCheck;
use LaravelNecromancer\Audit\Checks\ExternalServiceArtifactsWithoutTestsCheck;
use LaravelNecromancer\Audit\Checks\HighRiskArtifactsWithoutAdrCheck;
use LaravelNecromancer\Audit\Checks\IdentifierStyleCheck;
use LaravelNecromancer\Audit\Checks\InconsistentFlowMetadataCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoQueueNameCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoTimeoutCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoTriesCheck;
use LaravelNecromancer\Audit\Checks\MissingCastsCheck;
use LaravelNecromancer\Audit\Checks\MissingFillableCheck;
use LaravelNecromancer\Audit\Checks\MissingLocalAdrFileCheck;
use LaravelNecromancer\Audit\Checks\ModelsWithOpenGuardCheck;
use LaravelNecromancer\Audit\Checks\NarrativeAnnotationSummaryCheck;
use LaravelNecromancer\Audit\Checks\NonGetRoutesWithoutAuthCheck;
use LaravelNecromancer\Audit\Checks\UnnamedRoutesCheck;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class AuditCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:audit
        {--format=text : Output format: text, json, or markdown}
        {--output=     : Write the report to this file path}
        {--fail-on=    : Exit non-zero if findings at this severity or higher exist (error, warning, suggestion)}';

    protected $description = 'Audit the application for AI-readability issues';

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

        $checks = [
            new UnnamedRoutesCheck,
            new ClosureRoutesCheck,
            new NonGetRoutesWithoutAuthCheck,
            new MissingFillableCheck,
            new MissingCastsCheck,
            new ModelsWithOpenGuardCheck,
            new EmptyCommandDescriptionsCheck,
            new EventsWithNoListenersCheck,
            new BroadcastableEventsWithNoChannelCheck,
            new JobsWithNoQueueNameCheck,
            new JobsWithNoTimeoutCheck,
            new JobsWithNoTriesCheck,
            new HighRiskArtifactsWithoutAdrCheck,
            new ExternalServiceArtifactsWithoutTestsCheck,
            new NarrativeAnnotationSummaryCheck,
            new InconsistentFlowMetadataCheck,
            new IdentifierStyleCheck,
            new MissingLocalAdrFileCheck(base_path()),
        ];

        $artifacts = $manifest['artifacts'] ?? [];
        $checkResults = array_map(fn ($check) => $check->run($artifacts), $checks);

        $findings = array_merge(...array_map(fn (CheckResult $r) => $r->findings, $checkResults));
        $score = $this->calculateScore($checkResults);

        $format = (string) $this->option('format');
        $outputPath = $this->option('output');

        $report = match ($format) {
            'json' => $this->renderJson($findings, $score),
            'markdown' => $this->renderMarkdown($findings, $score),
            default => $this->renderText($findings, $score),
        };

        if (is_string($outputPath) && $outputPath !== '') {
            return $this->writeReportToFile($outputPath, $report);
        }

        foreach (explode(PHP_EOL, $report) as $reportLine) {
            $this->line($reportLine);
        }

        $failOn = $this->option('fail-on');

        if (is_string($failOn) && $failOn !== '') {
            $severityOrder = ['error' => 3, 'warning' => 2, 'suggestion' => 1];
            $threshold = $severityOrder[$failOn] ?? 0;

            foreach ($findings as $finding) {
                if (($severityOrder[$finding->severity] ?? 0) >= $threshold) {
                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Finding[]  $findings
     */
    private function renderText(array $findings, int $score): string
    {
        $errors = array_values(array_filter($findings, fn (Finding $f) => $f->severity === 'error'));
        $warnings = array_values(array_filter($findings, fn (Finding $f) => $f->severity === 'warning'));
        $suggestions = array_values(array_filter($findings, fn (Finding $f) => $f->severity === 'suggestion'));

        $lines = [];
        $lines[] = '  Laravel Necromancer — AI Readability Audit';
        $lines[] = '  ──────────────────────────────────────────';
        $lines[] = '  Score: '.$score.'/100';
        $lines[] = '';
        $lines[] = sprintf(
            '  %d errors · %d warnings · %d suggestions',
            count($errors),
            count($warnings),
            count($suggestions),
        );
        $lines[] = '';

        if (empty($findings)) {
            $lines[] = '  All checks passed.';

            return implode(PHP_EOL, $lines);
        }

        foreach (['error' => 'ERRORS', 'warning' => 'WARNINGS', 'suggestion' => 'SUGGESTIONS'] as $severity => $label) {
            $group = array_values(array_filter($findings, fn (Finding $f) => $f->severity === $severity));

            if (empty($group)) {
                continue;
            }

            $lines[] = '  '.$label;

            foreach ($group as $finding) {
                $lines[] = '  '.$finding->message;

                if ($finding->source !== null) {
                    $lines[] = '     '.$finding->source;
                }
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  Finding[]  $findings
     */
    private function renderJson(array $findings, int $score): string
    {
        $errors = array_filter($findings, fn (Finding $f) => $f->severity === 'error');
        $warnings = array_filter($findings, fn (Finding $f) => $f->severity === 'warning');
        $suggestions = array_filter($findings, fn (Finding $f) => $f->severity === 'suggestion');

        return json_encode([
            'score' => $score,
            'counts' => [
                'errors' => count($errors),
                'warnings' => count($warnings),
                'suggestions' => count($suggestions),
            ],
            'findings' => array_map(fn (Finding $f) => [
                'severity' => $f->severity,
                'message' => $f->message,
                'artifactType' => $f->artifactType,
                'context' => $f->context,
                'source' => $f->source,
            ], $findings),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  Finding[]  $findings
     */
    private function renderMarkdown(array $findings, int $score): string
    {
        $errors = array_values(array_filter($findings, fn (Finding $f) => $f->severity === 'error'));
        $warnings = array_values(array_filter($findings, fn (Finding $f) => $f->severity === 'warning'));
        $suggestions = array_values(array_filter($findings, fn (Finding $f) => $f->severity === 'suggestion'));

        $lines = [];
        $lines[] = '## Laravel Necromancer — AI Readability Audit';
        $lines[] = '';
        $lines[] = sprintf(
            '**Score: %d/100** · %d errors · %d warnings · %d suggestions',
            $score,
            count($errors),
            count($warnings),
            count($suggestions),
        );

        if (empty($findings)) {
            $lines[] = '';
            $lines[] = '_All checks passed._';

            return implode(PHP_EOL, $lines);
        }

        foreach (['error' => 'Errors', 'warning' => 'Warnings', 'suggestion' => 'Suggestions'] as $severity => $label) {
            $group = array_values(array_filter($findings, fn (Finding $f) => $f->severity === $severity));

            if (empty($group)) {
                continue;
            }

            $lines[] = '';
            $lines[] = "### {$label}";
            $lines[] = '';

            foreach ($group as $finding) {
                $source = $finding->source !== null ? " — `{$finding->source}`" : '';
                $lines[] = "- {$finding->message}{$source}";
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function writeReportToFile(string $path, string $content): int
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory) || is_dir($path)) {
            $this->error("Unable to write audit report to {$path}.");

            return self::FAILURE;
        }

        if (@file_put_contents($path, $content.PHP_EOL) === false) {
            $this->error("Unable to write audit report to {$path}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  CheckResult[]  $checkResults
     */
    private function calculateScore(array $checkResults): int
    {
        $severityWeights = ['error' => 3, 'warning' => 2, 'suggestion' => 1];
        $totalWeight = 0;
        $passedWeight = 0;

        foreach ($checkResults as $result) {
            if ($result->total === 0) {
                continue;
            }

            $weight = $severityWeights[$result->severity] ?? 1;
            $passed = $result->total - count($result->findings);
            $totalWeight += $result->total * $weight;
            $passedWeight += $passed * $weight;
        }

        return $totalWeight > 0 ? (int) round(($passedWeight / $totalWeight) * 100) : 100;
    }
}
