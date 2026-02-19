<?php

namespace Devlin\ModelAnalyzer\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Devlin\ModelAnalyzer\ModelAnalyzerServiceProvider;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            ModelAnalyzerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Use SQLite in-memory for fast, isolated tests
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Point package at our fixture models
        $app['config']->set('model-analyzer.model_paths', [
            __DIR__ . '/Fixtures/Models',
        ]);

        $app['config']->set('model-analyzer.excluded_tables', [
            'migrations',
        ]);
    }

    /**
     * Run an Artisan command and return [exitCode, outputString].
     *
     * Laravel 8 does not route the BufferedOutput passed to Artisan::call()
     * through to the command's OutputStyle. Binding OutputStyle in the
     * container before the call is the only reliable way to capture output.
     */
    protected function captureArtisanOutput(string $command, array $args = []): array
    {
        $buf = new BufferedOutput();

        $this->app->bind(OutputStyle::class, function () use ($buf) {
            return new OutputStyle(new ArrayInput([]), $buf);
        });

        $exitCode = Artisan::call($command, $args, $buf);

        return [$exitCode, $buf->fetch()];
    }
}
