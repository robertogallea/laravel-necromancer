<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Actions\Orders;

use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerOrder;

final class NecromancerRefundOrder
{
    #[Necromancer(domain: 'refunds')]
    public function __invoke(NecromancerOrder $order): void {}
}
