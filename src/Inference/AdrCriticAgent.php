<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelNecromancer\Inference\Contracts\AdrCritic;

final class AdrCriticAgent implements AdrCritic
{
    /**
     * @param  list<InferredAdr>  $adrs
     */
    public function critique(array $adrs, string $manifestSummary, ?string $provider = null, ?string $model = null, ?float $temperature = null): AdrCriticResult
    {
        $agent = new TemperatureAwareStructuredAgent(
            instructions: $this->instructions(),
            messages: [],
            tools: [],
            schema: function (JsonSchema $schema): array {
                return [
                    'decisions' => $schema->array()->items(
                        $schema->object([
                            'title' => $schema->string()->required(),
                            'slug' => $schema->string()->required(),
                            'status' => $schema->string()->enum(['proposed', 'accepted'])->required(),
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
                    'satisfied' => $schema->boolean()->required(),
                ];
            },
            agentTemperature: $temperature,
        );

        $response = $agent->prompt($this->buildPrompt($adrs, $manifestSummary), provider: $provider, model: $model);

        $reviewed = array_map(
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

        return new AdrCriticResult(
            adrs: $reviewed,
            satisfied: (bool) ($response['satisfied'] ?? true),
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
        You are a strict senior architect reviewing proposed Architecture Decision Records.
        You will receive the application manifest summary and a list of proposed ADRs.

        For each proposed ADR, evaluate:
        1. Is this a genuine architectural decision with a real trade-off?
           REJECT if it merely describes a default Laravel feature (e.g. "uses Eloquent", "has named routes").
        2. Is the evidence from the manifest specific enough to justify this ADR?
           REJECT if the evidence is vague or inferred from a single trivial artifact.
        3. Is the confidence rating appropriate given the evidence?
           Adjust if needed: high = multiple artifacts; medium = single artifact; low = inferred.
        4. Is the wording specific to THIS application, not generic boilerplate?
           IMPROVE the title and context if they read as generic Laravel advice.

        Return only the ADRs that survive review. You may improve wording, adjust confidence,
        or correct status. Do NOT add new ADRs. Do NOT change slugs or dimensions.

        Set 'satisfied' to TRUE if all remaining ADRs are of high quality and another review
        pass is unlikely to improve them further. Set 'satisfied' to FALSE if you found
        significant issues in this pass and believe a follow-up review would still be beneficial.
        PROMPT;
    }

    /**
     * @param  list<InferredAdr>  $adrs
     */
    private function buildPrompt(array $adrs, string $manifestSummary): string
    {
        $lines = ["## Application Manifest\n{$manifestSummary}\n\n## Proposed ADRs\n"];

        foreach ($adrs as $i => $adr) {
            $n = $i + 1;
            $lines[] = "ADR {$n} [{$adr->dimension}, confidence={$adr->confidence}, status={$adr->status}]";
            $lines[] = "Title: {$adr->title}";
            $lines[] = "Slug: {$adr->slug}";
            $lines[] = "Context: {$adr->context}";
            $lines[] = "Decision: {$adr->decision}";
            $lines[] = "Consequences: {$adr->consequences}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
