<?php

namespace Devlin\ModelAnalyzer\Models;

class Issue
{
    /** @var string */
    public $type;

    /** @var string error|warning|info */
    public $severity;

    /** @var string */
    public $model;

    /** @var string */
    public $message;

    /** @var string|null */
    public $suggestion;

    /** @var array */
    public $context;

    /**
     * @param string      $type
     * @param string      $severity
     * @param string      $model
     * @param string      $message
     * @param string|null $suggestion
     * @param array       $context
     */
    public function __construct(
        $type,
        $severity,
        $model,
        $message,
        $suggestion = null,
        $context = []
    ) {
        $this->type       = $type;
        $this->severity   = $severity;
        $this->model      = $model;
        $this->message    = $message;
        $this->suggestion = $suggestion;
        $this->context    = $context;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'type'       => $this->type,
            'severity'   => $this->severity,
            'model'      => $this->model,
            'message'    => $this->message,
            'suggestion' => $this->suggestion,
            'context'    => $this->context,
        ];
    }
}
