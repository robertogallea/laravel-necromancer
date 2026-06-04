<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

final class BenchmarkRunner
{
    /** @var string[] */
    private array $warnings = [];

    public function __construct(
        private readonly TaskSuite $taskSuite,
        private readonly GoldenAnswerResolver $resolver,
        private readonly FactChecker $factChecker,
        private readonly JudgeClient $judgeClient,
    ) {}

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array{
     *   conditions: string[],
     *   types: string[]|null,
     *   noJudge: bool,
     *   generationModel: string|null,
     *   generationProvider: string|null,
     *   judgeModel: string|null,
     *   judgeProvider: string|null,
     *   contextPaths: array<string, string>,
     * } $options
     */
    public function run(array $options): BenchmarkReport
    {
        $tasks = $this->taskSuite->tasks($options['types'] ?? null);
        $results = [];

        foreach ($tasks as $task) {
            foreach ($options['conditions'] as $condition) {
                $results[] = $this->runTask($task, $condition, $options);
            }
        }

        return new BenchmarkReport($results);
    }

    /** @param array<string, mixed> $options */
    private function runTask(array $task, string $condition, array $options): BenchmarkResult
    {
        $context = $this->loadContext($condition, $options['contextPaths']);
        $instructions = $this->buildInstructions($context);

        $agent = new GenerationAgent($instructions, [], []);

        $text = '';
        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($agent->stream($task['prompt'], provider: $options['generationProvider'], model: $options['generationModel']) as $event) {
            if ($event instanceof TextDelta) {
                $text .= $event->delta;
            } elseif ($event instanceof StreamEnd) {
                $promptTokens = $event->usage->promptTokens;
                $completionTokens = $event->usage->completionTokens;
            }
        }

        $checks = $this->factChecker->check($text, $task['assertions']);

        $resolved = $this->resolver->resolve($task['assertions']['fact_keys'] ?? []);
        $goldenAnswersTrusted = empty($resolved) || ! in_array(false, array_column($resolved, 'trusted'), true);

        $judgeScore = null;
        $judgeTokens = null;

        if (! $options['noJudge']) {
            try {
                $judged = $this->judgeClient->score(
                    taskPrompt: $task['prompt'],
                    mustContain: $task['assertions']['must_contain'] ?? [],
                    mustNotContain: $task['assertions']['must_not_contain'] ?? [],
                    response: $text,
                    provider: $options['judgeProvider'],
                    model: $options['judgeModel'],
                );
                $judgeScore = (float) $judged['total'];
                $judgeTokens = $judged['tokens'];
            } catch (ConnectionException $e) {
                $this->warnings[] = "Judge timed out on task {$task['id']} ({$condition}): {$e->getMessage()}";
            } catch (\Throwable $e) {
                $this->warnings[] = "Judge failed on task {$task['id']} ({$condition}): {$e->getMessage()}";
            }
        }

        return new BenchmarkResult(
            taskId: $task['id'],
            taskType: $task['type'],
            condition: $condition,
            prompt: $task['prompt'],
            response: $text,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            accuracy: $checks['accuracy'],
            hallucinationRate: $checks['hallucinationRate'],
            judgeScore: $judgeScore,
            judgeTokens: $judgeTokens,
            goldenAnswersTrusted: $goldenAnswersTrusted,
        );
    }

    /** @param array<string, string> $contextPaths */
    private function loadContext(string $condition, array $contextPaths): string
    {
        $path = $contextPaths[$condition] ?? null;

        if ($path === null || ! file_exists($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    private function buildInstructions(string $context): string
    {
        if ($context === '') {
            return 'You are a Laravel expert. Answer questions about this codebase accurately and concisely.';
        }

        return <<<INSTRUCTIONS
            You are a Laravel expert. Here is the application context file:

            {$context}

            Answer questions about this codebase accurately and concisely. Ground your answers in the context above.
            INSTRUCTIONS;
    }
}
