<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Gates;

final class NecromancerManageUsersGate
{
    public function __invoke(object $user): bool
    {
        return true;
    }
}
