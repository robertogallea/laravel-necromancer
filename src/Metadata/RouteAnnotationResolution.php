<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

final readonly class RouteAnnotationResolution
{
    /**
     * @param  array<string, mixed>  $compatibility
     * @param  list<string>  $diagnostics
     */
    public function __construct(
        public array $compatibility,
        public ArtifactAnnotations $annotations,
        public array $diagnostics = [],
    ) {}
}
