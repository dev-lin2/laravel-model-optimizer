<?php

namespace Devlin\ModelAnalyzer\Schema;

/**
 * Compares two snapshots and reports drift between them.
 *
 * Used by --source=both. Comparison is skipped (with a reason) when either
 * side is unavailable, so a missing database never turns into an error.
 */
class SchemaDiff
{
    /** @var SchemaSnapshot */
    private $left;

    /** @var SchemaSnapshot */
    private $right;

    /**
     * @param SchemaSnapshot $left
     * @param SchemaSnapshot $right
     */
    public function __construct(SchemaSnapshot $left, SchemaSnapshot $right)
    {
        $this->left  = $left;
        $this->right = $right;
    }

    /**
     * @return bool
     */
    public function comparable()
    {
        return $this->left->available && $this->right->available;
    }

    /**
     * @return string|null
     */
    public function skipReason()
    {
        if (!$this->left->available) {
            return sprintf('%s schema unavailable; drift not computed.', $this->left->source);
        }

        if (!$this->right->available) {
            return sprintf('%s schema unavailable; drift not computed.', $this->right->source);
        }

        return null;
    }

    /**
     * Compute the drift report.
     *
     * @return array{
     *     comparable: bool,
     *     reason: string|null,
     *     tables_only_in_left: string[],
     *     tables_only_in_right: string[],
     *     columns_only_in_left: array[],
     *     columns_only_in_right: array[],
     *     left: string,
     *     right: string
     * }
     */
    public function compute()
    {
        $report = [
            'left'                  => $this->left->source,
            'right'                 => $this->right->source,
            'comparable'            => $this->comparable(),
            'reason'                => $this->skipReason(),
            'tables_only_in_left'   => [],
            'tables_only_in_right'  => [],
            'columns_only_in_left'  => [],
            'columns_only_in_right' => [],
        ];

        if (!$report['comparable']) {
            return $report;
        }

        $leftTables  = $this->left->tableNames();
        $rightTables = $this->right->tableNames();

        $report['tables_only_in_left']  = array_values(array_diff($leftTables, $rightTables));
        $report['tables_only_in_right'] = array_values(array_diff($rightTables, $leftTables));

        foreach (array_intersect($leftTables, $rightTables) as $table) {
            $leftCols  = $this->left->columnNames($table);
            $rightCols = $this->right->columnNames($table);

            foreach (array_diff($leftCols, $rightCols) as $column) {
                $report['columns_only_in_left'][] = ['table' => $table, 'column' => $column];
            }

            foreach (array_diff($rightCols, $leftCols) as $column) {
                $report['columns_only_in_right'][] = ['table' => $table, 'column' => $column];
            }
        }

        return $report;
    }

    /**
     * Total number of drift items found.
     *
     * @param  array $report
     * @return int
     */
    public static function count(array $report)
    {
        return count($report['tables_only_in_left'])
            + count($report['tables_only_in_right'])
            + count($report['columns_only_in_left'])
            + count($report['columns_only_in_right']);
    }
}
