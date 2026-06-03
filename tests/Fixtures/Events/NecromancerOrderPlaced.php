<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Events;

final readonly class NecromancerOrderPlaced
{
    public function __construct(public string $orderId) {}
}
