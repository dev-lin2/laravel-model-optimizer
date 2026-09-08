<?php

namespace Devlin\ModelAnalyzer\Schema;

use Devlin\ModelAnalyzer\Analyzers\DatabaseSchemaReader;
use Devlin\ModelAnalyzer\Contracts\SchemaSourceInterface;

/**
 * Reads schema from the live database connection.
 *
 * Wraps DatabaseSchemaReader and normalizes its per-driver shapes into the
 * common SchemaSnapshot format. A missing or misconfigured connection yields
 * an unavailable snapshot rather than an exception.
 */
class DatabaseSchemaSource implements SchemaSourceInterface
{
    /** @var DatabaseSchemaReader */
    private $reader;

    /** @var SchemaSnapshot|null */
    private $cached = null;

    /**
     * @param DatabaseSchemaReader $reader
     */
    public function __construct(DatabaseSchemaReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'database';
    }

    /**
     * @return SchemaSnapshot
     */
    public function snapshot()
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $snapshot = new SchemaSnapshot('database');

        try {
            $tables = $this->reader->getTables();
        } catch (\Throwable $e) {
            $this->cached = SchemaSnapshot::unavailable(
                'database',
                'Could not list tables: ' . $e->getMessage()
            );

            return $this->cached;
        }

        if (count($tables) === 0) {
            $snapshot->addWarning(
                'No tables found on the database connection. The database may be empty or unmigrated.'
            );
        }

        foreach ($tables as $table) {
            $snapshot->ensureTable($table);

            try {
                $snapshot->tables[$table]['columns'] = $this->normalizeColumns(
                    $this->reader->getColumns($table)
                );
            } catch (\Throwable $e) {
                $snapshot->addWarning(
                    sprintf('Could not read columns for table "%s": %s', $table, $e->getMessage())
                );
            }

            try {
                $snapshot->tables[$table]['foreignKeys'] = $this->normalizeForeignKeys(
                    $this->reader->getForeignKeys($table)
                );
            } catch (\Throwable $e) {
                $snapshot->addWarning(
                    sprintf('Could not read foreign keys for table "%s": %s', $table, $e->getMessage())
                );
            }

            try {
                $snapshot->tables[$table]['indexes'] = $this->normalizeIndexes(
                    $this->reader->getIndexes($table)
                );
            } catch (\Throwable $e) {
                $snapshot->addWarning(
                    sprintf('Could not read indexes for table "%s": %s', $table, $e->getMessage())
                );
            }
        }

        $this->cached = $snapshot;

        return $snapshot;
    }

    /**
     * @param  array $raw
     * @return array<string, array>
     */
    private function normalizeColumns(array $raw)
    {
        $columns = [];

        foreach ($raw as $key => $col) {
            // Guard against a flat list of names (the pre-normalization shape).
            if (!is_array($col)) {
                $name = is_string($col) ? $col : (string) $key;

                $columns[$name] = [
                    'name'     => $name,
                    'type'     => 'unknown',
                    'nullable' => null,
                    'key'      => '',
                    'default'  => null,
                ];
                continue;
            }

            $name = isset($col['name']) ? $col['name'] : (string) $key;

            $columns[$name] = [
                'name'     => $name,
                'type'     => isset($col['type']) ? strtolower((string) $col['type']) : 'unknown',
                'nullable' => $this->normalizeNullable($col),
                'key'      => isset($col['key']) ? (string) $col['key'] : '',
                'default'  => isset($col['default']) ? $col['default'] : null,
            ];
        }

        return $columns;
    }

    /**
     * MySQL reports 'YES'/'NO'; SQLite reports a bool. Unknown stays null.
     *
     * @param  array $col
     * @return bool|null
     */
    private function normalizeNullable(array $col)
    {
        if (!array_key_exists('nullable', $col)) {
            return null;
        }

        $value = $col['nullable'];

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return strtoupper($value) === 'YES';
        }

        return (bool) $value;
    }

    /**
     * @param  array $raw
     * @return array[]
     */
    private function normalizeForeignKeys(array $raw)
    {
        $fks = [];

        foreach ($raw as $fk) {
            if (!is_array($fk) || !isset($fk['column'])) {
                continue;
            }

            $fks[] = [
                'column'     => $fk['column'],
                'references' => isset($fk['referenced_column']) ? $fk['referenced_column'] : 'id',
                'on'         => isset($fk['referenced_table']) ? $fk['referenced_table'] : null,
                'name'       => isset($fk['constraint_name']) ? $fk['constraint_name'] : null,
            ];
        }

        return $fks;
    }

    /**
     * Collapse the reader's one-row-per-column shape into one entry per index,
     * ordered by seq_in_index so composite indexes keep their column order.
     *
     * @param  array $raw
     * @return array[]
     */
    private function normalizeIndexes(array $raw)
    {
        $grouped = [];

        foreach ($raw as $row) {
            if (!is_array($row) || !isset($row['index_name'])) {
                continue;
            }

            $name = $row['index_name'];

            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'name'    => $name,
                    'columns' => [],
                    'unique'  => isset($row['non_unique']) ? !$row['non_unique'] : false,
                ];
            }

            $seq = isset($row['seq_in_index'])
                ? (int) $row['seq_in_index']
                : count($grouped[$name]['columns']) + 1;

            $grouped[$name]['columns'][$seq] = isset($row['column']) ? $row['column'] : null;
        }

        $indexes = [];

        foreach ($grouped as $index) {
            ksort($index['columns']);

            $index['columns'] = array_values(array_filter($index['columns'], function ($c) {
                return $c !== null;
            }));

            $indexes[] = $index;
        }

        return $indexes;
    }
}
