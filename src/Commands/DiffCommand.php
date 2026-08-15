<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Diff\DiffReviewAgent;
use LaravelNecromancer\Diff\DiffReviewResult;
use LaravelNecromancer\Diff\FlaggedArtifacts;
use LaravelNecromancer\Diff\ManifestDiff;
use LaravelNecromancer\Diff\ManifestDiffer;
use LaravelNecromancer\Inference\ManifestSummarizer;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class DiffCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:diff
        {branch=main : Git ref to compare against (branch, tag, SHA, or remote ref)}
        {--review : Include AI architectural review (requires laravel/ai)}
        {--format= : Output format: leave blank for terminal, or "markdown" for PR-ready output}
        {--base-manifest= : Path to base manifest file, bypasses git show}
        {--output= : Write output to a file instead of terminal}';

    protected $description = 'Compare the current manifest against another branch or ref';

    public function handle(AiDetector $aiDetector, ManifestReader $reader): int
    {
        $headPath = $this->resolveManifestPath();

        if (! file_exists($headPath)) {
            $this->error('Manifest not found. Run `php artisan necromancer:scan` first.');

            return self::FAILURE;
        }

        try {
            $head = $reader->read($headPath);
        } catch (\JsonException $e) {
            $this->error("Manifest is not valid JSON: {$e->getMessage()}");

            return self::FAILURE;
        } catch (ManifestNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $base = $this->loadBaseManifest($reader);

        if ($base === null) {
            return self::FAILURE;
        }

        try {
            $diff = (new ManifestDiffer)->diff(
                $base['artifacts'] ?? [],
                $head['artifacts'] ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            $this->error("Manifest contains an artifact with no canonical key: {$e->getMessage()}");

            return self::FAILURE;
        }

        $format = $this->option('format');
        $rendered = $format === 'markdown'
            ? $this->renderMarkdown($diff)
            : $this->renderText($diff);

        if ($this->option('review')) {
            $reviewSection = $this->runAiReview($diff, $head, $aiDetector, $format === 'markdown');

            if ($reviewSection !== null) {
                $rendered .= PHP_EOL.$reviewSection;
            }
        }

        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            $dir = dirname($outputPath);

            if (! is_dir($dir)) {
                $this->error("Directory does not exist: {$dir}");

                return self::FAILURE;
            }

            if (is_dir($outputPath)) {
                $this->error("Output path is a directory: {$outputPath}");

                return self::FAILURE;
            }

            $toWrite = $format !== 'markdown' ? $this->stripConsoleTags($rendered) : $rendered;

            if (@file_put_contents($outputPath, $toWrite.PHP_EOL) === false) {
                $this->error("Unable to write output to {$outputPath}.");

                return self::FAILURE;
            }

            $this->info("Written to {$outputPath}");

            return self::SUCCESS;
        }

        foreach (explode(PHP_EOL, $rendered) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadBaseManifest(ManifestReader $reader): ?array
    {
        $path = $this->option('base-manifest');

        if (is_string($path) && $path !== '') {
            if (! file_exists($path)) {
                $this->error("Base manifest file not found: {$path}");

                return null;
            }

            try {
                return $reader->read($path);
            } catch (\JsonException $e) {
                $this->error("Manifest is not valid JSON: {$e->getMessage()}");

                return null;
            } catch (ManifestNotFoundException $e) {
                $this->error($e->getMessage());

                return null;
            }
        }

        $branch = (string) $this->argument('branch');
        $manifestRelPath = $this->resolveManifestRelativePath();

        $result = Process::run(['git', 'show', "{$branch}:{$manifestRelPath}"]);

        if (! $result->successful() || trim($result->output()) === '') {
            if (str_contains($result->errorOutput(), 'not a git repository')
                || (! $result->successful() && trim($result->errorOutput()) === '' && trim($result->output()) === '')) {
                $this->error('This command requires git.');
            } else {
                $this->error("No necromancer.json found on '{$branch}'. Run `php artisan necromancer:scan` on that branch and commit the manifest.");
            }

            return null;
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error("Manifest is not valid JSON: {$e->getMessage()}");

            return null;
        }

        if (! $reader->isCurrentSchema($manifest)) {
            $this->error("The manifest on '{$branch}' predates schema v1 and is no longer supported. Run `php artisan necromancer:scan` on that branch and commit the manifest.");

            return null;
        }

        return $manifest;
    }

    private function resolveManifestRelativePath(): string
    {
        $configured = (string) config('necromancer.output.manifest', 'necromancer.json');

        if ($this->isAbsolutePath($configured)) {
            return 'necromancer.json';
        }

        return $configured !== '' ? $configured : 'necromancer.json';
    }

    private function renderText(ManifestDiff $diff): string
    {
        $branch = (string) $this->argument('branch');

        $lines = [];
        $lines[] = '  Laravel Necromancer — Branch Diff: '.$branch.' → HEAD';
        $lines[] = '  ──────────────────────────────────────────────────';
        $lines[] = sprintf(
            '  %d added · %d removed · %d changed',
            $diff->totalAdditions(),
            $diff->totalRemovals(),
            $diff->totalChanges(),
        );

        if ($diff->isEmpty()) {
            $lines[] = '';
            $lines[] = '  No architectural drift detected.';

            return implode(PHP_EOL, $lines);
        }

        $flagged = FlaggedArtifacts::fromDiff($diff);

        if (! empty($flagged)) {
            $lines[] = '';
            $lines[] = '  FLAGGED ARTIFACTS';

            foreach ($flagged as ['type' => $type, 'artifact' => $artifact]) {
                $id = (string) ($artifact['id'] ?? '');
                $lines[] = "  <fg=red>⚠</>  {$type}  {$this->labelArtifact($type, $artifact)} ({$id})  ".FlaggedArtifacts::reason($artifact);
            }
        }

        if (! empty($diff->added)) {
            $lines[] = '';
            $lines[] = '  ADDED';

            foreach ($diff->added as $type => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $label = $this->labelArtifact($type, $artifact);
                    $lines[] = "  <fg=green>✚</>  {$type}  {$label}";
                }
            }
        }

        if (! empty($diff->removed)) {
            $lines[] = '';
            $lines[] = '  REMOVED';

            foreach ($diff->removed as $type => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $label = $this->labelArtifact($type, $artifact);
                    $lines[] = "  <fg=red>✖</>  {$type}  {$label}";
                }
            }
        }

        if (! empty($diff->changed)) {
            $lines[] = '';
            $lines[] = '  CHANGED';

            foreach ($diff->changed as $type => $changes) {
                foreach ($changes as $change) {
                    $label = $this->labelArtifact($type, $change['to']);
                    $lines[] = "  <fg=yellow>↕</>  {$type}  {$label}";

                    $diffFields = $this->computeDiffingFields($change['from'], $change['to']);
                    $shown = array_slice($diffFields, 0, 5);
                    $remaining = count($diffFields) - count($shown);

                    foreach ($shown as [$key, $fromValue, $toValue]) {
                        $from = json_encode($fromValue, JSON_THROW_ON_ERROR);
                        $to = json_encode($toValue, JSON_THROW_ON_ERROR);
                        $lines[] = "               {$key}: {$from} → {$to}";
                    }

                    if ($remaining > 0) {
                        $lines[] = "               ... {$remaining} more";
                    }
                }
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function renderMarkdown(ManifestDiff $diff): string
    {
        $branch = (string) $this->argument('branch');

        $lines = [];
        $lines[] = "## Necromancer Branch Diff: {$branch} → HEAD";
        $lines[] = '';
        $lines[] = sprintf(
            '**%d added · %d removed · %d changed**',
            $diff->totalAdditions(),
            $diff->totalRemovals(),
            $diff->totalChanges(),
        );

        if ($diff->isEmpty()) {
            $lines[] = '';
            $lines[] = '_No architectural drift detected._';

            return implode(PHP_EOL, $lines);
        }

        $flagged = FlaggedArtifacts::fromDiff($diff);

        if (! empty($flagged)) {
            $lines[] = '';
            $lines[] = '### Flagged Artifacts';

            foreach ($flagged as ['type' => $type, 'artifact' => $artifact]) {
                $id = (string) ($artifact['id'] ?? '');
                $lines[] = "- `[{$type}]` `{$this->labelArtifact($type, $artifact)}` (`{$id}`) — ".FlaggedArtifacts::reason($artifact);
            }
        }

        if (! empty($diff->added)) {
            $lines[] = '';
            $lines[] = '### Added';

            foreach ($diff->added as $type => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $label = $this->labelArtifact($type, $artifact);
                    $lines[] = "- `[{$type}]` {$label}";
                }
            }
        }

        if (! empty($diff->removed)) {
            $lines[] = '';
            $lines[] = '### Removed';

            foreach ($diff->removed as $type => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $label = $this->labelArtifact($type, $artifact);
                    $lines[] = "- `[{$type}]` {$label}";
                }
            }
        }

        if (! empty($diff->changed)) {
            $lines[] = '';
            $lines[] = '### Changed';

            foreach ($diff->changed as $type => $changes) {
                foreach ($changes as $change) {
                    $label = $this->labelArtifact($type, $change['to']);
                    $lines[] = "- `[{$type}]` {$label}";

                    $diffFields = $this->computeDiffingFields($change['from'], $change['to']);
                    $shown = array_slice($diffFields, 0, 5);
                    $remaining = count($diffFields) - count($shown);

                    foreach ($shown as [$key, $fromValue, $toValue]) {
                        $from = json_encode($fromValue, JSON_THROW_ON_ERROR);
                        $to = json_encode($toValue, JSON_THROW_ON_ERROR);
                        $lines[] = "  - `{$key}`: `{$from}` → `{$to}`";
                    }

                    if ($remaining > 0) {
                        $lines[] = "  - ... {$remaining} more field(s)";
                    }
                }
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function runAiReview(ManifestDiff $diff, array $head, AiDetector $aiDetector, bool $markdown): ?string
    {
        if (! $aiDetector->isAvailable()) {
            $this->warn('laravel/ai is not installed — skipping AI review.');

            return null;
        }

        if ($diff->isEmpty()) {
            return null;
        }

        $manifestSummary = (new ManifestSummarizer)->summarize($head);
        $branch = (string) $this->argument('branch');
        $appName = (string) config('app.name');

        $agent = app(DiffReviewAgent::class);

        $result = $agent->review(
            diff: $diff,
            manifestSummary: $manifestSummary,
            baseBranch: $branch,
            appName: $appName,
        );

        return $markdown
            ? $this->renderMarkdownReview($result)
            : $this->renderTextReview($result);
    }

    private function renderTextReview(DiffReviewResult $result): string
    {
        $lines = [];
        $lines[] = '  ──────────────────────────────────────────────────';
        $lines[] = '  AI Review';
        $lines[] = '';
        $lines[] = '  '.$result->summary;

        if (! empty($result->evidence)) {
            $lines[] = '';
            $lines[] = '  Evidence:';

            foreach ($result->evidence as $item) {
                $lines[] = '  - '.$item;
            }
        }

        if (! empty($result->risks)) {
            $lines[] = '';
            $lines[] = '  Risks:';

            foreach ($result->risks as $item) {
                $lines[] = '  - '.$item;
            }
        }

        if (! empty($result->reviewerQuestions)) {
            $lines[] = '';
            $lines[] = '  Reviewer Questions:';

            foreach ($result->reviewerQuestions as $i => $question) {
                $lines[] = '  '.($i + 1).'. '.$question;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function renderMarkdownReview(DiffReviewResult $result): string
    {
        $lines = [];
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## AI Review';
        $lines[] = '';
        $lines[] = $result->summary;

        if (! empty($result->evidence)) {
            $lines[] = '';
            $lines[] = '### Evidence';

            foreach ($result->evidence as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (! empty($result->risks)) {
            $lines[] = '';
            $lines[] = '### Risks';

            foreach ($result->risks as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (! empty($result->reviewerQuestions)) {
            $lines[] = '';
            $lines[] = '### Reviewer Questions';

            foreach ($result->reviewerQuestions as $i => $question) {
                $lines[] = ($i + 1).'. '.$question;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function stripConsoleTags(string $text): string
    {
        return preg_replace('/<[^>]+>/', '', $text) ?? $text;
    }

    private function labelArtifact(string $type, array $artifact): string
    {
        if ($type === 'routes') {
            $method = $artifact['method'] ?? '';
            $uri = $artifact['uri'] ?? '';
            $name = $artifact['name'] ?? null;

            return $name
                ? "{$method} {$uri} ({$name})"
                : "{$method} {$uri}";
        }

        if ($type === 'tests') {
            return basename((string) ($artifact['file'] ?? ''));
        }

        if ($type === 'gates') {
            return (string) ($artifact['ability'] ?? '');
        }

        if ($type === 'scheduled_tasks') {
            return (string) ($artifact['command'] ?? '');
        }

        $class = (string) ($artifact['class'] ?? $artifact['signature'] ?? '');

        return basename(str_replace('\\', '/', $class));
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{string, mixed, mixed}>
     */
    private function computeDiffingFields(array $from, array $to): array
    {
        $fields = [];
        $allKeys = array_unique(array_merge(array_keys($from), array_keys($to)));

        foreach ($allKeys as $key) {
            $fromValue = $from[$key] ?? null;
            $toValue = $to[$key] ?? null;

            if (json_encode($fromValue, JSON_THROW_ON_ERROR) !== json_encode($toValue, JSON_THROW_ON_ERROR)) {
                $fields[] = [$key, $fromValue, $toValue];
            }
        }

        return $fields;
    }
}
