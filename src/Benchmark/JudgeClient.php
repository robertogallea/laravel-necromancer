<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelNecromancer\Inference\TemperatureAwareStructuredAgent;

final class JudgeClient
{
    /**
     * @param  string[]  $mustContain
     * @param  string[]  $mustNotContain
     * @return array{correctness: int, completeness: int, conventions: int, conciseness: int, total: int, tokens: int}
     */
    public function score(
        string $taskPrompt,
        array $mustContain,
        array $mustNotContain,
        string $response,
        ?string $provider = null,
        ?string $model = null,
    ): array {
        $expectedFacts = $mustContain
            ? implode("\n", array_map(fn (string $s): string => "- {$s}", $mustContain))
            : '(none specified)';

        $hallucinationMarkers = $mustNotContain
            ? implode("\n", array_map(fn (string $s): string => "- {$s}", $mustNotContain))
            : '(none specified)';

        $instructions = 'You are evaluating an AI response to a Laravel codebase task. Score objectively based only on the provided criteria. Respond only with the JSON scores.';

        $prompt = <<<PROMPT
        Task: {$taskPrompt}

        Expected facts (should appear in a correct response):
        {$expectedFacts}

        Hallucination markers (should NOT appear):
        {$hallucinationMarkers}

        Response to evaluate:
        {$response}

        Score each dimension with an integer:
        - correctness (0–3): All expected facts present? No hallucination markers found?
        - completeness (0–3): Does the response address the full task?
        - conventions (0–2): Does generated code follow idiomatic Laravel patterns?
        - conciseness (0–2): Free of padding and irrelevant content?
        - total: sum of the four dimensions above.
        PROMPT;

        $agent = new TemperatureAwareStructuredAgent(
            instructions: $instructions,
            messages: [],
            tools: [],
            schema: function (JsonSchema $schema): array {
                return [
                    'correctness' => $schema->integer()->required(),
                    'completeness' => $schema->integer()->required(),
                    'conventions' => $schema->integer()->required(),
                    'conciseness' => $schema->integer()->required(),
                    'total' => $schema->integer()->required(),
                ];
            },
        );

        $result = $agent->prompt($prompt, provider: $provider, model: $model);

        return [
            'correctness' => (int) ($result['correctness'] ?? 0),
            'completeness' => (int) ($result['completeness'] ?? 0),
            'conventions' => (int) ($result['conventions'] ?? 0),
            'conciseness' => (int) ($result['conciseness'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
            'tokens' => $result->usage->promptTokens + $result->usage->completionTokens,
        ];
    }
}
