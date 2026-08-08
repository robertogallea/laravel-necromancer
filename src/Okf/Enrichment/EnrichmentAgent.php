<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelNecromancer\Inference\TemperatureAwareStructuredAgent;
use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;

final class EnrichmentAgent implements ConceptEnricher
{
    public function enrich(string $prompt, ?string $provider = null, ?string $model = null, ?float $temperature = null): RawEnrichment
    {
        $agent = new TemperatureAwareStructuredAgent(
            instructions: $this->instructions(),
            messages: [],
            tools: [],
            schema: function (JsonSchema $schema): array {
                return [
                    'description' => $schema->string()->required(),
                    'narrative' => $schema->string()->required(),
                ];
            },
            agentTemperature: $temperature,
        );

        $response = $agent->prompt($prompt, provider: $provider, model: $model);

        return new RawEnrichment(
            description: (string) ($response['description'] ?? ''),
            narrative: (string) ($response['narrative'] ?? ''),
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
        You write concise, factual prose for a portable software knowledge bundle.
        Ground every statement in the concept data you are given. Never invent facts,
        relationships, or business context that is not present in that data. If the
        data is too sparse to say anything specific, say so plainly.
        PROMPT;
    }
}
