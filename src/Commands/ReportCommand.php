<?php

namespace Devlin\ModelAnalyzer\Commands;

use Devlin\ModelAnalyzer\Analyzers\MissingForeignKeyDetector;
use Devlin\ModelAnalyzer\Commands\Concerns\InteractsWithSchemaSources;
use Devlin\ModelAnalyzer\Schema\SchemaDiff;
use Devlin\ModelAnalyzer\Schema\SchemaSourceFactory;
use Devlin\ModelAnalyzer\Support\ReportGenerator;
use Illuminate\Console\Command;

/**
 * Reports schema findings: missing foreign keys, unindexed FK candidates and
 * drift between sources.
 *
 * Exits 0 even when findings exist, so it can never break a pipeline by
 * accident. Opt into a non-zero exit with --fail-on-findings.
 */
class ReportCommand extends Command
{
    use InteractsWithSchemaSources;

    /** @var string */
    protected $signature = 'model-analyzer:report
                            {--source=database   : Schema source: database, migrations, or both}
                            {--format=cli        : Output format: cli, md, json, or html}
                            {--output=           : Write the report to this file instead of stdout}
                            {--issues=all        : Which notices to include: all, errors, warnings, none}
                            {--hide-errors       : Never include error notices}
                            {--hide-warnings     : Never include warning notices}
                            {--fail-on-findings  : Exit non-zero when findings exist (for CI)}';

    /** @var string */
    protected $description = 'Report missing foreign keys, unindexed keys and schema drift';

    /**
     * @return int
     */
    public function handle()
    {
        $selector = $this->resolveSelector();
        $format   = strtolower(trim((string) $this->option('format')));

        if ($format === '' || $format === 'markdown') {
            $format = $format === '' ? 'cli' : 'md';
        }

        if (!in_array($format, ['cli', 'md', 'json', 'html'], true)) {
            $this->errorIssue(sprintf('Unknown --format "%s". Falling back to "cli".', $this->option('format')));
            $format = 'cli';
        }

        $config    = (array) config('model-analyzer', []);
        $factory   = $this->sourceFactory($config);
        $snapshots = $factory->snapshots($selector);

        $detector = new MissingForeignKeyDetector();
        $findings = [];

        foreach ($snapshots as $name => $snapshot) {
            try {
                $findings[$name] = $detector->detect($snapshot);
            } catch (\Throwable $e) {
                $findings[$name] = [];
                $snapshot->addWarning('Foreign key analysis failed: ' . $e->getMessage());
            }
        }

        $diff = null;

        if ($selector === SchemaSourceFactory::SOURCE_BOTH
            && isset($snapshots['database'], $snapshots['migrations'])) {
            $diff = (new SchemaDiff($snapshots['database'], $snapshots['migrations']))->compute();
        }

        $generator = new ReportGenerator($this->showErrors(), $this->showWarnings());
        $report    = $generator->build($snapshots, $findings, $diff);

        if ($format === 'cli') {
            $this->renderCli($report);
        } else {
            $content = $format === 'json'
                ? $generator->toJson($report)
                : ($format === 'html' ? $generator->toHtml($report) : $generator->toMarkdown($report));

            if (!$this->writeOrEcho($content, $format)) {
                return 0;
            }
        }

        if ($this->option('fail-on-findings') && $this->totalFindings($report) > 0) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array $report
     * @return int
     */
    private function totalFindings(array $report)
    {
        return $report['summary']['missing_foreign_keys']
            + $report['summary']['drift_items'];
    }

    /**
     * @param  string $content
     * @param  string $format
     * @return bool True when output was handled without a write failure
     */
    private function writeOrEcho($content, $format)
    {
        $path = (string) $this->option('output');

        if (trim($path) === '') {
            $this->line($content);

            return true;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            $this->errorIssue('Directory does not exist: ' . $dir);

            return false;
        }

        if (@file_put_contents($path, $content) === false) {
            $this->errorIssue('Could not write to ' . $path);

            return false;
        }

        $this->newLine();
        $this->info('Report written: ' . $path);
        $this->newLine();

        return true;
    }

    /**
     * @param  array $report
     * @return void
     */
    private function renderCli(array $report)
    {
        $this->newLine();
        $this->line('<options=bold>Schema Report</>');
        $this->newLine();

        $this->line('Sources:');

        foreach ($report['sources'] as $source) {
            $this->line(sprintf(
                '  %s — %s, %d tables',
                $source['source'],
                $source['available'] ? '<info>available</info>' : '<comment>unavailable</comment>',
                $source['tables']
            ));

            foreach ($source['errors'] as $error) {
                $this->line('    <fg=red>error</>   ' . $error);
            }

            foreach ($source['warnings'] as $warning) {
                $this->line('    <fg=yellow>warning</> ' . $warning);
            }
        }

        $this->newLine();

        foreach ($report['missing_foreign_keys'] as $source => $items) {
            $this->line(sprintf('<options=bold>Missing foreign keys (%s): %d</>', $source, count($items)));

            if (count($items) === 0) {
                $this->line('  <info>none</info>');
                $this->newLine();
                continue;
            }

            $rows = [];

            foreach ($items as $item) {
                $rows[] = [
                    $item['table'],
                    $item['column'],
                    $item['on'] . '.' . $item['references'],
                    !empty($item['has_index']) ? 'yes' : 'no',
                ];
            }

            $this->table(['Table', 'Column', 'References', 'Indexed'], $rows);
            $this->newLine();
        }

        if (isset($report['drift']) && $report['drift'] !== null) {
            $this->renderCliDrift($report['drift']);
        }

        $this->line(sprintf(
            'Summary: %d missing FKs, %d unindexed candidates, %d drift items',
            $report['summary']['missing_foreign_keys'],
            $report['summary']['unindexed_candidates'],
            $report['summary']['drift_items']
        ));
        $this->newLine();
    }

    /**
     * @param  array $drift
     * @return void
     */
    private function renderCliDrift(array $drift)
    {
        $this->line('<options=bold>Schema drift</>');

        if (!$drift['comparable']) {
            $this->line('  <comment>' . $drift['reason'] . '</comment>');
            $this->newLine();

            return;
        }

        if (SchemaDiff::count($drift) === 0) {
            $this->line('  <info>none</info>');
            $this->newLine();

            return;
        }

        foreach ($drift['tables_only_in_left'] as $table) {
            $this->line(sprintf('  only in %s: table %s', $drift['left'], $table));
        }

        foreach ($drift['tables_only_in_right'] as $table) {
            $this->line(sprintf('  only in %s: table %s', $drift['right'], $table));
        }

        foreach ($drift['columns_only_in_left'] as $entry) {
            $this->line(sprintf('  only in %s: %s.%s', $drift['left'], $entry['table'], $entry['column']));
        }

        foreach ($drift['columns_only_in_right'] as $entry) {
            $this->line(sprintf('  only in %s: %s.%s', $drift['right'], $entry['table'], $entry['column']));
        }

        $this->newLine();
    }
}
