<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use Throwable;

final readonly class MailableCollector
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

        foreach ((new ClassDiscovery($this->discoveryRoots()))->classes() as $class) {
            $artifact = $this->collectClass($class);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
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
            'path' => $this->app->basePath('app/Mail'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Mail\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return null;
        }

        if (! $reflection->isSubclassOf(Mailable::class)) {
            return null;
        }

        $queued = $reflection->implementsInterface(ShouldQueue::class);

        $queueProperty = $reflection->getDefaultProperties()['queue'] ?? null;
        $queue = is_string($queueProperty) ? $queueProperty : null;

        $subject = null;

        try {
            if ($reflection->hasMethod('envelope')) {
                $instance = $reflection->newInstanceWithoutConstructor();
                $envelope = $reflection->getMethod('envelope')->invoke($instance);
                $subjectVal = $envelope instanceof Envelope ? $envelope->subject : null;
                $subject = is_string($subjectVal) ? $subjectVal : null;
            }
        } catch (Throwable) {
            $subject = null;
        }

        $view = null;

        try {
            if ($reflection->hasMethod('content')) {
                $instance = $reflection->newInstanceWithoutConstructor();
                $content = $reflection->getMethod('content')->invoke($instance);
                if ($content instanceof Content) {
                    $viewVal = $content->view ?? $content->markdown ?? null;
                    $view = is_string($viewVal) ? $viewVal : null;
                }
            }
        } catch (Throwable) {
            $view = null;
        }

        return StructuralArtifact::mailable(
            class: $class,
            subject: $subject,
            queued: $queued,
            queue: $queue,
            view: $view,
            source: (new SourceLocator)->forClass($reflection),
        );
    }
}
