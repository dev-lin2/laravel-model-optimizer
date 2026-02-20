<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Database\Eloquent\Relations\Relation;
use Devlin\ModelAnalyzer\Models\RelationshipInfo;

class ModelRelationshipParser
{
    /** @var array<int, array<string, string>> */
    private $parseErrors = [];

    /**
     * Short class names of all known Eloquent relation types.
     *
     * @var string[]
     */
    private static $relationTypes = [
        'HasOne',
        'HasMany',
        'BelongsTo',
        'BelongsToMany',
        'MorphOne',
        'MorphMany',
        'MorphTo',
        'MorphToMany',
        'MorphedByMany',
        'HasOneThrough',
        'HasManyThrough',
    ];

    /**
     * Parse all relationship methods from the given model class.
     *
     * @param string $modelClass
     * @return RelationshipInfo[]
     */
    public function parse($modelClass)
    {
        $this->parseErrors = [];
        $relationships = [];

        try {
            $reflection = new ReflectionClass($modelClass);
        } catch (\ReflectionException $e) {
            return $relationships;
        }

        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Only methods declared on this model (not inherited helpers)
            if ($method->getDeclaringClass()->getName() !== $modelClass) {
                continue;
            }

            // Must accept zero required parameters
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if (!$this->isRelationshipMethod($method)) {
                continue;
            }

            $info = $this->extractRelationshipInfo($modelClass, $method);

            if ($info !== null) {
                $relationships[] = $info;
            }
        }

        return $relationships;
    }

    /**
     * Determine whether a method returns an Eloquent Relation.
     *
     * @param ReflectionMethod $method
     * @return bool
     */
    protected function isRelationshipMethod(ReflectionMethod $method)
    {
        // Quick check via return type hint (PHP 7.4 compatible string cast)
        if ($method->hasReturnType()) {
            $returnType = (string) $method->getReturnType();
            // Strip leading "?" for nullable types
            $returnType = ltrim($returnType, '?');

            foreach (self::$relationTypes as $type) {
                if (stripos($returnType, $type) !== false) {
                    return true;
                }
            }

            // If return type is set but is something completely different, skip
            if ($returnType !== '' && stripos($returnType, 'Relation') === false) {
                return false;
            }
        }

        // Fall back: actually call the method and check the return value
        try {
            $instance    = $this->makeInstance($method->getDeclaringClass()->getName());
            $returnValue = $method->invoke($instance);
            return $returnValue instanceof Relation;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Call the relationship method and build a RelationshipInfo DTO.
     *
     * @param string           $modelClass
     * @param ReflectionMethod $method
     * @return RelationshipInfo|null
     */
    protected function extractRelationshipInfo($modelClass, ReflectionMethod $method)
    {
        try {
            $instance = $this->makeInstance($modelClass);
            /** @var Relation $relation */
            $relation = $method->invoke($instance);

            if (!($relation instanceof Relation)) {
                return null;
            }

            $type        = $this->getRelationType($relation);
            $related     = get_class($relation->getRelated());
            $foreignKey  = $this->getForeignKey($relation, $type);
            $ownerKey    = $this->getOwnerKey($relation, $type);
            $table       = $relation->getRelated()->getTable();
            $pivotTable  = $this->getPivotTable($relation, $type);
            $line        = $method->getStartLine();

            return new RelationshipInfo(
                $method->getName(),
                $type,
                $related,
                $foreignKey,
                $ownerKey,
                $table,
                $pivotTable,
                $line
            );
        } catch (\Throwable $e) {
            $this->parseErrors[] = [
                'model' => $modelClass,
                'method' => $method->getName(),
                'error' => $e->getMessage(),
            ];
            return null;
        }
    }

    /**
     * Return and clear parser errors from the last parse() call.
     *
     * @return array<int, array<string, string>>
     */
    public function consumeErrors()
    {
        $errors = $this->parseErrors;
        $this->parseErrors = [];

        return $errors;
    }

    /**
     * Return the short class name of the relation (e.g. "HasMany").
     *
     * @param Relation $relation
     * @return string
     */
    private function getRelationType(Relation $relation)
    {
        $parts = explode('\\', get_class($relation));
        return end($parts);
    }

    /**
     * Extract the foreign key from a relation object.
     *
     * @param Relation $relation
     * @param string   $type
     * @return string|null
     */
    private function getForeignKey(Relation $relation, $type)
    {
        try {
            switch ($type) {
                case 'HasOne':
                case 'HasMany':
                case 'MorphOne':
                case 'MorphMany':
                    return $relation->getForeignKeyName();

                case 'BelongsTo':
                    return $relation->getForeignKeyName();

                case 'BelongsToMany':
                case 'MorphToMany':
                case 'MorphedByMany':
                    return $relation->getForeignPivotKeyName();

                default:
                    return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract the owner/local key from a relation object.
     *
     * @param Relation $relation
     * @param string   $type
     * @return string|null
     */
    private function getOwnerKey(Relation $relation, $type)
    {
        try {
            switch ($type) {
                case 'BelongsTo':
                    return $relation->getOwnerKeyName();

                case 'HasOne':
                case 'HasMany':
                    return $relation->getLocalKeyName();

                case 'BelongsToMany':
                case 'MorphToMany':
                case 'MorphedByMany':
                    return $relation->getRelatedPivotKeyName();

                default:
                    return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return the pivot/join table name for many-to-many relations.
     *
     * @param Relation $relation
     * @param string   $type
     * @return string|null
     */
    private function getPivotTable(Relation $relation, $type)
    {
        if (!in_array($type, ['BelongsToMany', 'MorphToMany', 'MorphedByMany'], true)) {
            return null;
        }

        try {
            return $relation->getTable();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create an instance of the model without triggering the constructor side-effects.
     *
     * @param string $modelClass
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function makeInstance($modelClass)
    {
        $reflection = new ReflectionClass($modelClass);
        /** @var \Illuminate\Database\Eloquent\Model $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        return $instance;
    }
}
