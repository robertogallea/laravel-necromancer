<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Contracts;

use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerFakeInvoiceNumbers;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerSequentialInvoiceNumbers;

#[Bind(NecromancerSequentialInvoiceNumbers::class)]
#[Bind(NecromancerFakeInvoiceNumbers::class, environments: 'testing')]
#[Singleton]
interface NecromancerInvoiceNumbers {}
