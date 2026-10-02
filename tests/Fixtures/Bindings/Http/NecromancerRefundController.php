<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Http;

use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerPaymentGateway;

final class NecromancerRefundController
{
    public function __construct(public NecromancerPaymentGateway $gateway) {}
}
