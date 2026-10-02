<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

use LaravelNecromancer\Tests\Fixtures\Http\Concerns\NecromancerRespondsWithJson;

final class NecromancerReportController
{
    use NecromancerRespondsWithJson;

    public function index(): string
    {
        return $this->respond('index');
    }
}
