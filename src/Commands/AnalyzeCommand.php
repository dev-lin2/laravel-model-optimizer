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

        $result = $analyzer->analyze($onlyModels ?: null);

        $format = strtolower($this->option('format'));

        if ($format === 'json') {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT));
        } else {
            $formatter = new OutputFormatter($this->output);
            $formatter->printAnalysis($result);
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
