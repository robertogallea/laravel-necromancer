<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

use LaravelNecromancer\Tests\Fixtures\Jobs\NecromancerQueuedJob;

final class NecromancerPlaceOrder
{
    public function handle(): void
    {
        NecromancerQueuedJob::dispatch();
    }
}
