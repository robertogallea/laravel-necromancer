<?php

use LaravelNecromancer\Okf\BundleSwap;

function swapTempDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-okf-swap-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeSwapTree(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    @chmod($path, 0755);

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? removeSwapTree($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/necromancer-okf-swap-*') as $dir) {
        removeSwapTree($dir);
    }
});

test('swap() moves the temp directory into an empty output path', function () {
    $parent = swapTempDir();
    $temp = $parent.'/staged';
    mkdir($temp);
    file_put_contents($temp.'/marker.md', 'new content');

    $output = $parent.'/bundle';

    (new BundleSwap)->swap($temp, $output);

    expect(is_file($output.'/marker.md'))->toBeTrue()
        ->and(is_dir($temp))->toBeFalse();
});

test('swap() replaces an existing output directory with the temp directory', function () {
    $parent = swapTempDir();
    $temp = $parent.'/staged';
    mkdir($temp);
    file_put_contents($temp.'/marker.md', 'new content');

    $output = $parent.'/bundle';
    mkdir($output);
    file_put_contents($output.'/marker.md', 'old content');

    (new BundleSwap)->swap($temp, $output);

    expect(file_get_contents($output.'/marker.md'))->toBe('new content')
        ->and(is_dir($temp))->toBeFalse()
        ->and(is_dir($output.'.bak'))->toBeFalse();
});

test('swap() restores the original output directory when it cannot move the old one aside', function () {
    if (posix_getuid() === 0) {
        $this->markTestSkipped('Permission checks are bypassed when running as root.');
    }

    $parent = swapTempDir();
    $temp = $parent.'/staged';
    mkdir($temp);
    file_put_contents($temp.'/marker.md', 'new content');

    $output = $parent.'/bundle';
    mkdir($output);
    file_put_contents($output.'/marker.md', 'do-not-touch');

    chmod($parent, 0555);

    try {
        expect(fn () => (new BundleSwap)->swap($temp, $output))->toThrow(RuntimeException::class);
    } finally {
        chmod($parent, 0755);
    }

    expect(file_get_contents($output.'/marker.md'))->toBe('do-not-touch');
});
