<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Inference\CodebaseAnswerAgent;
use LaravelNecromancer\Inference\ManifestSummarizer;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Prompt\PromptRelevanceScorer;

final class AskCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:ask
        {question? : The question to ask about the codebase}
        {--provider= : AI provider override}
        {--model=    : Model override}
        {--privacy   : Send a condensed manifest summary to the AI provider instead of full JSON}';

    protected $description = 'Ask a natural-language question about your codebase using the Necromancer manifest and laravel/ai';

    public function handle(ManifestReader $reader, AiDetector $aiDetector, PromptRelevanceScorer $scorer): int
    {
        $manifestPath = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($manifestPath);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        if (! $aiDetector->isAvailable()) {
            $this->error('laravel/ai is not installed.');
            $this->line('');
            $this->line('Run: composer require laravel/ai');
            $this->line('Then configure a provider in config/ai.php before running necromancer:ask.');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        $privacy = (bool) $this->option('privacy');

        if ($privacy) {
            $this->line('<comment>Using condensed manifest payload (--privacy). Answers may be less detailed.</comment>');
        }

        $question = $this->argument('question') ?? $this->ask('Question');

        if (! filled($question)) {
            $this->error('No question provided.');

            return self::FAILURE;
        }

        $instructions = $this->buildInstructions($manifest, $privacy, $scorer, $question);
        $provider = $this->option('provider') ?: null;
        $model = $this->option('model') ?: null;

        $agent = new CodebaseAnswerAgent(
            instructions: $instructions,
            messages: [],
            tools: [],
        );

        foreach ($agent->stream($question, provider: $provider, model: $model) as $event) {
            if ($event instanceof TextDelta) {
                $this->output->write($event->delta);
            } elseif ($event instanceof TextEnd) {
                break;
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $manifest */
    private function buildInstructions(array $manifest, bool $privacy, PromptRelevanceScorer $scorer, string $question): string
    {
        if ($privacy) {
            $context = (new ManifestSummarizer)->summarize($manifest);
            $label = 'Condensed Manifest Summary';
        } else {
            $context = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $label = 'Full Manifest (JSON)';
        }

        $relevantEvidence = $this->buildRelevantEvidenceBlock($manifest, $scorer, $question);

        return <<<PROMPT
        You are a Laravel application expert. Use the following manifest — a machine-readable inventory of this app's routes, models, jobs, events, commands, and policies — to answer questions accurately and concisely. If you cannot determine something from the manifest, say so clearly.
        {$relevantEvidence}
        {$label}:
        {$context}
        PROMPT;
    }

    /**
     * Ranks manifest artifacts against the question (keyword matches, boosted for
     * declared route metadata domain/flow/capability) and surfaces the top matches
     * ahead of the full manifest — prioritizing the AI's attention without discarding
     * anything, so broad questions with no strong keyword match are unaffected.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function buildRelevantEvidenceBlock(array $manifest, PromptRelevanceScorer $scorer, string $question): string
    {
        $artifacts = (array) ($manifest['artifacts'] ?? []);
        $ranked = $scorer->score($artifacts, $question, 10);

        if (empty($ranked)) {
            return '';
        }

        $lines = array_map(
            fn (array $result): string => '- ['.$result['type'].'] '.$this->labelArtifact($result['type'], $result['artifact']),
            $ranked,
        );

        return "\nMost Relevant Evidence For This Question (ranked, highest first):\n".implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $artifact */
    private function labelArtifact(string $type, array $artifact): string
    {
        if ($type === 'routes') {
            $method = $artifact['method'] ?? '';
            $uri = $artifact['uri'] ?? '';
            $name = $artifact['name'] ?? null;

            return $name ? "{$method} {$uri} ({$name})" : "{$method} {$uri}";
        }

        if ($type === 'tests') {
            return basename((string) ($artifact['file'] ?? ''));
        }

        $class = (string) ($artifact['class'] ?? $artifact['signature'] ?? '');

        return basename(str_replace('\\', '/', $class));
    }
}
