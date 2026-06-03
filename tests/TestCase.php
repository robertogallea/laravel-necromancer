<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests;

use Illuminate\Foundation\Application;
use LaravelNecromancer\NecromancerServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public static function applicationBasePath(): string
    {
        return dirname(__DIR__);
    }

    protected function setUp(): void
    {
        $base = static::applicationBasePath();

        foreach ([
            "{$base}/bootstrap/cache",
            "{$base}/storage/framework/testing",
            "{$base}/storage/logs",
        ] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        // The package root has no App\ namespace; set one so getNamespace() does
        // not throw when the default ModelCollector/CommandCollector scan app/
        (function (): void {
            $this->namespace = 'App\\';
        })->call($app);
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [NecromancerServiceProvider::class];
    }
}
