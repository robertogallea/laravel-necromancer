<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

final class NecromancerAuditLogger extends AbstractLogger
{
    public function log($level, string|Stringable $message, array $context = []): void {}
}
