<?php

namespace Devlin\ModelAnalyzer\Models;

class RelationshipInfo
{
    /** @var string Method name, e.g. "posts" */
    public $name;

    /** @var string Short class name, e.g. "HasMany" */
    public $type;

    /** @var string Fully-qualified related model class */
    public $related;

    /** @var string|null */
    public $foreignKey;

    /** @var string|null */
    public $ownerKey;

    /** @var string|null Related table name */
    public $table;

    /** @var string|null Pivot table for BelongsToMany */
    public $pivotTable;

    /** @var int|null Source line number (for reporting) */
    public $methodLine;

    /**
     * @param string      $name
     * @param string      $type
     * @param string      $related
     * @param string|null $foreignKey
     * @param string|null $ownerKey
     * @param string|null $table
     * @param string|null $pivotTable
     * @param int|null    $methodLine
     */
    public function __construct(
        $name,
        $type,
        $related,
        $foreignKey = null,
        $ownerKey = null,
        $table = null,
        $pivotTable = null,
        $methodLine = null
    ) {
        $this->name       = $name;
        $this->type       = $type;
        $this->related    = $related;
        $this->foreignKey = $foreignKey;
        $this->ownerKey   = $ownerKey;
        $this->table      = $table;
        $this->pivotTable = $pivotTable;
        $this->methodLine = $methodLine;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'name'        => $this->name,
            'type'        => $this->type,
            'related'     => $this->related,
            'foreign_key' => $this->foreignKey,
            'owner_key'   => $this->ownerKey,
            'table'       => $this->table,
            'pivot_table' => $this->pivotTable,
            'method_line' => $this->methodLine,
        ];
    }
}
