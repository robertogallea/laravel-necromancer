<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Actions;

final readonly class NecromancerOrderData
{
    public function __construct(public int $orderId) {}
}
