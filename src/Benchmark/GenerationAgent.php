<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

use Laravel\Ai\AnonymousAgent;

final class GenerationAgent extends AnonymousAgent
{
    /**
     * Caps the tool-calling loop for the necromancer-mcp condition. Pinned
     * explicitly rather than left to laravel/ai's framework-computed default
     * so benchmark runs stay reproducible across laravel/ai version upgrades.
     * Has no effect on conditions that attach no tools.
     */
    private const MAX_STEPS = 8;

    public function maxSteps(): int
    {
        return self::MAX_STEPS;
    }
}
