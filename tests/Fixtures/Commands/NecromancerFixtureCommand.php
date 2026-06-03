<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use LogicException;

final class NecromancerFixtureCommand extends Command
{
    protected $signature = 'necromancer:fixture {--force}';

    protected $description = 'Fixture command for Necromancer scans';

    public function handle(): int
    {
        throw new LogicException('Fixture commands must not run during scans.');
    }
}
