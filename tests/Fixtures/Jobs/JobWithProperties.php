<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class JobWithProperties implements ShouldQueue
{
    public string $queue = 'emails';

    public string $connection = 'database';

    public int $tries = 3;

    public int $timeout = 60;
}
