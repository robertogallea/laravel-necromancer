<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

final readonly class RouteAnnotationResolution
{
    /**
     * @param  list<string>  $diagnostics
     */
    public function __construct(
        public ArtifactAnnotations $annotations,
        public array $diagnostics = [],
    ) {}
}
