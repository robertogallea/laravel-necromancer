<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit;

final readonly class CheckResult
{
    /**
     * @param  Finding[]  $findings
     */
    public function __construct(
        public string $severity,
        public int $total,
        public array $findings,
    ) {}
}
