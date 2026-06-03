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

final class AskCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:ask
        {question? : The question to ask about the codebase}
        {--provider= : AI provider override}
        {--model=    : Model override}
        {--privacy   : Send a condensed manifest summary to the AI provider instead of full JSON}';

    protected $description = 'Ask a natural-language question about your codebase using the Necromancer manifest and laravel/ai';

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

        $instructions = $this->buildInstructions($manifest, $privacy);
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
    private function buildInstructions(array $manifest, bool $privacy = false): string
    {
        if ($privacy) {
            $context = (new ManifestSummarizer)->summarize($manifest);
            $label = 'Condensed Manifest Summary';
        } else {
            $context = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $label = 'Full Manifest (JSON)';
        }

        return <<<PROMPT
        You are a Laravel application expert. Use the following manifest — a machine-readable inventory of this app's routes, models, jobs, events, commands, and policies — to answer questions accurately and concisely. If you cannot determine something from the manifest, say so clearly.

        {$label}:
        {$context}
        PROMPT;
    }
}
