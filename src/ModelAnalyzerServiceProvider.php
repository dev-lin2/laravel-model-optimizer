<?php

namespace Devlin\ModelAnalyzer;

use Illuminate\Support\ServiceProvider;
use Devlin\ModelAnalyzer\Commands\AnalyzeCommand;
use Devlin\ModelAnalyzer\Commands\HealthCommand;
use Devlin\ModelAnalyzer\Commands\ListModelsCommand;

class ModelAnalyzerServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/model-analyzer.php',
            'model-analyzer'
        );

        $this->app->singleton(ModelAnalyzer::class, function ($app) {
            return new ModelAnalyzer(
                config('model-analyzer')
            );
        });
    }

    /**
     * Bootstrap package services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/model-analyzer.php' => config_path('model-analyzer.php'),
            ], 'model-analyzer-config');

            $this->commands([
                AnalyzeCommand::class,
                HealthCommand::class,
                ListModelsCommand::class,
            ]);
        }
    }
}
