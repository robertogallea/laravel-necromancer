<?php

declare(strict_types=1);

/**
 * Stub definitions for the optional livewire/livewire package.
 * These allow PHPStan to analyse Livewire integration code
 * without requiring the package to be installed.
 */

namespace Livewire {
    abstract class Component {}
}

namespace Livewire\Attributes {
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    class On
    {
        public function __construct(public string|array $event) {}
    }
}
