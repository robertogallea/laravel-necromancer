<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final class NecromancerBroadcastedEvent implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [];
    }
}
