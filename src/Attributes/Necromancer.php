<?php

declare(strict_types=1);

namespace LaravelNecromancer\Attributes;

use Attribute;
use LaravelNecromancer\Metadata\Risk;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Necromancer
{
    /**
     * @param  list<string>  $externalServices
     * @param  list<string>  $adrs
     */
    public function __construct(
        public ?string $domain = null,
        public ?string $flow = null,
        public ?string $capability = null,
        public ?string $summary = null,
        public ?Risk $risk = null,
        public array $externalServices = [],
        public array $adrs = [],
    ) {}
}
