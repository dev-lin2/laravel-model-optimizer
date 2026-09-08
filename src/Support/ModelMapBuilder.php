<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\ModelAnalyzer;

/**
 * Builds the optional table => model enrichment map used by the docs, ERD and
 * report renderers.
 *
 * Model discovery is a convenience, never a requirement: any failure returns
 * an empty map so a migration-sourced run with no application models still
 * produces complete output.
 */
class ModelMapBuilder
{
    /**
     * table => ['model' => string, 'short_name' => string, 'relationships' => array[]]
     *
     * @param  ModelAnalyzer $analyzer
     * @param  string[]|null $onlyModels
     * @return array<string, array>
     */
    public static function build(ModelAnalyzer $analyzer, $onlyModels = null)
    {
        try {
            $result = $analyzer->analyze($onlyModels);
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];

        foreach ($result->models as $model) {
            if (!isset($model->table) || $model->table === '') {
                continue;
            }

            $relationships = [];

            foreach ($model->relationships as $rel) {
                $relationships[] = [
                    'method'      => isset($rel->name) ? $rel->name : '?',
                    'type'        => isset($rel->type) ? $rel->type : '?',
                    'related'     => isset($rel->related) ? $rel->related : '?',
                    'foreign_key' => isset($rel->foreignKey) ? $rel->foreignKey : null,
                    'table'       => isset($rel->table) ? $rel->table : null,
                ];
            }

            $map[$model->table] = [
                'model'         => $model->class,
                'short_name'    => $model->shortName,
                'relationships' => $relationships,
            ];
        }

        return $map;
    }
}
