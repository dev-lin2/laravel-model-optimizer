<?php

namespace Devlin\ModelAnalyzer\Schema;

/**
 * Normalized, source-agnostic view of a database schema.
 *
 * Produced by any SchemaSourceInterface implementation so that consumers
 * (ERD generators, docs, reports, detectors) never care whether the data
 * came from a live connection or from parsed migration files.
 *
 * Every accessor degrades to an empty value rather than throwing. A snapshot
 * that could not be built at all is still a valid object with available=false
 * and a populated $errors array.
 */
class SchemaSnapshot
{
    /** @var string Source identifier: 'database' or 'migrations' */
    public $source;

    /**
     * Normalized table map.
     *
     * table => [
     *   'columns'     => [name => ['name','type','nullable','key','default']],
     *   'foreignKeys' => [['column','references','on','name']],
     *   'indexes'     => [['name','columns','unique']],
     * ]
     *
     * @var array<string, array>
     */
    public $tables = [];

    /** @var string[] Hard failures encountered while building this snapshot */
    public $errors = [];

    /** @var string[] Non-fatal problems (partial parses, skipped files) */
    public $warnings = [];

    /** @var bool False when the source was entirely unusable */
    public $available = true;

    /**
     * @param string $source
     */
    public function __construct($source = 'unknown')
    {
        $this->source = $source;
    }

    /**
     * Build a snapshot representing a source that could not be read at all.
     *
     * @param  string $source
     * @param  string $error
     * @return self
     */
    public static function unavailable($source, $error)
    {
        $snapshot            = new self($source);
        $snapshot->available = false;
        $snapshot->errors[]  = $error;

        return $snapshot;
    }

    /**
     * @param  string $message
     * @return void
     */
    public function addError($message)
    {
        $this->errors[] = $message;
    }

    /**
     * @param  string $message
     * @return void
     */
    public function addWarning($message)
    {
        $this->warnings[] = $message;
    }

    /**
     * Register a table, creating its normalized skeleton if absent.
     *
     * @param  string $table
     * @return void
     */
    public function ensureTable($table)
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [
                'columns'     => [],
                'foreignKeys' => [],
                'indexes'     => [],
            ];
        }
    }

    /**
     * @return string[]
     */
    public function tableNames()
    {
        return array_keys($this->tables);
    }

    /**
     * @param  string $table
     * @return bool
     */
    public function hasTable($table)
    {
        return isset($this->tables[$table]);
    }

    /**
     * Columns for a table, keyed by column name. Empty array when unknown.
     *
     * @param  string $table
     * @return array<string, array>
     */
    public function columns($table)
    {
        return isset($this->tables[$table]['columns'])
            ? $this->tables[$table]['columns']
            : [];
    }

    /**
     * @param  string $table
     * @return string[]
     */
    public function columnNames($table)
    {
        return array_keys($this->columns($table));
    }

    /**
     * @param  string $table
     * @param  string $column
     * @return bool
     */
    public function hasColumn($table, $column)
    {
        $columns = $this->columns($table);

        return isset($columns[$column]);
    }

    /**
     * @param  string $table
     * @return array[]
     */
    public function foreignKeys($table)
    {
        return isset($this->tables[$table]['foreignKeys'])
            ? $this->tables[$table]['foreignKeys']
            : [];
    }

    /**
     * @param  string $table
     * @return array[]
     */
    public function indexes($table)
    {
        return isset($this->tables[$table]['indexes'])
            ? $this->tables[$table]['indexes']
            : [];
    }

    /**
     * Whether any foreign key constraint covers the given column.
     *
     * @param  string $table
     * @param  string $column
     * @return bool
     */
    public function columnHasForeignKey($table, $column)
    {
        foreach ($this->foreignKeys($table) as $fk) {
            if (isset($fk['column']) && $fk['column'] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any index has the given column in its first position.
     *
     * @param  string $table
     * @param  string $column
     * @return bool
     */
    public function columnHasIndex($table, $column)
    {
        foreach ($this->indexes($table) as $index) {
            $columns = isset($index['columns']) ? $index['columns'] : [];

            if (isset($columns[0]) && $columns[0] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return count($this->tables) === 0;
    }

    /**
     * @return bool
     */
    public function hasIssues()
    {
        return count($this->errors) > 0 || count($this->warnings) > 0;
    }

    /**
     * Human-readable one-line description of source health, for command output.
     *
     * @return string
     */
    public function statusLine()
    {
        if (!$this->available) {
            return sprintf('%s: unavailable', $this->source);
        }

        return sprintf(
            '%s: %d tables, %d errors, %d warnings',
            $this->source,
            count($this->tables),
            count($this->errors),
            count($this->warnings)
        );
    }
}
