<?php

namespace Devlin\ModelAnalyzer\Models;

class AnalysisResult
{
    /** @var ModelInfo[] */
    public $models = [];

    /** @var array Raw database schema keyed by table name */
    public $schema = [];

    /** @var Issue[] */
    public $issues = [];

    /** @var int */
    public $healthScore = 0;

    /**
     * @param Issue $issue
     * @return void
     */
    public function addIssue(Issue $issue)
    {
        $this->issues[] = $issue;
    }

    /**
     * @param string $severity  error|warning|info
     * @return Issue[]
     */
    public function getIssuesBySeverity($severity)
    {
        $filtered = [];
        foreach ($this->issues as $issue) {
            if ($issue->severity === $severity) {
                $filtered[] = $issue;
            }
        }
        return $filtered;
    }

    /**
     * @return Issue[]
     */
    public function getErrors()
    {
        return $this->getIssuesBySeverity('error');
    }

    /**
     * @return Issue[]
     */
    public function getWarnings()
    {
        return $this->getIssuesBySeverity('warning');
    }

    /**
     * @return Issue[]
     */
    public function getInfos()
    {
        return $this->getIssuesBySeverity('info');
    }

    /**
     * @return int Total relationship count across all models
     */
    public function totalRelationships()
    {
        $count = 0;
        foreach ($this->models as $model) {
            $count += count($model->relationships);
        }
        return $count;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $issueData  = [];
        foreach ($this->issues as $issue) {
            $issueData[] = $issue->toArray();
        }

        return [
            'health' => [
                'score' => $this->healthScore,
                'stats' => [
                    'models'        => count($this->models),
                    'tables'        => count($this->schema),
                    'relationships' => $this->totalRelationships(),
                    'errors'        => count($this->getErrors()),
                    'warnings'      => count($this->getWarnings()),
                    'infos'         => count($this->getInfos()),
                ],
            ],
            'issues' => $issueData,
        ];
    }
}
