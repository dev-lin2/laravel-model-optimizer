<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;
use Illuminate\Support\Str;

/**
 * Finds columns that look like foreign keys but have no FK constraint.
 *
 * A column is only reported when a plausible target table actually exists in
 * the same snapshot, which keeps false positives low: `logs.external_id` with
 * no `externals` table is skipped rather than reported.
 *
 * Polymorphic columns are excluded by design — a `*_id` paired with a
 * `*_type` column cannot carry a single-table FK constraint.
 */
class MissingForeignKeyDetector
{
    /** @var array<string, string> Column name => explicit target table */
    private $hints;

    /**
     * @param array<string, string> $hints Optional column => table overrides
     */
    public function __construct(array $hints = [])
    {
        $this->hints = $hints;
    }

    /**
     * Return one finding per column that should probably have a constraint.
     *
     * Each finding: ['table','column','references','on','reason','suggestion']
     *
     * @param  SchemaSnapshot $snapshot
     * @return array[]
     */
    public function detect(SchemaSnapshot $snapshot)
    {
        $findings = [];

        foreach ($snapshot->tableNames() as $table) {
            foreach ($snapshot->columns($table) as $column => $meta) {
                $finding = $this->inspect($snapshot, $table, $column);

                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @param  string         $column
     * @return array|null
     */
    private function inspect(SchemaSnapshot $snapshot, $table, $column)
    {
        if (!$this->looksLikeForeignKey($column)) {
            return null;
        }

        if ($this->isPolymorphic($snapshot, $table, $column)) {
            return null;
        }

        if ($snapshot->columnHasForeignKey($table, $column)) {
            return null;
        }

        $target = $this->resolveTarget($snapshot, $column);

        if ($target === null) {
            return null;
        }

        return [
            'table'       => $table,
            'column'      => $column,
            'references'  => 'id',
            'on'          => $target,
            'reason'      => sprintf(
                'No foreign key constraint on %s.%s; target table "%s" exists.',
                $table,
                $column,
                $target
            ),
            'suggestion'  => $this->suggestion($column, $target),
            'has_index'   => $snapshot->columnHasIndex($table, $column),
        ];
    }

    /**
     * @param  string $column
     * @return bool
     */
    private function looksLikeForeignKey($column)
    {
        if (isset($this->hints[$column])) {
            return true;
        }

        return substr($column, -3) === '_id' && strlen($column) > 3 && $column !== 'uuid';
    }

    /**
     * A `foo_id` column accompanied by `foo_type` is a morph, not an FK.
     *
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @param  string         $column
     * @return bool
     */
    private function isPolymorphic(SchemaSnapshot $snapshot, $table, $column)
    {
        if (substr($column, -3) !== '_id') {
            return false;
        }

        $base = substr($column, 0, -3);

        return $snapshot->hasColumn($table, $base . '_type');
    }

    /**
     * Find an existing table this column plausibly points at.
     *
     * @param  SchemaSnapshot $snapshot
     * @param  string         $column
     * @return string|null
     */
    private function resolveTarget(SchemaSnapshot $snapshot, $column)
    {
        if (isset($this->hints[$column])) {
            return $snapshot->hasTable($this->hints[$column]) ? $this->hints[$column] : null;
        }

        $base = substr($column, 0, -3);

        if ($base === '') {
            return null;
        }

        foreach ([Str::plural($base), $base] as $candidate) {
            if ($snapshot->hasTable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  string $column
     * @param  string $target
     * @return string
     */
    private function suggestion($column, $target)
    {
        return sprintf(
            "\$table->foreign('%s')->references('id')->on('%s');",
            $column,
            $target
        );
    }
}
