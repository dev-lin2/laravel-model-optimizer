<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Devlin\ModelAnalyzer\Contracts\DetectorInterface;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;

class DatabaseMismatchDetector implements DetectorInterface
{
    /** @var DatabaseSchemaReader */
    private $schema;

    /**
     * @param DatabaseSchemaReader $schema
     */
    public function __construct(DatabaseSchemaReader $schema)
    {
        $this->schema = $schema;
    }

    /**
     * {@inheritdoc}
     */
    public function detect(AnalysisResult $result)
    {
        foreach ($result->models as $model) {
            // 1. Check the model's own table exists
            if (!$this->schema->tableExists($model->table)) {
                $result->addIssue(new Issue(
                    'missing_table',
                    'error',
                    $model->shortName,
                    sprintf(
                        'Table "%s" for model %s does not exist in the database',
                        $model->table,
                        $model->shortName
                    ),
                    sprintf('php artisan make:migration create_%s_table', $model->table),
                    ['table' => $model->table]
                ));
                continue; // No point checking relationships if base table missing
            }

            foreach ($model->relationships as $rel) {
                // 2. Check the related table exists
                if ($rel->table !== null && !$this->schema->tableExists($rel->table)) {
                    $result->addIssue(new Issue(
                        'missing_table',
                        'error',
                        $model->shortName,
                        sprintf(
                            'Table "%s" referenced in %s::%s() does not exist',
                            $rel->table,
                            $model->shortName,
                            $rel->name
                        ),
                        sprintf('php artisan make:migration create_%s_table', $rel->table),
                        ['model' => $model->class, 'relationship' => $rel->name, 'table' => $rel->table]
                    ));
                    continue;
                }

                // 3. Check pivot table for BelongsToMany
                if ($rel->pivotTable !== null && !$this->schema->tableExists($rel->pivotTable)) {
                    $result->addIssue(new Issue(
                        'missing_table',
                        'error',
                        $model->shortName,
                        sprintf(
                            'Pivot table "%s" for %s::%s() does not exist',
                            $rel->pivotTable,
                            $model->shortName,
                            $rel->name
                        ),
                        sprintf('php artisan make:migration create_%s_table', $rel->pivotTable),
                        ['model' => $model->class, 'relationship' => $rel->name, 'pivot_table' => $rel->pivotTable]
                    ));
                    continue;
                }

                // 4. Check foreign key column exists
                if ($rel->foreignKey !== null && $rel->table !== null) {
                    $fkTable  = $this->resolveFkTable($rel);
                    $fkColumn = $rel->foreignKey;

                    if ($fkTable !== null && $this->schema->tableExists($fkTable)) {
                        if (!$this->schema->columnExists($fkTable, $fkColumn)) {
                            $result->addIssue(new Issue(
                                'missing_column',
                                'error',
                                $model->shortName,
                                sprintf(
                                    'Column "%s.%s" for %s::%s() does not exist',
                                    $fkTable,
                                    $fkColumn,
                                    $model->shortName,
                                    $rel->name
                                ),
                                sprintf(
                                    'php artisan make:migration add_%s_to_%s_table',
                                    $fkColumn,
                                    $fkTable
                                ),
                                [
                                    'model'        => $model->class,
                                    'relationship' => $rel->name,
                                    'table'        => $fkTable,
                                    'column'       => $fkColumn,
                                ]
                            ));
                        }
                    }
                }

                // 5. Check for missing FK constraint
                if ($rel->foreignKey !== null && $rel->table !== null) {
                    $fkTable  = $this->resolveFkTable($rel);
                    $fkColumn = $rel->foreignKey;

                    if ($fkTable !== null
                        && $this->schema->tableExists($fkTable)
                        && $this->schema->columnExists($fkTable, $fkColumn)
                        && !$this->schema->columnHasForeignKey($fkTable, $fkColumn)
                    ) {
                        $result->addIssue(new Issue(
                            'missing_foreign_key',
                            'warning',
                            $model->shortName,
                            sprintf(
                                'Column "%s.%s" has no foreign key constraint',
                                $fkTable,
                                $fkColumn
                            ),
                            sprintf(
                                '$table->foreign(\'%s\')->references(\'id\')->on(\'%s\');',
                                $fkColumn,
                                $rel->table
                            ),
                            [
                                'model'        => $model->class,
                                'relationship' => $rel->name,
                                'table'        => $fkTable,
                                'column'       => $fkColumn,
                            ]
                        ));
                    }
                }
            }

            // 6. Orphaned FK columns — DB has FK but no relationship declared
            $this->detectOrphanedForeignKeys($model, $result);
        }
    }

    /**
     * Determine which table holds the foreign key column for a relationship.
     *
     * @param \Devlin\ModelAnalyzer\Models\RelationshipInfo $rel
     * @return string|null
     */
    private function resolveFkTable($rel)
    {
        switch ($rel->type) {
            case 'HasOne':
            case 'HasMany':
            case 'MorphOne':
            case 'MorphMany':
                return $rel->table; // FK is on the related table

            case 'BelongsTo':
                return null; // We'd need the owner model's table — handled by the HasOne/HasMany side

            default:
                return null;
        }
    }

    /**
     * Report FK columns in the DB that have no corresponding relationship method.
     *
     * @param \Devlin\ModelAnalyzer\Models\ModelInfo $model
     * @param AnalysisResult                         $result
     * @return void
     */
    private function detectOrphanedForeignKeys($model, AnalysisResult $result)
    {
        $declaredFks = [];
        foreach ($model->relationships as $rel) {
            if ($rel->foreignKey !== null) {
                $declaredFks[] = $rel->foreignKey;
            }
        }

        foreach ($this->schema->getForeignKeys($model->table) as $fk) {
            if (!in_array($fk['column'], $declaredFks, true)) {
                $result->addIssue(new Issue(
                    'orphaned_foreign_key',
                    'info',
                    $model->shortName,
                    sprintf(
                        'Column "%s.%s" is a foreign key in the DB but has no relationship method in %s',
                        $model->table,
                        $fk['column'],
                        $model->shortName
                    ),
                    null,
                    [
                        'model'  => $model->class,
                        'table'  => $model->table,
                        'column' => $fk['column'],
                    ]
                ));
            }
        }
    }
}
