<?php

use Illuminate\Support\Facades\File;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;

function readsManifestHost(): object
{
    return new class
    {
        use ReadsManifest;

        public function checkStale(array $manifest): bool
        {
            return $this->isStale($manifest);
        }
    };
}

afterEach(function () {
    File::deleteDirectory(base_path('app'));
    File::deleteDirectory(base_path('database'));
});

test('isStale() returns false for a manifest with no source hashes and no newer files', function () {
    $manifest = ['meta' => ['generated_at' => now()->addMinute()->toIso8601String()], 'artifacts' => []];

    expect(readsManifestHost()->checkStale($manifest))->toBeFalse();
});

test('isStale() returns true when a stored source hash no longer matches the file on disk', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Order.php'), '<?php class Order {}');

    $manifest = [
        'meta' => ['generated_at' => now()->addMinute()->toIso8601String()],
        'artifacts' => [
            'models' => [
                ['class' => 'App\\Order', 'source' => ['file' => 'app/Order.php', 'hash' => 'not-the-real-hash']],
            ],
        ],
    ];

    expect(readsManifestHost()->checkStale($manifest))->toBeTrue();
});

test('isStale() returns true when a source file referenced by hash no longer exists', function () {
    $manifest = [
        'meta' => ['generated_at' => now()->addMinute()->toIso8601String()],
        'artifacts' => [
            'models' => [
                ['class' => 'App\\Missing', 'source' => ['file' => 'app/DoesNotExist.php', 'hash' => 'abc123']],
            ],
        ],
    ];

    expect(readsManifestHost()->checkStale($manifest))->toBeTrue();
});

test('isStale() returns true when app files are newer than generated_at', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    $manifest = ['meta' => ['generated_at' => '1970-01-01T00:00:00+00:00'], 'artifacts' => []];

    expect(readsManifestHost()->checkStale($manifest))->toBeTrue();
});

test('isStale() ignores non-PHP files such as database.sqlite when checking mtime', function () {
    File::ensureDirectoryExists(base_path('database'));
    File::put(base_path('database/database.sqlite'), '');

    $manifest = ['meta' => ['generated_at' => '1970-01-01T00:00:00+00:00'], 'artifacts' => []];

    expect(readsManifestHost()->checkStale($manifest))->toBeFalse();
});

test('isStale() still returns true when a PHP file under database/ is newer than generated_at', function () {
    File::ensureDirectoryExists(base_path('database/migrations'));
    File::put(base_path('database/migrations/create_orders_table.php'), '<?php');

    $manifest = ['meta' => ['generated_at' => '1970-01-01T00:00:00+00:00'], 'artifacts' => []];

    expect(readsManifestHost()->checkStale($manifest))->toBeTrue();
});
