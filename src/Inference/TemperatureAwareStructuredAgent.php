<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

use Closure;

final class TemperatureAwareStructuredAgent extends \Laravel\Ai\StructuredAnonymousAgent
{
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        ?Closure $schema = null,
        private readonly ?float $agentTemperature = null,
    ) {
        parent::__construct($instructions, $messages, $tools, $schema);
    }

    public function temperature(): ?float
    {
        return $this->agentTemperature;
    }
}
