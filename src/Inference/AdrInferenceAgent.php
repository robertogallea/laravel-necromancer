<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelNecromancer\Inference\Contracts\AdrInferrer;

final class AdrInferenceAgent implements AdrInferrer
{
    public function infer(string $prompt, ?string $provider = null, ?string $model = null, ?string $locale = null, ?float $temperature = null): AdrInferenceResult
    {
        $agent = new TemperatureAwareStructuredAgent(
            instructions: $this->instructions($locale),
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

        $response = $agent->prompt($prompt, provider: $provider, model: $model);

        $adrs = array_map(
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
            adrs: $adrs,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    private function instructions(?string $locale): string
    {
        $languageDirective = $locale !== null
            ? "IMPORTANT: Write all narrative fields (title, context, decision, consequences) in the language corresponding to locale '{$locale}'. All other fields (slug, status, dimension, confidence) must remain in English.\n\n"
            : '';

        return <<<PROMPT
        {$languageDirective}You are a Laravel architect. Analyse the provided application summary and identify
        architectural decisions using the following nine dimensions:

        1. async-processing    — Jobs, queues, workers, retry strategy
        2. authorization       — Policies, gates, middleware auth guards
        3. event-driven        — Events, listeners, broadcasting
        4. api-design          — Route structure, API resources, versioning, response format
        5. data-modeling       — Model relationships, casts, soft deletes, scopes
        6. command-scheduling  — Artisan commands, scheduled tasks
        7. form-validation     — Form requests vs inline validation strategy
        8. external-services   — Mail, storage, payment, third-party integrations
        9. architecture-pattern — Service layer, repository, MVC deviation choices

        Rules:
        - Produce AT MOST ONE ADR per dimension.
        - Only produce an ADR for a dimension when there is CLEAR, SPECIFIC evidence in the manifest
          that a genuine architectural decision was made — not just "the app uses Eloquent" or "it has routes".
        - If a dimension shows only default Laravel behaviour with no trade-off, omit it entirely.
        - Set confidence: "high" = multiple artifacts confirm the decision; "medium" = single artifact;
          "low" = inferred, no direct artifact evidence.
        - Set status: "proposed" for low-confidence decisions; "inferred" otherwise.
        - slug: English kebab-case, always — even if writing in another language.
        - counter_evidence: Cite specific manifest entries that CONTRADICT or weaken the decision.
          Name artifact classes, route names, or config keys. If no contradicting evidence exists,
          write exactly: "No contradicting evidence found in the manifest."
        PROMPT;
    }
}
