<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;

/**
 * Builds ERD render data from a schema snapshot, table-first.
 *
 * Every table in the snapshot becomes an entity, whether or not an Eloquent
 * model maps to it, so pivots and model-less tables appear. Models supply the
 * display name and extra relationship edges where they exist.
 *
 * Output matches the shape ErdGenerator/SvgErdGenerator already consume:
 * ['tables' => [...], 'relationships' => [...]].
 */
class SnapshotErdData
{
    /**
     * @param  SchemaSnapshot       $snapshot
     * @param  array<string, array> $models table => ['model','short_name','relationships']
     * @return array{tables: array, relationships: array}
     */
    public static function build(SchemaSnapshot $snapshot, array $models = [])
    {
        $tables        = [];
        $entityByTable = [];

        $tableNames = $snapshot->tableNames();
        sort($tableNames);

        foreach ($tableNames as $table) {
            $model     = isset($models[$table]) ? $models[$table] : null;
            $entity    = $model !== null && isset($model['short_name'])
                ? $model['short_name']
                : $table;

            $entityByTable[$table] = $entity;

            $fkColumns = [];
            foreach ($snapshot->foreignKeys($table) as $fk) {
                if (isset($fk['column'])) {
                    $fkColumns[$fk['column']] = true;
                }
            }

            $columns = [];

            foreach ($snapshot->columns($table) as $name => $meta) {
                $key = isset($meta['key']) ? $meta['key'] : '';

                $columns[] = [
                    'name'      => $name,
                    'type'      => isset($meta['type']) && $meta['type'] !== '' ? $meta['type'] : 'unknown',
                    'isPrimary' => $key === 'PRI' || $name === 'id',
                    'isForeign' => isset($fkColumns[$name]) || (bool) preg_match('/_id$/', $name),
                    'isMissing' => false,
                ];
            }

            $tables[] = [
                'name'       => $entity,
                'fullClass'  => $model !== null && isset($model['model']) ? $model['model'] : $table,
                'tableName'  => $table,
                'columns'    => $columns,
                'hasError'   => false,
                'hasWarning' => count($columns) === 0,
                'hasModel'   => $model !== null,
            ];
        }

        return [
            'tables'        => $tables,
            'relationships' => self::relationships($snapshot, $models, $entityByTable),
        ];
    }

    /**
     * Edges come from actual FK constraints first, then from Eloquent
     * relationships for pairs the constraints did not already cover.
     *
     * @param  SchemaSnapshot       $snapshot
     * @param  array<string, array> $models
     * @param  array<string, string> $entityByTable
     * @return array[]
     */
    private static function relationships(SchemaSnapshot $snapshot, array $models, array $entityByTable)
    {
        $relationships = [];
        $seen          = [];

        foreach ($snapshot->tableNames() as $table) {
            foreach ($snapshot->foreignKeys($table) as $fk) {
                $target = isset($fk['on']) ? $fk['on'] : null;

                if ($target === null || !isset($entityByTable[$target])) {
                    continue;
                }

                $from = $entityByTable[$table];
                $to   = $entityByTable[$target];
                $key  = $from . '->' . $to . ':' . $fk['column'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $relationships[] = [
                    'from'        => $from,
                    'to'          => $to,
                    'type'        => 'ForeignKey',
                    'label'       => $fk['column'],
                    'cardinality' => '*-1',
                    'foreignKey'  => $fk['column'],
                    'hasIssue'    => false,
                ];
            }
        }

        foreach ($models as $table => $model) {
            if (!isset($entityByTable[$table])) {
                continue;
            }

            $from = $entityByTable[$table];

            foreach (isset($model['relationships']) ? $model['relationships'] : [] as $rel) {
                $targetTable = isset($rel['table']) ? $rel['table'] : null;

                if ($targetTable === null || !isset($entityByTable[$targetTable])) {
                    continue;
                }

                $to  = $entityByTable[$targetTable];
                $key = $from . '->' . $to . ':' . (isset($rel['foreign_key']) ? $rel['foreign_key'] : '');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $relationships[] = [
                    'from'        => $from,
                    'to'          => $to,
                    'type'        => isset($rel['type']) ? $rel['type'] : 'Relation',
                    'label'       => isset($rel['method']) ? $rel['method'] : '',
                    'cardinality' => self::cardinality(isset($rel['type']) ? $rel['type'] : ''),
                    'foreignKey'  => isset($rel['foreign_key']) ? $rel['foreign_key'] : null,
                    'hasIssue'    => false,
                ];
            }
        }

        return $relationships;
    }

    /**
     * @param  string $type
     * @return string
     */
    private static function cardinality($type)
    {
        switch ($type) {
            case 'HasMany':
            case 'MorphMany':
                return '1-*';
            case 'BelongsTo':
            case 'MorphTo':
                return '*-1';
            case 'BelongsToMany':
            case 'MorphToMany':
            case 'MorphedByMany':
                return '*-*';
            default:
                return '1-1';
        }
    }
}
