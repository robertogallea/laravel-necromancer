<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Support\Str;
use LaravelNecromancer\Manifest\StructuralArtifact;

final readonly class RouteNoiseFilter
{
    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $uriPatterns
     */
    public function __construct(
        private array $patterns = ['horizon.*', 'telescope.*', 'debugbar.*'],
        private array $uriPatterns = [],
    ) {}

    public function allows(StructuralArtifact $artifact): bool
    {
        if (! $artifact->isRoute()) {
            return true;
        }

        $uri = $artifact->routeUri();

        if ($uri !== null) {
            foreach ($this->uriPatterns as $pattern) {
                if (Str::is($pattern, $uri)) {
                    return false;
                }
            }
        }

        $name = $artifact->routeName();

        if ($name === null) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (Str::is($pattern, $name)) {
                return false;
            }
        }

        return true;
    }
}
