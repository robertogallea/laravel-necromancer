<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Policies;

use Illuminate\Foundation\Auth\User;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerOrder;

final class NecromancerPostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, NecromancerOrder $order): bool
    {
        return true;
    }

    public function delete(User $user, NecromancerOrder $order): bool
    {
        return true;
    }
}
