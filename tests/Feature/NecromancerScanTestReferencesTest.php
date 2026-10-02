<?php

use Illuminate\Support\Facades\File;
use LaravelNecromancer\Collection\TestCollector;

test('the scan records the app classes and route names a test file references', function () {
    $directory = base_path('storage/framework/testing/test-references');
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/CreateOrderTest.php", <<<'PHP'
        <?php

        use App\Models\Order;

        it('creates an order', function () {
            $this->post(route('orders.store'))->assertCreated();

            expect(Order::query()->count())->toBe(1);
        });
        PHP);

    app()->bind(TestCollector::class, fn ($app): TestCollector => new TestCollector($app, [['path' => $directory, 'type' => 'feature']]));
    $path = base_path('storage/framework/testing/necromancer-test-references.json');

    try {
        $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'tests'])->assertSuccessful();

        $tests = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR)['artifacts']['tests'];

        expect($tests)->toHaveCount(1)
            ->and($tests[0]['references'])->toBe([
                ['kind' => 'class', 'target' => 'App\\Models\\Order'],
                ['kind' => 'route', 'target' => 'orders.store'],
            ]);
    } finally {
        File::deleteDirectory($directory);
        File::delete($path);
    }
});
