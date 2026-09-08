<?php

namespace Devlin\ModelAnalyzer\Commands;

use Illuminate\Console\Command;
use Devlin\ModelAnalyzer\Commands\Concerns\InteractsWithSchemaSources;
use Devlin\ModelAnalyzer\ModelAnalyzer;
use Devlin\ModelAnalyzer\Schema\SchemaSourceFactory;
use Devlin\ModelAnalyzer\Support\HtmlGraphGenerator;
use Devlin\ModelAnalyzer\Support\ErdGenerator;
use Devlin\ModelAnalyzer\Support\ModelMapBuilder;
use Devlin\ModelAnalyzer\Support\SvgGraphGenerator;
use Devlin\ModelAnalyzer\Support\SvgErdGenerator;

class VisualizeCommand extends Command
{
    use InteractsWithSchemaSources;

    /** @var string Summary line describing the last generation */
    private $lastSummary = '';

    /** @var string */
    protected $signature = 'model-analyzer:visualize
                            {--output=  : Output file path (default: model-relationships.html or model-erd.html)}
                            {--models=  : Comma-separated list of model names to include}
                            {--erd : Generate an Entity Relationship Diagram instead of a force-directed graph}
                            {--format=html : Output format (html or svg)}
                            {--source=  : Schema source for ERDs: database, migrations, or both}
                            {--issues=all : Which notices to print: all, errors, warnings, none}
                            {--hide-errors : Never print error notices}
                            {--hide-warnings : Never print warning notices}';

    /** @var string */
    protected $description = 'Generate an interactive HTML or static SVG graph of model relationships';

    /**
     * @param ModelAnalyzer $analyzer
     * @return int
     */
    public function handle(ModelAnalyzer $analyzer)
    {
        $isErd = $this->option('erd');
        $format = strtolower($this->option('format') ?: 'html');

        if (!in_array($format, ['html', 'svg'])) {
            $this->error("Invalid format '{$format}'. Supported formats: html, svg");
            return 1;
        }

        $ext = $format === 'svg' ? 'svg' : 'html';
        $defaultFile = $isErd ? "model-erd.{$ext}" : "model-relationships.{$ext}";
        $outputPath = $this->option('output') ?: getcwd() . '/' . $defaultFile;
        $onlyModels = $this->parseCommaSeparated($this->option('models'));

        $rawSource = (string) $this->option('source');
        $useSource = trim($rawSource) !== '';

        if ($useSource && !$isErd) {
            $this->warnIssue('--source only affects --erd output; the relationship graph is always model-driven.');
            $useSource = false;
        }

        $this->newLine();

        if ($useSource) {
            $html = $this->generateFromSource($analyzer, $format, $onlyModels);

            if ($html === null) {
                return 0;
            }

            $summary = $this->lastSummary;
        } else {
            $this->info('Analyzing model relationships...');

            try {
                $result = $analyzer->analyze($onlyModels ?: null);
            } catch (\Throwable $e) {
                $this->errorIssue('Analysis failed: ' . $e->getMessage());
                return 0;
            }

            if (count($result->models) === 0) {
                $this->warnIssue('No models were discovered. Check model_paths in config/model-analyzer.php.');
                return 0;
            }

            if ($isErd) {
                $generator = $format === 'svg' ? new SvgErdGenerator() : new ErdGenerator();
            } else {
                $generator = $format === 'svg' ? new SvgGraphGenerator() : new HtmlGraphGenerator();
            }

            $html = $generator->generate($result);

            $summary = sprintf(
                '  %d models, %d relationships, health score: %d/100',
                count($result->models),
                $result->totalRelationships(),
                $result->healthScore
            );
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            $this->errorIssue("Directory does not exist: {$dir}");
            return 0;
        }

        if (@file_put_contents($outputPath, $html) === false) {
            $this->errorIssue("Could not write to {$outputPath}");
            return 0;
        }

        $this->newLine();
        $label = $isErd ? 'ERD generated' : 'Graph generated';
        $this->info("{$label}: {$outputPath}");
        $this->line($summary);
        $this->newLine();

        return 0;
    }

    /**
     * Build ERD markup from a schema source rather than from models.
     *
     * @param  ModelAnalyzer $analyzer
     * @param  string        $format
     * @param  string[]      $onlyModels
     * @return string|null
     */
    private function generateFromSource(ModelAnalyzer $analyzer, $format, array $onlyModels)
    {
        $selector  = $this->resolveSelector();
        $config    = (array) config('model-analyzer', []);
        $factory   = $this->sourceFactory($config);
        $snapshots = $factory->snapshots($selector);

        $this->line('Schema sources:');
        $this->reportSources($snapshots);

        $snapshot = SchemaSourceFactory::primary($snapshots);

        if (!$snapshot->available) {
            $this->warnIssue('No schema source was readable; generating an empty diagram.');
        } elseif ($snapshot->isEmpty()) {
            $this->warnIssue('The selected schema source contains no tables; generating an empty diagram.');
        }

        $models = ModelMapBuilder::build($analyzer, $onlyModels ?: null);

        $generator = $format === 'svg' ? new SvgErdGenerator() : new ErdGenerator();

        $this->lastSummary = sprintf(
            '  source: %s, %d tables, %d models matched',
            $snapshot->source,
            count($snapshot->tables),
            count(array_intersect(array_keys($models), $snapshot->tableNames()))
        );

        return $generator->generateFromSnapshot($snapshot, $models);
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
