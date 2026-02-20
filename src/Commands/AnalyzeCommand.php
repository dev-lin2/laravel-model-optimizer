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
        $result = null;
        $progress = $debug ? function ($event, array $payload = []) {
            if ($event === 'model_start') {
                $this->line(sprintf('Analyzing model: %s', $payload['class']));
            }
        } : null;

        if ($format === 'json') {
            $result = $analyzer->analyze($onlyModels ?: null, $progress);
        } else {
            $this->newLine();
            $this->info('Scanning models and analyzing relationships...');
            $startedAt = microtime(true);

            try {
                $result = $analyzer->analyze($onlyModels ?: null, $progress);
            } catch (\Throwable $e) {
                $this->error('Analysis failed: ' . $e->getMessage());
                return 1;
            }

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
}
