<?php

use LaravelNecromancer\Okf\ArtifactConcept;
use LaravelNecromancer\Okf\AtomicBundleWriter;

function atomicWriterTempDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-atomic-writer-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/necromancer-atomic-writer-*') as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
});

test('write() writes each concept file and a bundle.json merging the given index fields', function () {
    $output = atomicWriterTempDir().'/bundle';
    $concept = new ArtifactConcept('jobs:App\\Jobs\\X', 'app-jobs-x-abcd1234.md', 'content');

    (new AtomicBundleWriter)->write($output, [$concept], ['generated_at' => '2026-08-08T00:00:00+00:00', 'artifact_count' => 1]);

    expect(is_file($output.'/artifacts/app-jobs-x-abcd1234.md'))->toBeTrue()
        ->and(file_get_contents($output.'/artifacts/app-jobs-x-abcd1234.md'))->toBe("content\n");

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index['okf_version'])->toBe('0.2')
        ->and($index['necromancer_schema_version'])->toBe(1)
        ->and($index['artifact_count'])->toBe(1)
        ->and($index['generated_at'])->toBe('2026-08-08T00:00:00+00:00');
});

test('write() replaces a pre-existing bundle at the same output path', function () {
    $output = atomicWriterTempDir().'/bundle';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/artifacts/stale.md', 'old');

    (new AtomicBundleWriter)->write($output, [], ['artifact_count' => 0]);

    expect(is_file($output.'/artifacts/stale.md'))->toBeFalse();
});

test('write() writes a README.md file when given one', function () {
    $output = atomicWriterTempDir().'/bundle';

    (new AtomicBundleWriter)->write($output, [], ['artifact_count' => 0], 'Hello README');

    expect(file_get_contents($output.'/README.md'))->toBe("Hello README\n");
});

test('write() writes no README.md file when none is given', function () {
    $output = atomicWriterTempDir().'/bundle';

    (new AtomicBundleWriter)->write($output, [], ['artifact_count' => 0]);

    expect(is_file($output.'/README.md'))->toBeFalse();
});

test('write() replaces a pre-existing README.md at the same output path', function () {
    $output = atomicWriterTempDir().'/bundle';
    mkdir($output, 0755, true);
    file_put_contents($output.'/README.md', 'old readme');

    (new AtomicBundleWriter)->write($output, [], ['artifact_count' => 0], 'new readme');

    expect(file_get_contents($output.'/README.md'))->toBe("new readme\n");
});
