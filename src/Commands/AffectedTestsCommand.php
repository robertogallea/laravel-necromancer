<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ManifestScopeGuard;
use LaravelNecromancer\Relationships\AffectedTest;
use LaravelNecromancer\Relationships\AffectedTestFinder;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

final class AffectedTestsCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:affected-tests
        {artifact?       : An exact Artifact ID, a fully-qualified class name, or a domain:/flow:/adr: ID}
        {--stdin         : Read changed file paths from stdin, one per line}
        {--depth=2       : How many Relationships away to walk (at least 1)}
        {--json          : Output the Affected Tests as JSON}
        {--paths         : Output only the test file paths, one per line}
        {--allow-stale   : Analyze even if the manifest appears stale}
        {--allow-partial : Analyze even if the manifest scope is partial}';

    protected $description = 'List the tests affected by a changed artifact or a set of changed files';

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer, AffectedTestFinder $finder): int
    {
        $artifact = $this->argument('artifact');
        $fromStdin = (bool) $this->option('stdin');

        if (($artifact !== null) === $fromStdin) {
            $this->error('Pass exactly one of the artifact argument or --stdin.');

            return self::FAILURE;
        }

        if ($this->option('json') && $this->option('paths')) {
            $this->error('The --json and --paths options are mutually exclusive.');

            return self::FAILURE;
        }

        $depth = $this->depth();

        if ($depth === null) {
            return self::FAILURE;
        }

        try {
            $manifest = $reader->read($this->resolveManifestPath());
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $refusal = ManifestScopeGuard::refusal(
            $manifest,
            $this->isStale($manifest),
            (bool) $this->option('allow-stale'),
            (bool) $this->option('allow-partial'),
            'analyze anyway',
        );

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $unmapped = [];

        if ($fromStdin) {
            ['starts' => $starts, 'unmapped' => $unmapped] = $finder->startsForPaths($manifest, $this->stdinLines(), app()->basePath());
            $subject = count($starts).' changed artifact(s)';
        } else {
            $starts = $this->resolveStart($analyzer, $manifest, (string) $artifact);

            if ($starts === null) {
                return self::FAILURE;
            }

            $subject = $starts[0];
        }

        $tests = $finder->find($manifest, $starts, $depth);
        $direct = array_values(array_filter($tests, fn (AffectedTest $test): bool => $test->directlyAffected()));
        $indirect = array_values(array_filter($tests, fn (AffectedTest $test): bool => ! $test->directlyAffected()));

        if ($this->option('json')) {
            $this->line(json_encode(
                ['directly_affected' => $direct, 'indirectly_affected' => $indirect, 'unmapped' => $unmapped],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        if ($this->option('paths')) {
            $this->renderPaths($tests, $unmapped);

            return self::SUCCESS;
        }

        $this->line("Affected tests for {$subject}, depth {$depth}");

        if ($tests === []) {
            $this->line('');
            $this->line('No affected tests found.');
        }

        $this->renderSection('Directly affected', array_map($this->testLine(...), $direct));
        $this->renderSection('Indirectly affected', array_map($this->testLine(...), $indirect));
        $this->renderSection('Unmapped', $unmapped);

        return self::SUCCESS;
    }

    /**
     * The single start an artifact argument denotes, or null after
     * reporting an unknown or ambiguous one in necromancer:impact's words.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>|null
     */
    private function resolveStart(ImpactAnalyzer $analyzer, array $manifest, string $input): ?array
    {
        $candidates = $analyzer->startCandidates($manifest, $input);

        if ($candidates === []) {
            $this->error("No artifact matches '{$input}'. Pass an exact Artifact ID, a fully-qualified class name, or a referenced domain:/flow:/adr: ID.");

            return null;
        }

        if (count($candidates) > 1) {
            $this->error("'{$input}' matches several artifacts. Pass one of these Artifact IDs instead:");

            foreach ($candidates as $candidate) {
                $this->line("  {$candidate}");
            }

            return null;
        }

        return $candidates;
    }

    private function testLine(AffectedTest $test): string
    {
        if ($test->node === null) {
            return "{$test->file}  (changed)";
        }

        return "{$test->file}  ← via {$test->viaLabel}".($test->match() === 'namespace' ? '  (namespace match)' : '');
    }

    /**
     * @param  list<string>  $lines
     */
    private function renderSection(string $heading, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->line('');
        $this->line($heading);

        foreach ($lines as $line) {
            $this->line("  {$line}");
        }
    }

    /**
     * Test files alone on stdout so they can be piped; unmapped paths go
     * to stderr.
     *
     * @param  list<AffectedTest>  $tests
     * @param  list<string>  $unmapped
     */
    private function renderPaths(array $tests, array $unmapped): void
    {
        foreach (array_unique(array_map(fn (AffectedTest $test): string => $test->file, $tests)) as $file) {
            $this->output->writeln($file);
        }

        $output = $this->output->getOutput();
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ($unmapped as $path) {
            $stderr->writeln("Unmapped: {$path}");
        }
    }

    /**
     * @return list<string>
     */
    private function stdinLines(): array
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $contents = stream_get_contents($stream ?? STDIN);

        return $contents === false ? [] : (preg_split('/\R/', $contents) ?: []);
    }

    private function depth(): ?int
    {
        $depth = (string) $this->option('depth');

        if (! ctype_digit($depth) || (int) $depth < 1) {
            $this->error('The --depth option must be an integer of at least 1.');

            return null;
        }

        return (int) $depth;
    }
}
