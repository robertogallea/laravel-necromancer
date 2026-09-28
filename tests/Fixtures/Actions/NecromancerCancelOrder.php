<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Actions;

use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Metadata\Risk;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerCustomer;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerOrder;

#[Necromancer(domain: 'orders', flow: 'order-cancellation', risk: Risk::High)]
final class NecromancerCancelOrder
{
    public function handle(NecromancerOrder $order, NecromancerCustomer $actor): NecromancerOrder
    {
        return $order;
    }
}
