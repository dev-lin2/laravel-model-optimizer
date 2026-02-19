<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Devlin\ModelAnalyzer\Contracts\DetectorInterface;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;

class CircularDependencyDetector implements DetectorInterface
{
    /**
     * Adjacency list: modelClass => [relatedClass, ...]
     *
     * @var array
     */
    private $graph = [];

    /**
     * {@inheritdoc}
     */
    public function detect(AnalysisResult $result)
    {
        $this->buildGraph($result);

        $visited = [];
        $stack   = [];

        foreach (array_keys($this->graph) as $model) {
            if (!isset($visited[$model])) {
                $path  = [];
                $cycle = $this->detectCycle($model, $visited, $stack, $path);

                if ($cycle !== null) {
                    $cycleStr  = implode(' → ', array_map('class_basename', $cycle));
                    $cycleStr .= ' → ' . class_basename($cycle[0]); // close the loop

                    $result->addIssue(new Issue(
                        'circular_dependency',
                        'error',
                        class_basename($cycle[0]),
                        sprintf('Circular relationship detected: %s', $cycleStr),
                        'Review these relationships and consider whether bidirectional navigation is truly needed, or break the cycle by removing one direction.',
                        ['cycle' => $cycle]
                    ));
                }
            }
        }
    }

    /**
     * Build the relationship graph from analysis results.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function buildGraph(AnalysisResult $result)
    {
        $this->graph = [];

        foreach ($result->models as $model) {
            if (!isset($this->graph[$model->class])) {
                $this->graph[$model->class] = [];
            }

            foreach ($model->relationships as $rel) {
                if (!in_array($rel->related, $this->graph[$model->class], true)) {
                    $this->graph[$model->class][] = $rel->related;
                }
            }
        }
    }

    /**
     * DFS cycle detection. Returns the cycle path array or null.
     *
     * @param string   $model
     * @param array    $visited   Tracks globally visited nodes
     * @param array    $stack     Tracks nodes in current DFS path
     * @param string[] $path      Current traversal path
     * @return string[]|null
     */
    private function detectCycle($model, array &$visited, array &$stack, array &$path)
    {
        $visited[$model] = true;
        $stack[$model]   = true;
        $path[]          = $model;

        foreach ($this->graph[$model] ?? [] as $related) {
            // Only follow edges to models we actually scanned
            if (!isset($this->graph[$related])) {
                continue;
            }

            if (!isset($visited[$related])) {
                $cycle = $this->detectCycle($related, $visited, $stack, $path);
                if ($cycle !== null) {
                    return $cycle;
                }
            } elseif (isset($stack[$related])) {
                // Cycle found — slice path from the cycle entry point
                $startIndex = array_search($related, $path, true);
                return array_slice($path, $startIndex);
            }
        }

        array_pop($path);
        unset($stack[$model]);

        return null;
    }
}
