<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Devlin\ModelAnalyzer\Contracts\DetectorInterface;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;
use Devlin\ModelAnalyzer\Models\ModelInfo;
use Devlin\ModelAnalyzer\Models\RelationshipInfo;

class InverseDetector implements DetectorInterface
{
    /**
     * Expected inverse type(s) for each relationship type.
     *
     * @var array
     */
    private $inverseMap = [
        'HasOne'        => ['BelongsTo'],
        'HasMany'       => ['BelongsTo'],
        'BelongsTo'     => ['HasOne', 'HasMany'],
        'BelongsToMany' => ['BelongsToMany'],
        'MorphOne'      => ['MorphTo'],
        'MorphMany'     => ['MorphTo'],
        'MorphTo'       => ['MorphOne', 'MorphMany'],
    ];

    /**
     * {@inheritdoc}
     */
    public function detect(AnalysisResult $result)
    {
        // Build a lookup: class => ModelInfo
        $modelMap = [];
        foreach ($result->models as $model) {
            $modelMap[$model->class] = $model;
        }

        foreach ($result->models as $model) {
            foreach ($model->relationships as $rel) {
                if (!isset($this->inverseMap[$rel->type])) {
                    continue; // Skip types we don't check (e.g. MorphToMany)
                }

                $expectedInverses = $this->inverseMap[$rel->type];

                // The related model must be in our scanned set
                if (!isset($modelMap[$rel->related])) {
                    continue;
                }

                $relatedModel = $modelMap[$rel->related];

                if ($this->hasInverse($relatedModel, $model->class, $expectedInverses)) {
                    continue;
                }

                $suggestion = $this->buildSuggestion($model, $rel, $expectedInverses);

                $result->addIssue(new Issue(
                    'missing_inverse',
                    'warning',
                    $model->shortName,
                    sprintf(
                        '%s::%s() has no inverse %s in %s',
                        $model->shortName,
                        $rel->name,
                        implode(' or ', array_map('lcfirst', $expectedInverses)),
                        $relatedModel->shortName
                    ),
                    $suggestion,
                    [
                        'model'         => $model->class,
                        'relationship'  => $rel->name,
                        'related_model' => $rel->related,
                    ]
                ));
            }
        }
    }

    /**
     * Check whether the related model already has an inverse pointing back.
     *
     * @param ModelInfo $relatedModel
     * @param string    $originClass
     * @param string[]  $expectedTypes
     * @return bool
     */
    private function hasInverse(ModelInfo $relatedModel, $originClass, array $expectedTypes)
    {
        foreach ($relatedModel->relationships as $rel) {
            if (!in_array($rel->type, $expectedTypes, true)) {
                continue;
            }
            if ($rel->related === $originClass) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate a code suggestion for the missing inverse.
     *
     * @param ModelInfo        $model
     * @param RelationshipInfo $rel
     * @param string[]         $expectedTypes
     * @return string
     */
    private function buildSuggestion(ModelInfo $model, RelationshipInfo $rel, array $expectedTypes)
    {
        $inverseType = lcfirst($expectedTypes[0]);
        $methodName  = lcfirst($model->shortName);
        $modelClass  = $model->shortName . '::class';

        return sprintf(
            "Add to %s model:\npublic function %s()\n{\n    return \$this->%s(%s);\n}",
            class_basename($rel->related),
            $methodName,
            $inverseType,
            $modelClass
        );
    }
}
