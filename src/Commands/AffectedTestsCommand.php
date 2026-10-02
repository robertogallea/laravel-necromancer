<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Commands\Concerns\ResolvesImpactStart;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ManifestScopeGuard;
use LaravelNecromancer\Relationships\AffectedTest;
use LaravelNecromancer\Relationships\AffectedTestFinder;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\TestMatch;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AffectedTestsCommand extends Command
{
    use ReadsManifest;
    use ResolvesImpactStart;

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

        $depth = $this->impactDepth();

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
            $start = $this->resolveImpactStart($analyzer, $manifest, (string) $artifact);

            if ($start === null) {
                return self::FAILURE;
            }

            $starts = [$start];
            $subject = $start;
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

    private function testLine(AffectedTest $test): string
    {
        if ($test->node === null) {
            return "{$test->file}  (changed)";
        }

        $qualifier = TestMatch::qualifierFor($test->match());

        return "{$test->file}  ← via {$test->viaLabel}".($qualifier !== '' ? "  ({$qualifier})" : '');
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

        foreach ($unmapped as $path) {
            $this->stderr()->writeln("Unmapped: {$path}");
        }
    }

    /**
     * Under --paths, errors go to stderr so nothing but test paths can
     * reach a pipe.
     *
     * @param  string  $string
     * @param  int|string|null  $verbosity
     */
    public function error($string, $verbosity = null): void
    {
        if ($this->option('paths')) {
            $this->stderr()->writeln("<error>{$string}</error>");

            return;
        }

        parent::error($string, $verbosity);
    }

    private function stderr(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
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
}
