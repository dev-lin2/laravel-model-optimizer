<?php

namespace Devlin\ModelAnalyzer\Schema;

use Devlin\ModelAnalyzer\Contracts\SchemaSourceInterface;
use Devlin\ModelAnalyzer\Support\MigrationScanner;

/**
 * Builds a schema snapshot by statically parsing migration files.
 *
 * Requires no database connection, so it works in CI and on a fresh checkout.
 * Missing or unreadable migration directories degrade to an unavailable or
 * empty snapshot rather than an exception.
 */
class MigrationSchemaSource implements SchemaSourceInterface
{
    /** @var string[] */
    private $paths;

    /** @var MigrationScanner */
    private $scanner;

    /** @var SchemaSnapshot|null */
    private $cached = null;

    /**
     * @param string[]              $paths
     * @param MigrationScanner|null $scanner
     */
    public function __construct(array $paths, MigrationScanner $scanner = null)
    {
        $this->paths   = $paths;
        $this->scanner = $scanner ?: new MigrationScanner();
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'migrations';
    }

    /**
     * @return SchemaSnapshot
     */
    public function snapshot()
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $snapshot = new SchemaSnapshot('migrations');

        $existing = array_values(array_filter($this->paths, function ($path) {
            return is_string($path) && is_dir($path);
        }));

        if (count($this->paths) === 0) {
            $this->cached = SchemaSnapshot::unavailable(
                'migrations',
                'No migration_paths configured. Set migration_paths in config/model-analyzer.php.'
            );

            return $this->cached;
        }

        if (count($existing) === 0) {
            $this->cached = SchemaSnapshot::unavailable(
                'migrations',
                'None of the configured migration paths exist: ' . implode(', ', array_map('strval', $this->paths))
            );

            return $this->cached;
        }

        foreach ($this->paths as $path) {
            if (!in_array($path, $existing, true)) {
                $snapshot->addWarning(sprintf('Migration path does not exist: %s', $path));
            }
        }

        try {
            $tables = $this->scanner->scan($existing);
        } catch (\Throwable $e) {
            $this->cached = SchemaSnapshot::unavailable(
                'migrations',
                'Migration scan failed: ' . $e->getMessage()
            );

            return $this->cached;
        }

        foreach ($this->scanner->getWarnings() as $warning) {
            $snapshot->addWarning($warning);
        }

        if (count($tables) === 0) {
            $snapshot->addWarning('No tables were found in the migration files.');
        }

        $foreignKeys = $this->scanner->getForeignKeys();
        $indexes     = $this->scanner->getIndexes();
        $columnMeta  = $this->scanner->getColumnMeta();

        foreach ($tables as $table => $columns) {
            $snapshot->ensureTable($table);

            $snapshot->tables[$table]['columns'] = $this->normalizeColumns(
                $table,
                is_array($columns) ? $columns : [],
                isset($columnMeta[$table]) ? $columnMeta[$table] : []
            );

            $snapshot->tables[$table]['foreignKeys'] = isset($foreignKeys[$table])
                ? $foreignKeys[$table]
                : [];

            $snapshot->tables[$table]['indexes'] = isset($indexes[$table])
                ? $indexes[$table]
                : [];
        }

        $this->cached = $snapshot;

        return $snapshot;
    }

    /**
     * Convert the scanner's flat column => type map into normalized entries.
     *
     * Migrations do not carry every attribute a live connection does, so
     * unknown values stay null rather than being guessed.
     *
     * @param  string $table
     * @param  array  $columns
     * @param  array  $meta
     * @return array<string, array>
     */
    private function normalizeColumns($table, array $columns, array $meta)
    {
        $normalized = [];

        foreach ($columns as $name => $type) {
            $normalized[$name] = [
                'name'     => $name,
                'type'     => is_string($type) && $type !== '' ? $type : 'unknown',
                'nullable' => isset($meta[$name]['nullable']) ? (bool) $meta[$name]['nullable'] : null,
                'key'      => $name === 'id' ? 'PRI' : '',
                'default'  => null,
            ];
        }

        return $normalized;
    }
}
