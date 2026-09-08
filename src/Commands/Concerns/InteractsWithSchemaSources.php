<?php

namespace Devlin\ModelAnalyzer\Commands\Concerns;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;
use Devlin\ModelAnalyzer\Schema\SchemaSourceFactory;

/**
 * Shared --source resolution and issue-visibility handling for commands.
 *
 * The contract these helpers enforce: a command never aborts because a schema
 * source was missing. Unavailable sources produce empty output plus a notice,
 * and the notice itself can be silenced with --hide-errors/--hide-warnings.
 */
trait InteractsWithSchemaSources
{
    /**
     * The option block shared by every schema-aware command.
     *
     * Kept as a constant string so signatures stay consistent; PHP does not
     * allow interpolation in $signature, so each command inlines the same
     * options and this documents the canonical set.
     *
     * @return string[]
     */
    public static function sharedOptionNames()
    {
        return ['source', 'issues', 'hide-errors', 'hide-warnings'];
    }

    /**
     * Resolve --source into a normalized selector, warning and defaulting to
     * database when the value is unrecognized.
     *
     * @return string
     */
    protected function resolveSelector()
    {
        $raw        = $this->option('source');
        $normalized = SchemaSourceFactory::normalizeSelector($raw);

        if ($normalized === null) {
            $this->warnIssue(sprintf(
                'Unknown --source "%s". Expected one of: %s. Falling back to "database".',
                $raw,
                implode(', ', SchemaSourceFactory::validSelectors())
            ));

            return SchemaSourceFactory::SOURCE_DATABASE;
        }

        return $normalized;
    }

    /**
     * @param  array $config
     * @return SchemaSourceFactory
     */
    protected function sourceFactory(array $config)
    {
        return new SchemaSourceFactory($config);
    }

    /**
     * Whether errors should be printed. Controlled by --issues and
     * --hide-errors, with --hide-errors taking precedence.
     *
     * @return bool
     */
    protected function showErrors()
    {
        if ($this->optionExists('hide-errors') && $this->option('hide-errors')) {
            return false;
        }

        $mode = $this->issueMode();

        return in_array($mode, ['all', 'errors'], true);
    }

    /**
     * @return bool
     */
    protected function showWarnings()
    {
        if ($this->optionExists('hide-warnings') && $this->option('hide-warnings')) {
            return false;
        }

        $mode = $this->issueMode();

        return in_array($mode, ['all', 'warnings'], true);
    }

    /**
     * Normalized --issues value: all | errors | warnings | none.
     *
     * @return string
     */
    protected function issueMode()
    {
        if (!$this->optionExists('issues')) {
            return 'all';
        }

        $mode = strtolower(trim((string) $this->option('issues')));

        if ($mode === '') {
            return 'all';
        }

        if (!in_array($mode, ['all', 'errors', 'warnings', 'none'], true)) {
            return 'all';
        }

        return $mode;
    }

    /**
     * True when the command's signature declares the option.
     *
     * @param  string $name
     * @return bool
     */
    protected function optionExists($name)
    {
        return $this->getDefinition()->hasOption($name);
    }

    /**
     * Print a snapshot's errors and warnings, subject to visibility flags.
     *
     * @param  SchemaSnapshot $snapshot
     * @return void
     */
    protected function reportSnapshotIssues(SchemaSnapshot $snapshot)
    {
        if ($this->showErrors()) {
            foreach ($snapshot->errors as $error) {
                $this->line('  <fg=red>error</>   ' . $error);
            }
        }

        if ($this->showWarnings()) {
            foreach ($snapshot->warnings as $warning) {
                $this->line('  <fg=yellow>warning</> ' . $warning);
            }
        }
    }

    /**
     * Print a status line per snapshot, then any issues.
     *
     * @param  array<string, SchemaSnapshot> $snapshots
     * @return void
     */
    protected function reportSources(array $snapshots)
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->available) {
                $this->line('  <info>' . $snapshot->statusLine() . '</info>');
            } else {
                $this->line('  <comment>' . $snapshot->statusLine() . '</comment>');
            }

            $this->reportSnapshotIssues($snapshot);
        }
    }

    /**
     * Emit a warning through the visibility filter.
     *
     * @param  string $message
     * @return void
     */
    protected function warnIssue($message)
    {
        if ($this->showWarnings()) {
            $this->warn($message);
        }
    }

    /**
     * Emit an error through the visibility filter. Never changes exit code.
     *
     * @param  string $message
     * @return void
     */
    protected function errorIssue($message)
    {
        if ($this->showErrors()) {
            $this->error($message);
        }
    }
}
