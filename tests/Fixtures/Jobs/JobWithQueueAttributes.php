<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue('notifications')]
#[Connection('redis')]
#[Tries(5)]
#[Timeout(120)]
#[Backoff(30, 60, 90)]
#[MaxExceptions(3)]
class JobWithQueueAttributes implements ShouldQueue
{
    use Queueable;
    // No property declarations — all config via attributes
}
