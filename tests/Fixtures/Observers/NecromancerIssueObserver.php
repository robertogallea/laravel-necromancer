<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Observers;

final class NecromancerIssueObserver
{
    public function created(object $model): void {}

    public function deleted(object $model): void {}

    public function updating(object $model): void {}
}
