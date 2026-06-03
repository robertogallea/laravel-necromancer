<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;

interface CheckInterface
{
    /**
     * @param  array<string, mixed>  $artifacts
     */
    public function run(array $artifacts): CheckResult;
}
