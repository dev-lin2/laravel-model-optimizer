<?php

namespace Devlin\ModelAnalyzer\Commands;

use Illuminate\Console\Command;
use Devlin\ModelAnalyzer\ModelAnalyzer;
use Devlin\ModelAnalyzer\Support\OutputFormatter;

class AnalyzeCommand extends Command
{
    /** @var string */
    protected $signature = 'model-analyzer:analyze
                            {--strict          : Exit with non-zero code if any issues are found}
                            {--format=cli      : Output format: cli or json}
                            {--debug           : Print model-by-model progress for troubleshooting}
                            {--models=         : Comma-separated list of model names to analyze}
                            {--tables=         : Comma-separated list of tables to analyze}';

    /** @var string */
    protected $description = 'Analyze Eloquent model relationships against the database schema';

    /**
     * Execute the console command.
     *
     * @param ModelAnalyzer $analyzer
     * @return int
     */
    public function handle(ModelAnalyzer $analyzer)
    {
        $onlyModels = $this->parseCommaSeparated($this->option('models'));
        $format = strtolower($this->option('format'));
        $debug = (bool) $this->option('debug');
        $analysisState = [
            'started' => false,
            'finished' => false,
            'current_model' => null,
        ];

        $this->registerTerminationDiagnostics($analysisState);

        $result = null;
        $progress = function ($event, array $payload = []) use (&$analysisState, $debug) {
            if ($event === 'model_start') {
                $analysisState['current_model'] = $payload['class'] ?? null;

                if ($debug) {
                    $this->line(sprintf('Analyzing model: %s', $payload['class']));
                }
                return;
            }

            if ($event === 'model_error') {
                $analysisState['current_model'] = null;

                if ($debug) {
                    $this->warn(sprintf(
                        'Model error in %s: %s',
                        $payload['class'] ?? 'unknown',
                        $payload['error'] ?? 'unknown error'
                    ));
                }
                return;
            }

            if ($event === 'model_done') {
                $analysisState['current_model'] = null;
                return;
            }

            if ($event === 'phase_start' && $debug) {
                $phase = $payload['phase'] ?? 'unknown';

                if ($phase === 'schema') {
                    $this->line('Building schema snapshot...');
                } elseif ($phase === 'detector') {
                    $this->line(sprintf('Running detector: %s', $payload['name'] ?? 'unknown'));
                } elseif ($phase === 'health_score') {
                    $this->line('Calculating health score...');
                }
            }
        };

        if ($format !== 'json') {
            $this->newLine();
            $this->info('Scanning models and analyzing relationships...');
        }

        $startedAt = microtime(true);
        $analysisState['started'] = true;

        try {
            $result = $analyzer->analyze($onlyModels ?: null, $progress);
        } catch (\Throwable $e) {
            $analysisState['finished'] = true;
            $this->error('Analysis failed: ' . $e->getMessage());
            return 1;
        }

        $analysisState['finished'] = true;

        if ($format !== 'json') {
            $duration = microtime(true) - $startedAt;
            $this->info(sprintf('Analysis completed in %.2fs.', $duration));
            $this->newLine(2);
        }

        if ($format === 'json') {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT));
        } else {
            $formatter = new OutputFormatter($this->output);
            $formatter->printAnalysis($result);

            if (count($result->models) === 0) {
                $this->warn('No Eloquent models were discovered. Check model_paths in config/model-analyzer.php.');
            }
        }

        $hasErrors = count($result->getErrors()) > 0;
        $strict    = $this->option('strict') || config('model-analyzer.strict_mode');

        if ($hasErrors) {
            return 1;
        }

        if ($strict && count($result->getWarnings()) > 0) {
            return 1;
        }

        return 0;
    }

    /**
     * @param string|null $value
     * @return string[]
     */
    private function parseCommaSeparated($value)
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * Print actionable diagnostics if the PHP process terminates unexpectedly
     * during model analysis (fatal error / die / exit).
     *
     * @param array<string, mixed> $analysisState
     * @return void
     */
    private function registerTerminationDiagnostics(array &$analysisState)
    {
        register_shutdown_function(function () use (&$analysisState) {
            if (empty($analysisState['started']) || !empty($analysisState['finished'])) {
                return;
            }

            $currentModel = isset($analysisState['current_model']) && $analysisState['current_model'] !== null
                ? $analysisState['current_model']
                : 'unknown model';

            $lastError = error_get_last();
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

            if (is_array($lastError) && isset($lastError['type']) && in_array($lastError['type'], $fatalTypes, true)) {
                $message = sprintf(
                    '[model-analyzer] Fatal error while analyzing model %s: %s in %s:%d',
                    $currentModel,
                    $lastError['message'] ?? 'unknown error',
                    $lastError['file'] ?? 'unknown file',
                    $lastError['line'] ?? 0
                );

                fwrite(STDERR, PHP_EOL . $message . PHP_EOL);
                return;
            }

            $message = sprintf(
                '[model-analyzer] Analysis terminated while analyzing model %s. This is usually caused by exit()/die()/dd() or a process-level fatal error.',
                $currentModel
            );
            fwrite(STDERR, PHP_EOL . $message . PHP_EOL);
        });
    }
}
