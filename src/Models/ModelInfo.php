<?php

namespace Devlin\ModelAnalyzer\Models;

class ModelInfo
{
    /** @var string Fully-qualified class name */
    public $class;

    /** @var string Short class name */
    public $shortName;

    /** @var string Database table name */
    public $table;

    /** @var RelationshipInfo[] */
    public $relationships;

    /**
     * @param string             $class
     * @param string             $shortName
     * @param string             $table
     * @param RelationshipInfo[] $relationships
     */
    public function __construct($class, $shortName, $table, $relationships = [])
    {
        $this->class         = $class;
        $this->shortName     = $shortName;
        $this->table         = $table;
        $this->relationships = $relationships;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $rels = [];
        foreach ($this->relationships as $rel) {
            $rels[] = $rel->toArray();
        }

        return [
            'class'         => $this->class,
            'short_name'    => $this->shortName,
            'table'         => $this->table,
            'relationships' => $rels,
        ];
    }
}
