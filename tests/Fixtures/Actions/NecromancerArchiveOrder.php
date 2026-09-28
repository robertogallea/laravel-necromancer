<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Actions;

final class NecromancerArchiveOrder extends AbstractNecromancerAction
{
    public static function make(): self
    {
        return new self;
    }

    public function execute($order)
    {
        return $order;
    }

    public function archive(): void {}

    private function audit(): void {}
}
