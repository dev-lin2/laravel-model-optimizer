<?php

namespace Devlin\ModelAnalyzer\Commands;

use Devlin\ModelAnalyzer\Commands\Concerns\InteractsWithSchemaSources;
use Devlin\ModelAnalyzer\ModelAnalyzer;
use Devlin\ModelAnalyzer\Schema\SchemaSourceFactory;
use Devlin\ModelAnalyzer\Support\DocsGenerator;
use Devlin\ModelAnalyzer\Support\ModelMapBuilder;
use Illuminate\Console\Command;

/**
 * Writes a data dictionary describing the schema as it is.
 *
 * Never fails on a missing source: an unavailable database or migration
 * directory produces a document that says so, and still exits 0.
 */
class DocsCommand extends Command
{
    use InteractsWithSchemaSources;

    /** @var string */
    protected $signature = 'model-analyzer:docs
                            {--source=database : Schema source: database, migrations, or both}
                            {--format=md       : Output format: md or html}
                            {--output=         : Output file path}
                            {--models=         : Comma-separated list of model names to include}
                            {--issues=all      : Which notices to print: all, errors, warnings, none}
                            {--hide-errors     : Never print error notices}
                            {--hide-warnings   : Never print warning notices}';

    /** @var string */
    protected $description = 'Generate schema documentation from the database or from migrations';

    /**
     * @param  ModelAnalyzer $analyzer
     * @return int
     */
    public function handle(ModelAnalyzer $analyzer)
    {
        $selector = $this->resolveSelector();
        $format   = strtolower(trim((string) $this->option('format')));

        if ($format === '' || $format === 'markdown') {
            $format = 'md';
        }

        if (!in_array($format, ['md', 'html'], true)) {
            $this->errorIssue(sprintf('Unknown --format "%s". Falling back to "md".', $this->option('format')));
            $format = 'md';
        }

        $config    = (array) config('model-analyzer', []);
        $factory   = $this->sourceFactory($config);
        $snapshots = $factory->snapshots($selector);

        $this->newLine();
        $this->line('Schema sources:');
        $this->reportSources($snapshots);

        $primary = SchemaSourceFactory::primary($snapshots);

        if (!$primary->available) {
            $this->warnIssue('No schema source was readable; writing an empty document.');
        }

        $models = ModelMapBuilder::build($analyzer, $this->parseCommaSeparated($this->option('models')));

        $generator = new DocsGenerator($models);
        $content   = $format === 'html'
            ? $generator->generateHtml($primary)
            : $generator->generateMarkdown($primary);

        $path = $this->resolveOutputPath($format, $primary->source);

        if ($path === null) {
            return 0;
        }

        if (@file_put_contents($path, $content) === false) {
            $this->errorIssue('Could not write to ' . $path);

            return 0;
        }

        $this->newLine();
        $this->info('Documentation written: ' . $path);
        $this->line(sprintf(
            '  source: %s, %d tables, %d models matched',
            $primary->source,
            count($primary->tables),
            count(array_intersect(array_keys($models), $primary->tableNames()))
        ));
        $this->newLine();

        return 0;
    }

    /**
     * @param  string $format
     * @param  string $source
     * @return string|null
     */
    private function resolveOutputPath($format, $source)
    {
        $path = (string) $this->option('output');

        if (trim($path) === '') {
            $path = getcwd() . '/schema-docs-' . $source . '.' . $format;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            $this->errorIssue('Directory does not exist: ' . $dir);

            return null;
        }

        return $path;
    }

    /**
     * @param  string|null $value
     * @return string[]
     */
    private function parseCommaSeparated($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
