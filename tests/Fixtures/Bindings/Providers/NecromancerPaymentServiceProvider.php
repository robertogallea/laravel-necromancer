<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Providers;

use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerPaymentGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerLedger;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerStripeGateway;

final class NecromancerPaymentServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        NecromancerPaymentGateway::class => NecromancerStripeGateway::class,
    ];

    /** @var array<int, class-string> */
    public $singletons = [
        NecromancerLedger::class,
    ];
}
