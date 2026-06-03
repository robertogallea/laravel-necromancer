<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use LogicException;

final class NecromancerQueuedJob implements ShouldQueue
{
    public string $queue = 'necromancer-jobs';

    public string $connection = 'redis';

    public int $tries = 3;

    public function __construct(private readonly string $payload) {}

    public function handle(): void
    {
        throw new LogicException('Fixture job handlers must not run during scans.');
    }
}
