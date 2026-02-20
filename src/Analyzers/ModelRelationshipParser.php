<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Devlin\ModelAnalyzer\Models\RelationshipInfo;

class ModelRelationshipParser
{
    /** @var array<int, array<string, string>> */
    private $parseErrors = [];

    /**
     * Cache of parsed file contexts (namespace + use-statements) keyed by filename.
     * Avoids re-tokenising the same file for every method in it.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private $fileContextCache = [];

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
     * Eloquent method name → Relation short type name.
     *
     * @var array<string, string>
     */
    private static $typeMap = [
        'hasOne'         => 'HasOne',
        'hasMany'        => 'HasMany',
        'belongsTo'      => 'BelongsTo',
        'belongsToMany'  => 'BelongsToMany',
        'morphOne'       => 'MorphOne',
        'morphMany'      => 'MorphMany',
        'morphTo'        => 'MorphTo',
        'morphToMany'    => 'MorphToMany',
        'morphedByMany'  => 'MorphedByMany',
        'hasOneThrough'  => 'HasOneThrough',
        'hasManyThrough' => 'HasManyThrough',
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

        // Fall back: scan the method source for known Eloquent relationship calls.
        // Avoids executing arbitrary model code (which can call exit/die/dd).
        try {
            $filename = $method->getFileName();
            if ($filename === false) {
                return false;
            }

            $lines = file($filename, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                return false;
            }

            $start = $method->getStartLine() - 1;
            $end   = $method->getEndLine() - 1;
            $body  = implode("\n", array_slice($lines, $start, $end - $start + 1));

            foreach (array_keys(self::$typeMap) as $rel) {
                if (strpos($body, '$this->' . $rel . '(') !== false) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build a RelationshipInfo DTO for a relationship method.
     *
     * Tries static source parsing first (never executes user code, so safe against
     * exit()/die()/dd() and boot-method side-effects).  Falls back to method
     * invocation only when static parsing cannot resolve the related class.
     *
     * @param string           $modelClass
     * @param ReflectionMethod $method
     * @return RelationshipInfo|null
     */
    protected function extractRelationshipInfo($modelClass, ReflectionMethod $method)
    {
        // --- primary path: static source analysis (no code execution) ---
        try {
            $info = $this->extractFromSource($modelClass, $method);
            if ($info !== null) {
                return $info;
            }
        } catch (\Throwable $e) {
            // fall through to invocation
        }

        // --- fallback: invoke the method (handles dynamic/runtime relationships) ---
        try {
            $instance = $this->makeInstance($modelClass);
            /** @var Relation $relation */
            $relation = $method->invoke($instance);

            if (!($relation instanceof Relation)) {
                return null;
            }

            $type       = $this->getRelationType($relation);
            $related    = get_class($relation->getRelated());
            $foreignKey = $this->getForeignKey($relation, $type);
            $ownerKey   = $this->getOwnerKey($relation, $type);
            $table      = $relation->getRelated()->getTable();
            $pivotTable = $this->getPivotTable($relation, $type);
            $line       = $method->getStartLine();

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
                'model'  => $modelClass,
                'method' => $method->getName(),
                'error'  => $e->getMessage(),
            ];
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Static source-parsing helpers
    // -------------------------------------------------------------------------

    /**
     * Extract relationship info by reading the method source — zero code execution.
     *
     * @param string           $modelClass
     * @param ReflectionMethod $method
     * @return RelationshipInfo|null
     */
    private function extractFromSource($modelClass, ReflectionMethod $method)
    {
        $filename = $method->getFileName();
        if ($filename === false) {
            return null;
        }

        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $start = $method->getStartLine() - 1;
        $end   = $method->getEndLine() - 1;
        $body  = implode("\n", array_slice($lines, $start, $end - $start + 1));

        // Find the relation method name used in the body
        $relMethod = null;
        $type      = null;
        foreach (self::$typeMap as $methodName => $typeName) {
            if (strpos($body, '$this->' . $methodName . '(') !== false) {
                $relMethod = $methodName;
                $type      = $typeName;
                break;
            }
        }

        if ($relMethod === null) {
            return null;
        }

        // morphTo has no fixed related class — skip static path
        if ($relMethod === 'morphTo') {
            return null;
        }

        // Extract the raw argument tokens from the call
        $args = $this->extractCallArgs($body, $relMethod);
        if (empty($args)) {
            return null;
        }

        // Resolve the related class (first argument)
        if (!isset($this->fileContextCache[$filename])) {
            $this->fileContextCache[$filename] = $this->parseFileContext($filename);
        }
        [$namespace, $uses] = $this->fileContextCache[$filename];

        $related = $this->resolveClass($args[0], $namespace, $uses, $modelClass);
        if ($related === null || !class_exists($related)) {
            return null;
        }

        // Get the related model's table without invoking its constructor
        $table = null;
        try {
            $relRef = new ReflectionClass($related);
            if ($relRef->isSubclassOf(Model::class)) {
                $relInstance = $relRef->newInstanceWithoutConstructor();
                $table = $relInstance->getTable();
            }
        } catch (\Throwable $e) {
            // leave table as null
        }

        // Extract key columns and pivot table from remaining arguments
        $foreignKey = null;
        $ownerKey   = null;
        $pivotTable = null;

        if (in_array($type, ['BelongsToMany', 'MorphToMany', 'MorphedByMany'], true)) {
            $pivotTable = isset($args[1]) ? $this->stripQuotes($args[1]) : null;
            $foreignKey = isset($args[2]) ? $this->stripQuotes($args[2]) : null;
            $ownerKey   = isset($args[3]) ? $this->stripQuotes($args[3]) : null;
        } else {
            $foreignKey = isset($args[1]) ? $this->stripQuotes($args[1]) : null;
            $ownerKey   = isset($args[2]) ? $this->stripQuotes($args[2]) : null;
        }

        // Discard anything that looks like a class reference rather than a column name
        if ($foreignKey !== null && (strpos($foreignKey, '\\') !== false || strpos($foreignKey, '::') !== false)) {
            $foreignKey = null;
        }
        if ($ownerKey !== null && (strpos($ownerKey, '\\') !== false || strpos($ownerKey, '::') !== false)) {
            $ownerKey = null;
        }
        if ($pivotTable !== null && (strpos($pivotTable, '\\') !== false || strpos($pivotTable, '::') !== false)) {
            $pivotTable = null;
        }

        // Apply Eloquent's FK inference conventions when not explicitly provided
        if ($foreignKey === null) {
            switch ($type) {
                case 'HasOne':
                case 'HasMany':
                case 'MorphOne':
                case 'MorphMany':
                    // FK lives on the related table: {owner_snake}_id
                    $foreignKey = Str::snake(class_basename($modelClass)) . '_id';
                    break;
                case 'BelongsTo':
                    // FK lives on the owner table: {related_snake}_id
                    $foreignKey = Str::snake(class_basename($related)) . '_id';
                    break;
                case 'BelongsToMany':
                case 'MorphToMany':
                case 'MorphedByMany':
                    // FK in pivot for owner side: {owner_snake}_id
                    $foreignKey = Str::snake(class_basename($modelClass)) . '_id';
                    break;
            }
        }

        return new RelationshipInfo(
            $method->getName(),
            $type,
            $related,
            $foreignKey,
            $ownerKey,
            $table,
            $pivotTable,
            $method->getStartLine()
        );
    }

    /**
     * Extract the raw argument strings from a `$this->methodName(...)` call in $body.
     *
     * Handles nested parentheses and string literals correctly.
     *
     * @param string $body
     * @param string $relMethod
     * @return string[]
     */
    private function extractCallArgs($body, $relMethod)
    {
        $needle = '$this->' . $relMethod . '(';
        $pos    = strpos($body, $needle);
        if ($pos === false) {
            return [];
        }
        $pos += strlen($needle);

        $args    = [];
        $current = '';
        $depth   = 1;
        $inStr   = false;
        $strChar = null;
        $len     = strlen($body);

        for ($i = $pos; $i < $len; $i++) {
            $c = $body[$i];

            if ($inStr) {
                $current .= $c;
                if ($c === $strChar && ($i === 0 || $body[$i - 1] !== '\\')) {
                    $inStr = false;
                }
                continue;
            }

            if ($c === '\'' || $c === '"') {
                $inStr   = true;
                $strChar = $c;
                $current .= $c;
                continue;
            }

            if ($c === '(') {
                $depth++;
                $current .= $c;
                continue;
            }

            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    if (trim($current) !== '') {
                        $args[] = trim($current);
                    }
                    break;
                }
                $current .= $c;
                continue;
            }

            if ($c === ',' && $depth === 1) {
                $args[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $c;
        }

        return $args;
    }

    /**
     * Parse the namespace and file-level use-import statements from a PHP source file.
     *
     * @param  string $filename
     * @return array{0: string, 1: array<string, string>}  [$namespace, $uses]
     */
    private function parseFileContext($filename)
    {
        $namespace = '';
        $uses      = [];

        $source = @file_get_contents($filename);
        if ($source === false) {
            return [$namespace, $uses];
        }

        $tokens     = token_get_all($source);
        $count      = count($tokens);
        $blockDepth = 0; // tracks { } nesting; file-level use statements are at depth 0

        for ($i = 0; $i < $count; $i++) {
            // Track brace depth to distinguish file-level vs class-level `use`
            if ($tokens[$i] === '{') {
                $blockDepth++;
                continue;
            }
            if ($tokens[$i] === '}') {
                $blockDepth--;
                continue;
            }

            if (!is_array($tokens[$i])) {
                continue;
            }

            // Namespace declaration (always at depth 0 in well-formed PHP)
            if ($tokens[$i][0] === T_NAMESPACE && $blockDepth === 0) {
                $ns = '';
                $i++;
                while ($i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i]) && $tokens[$i][0] !== T_WHITESPACE) {
                        $ns .= $tokens[$i][1];
                    }
                    $i++;
                }
                $namespace = $ns;
                continue;
            }

            // File-level use import (depth 0 only — class-body `use` for traits is depth ≥ 1)
            if ($tokens[$i][0] === T_USE && $blockDepth === 0) {
                $fq    = '';
                $alias = null;
                $i++;
                while ($i < $count && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i])) {
                        if ($tokens[$i][0] === T_AS) {
                            $i++;
                            while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                                $i++;
                            }
                            if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                                $alias = $tokens[$i][1];
                            }
                        } elseif ($tokens[$i][0] !== T_WHITESPACE) {
                            $fq .= $tokens[$i][1];
                        }
                    }
                    $i++;
                }
                $fq = trim($fq);
                if ($fq !== '') {
                    $short = $alias ?? (($p = strrpos($fq, '\\')) !== false
                        ? substr($fq, $p + 1)
                        : $fq);
                    $uses[$short] = $fq;
                }
                continue;
            }
        }

        return [$namespace, $uses];
    }

    /**
     * Resolve a raw class token (e.g. "City::class", "'App\\Model\\City'", "City")
     * to a fully-qualified class name using the file's namespace and use-imports.
     *
     * @param string                $raw
     * @param string                $namespace
     * @param array<string, string> $uses
     * @param string                $modelClass  The declaring model (for self/static)
     * @return string|null
     */
    private function resolveClass($raw, $namespace, array $uses, $modelClass)
    {
        $raw = trim($raw);

        // Strip named-argument label, e.g. "related: City::class" → "City::class"
        // (only strip if there is a single colon that is not part of ::)
        if (preg_match('/^[a-zA-Z_]\w*\s*:\s*(?!:)(.+)$/s', $raw, $m)) {
            $raw = trim($m[1]);
        }

        // self::class / static::class → the declaring model
        if (in_array(strtolower($raw), ['self::class', 'static::class'], true)) {
            return $modelClass;
        }

        // Strip ::class suffix
        if (substr($raw, -7) === '::class') {
            $raw = rtrim(substr($raw, 0, -7));
        }

        // Quoted string → treat as fully-qualified (or rooted) class name
        if (strlen($raw) >= 2 && ($raw[0] === '\'' || $raw[0] === '"')) {
            return trim($raw, "'\"");
        }

        // Variable → cannot resolve statically
        if ($raw !== '' && $raw[0] === '$') {
            return null;
        }

        // Already fully-qualified (leading backslash)
        if ($raw !== '' && $raw[0] === '\\') {
            return ltrim($raw, '\\');
        }

        // Check use-import aliases (handles "Foo\Bar" where "Foo" is aliased)
        $parts = explode('\\', $raw);
        if (isset($uses[$parts[0]])) {
            $resolved = $uses[$parts[0]];
            if (count($parts) > 1) {
                $resolved .= '\\' . implode('\\', array_slice($parts, 1));
            }
            return $resolved;
        }

        // Assume same namespace as the declaring file
        return $namespace !== '' ? $namespace . '\\' . $raw : $raw;
    }

    /**
     * Strip surrounding single or double quotes from a string literal token.
     *
     * @param string $value
     * @return string
     */
    private function stripQuotes($value)
    {
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '\'' || $value[0] === '"')) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    // -------------------------------------------------------------------------
    // Invocation-based helpers (used only in the fallback path)
    // -------------------------------------------------------------------------

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
