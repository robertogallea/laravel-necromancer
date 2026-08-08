<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Command as LaravelCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

final readonly class CommandCollector
{
    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
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
        $seen = [];

        foreach ($this->app->make(Kernel::class)->all() as $command) {
            if (! $command instanceof SymfonyCommand) {
                continue;
            }

            // Aliases register the same class under multiple names — collect only once
            $class = $command::class;

            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            $artifact = $this->collectCommand($command);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    private function collectCommand(SymfonyCommand $command): ?StructuralArtifact
    {
        if ($command instanceof ClosureCommand) {
            return null;
        }

        $reflection = new ReflectionClass($command);

        if ($reflection->isAbstract() || ! $this->isApplicationLocal($reflection)) {
            return null;
        }

        return StructuralArtifact::command(
            class: $reflection->getName(),
            signature: $this->signature($command, $reflection),
            description: $command->getDescription(),
            source: (new SourceLocator)->forClass($reflection),
            aliases: $this->aliases($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }

    /**
     * @template T of SymfonyCommand
     *
     * @param  ReflectionClass<T>  $reflection
     */
    private function signature(SymfonyCommand $command, ReflectionClass $reflection): string
    {
        if ($command instanceof LaravelCommand && $reflection->hasProperty('signature')) {
            try {
                $property = $reflection->getProperty('signature');
                $property->setAccessible(true);

                $signature = $property->getValue($command);

                if (is_string($signature) && $signature !== '') {
                    return $signature;
                }
            } catch (Throwable) {
            }
        }

        return (string) $command->getName();
    }

    /**
     * @template T of SymfonyCommand
     *
     * @param  ReflectionClass<T>  $reflection
     * @return list<string>
     */
    private function aliases(ReflectionClass $reflection): array
    {
        $attr = AttributeReader::first($reflection, Aliases::class);

        return $attr !== null ? array_values($attr->aliases) : [];
    }

    /**
     * @template T of SymfonyCommand
     *
     * @param  ReflectionClass<T>  $reflection
     */
    private function isApplicationLocal(ReflectionClass $reflection): bool
    {
        $file = $reflection->getFileName();

        if (! is_string($file)) {
            return false;
        }

        $file = realpath($file) ?: $file;

        foreach ($this->discoveryRoots() as $root) {
            $rootPath = realpath($root['path']);

            if (! is_string($rootPath)) {
                continue;
            }

            $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            if (str_starts_with($file, $rootPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function discoveryRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        return [[
            'path' => $this->app->basePath('app'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\',
        ]];
    }
}
