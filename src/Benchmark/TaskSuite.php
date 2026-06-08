<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

final class TaskSuite
{
    /** @var list<array{id: string, type: string, prompt: string, assertions: array{must_contain: string[], must_not_contain: string[], fact_keys: string[]}}> */
    private array $tasks;

    /** @param list<array{id: string, type: string, prompt: string, assertions: array}>|null $override */
    public function __construct(?array $override = null)
    {
        $this->tasks = $override ?? require __DIR__.'/../../resources/benchmark/tasks.php';
    }

    /**
     * @param  list<string>|null  $types  Filter by task type(s). Null returns all.
     * @return list<array{id: string, type: string, prompt: string, assertions: array}>
     */
    public function tasks(?array $types = null): array
    {
        if ($types === null) {
            return $this->tasks;
        }

        return array_values(
            array_filter($this->tasks, fn (array $t): bool => in_array($t['type'], $types, true))
        );
    }
}
