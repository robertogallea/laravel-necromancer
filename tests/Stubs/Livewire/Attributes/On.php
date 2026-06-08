<?php

declare(strict_types=1);

namespace Livewire\Attributes;

if (! class_exists(On::class)) {
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    class On
    {
        public function __construct(public string|array $event) {}
    }
}
