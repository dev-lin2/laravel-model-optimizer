<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Illuminate\Support\Facades\DB;

class DatabaseSchemaReader
{
    /** @var string|null */
    private $connection;

    /** @var array */
    private $excludedTables;

    /** @var array|null Cached list of table names */
    private $tablesCache = null;

    /** @var array Cached columns keyed by table name */
    private $columnsCache = [];

    /** @var array Cached foreign keys keyed by table name */
    private $foreignKeysCache = [];

    /** @var array Cached indexes keyed by table name */
    private $indexesCache = [];

    /**
     * @param string|null $connection
     * @param array       $excludedTables
     */
    public function __construct($connection = null, array $excludedTables = [])
    {
        $this->connection     = $connection;
        $this->excludedTables = $excludedTables;
    }

    /**
     * Return all base table names in the current database.
     *
     * @return string[]
     */
    public function getTables()
    {
        if ($this->tablesCache !== null) {
            return $this->tablesCache;
        }

        try {
            if ($this->isSqlite()) {
                $rows   = $this->db()->select(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
                );
                $tables = array_map(function ($row) {
                    return ((array) $row)['name'];
                }, $rows);
            } else {
                $rows   = $this->db()->select(
                    "SELECT TABLE_NAME
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_TYPE = 'BASE TABLE'"
                );
                $tables = array_map(function ($row) {
                    $row = (array) $row;
                    return reset($row);
                }, $rows);
            }

            $excluded           = $this->excludedTables;
            $this->tablesCache  = array_values(
                array_filter($tables, function ($t) use ($excluded) {
                    return !in_array($t, $excluded, true);
                })
            );
        } catch (\Exception $e) {
            $this->tablesCache = [];
        }

        return $this->tablesCache;
    }

    /**
     * Return column metadata for a table.
     *
     * Each entry: ['name', 'type', 'nullable', 'key', 'default']
     *
     * @param string $table
     * @return array
     */
    public function getColumns($table)
    {
        if (isset($this->columnsCache[$table])) {
            return $this->columnsCache[$table];
        }

        try {
            $columns = [];

            if ($this->isSqlite()) {
                $rows = $this->db()->select(
                    'PRAGMA table_info(' . $this->quoteIdentifier($table) . ')'
                );
                foreach ($rows as $row) {
                    $col              = (array) $row;
                    $columns[$col['name']] = [
                        'name'    => $col['name'],
                        'type'    => strtolower($col['type']),
                        'nullable'=> !(bool) $col['notnull'],
                        'key'     => $col['pk'] ? 'PRI' : '',
                        'default' => $col['dflt_value'],
                    ];
                }
            } else {
                $rows = $this->db()->select(
                    "SELECT
                         COLUMN_NAME      AS name,
                         DATA_TYPE        AS type,
                         IS_NULLABLE      AS nullable,
                         COLUMN_KEY       AS `key`,
                         COLUMN_DEFAULT   AS `default`
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?",
                    [$table]
                );
                foreach ($rows as $row) {
                    $col              = (array) $row;
                    $columns[$col['name']] = $col;
                }
            }

            $this->columnsCache[$table] = $columns;
        } catch (\Exception $e) {
            $this->columnsCache[$table] = [];
        }

        return $this->columnsCache[$table];
    }

