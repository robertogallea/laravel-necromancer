<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Metadata\Risk;
use LogicException;

#[Necromancer(domain: 'billing', capability: 'invoice.send', risk: Risk::High, externalServices: ['stripe'])]
final class NecromancerAnnotatedJob implements ShouldQueue
{
    public function handle(): void
    {
        throw new LogicException('Fixture job handlers must not run during scans.');
    }
}
