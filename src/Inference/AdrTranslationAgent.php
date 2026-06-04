<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelNecromancer\Inference\Contracts\AdrTranslator;

final class AdrTranslationAgent implements AdrTranslator
{
    /**
     * @param  list<InferredAdr>  $adrs
     */
    public function translate(array $adrs, string $targetLocale, ?string $provider = null, ?string $model = null, ?float $temperature = null): AdrInferenceResult
    {
        $agent = new TemperatureAwareStructuredAgent(
            instructions: $this->instructions($targetLocale),
            messages: [],
            tools: [],
            schema: function (JsonSchema $schema): array {
                return [
                    'decisions' => $schema->array()->items(
                        $schema->object([
                            'title' => $schema->string()->required(),
                            'slug' => $schema->string()->required(),
                            'status' => $schema->string()->enum(['proposed', 'inferred'])->required(),
                            'context' => $schema->string()->required(),
                            'decision' => $schema->string()->required(),
                            'consequences' => $schema->string()->required(),
                            'counter_evidence' => $schema->string()->required(),
                            'dimension' => $schema->string()->enum([
                                'async-processing', 'authorization', 'event-driven',
                                'api-design', 'data-modeling', 'command-scheduling',
                                'form-validation', 'external-services', 'architecture-pattern',
                            ])->required(),
                            'confidence' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
                        ])->withoutAdditionalProperties()
                    )->required(),
                ];
            },
            agentTemperature: $temperature,
        );

        $response = $agent->prompt($this->serialize($adrs), provider: $provider, model: $model);

        $translated = array_map(
            fn (array $d) => new InferredAdr(
                title: $d['title'],
                slug: $d['slug'],
                status: $d['status'],
                context: $d['context'],
                decision: $d['decision'],
                consequences: $d['consequences'],
                counter_evidence: $d['counter_evidence'] ?? '',
                dimension: $d['dimension'],
                confidence: $d['confidence'],
            ),
            $response['decisions'] ?? [],
        );

        return new AdrInferenceResult(
            adrs: $translated,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    private function instructions(string $targetLocale): string
    {
        return <<<PROMPT
        You are a technical translator. You will receive a set of Architecture Decision Records.
        Translate them into the language corresponding to locale '{$targetLocale}'.

        Rules:
        - Translate only the narrative fields: title, context, decision, consequences.
        - Keep the 'slug' field exactly as-is (it is used as a filename).
        - Keep the 'status' field exactly as-is ('inferred' or 'proposed').
        - Keep the 'dimension' field exactly as-is (it is an English category identifier).
        - Keep the 'confidence' field exactly as-is ('high', 'medium', or 'low').
        - Return exactly the same number of records in the same order.
        - Do not add, remove, or reorder any records.
        PROMPT;
    }

    /**
     * @param  list<InferredAdr>  $adrs
     */
    private function serialize(array $adrs): string
    {
        $lines = [];

        foreach ($adrs as $i => $adr) {
            $n = $i + 1;
            $lines[] = "ADR {$n} (slug: {$adr->slug}, status: {$adr->status}, dimension: {$adr->dimension}, confidence: {$adr->confidence})";
            $lines[] = "Title: {$adr->title}";
            $lines[] = "Context: {$adr->context}";
            $lines[] = "Decision: {$adr->decision}";
            $lines[] = "Consequences: {$adr->consequences}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
