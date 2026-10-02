<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Providers;

use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerPaymentGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerStripeGateway;

final class NecromancerCodeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);
    }
}
