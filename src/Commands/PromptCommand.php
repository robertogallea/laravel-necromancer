<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Prompt\PromptRelevanceScorer;
use LaravelNecromancer\Prompt\QuestionContextualizer;

final class PromptCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:prompt
        {question?         : The question to ask about the codebase}
        {--top=10          : Maximum number of artifact citations to include}
        {--no-ai           : Skip AI question contextualization even if laravel/ai is installed}
        {--output=         : Write output to a file instead of stdout}';

    protected $description = 'Generate a source-grounded prompt for a question about your codebase';

    public function handle(
        ManifestReader $reader,
        PromptRelevanceScorer $scorer,
        QuestionContextualizer $contextualizer,
        AiDetector $aiDetector,
    ): int {
        $manifestPath = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($manifestPath);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        $question = $this->argument('question') ?? $this->ask('Question');

        if (! filled($question)) {
            $this->error('No question provided.');

            return self::FAILURE;
        }

        $top = max(1, (int) $this->option('top'));
        $artifacts = (array) ($manifest['artifacts'] ?? []);
        $results = $scorer->score($artifacts, $question, $top);

        if (empty($results)) {
            $this->warn('No relevant artifacts found for this query. The manifest may need refreshing or the query keywords do not match any tracked artifacts.');

            return self::SUCCESS;
        }

        if ($aiDetector->isAvailable() && ! $this->option('no-ai')) {
            $question = $contextualizer->contextualize($manifest, $question);
        }

        $output = $this->buildPrompt($manifest, $results, $question);

        if ($output === null) {
            $this->warn('Matched artifacts have no source locations. Re-run necromancer:scan to refresh.');

            return self::SUCCESS;
        }

        $path = $this->option('output');

        if (is_string($path) && $path !== '') {
            File::put($path, $output);
            $this->info("Prompt written to {$path}.");
        } else {
            $this->output->writeln($output);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{type: string, artifact: array<string, mixed>, score: int}>  $results
     */
    private function buildPrompt(array $manifest, array $results, string $question): ?string
    {
        $appName = (string) ($manifest['meta']['app_name'] ?? 'this application');
        $laravelVersion = (string) ($manifest['meta']['laravel_version'] ?? 'unknown');

        $citations = [];

        foreach ($results as $result) {
            $source = is_array($result['artifact']['source'] ?? null) ? $result['artifact']['source'] : null;

            if ($source === null) {
                continue;
            }

            $file = isset($source['file']) ? (string) $source['file'] : null;

            if ($file === null || $file === '') {
                continue;
            }

            if (isset($source['line'], $source['line_end'])) {
                $citations[] = "- {$file}:{$source['line']}-{$source['line_end']}";
            } elseif (isset($source['line'])) {
                $citations[] = "- {$file}:{$source['line']}";
            }
        }

        if (empty($citations)) {
            return null;
        }

        $citationBlock = implode("\n", $citations);

        return "You are analyzing {$appName}, a Laravel {$laravelVersion} application.\n"
            ."\n"
            ."Use the following source-grounded manifest entries:\n"
            .$citationBlock."\n"
            ."\n"
            ."Question:\n"
            .$question."\n"
            ."\n"
            ."Rules:\n"
            ."- Only answer from cited sources.\n"
            ."- Mention missing evidence.\n"
            .'- Do not assume runtime behavior not shown in code/tests.';
    }
}
