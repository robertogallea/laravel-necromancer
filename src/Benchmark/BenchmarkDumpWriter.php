<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

use RuntimeException;

final class BenchmarkDumpWriter
{
    /**
     * @param  array<string, mixed>  $run
     */
    public function write(BenchmarkReport $report, array $run, string $basePath): string
    {
        $basePath = $this->normalizeDirectory($basePath);
        $this->ensureDirectory($basePath, 'benchmark dump path');

        $runDirectory = $this->createRunDirectory($basePath, (string) ($run['started_at'] ?? 'now'));
        $responsesDirectory = $runDirectory.DIRECTORY_SEPARATOR.'responses';

        $this->ensureDirectory($responsesDirectory, 'benchmark responses path');

        $this->putJson($runDirectory.DIRECTORY_SEPARATOR.'run.json', $this->runPayload($report, $run));
        $this->putJson($runDirectory.DIRECTORY_SEPARATOR.'results.json', [
            'summary' => $report->byCondition(),
            'results' => $this->resultsPayload($report),
        ]);

        foreach ($report->results as $result) {
            $this->put(
                $responsesDirectory.DIRECTORY_SEPARATOR.$this->responseFilename($result),
                $this->renderResponse($result)
            );
        }

        return $runDirectory;
    }

    private function normalizeDirectory(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        return $path === '' ? DIRECTORY_SEPARATOR : $path;
    }

    private function createRunDirectory(string $basePath, string $startedAt): string
    {
        $timestamp = strtotime($startedAt);
        $name = date('Y-m-d-His', $timestamp === false ? time() : $timestamp);
        $candidate = $basePath.DIRECTORY_SEPARATOR.$name;

        if (! file_exists($candidate)) {
            $this->ensureDirectory($candidate, 'benchmark run path');

            return $candidate;
        }

        for ($i = 1; $i <= 99; $i++) {
            $candidate = $basePath.DIRECTORY_SEPARATOR.$name.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if (! file_exists($candidate)) {
                $this->ensureDirectory($candidate, 'benchmark run path');

                return $candidate;
            }
        }

        throw new RuntimeException("Unable to allocate a unique dump directory under {$basePath}.");
    }

    private function ensureDirectory(string $path, string $label): void
    {
        if (file_exists($path) && ! is_dir($path)) {
            throw new RuntimeException("The {$label} exists and is not a directory: {$path}");
        }

        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create {$label}: {$path}");
        }

        if (! is_writable($path)) {
            throw new RuntimeException("The {$label} is not writable: {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function runPayload(BenchmarkReport $report, array $run): array
    {
        return [
            'started_at' => $run['started_at'] ?? null,
            'manifest' => $this->fileMetadata((string) ($run['manifest_path'] ?? '')),
            'conditions' => $run['conditions'] ?? [],
            'types' => $run['types'] ?? null,
            'generation' => [
                'model' => $run['generation_model'] ?? null,
                'provider' => $run['generation_provider'] ?? null,
            ],
            'judge' => [
                'enabled' => (bool) ($run['judge_enabled'] ?? false),
                'model' => $run['judge_model'] ?? null,
                'provider' => $run['judge_provider'] ?? null,
            ],
            'timeout' => $run['timeout'] ?? null,
            'contexts' => $this->contextsPayload((array) ($run['context_paths'] ?? [])),
            'summary' => $report->byCondition(),
            'warnings' => array_values((array) ($run['warnings'] ?? [])),
        ];
    }

    /**
     * @param  array<string, string>  $contextPaths
     * @return array<string, array<string, mixed>>
     */
    private function contextsPayload(array $contextPaths): array
    {
        $contexts = [];

        foreach ($contextPaths as $condition => $path) {
            $contexts[$condition] = $path === ''
                ? ['path' => '', 'exists' => false, 'bytes' => 0, 'sha256' => null]
                : $this->fileMetadata($path);
        }

        return $contexts;
    }

    /**
     * @return array<string, mixed>
     */
    private function fileMetadata(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [
                'path' => $path,
                'exists' => false,
                'bytes' => 0,
                'sha256' => null,
            ];
        }

        return [
            'path' => $path,
            'exists' => true,
            'bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resultsPayload(BenchmarkReport $report): array
    {
        return array_map(fn (BenchmarkResult $result): array => [
            'task_id' => $result->taskId,
            'task_type' => $result->taskType,
            'condition' => $result->condition,
            'prompt' => $result->prompt,
            'response' => $result->response,
            'skipped' => $result->skipped,
            'skip_reason' => $result->skipReason,
            'accuracy' => $result->accuracy,
            'hallucination_rate' => $result->hallucinationRate,
            'judge_score' => $result->judgeScore,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'latency_ms' => $result->latencyMs,
            'judge_tokens' => $result->judgeTokens,
            'judge_latency_ms' => $result->judgeLatencyMs,
            'golden_answers_trusted' => $result->goldenAnswersTrusted,
        ], $report->results);
    }

    private function responseFilename(BenchmarkResult $result): string
    {
        $filename = $result->taskId.'__'.$result->condition;
        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $filename) ?: $filename;

        return $filename.'.md';
    }

    private function renderResponse(BenchmarkResult $result): string
    {
        $skipped = $result->skipped ? 'yes' : 'no';
        $skipReason = $result->skipReason ?? '';
        $judgeScore = $result->judgeScore === null ? 'not run' : (string) $result->judgeScore;
        $judgeTokens = $result->judgeTokens === null ? 'not run' : (string) $result->judgeTokens;
        $judgeLatency = $result->judgeLatencyMs === null ? 'not run' : "{$result->judgeLatencyMs}ms";

        return <<<MARKDOWN
            # {$result->taskId} / {$result->condition}

            - Task type: {$result->taskType}
            - Skipped: {$skipped}
            - Skip reason: {$skipReason}
            - Accuracy: {$result->accuracy}
            - Hallucination rate: {$result->hallucinationRate}
            - Judge score: {$judgeScore}
            - Prompt tokens: {$result->promptTokens}
            - Completion tokens: {$result->completionTokens}
            - Latency: {$result->latencyMs}ms
            - Judge tokens: {$judgeTokens}
            - Judge latency: {$judgeLatency}
            - Golden answers trusted: {$this->boolLabel($result->goldenAnswersTrusted)}

            ## Prompt

            ~~~text
            {$result->prompt}
            ~~~

            ## Response

            ~~~markdown
            {$result->response}
            ~~~

            MARKDOWN;
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putJson(string $path, array $payload): void
    {
        $this->put(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    private function put(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write file: {$path}");
        }
    }
}
