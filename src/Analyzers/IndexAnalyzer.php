<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Devlin\ModelAnalyzer\Contracts\DetectorInterface;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;

class IndexAnalyzer implements DetectorInterface
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
            if (!$this->schema->tableExists($model->table)) {
                continue;
            }

            foreach ($model->relationships as $rel) {
                $fkTable  = $this->resolveFkTable($rel);
                $fkColumn = $rel->foreignKey;

                if ($fkTable === null || $fkColumn === null) {
                    continue;
                }

                if (!$this->schema->tableExists($fkTable)) {
                    continue;
                }

                if (!$this->schema->columnExists($fkTable, $fkColumn)) {
                    continue;
                }

                if (!$this->schema->columnHasIndex($fkTable, $fkColumn)) {
                    $result->addIssue(new Issue(
                        'missing_index',
                        'warning',
                        $model->shortName,
                        sprintf(
                            'Foreign key column "%s.%s" has no index (performance risk)',
                            $fkTable,
                            $fkColumn
                        ),
                        sprintf(
                            '$table->index(\'%s\'); // in a migration for %s',
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
    }

    /**
     * Determine which table holds the foreign key for this relationship.
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
                return $rel->table; // FK lives on the related table

            case 'BelongsToMany':
            case 'MorphToMany':
            case 'MorphedByMany':
                return $rel->pivotTable;

            default:
                return null;
        }
    }
}
