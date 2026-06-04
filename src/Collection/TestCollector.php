<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Manifest\SourceLocation;
use LaravelNecromancer\Manifest\StructuralArtifact;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class TestCollector
{
    /**
     * @param  list<array{path: string, type: string}>|null  $roots
     */
    public function __construct(
        private Application $app,
        private ?array $roots = null,
    ) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $artifacts = [];

        foreach ($this->discoveryRoots() as $root) {
            if (! is_dir($root['path'])) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root['path'], RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $artifact = $this->collectFile($file, $root['type'], $root['path']);

                if ($artifact instanceof StructuralArtifact) {
                    $artifacts[] = $artifact;
                }
            }
        }

        return $artifacts;
    }

    /**
     * @return list<array{path: string, type: string}>
     */
    private function discoveryRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        $configured = config('necromancer.tests.roots', []);

        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        return [
            ['path' => $this->app->basePath('tests/Unit'), 'type' => 'unit'],
            ['path' => $this->app->basePath('tests/Feature'), 'type' => 'feature'],
        ];
    }

    private function collectFile(SplFileInfo $file, string $testType, string $rootPath): ?StructuralArtifact
    {
        $absolutePath = $file->getRealPath();

        if ($absolutePath === false) {
            return null;
        }

        if ($this->isExcluded($absolutePath)) {
            return null;
        }

        $parser = new TestFileParser($absolutePath);
        $methods = $parser->methods();
        $class = $parser->isPestFunctional() ? null : $parser->declaredClass();
        $subject = $this->inferSubject($parser, $absolutePath, $rootPath);
        $source = $this->buildSourceLocation($absolutePath);

        return StructuralArtifact::test(
            file: $source->file,
            testType: $testType,
            class: $class,
            subject: $subject,
            methods: $methods,
            source: $source,
        );
    }

    private function inferSubject(TestFileParser $parser, string $absolutePath, string $rootPath): ?string
    {
        $fromUses = $parser->usesSubject();

        if ($fromUses !== null) {
            return $fromUses;
        }

        return $this->inferSubjectFromConvention($absolutePath, $rootPath);
    }

    private function inferSubjectFromConvention(string $absolutePath, string $rootPath): ?string
    {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($absolutePath, $rootPath)) {
            return null;
        }

        $relative = substr($absolutePath, strlen($rootPath));

        if (! str_ends_with($relative, 'Test.php')) {
            return null;
        }

        // Strip Test.php → e.g. "Models/OrderTest.php" → "Models/Order"
        $withoutSuffix = substr($relative, 0, -strlen('Test.php'));

        // Convert path separators to namespace separators
        $namespacePart = str_replace(DIRECTORY_SEPARATOR, '\\', $withoutSuffix);
        $namespacePart = str_replace('/', '\\', $namespacePart);

        $appNamespace = rtrim($this->app->getNamespace(), '\\');

        return $appNamespace.'\\'.$namespacePart;
    }

    private function buildSourceLocation(string $absolutePath): SourceLocation
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relativePath = str_starts_with($absolutePath, $basePath)
            ? substr($absolutePath, strlen($basePath))
            : $absolutePath;

        $lineCount = is_file($absolutePath) ? count((array) file($absolutePath)) : null;
        $hash = is_file($absolutePath) ? (md5_file($absolutePath) ?: null) : null;

        return new SourceLocation(
            file: $relativePath,
            line: 1,
            line_end: $lineCount,
            hash: $hash,
        );
    }

    private function isExcluded(string $absolutePath): bool
    {
        $exclusions = config('necromancer.exclude.tests', []);

        if (! is_array($exclusions) || $exclusions === []) {
            return false;
        }

        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relativePath = str_starts_with($absolutePath, $basePath)
            ? substr($absolutePath, strlen($basePath))
            : $absolutePath;

        foreach ($exclusions as $pattern) {
            if (fnmatch((string) $pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
