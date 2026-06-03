<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Listeners;

use LaravelNecromancer\Tests\Fixtures\Events\NecromancerOrderPlaced;
use LogicException;

final class RecordNecromancerOrderMetrics
{
    public function __construct(private readonly string $metricsPrefix) {}

    public function __invoke(NecromancerOrderPlaced $event): void
    {
        throw new LogicException('Fixture listeners must not run during scans.');
    }
}
