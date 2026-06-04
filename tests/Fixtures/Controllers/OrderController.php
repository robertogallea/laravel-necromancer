<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Controllers;

use App\Models\User;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Authorize('view-orders', only: ['index'])]
#[Middleware('throttle:60,1')]
class OrderController
{
    public function index(): void {}

    #[Authorize('manage-billing', User::class)]
    public function update(): void {}
}
