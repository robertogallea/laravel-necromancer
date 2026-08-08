<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\InvalidJobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use LaravelNecromancer\Attributes\Necromancer;
use LogicException;

#[Necromancer(domain: '   ')]
final class NecromancerInvalidAnnotatedJob implements ShouldQueue
{
    public function handle(): void
    {
        throw new LogicException('Fixture job handlers must not run during scans.');
    }
}
