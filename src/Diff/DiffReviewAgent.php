<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaravelNecromancer\Inference\TemperatureAwareStructuredAgent;

final class DiffReviewAgent
{
    public function __construct(
        private readonly ?string $provider = null,
        private readonly ?string $model = null,
        private readonly ?float $temperature = null,
    ) {}

    public function review(ManifestDiff $diff, string $manifestSummary, string $baseBranch, string $appName): DiffReviewResult
    {
        if ($diff->isEmpty()) {
            throw new \InvalidArgumentException('Cannot review an empty diff.');
        }

        $formattedDiff = $this->formatDiff($diff);

        $prompt = <<<PROMPT
        You are an expert Laravel architect reviewing a pull request.

        Application: {$appName}
        Branch being reviewed: HEAD vs {$baseBranch}

        ## Current Application Context

        {$manifestSummary}

        ## Architectural Changes in This Branch

        {$formattedDiff}

        ## Your Task

        Analyze the architectural impact of these changes. Focus on:
        - What domain behavior changed (not just "a route was added" but why it matters)
        - Risks: missing tests, missing ADRs, risky changes to billing/auth/queues/webhooks/tenancy
        - Concrete reviewer questions that help catch issues

        Return a JSON object with:
        - summary: one paragraph describing the architectural impact
        - evidence: list of concrete observations (e.g. "New listener: ActivateSubscriptionAfterPayment")
        - risks: list of specific risks (e.g. "No failed-webhook test detected")
        - reviewer_questions: list of questions for the PR reviewer
        PROMPT;

        $agent = new TemperatureAwareStructuredAgent(
            instructions: 'You are an expert Laravel architect performing architectural code reviews.',
            messages: [],
            tools: [],
            schema: function (JsonSchema $schema): array {
                return [
                    'summary' => $schema->string()->required(),
                    'evidence' => $schema->array()->items($schema->string())->required(),
                    'risks' => $schema->array()->items($schema->string())->required(),
                    'reviewer_questions' => $schema->array()->items($schema->string())->required(),
                ];
            },
            agentTemperature: $this->temperature,
        );

        $response = $agent->prompt($prompt, provider: $this->provider, model: $this->model);

        return new DiffReviewResult(
            summary: $response['summary'],
            evidence: $response['evidence'],
            risks: $response['risks'],
            reviewerQuestions: $response['reviewer_questions'],
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    private function formatDiff(ManifestDiff $diff): string
    {
        $lines = [];

        $flagged = FlaggedArtifacts::fromDiff($diff);

        if (! empty($flagged)) {
            $lines[] = 'FLAGGED ARTIFACTS';
            foreach ($flagged as ['type' => $type, 'artifact' => $artifact]) {
                $id = (string) ($artifact['id'] ?? '');
                $lines[] = '- ['.$type.'] '.$this->labelArtifact($type, $artifact)." ({$id})  ".FlaggedArtifacts::reason($artifact);
            }
            $lines[] = '';
        }

        if (! empty($diff->added)) {
            $lines[] = 'ADDED';
            foreach ($diff->added as $type => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $lines[] = '- ['.$type.'] '.$this->labelArtifact($type, $artifact);
                }
            }
            $lines[] = '';
        }

        if (! empty($diff->removed)) {
            $lines[] = 'REMOVED';
            foreach ($diff->removed as $type => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $lines[] = '- ['.$type.'] '.$this->labelArtifact($type, $artifact);
                }
            }
            $lines[] = '';
        }

        if (! empty($diff->changed)) {
            $lines[] = 'CHANGED';
            foreach ($diff->changed as $type => $changes) {
                foreach ($changes as $change) {
                    $lines[] = '- ['.$type.'] '.$this->labelArtifact($type, $change['to']);
                    $diffFields = $this->diffingFields($change['from'], $change['to']);
                    foreach (array_slice($diffFields, 0, 5) as [$key, $fromValue, $toValue]) {
                        $lines[] = '  '.$key.': '.json_encode($fromValue, JSON_THROW_ON_ERROR).' → '.json_encode($toValue, JSON_THROW_ON_ERROR);
                    }
                    $remaining = count($diffFields) - 5;
                    if ($remaining > 0) {
                        $lines[] = '  ... '.$remaining.' more field(s)';
                    }
                }
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
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
     * @return list<array{string, mixed, mixed}>
     */
    private function diffingFields(array $from, array $to): array
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
