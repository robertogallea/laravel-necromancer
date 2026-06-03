<?php

declare(strict_types=1);

namespace LaravelNecromancer\Prompt;

use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use LaravelNecromancer\Inference\CodebaseAnswerAgent;
use LaravelNecromancer\Inference\ManifestSummarizer;

final class QuestionContextualizer
{
    public function __construct(
        private readonly ManifestSummarizer $summarizer,
    ) {}

    /**
     * Reformulates a raw user query into a precise, application-aware question
     * using the AI agent grounded on the manifest summary.
     *
     * Returns the AI-reformulated question, or $rawQuestion on failure.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function contextualize(array $manifest, string $rawQuestion): string
    {
        $summary = $this->summarizer->summarize($manifest);

        $instructions = <<<INSTRUCTIONS
        You are helping formulate precise questions about a Laravel application for code review.
        Given the application manifest summary and a raw user query, return ONLY a single reformulated question that is application-specific, technical, and concise.
        Do not add preamble or explanation — only the question text.

        Manifest summary:
        {$summary}
        INSTRUCTIONS;

        $agent = new CodebaseAnswerAgent(
            instructions: $instructions,
            messages: [],
            tools: [],
        );

        try {
            $buffer = '';

            foreach ($agent->stream($rawQuestion) as $event) {
                if ($event instanceof TextDelta) {
                    $buffer .= $event->delta;
                } elseif ($event instanceof TextEnd) {
                    break;
                }
            }

            $result = trim($buffer);

            return $result !== '' ? $result : $rawQuestion;
        } catch (\Throwable) {
            return $rawQuestion;
        }
    }
}
