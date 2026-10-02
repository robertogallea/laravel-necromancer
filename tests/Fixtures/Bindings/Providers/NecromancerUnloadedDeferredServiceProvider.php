<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerPaymentGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerRefundGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerStripeGateway;
use RuntimeException;

final class NecromancerUnloadedDeferredServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /** @var array<class-string, class-string> */
    public $singletons = [
        NecromancerPaymentGateway::class => NecromancerStripeGateway::class,
    ];

    public function register(): void
    {
        throw new RuntimeException('A deferred provider was registered during the scan.');
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [NecromancerPaymentGateway::class, NecromancerRefundGateway::class];
    }
}
