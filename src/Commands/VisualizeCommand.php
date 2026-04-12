<?php

namespace Devlin\ModelAnalyzer\Commands;

use Illuminate\Console\Command;
use Devlin\ModelAnalyzer\ModelAnalyzer;
use Devlin\ModelAnalyzer\Support\HtmlGraphGenerator;
use Devlin\ModelAnalyzer\Support\ErdGenerator;

class VisualizeCommand extends Command
{
    /** @var string */
    protected $signature = 'model-analyzer:visualize
                            {--output=  : Output file path (default: model-relationships.html or model-erd.html)}
                            {--models=  : Comma-separated list of model names to include}
                            {--erd : Generate an Entity Relationship Diagram instead of a force-directed graph}';

    /** @var string */
    protected $description = 'Generate an interactive HTML graph of model relationships';

    /**
     * @param ModelAnalyzer $analyzer
     * @return int
     */
    public function handle(ModelAnalyzer $analyzer)
    {
        $isErd = $this->option('erd');
        $defaultFile = $isErd ? 'model-erd.html' : 'model-relationships.html';
        $outputPath = $this->option('output') ?: getcwd() . '/' . $defaultFile;
        $onlyModels = $this->parseCommaSeparated($this->option('models'));

        $this->newLine();
        $this->info('Analyzing model relationships...');

        try {
            $result = $analyzer->analyze($onlyModels ?: null);
        } catch (\Throwable $e) {
            $this->error('Analysis failed: ' . $e->getMessage());
            return 1;
        }

        if (count($result->models) === 0) {
            $this->warn('No models were discovered. Check model_paths in config/model-analyzer.php.');
            return 1;
        }

        if ($isErd) {
            $generator = new ErdGenerator();
        } else {
            $generator = new HtmlGraphGenerator();
        }

        $html = $generator->generate($result);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            $this->error("Directory does not exist: {$dir}");
            return 1;
        }

        file_put_contents($outputPath, $html);

        $this->newLine();
        $label = $isErd ? 'ERD generated' : 'Graph generated';
        $this->info("{$label}: {$outputPath}");
        $this->line(sprintf(
            '  %d models, %d relationships, health score: %d/100',
            count($result->models),
            $result->totalRelationships(),
            $result->healthScore
        ));
        $this->newLine();

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
