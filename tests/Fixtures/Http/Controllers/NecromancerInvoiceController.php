<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

use LaravelNecromancer\Tests\Fixtures\Models\NecromancerOrder;

final class NecromancerInvoiceController
{
    public function show(NecromancerOrder $order): string
    {
        return 'show';
    }

    public function index(): string
    {
        return 'index';
    }
}
