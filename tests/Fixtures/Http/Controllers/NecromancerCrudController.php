<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

final class NecromancerCrudController extends AbstractNecromancerController
{
    public function __construct() {}

    public static function make(): self
    {
        return new self;
    }

    public function edit(): string
    {
        return 'edit';
    }

    protected function authorizeEdit(): void {}
}
