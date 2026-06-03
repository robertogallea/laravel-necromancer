<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Commands;

use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Command;

#[Aliases(['oc', 'orders:clean'])]
class CommandWithAliases extends Command
{
    protected $signature = 'orders:cleanup';

    protected $description = 'Clean up old orders';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