    /**
     * Return foreign key constraints for a table.
     *
     * Each entry: ['column', 'referenced_table', 'referenced_column', 'update_rule', 'delete_rule']
     *
     * @param string $table
     * @return array
     */
    public function getForeignKeys($table)
    {
        if (isset($this->foreignKeysCache[$table])) {
            return $this->foreignKeysCache[$table];
        }

        try {
            $fks = [];

            if ($this->isSqlite()) {
                $rows = $this->db()->select(
                    'PRAGMA foreign_key_list(' . $this->quoteIdentifier($table) . ')'
                );
                foreach ($rows as $row) {
                    $row  = (array) $row;
                    $fks[] = [
                        'column'             => $row['from'],
                        'referenced_table'   => $row['table'],
                        'referenced_column'  => $row['to'],
                        'update_rule'        => $row['on_update'],
                        'delete_rule'        => $row['on_delete'],
                    ];
                }
            } else {
                $rows = $this->db()->select(
                    "SELECT
                         COLUMN_NAME            AS `column`,
                         REFERENCED_TABLE_NAME  AS referenced_table,
                         REFERENCED_COLUMN_NAME AS referenced_column,
                         UPDATE_RULE            AS update_rule,
                         DELETE_RULE            AS delete_rule
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND REFERENCED_TABLE_NAME IS NOT NULL",
                    [$table]
                );
                foreach ($rows as $row) {
                    $fks[] = (array) $row;
                }
            }

            $this->foreignKeysCache[$table] = $fks;
        } catch (\Exception $e) {
            $this->foreignKeysCache[$table] = [];
        }

        return $this->foreignKeysCache[$table];
    }

    /**
     * Return index metadata for a table.
     *
     * Each entry: ['index_name', 'column', 'non_unique', 'seq_in_index']
     *
     * @param string $table
     * @return array
     */
    public function getIndexes($table)
    {
        if (isset($this->indexesCache[$table])) {
            return $this->indexesCache[$table];
        }

        try {
            $indexes = [];

            if ($this->isSqlite()) {
                $indexList = $this->db()->select(
                    'PRAGMA index_list(' . $this->quoteIdentifier($table) . ')'
                );
                foreach ($indexList as $idx) {
                    $idx  = (array) $idx;
                    $info = $this->db()->select(
                        'PRAGMA index_info(' . $this->quoteIdentifier($idx['name']) . ')'
                    );
                    foreach ($info as $col) {
                        $col       = (array) $col;
                        $indexes[] = [
                            'index_name'   => $idx['name'],
                            'column'       => $col['name'],
                            'non_unique'   => !(bool) $idx['unique'],
                            'seq_in_index' => $col['seqno'] + 1,
                        ];
                    }
                }
            } else {
                $rows = $this->db()->select(
                    "SELECT
                         INDEX_NAME    AS index_name,
                         COLUMN_NAME   AS `column`,
                         NON_UNIQUE    AS non_unique,
                         SEQ_IN_INDEX  AS seq_in_index
                     FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?",
                    [$table]
                );
                foreach ($rows as $row) {
                    $indexes[] = (array) $row;
                }
            }

            $this->indexesCache[$table] = $indexes;
        } catch (\Exception $e) {
            $this->indexesCache[$table] = [];
        }

        return $this->indexesCache[$table];
    }

    /**
     * Return true if the table exists in the database.
     *
     * @param string $table
     * @return bool
     */
    public function tableExists($table)
    {
        return in_array($table, $this->getTables(), true);
    }

    /**
     * Return true if the column exists on the given table.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function columnExists($table, $column)
    {
        $columns = $this->getColumns($table);
        return isset($columns[$column]);
    }

    /**
     * Return true if the column has at least one index.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function columnHasIndex($table, $column)
    {
        foreach ($this->getIndexes($table) as $index) {
            if ($index['column'] === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return true if a FK constraint exists for the given column.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function columnHasForeignKey($table, $column)
    {
        foreach ($this->getForeignKeys($table) as $fk) {
            if ($fk['column'] === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clear all caches (useful in testing).
     *
     * @return void
     */
    public function clearCache()
    {
        $this->tablesCache      = null;
        $this->columnsCache     = [];
        $this->foreignKeysCache = [];
        $this->indexesCache     = [];
    }

    /**
     * Return the DB connection to use.
     *
     * @return \Illuminate\Database\Connection
     */
    private function db()
    {
        if ($this->connection !== null) {
            return DB::connection($this->connection);
        }
        return DB::connection();
    }

    /**
     * @return bool
     */
    private function isSqlite()
    {
        return $this->db()->getDriverName() === 'sqlite';
    }

    /**
     * Quote an identifier for use in SQLite PRAGMA statements.
     * PRAGMA does not support bound parameters, so we embed the name directly.
     *
     * @param string $name
     * @return string
     */
    private function quoteIdentifier($name)
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
