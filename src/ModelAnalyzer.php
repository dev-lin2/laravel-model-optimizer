<?php

namespace Devlin\ModelAnalyzer;

use Devlin\ModelAnalyzer\Analyzers\CircularDependencyDetector;
use Devlin\ModelAnalyzer\Analyzers\DatabaseMismatchDetector;
use Devlin\ModelAnalyzer\Analyzers\DatabaseSchemaReader;
use Devlin\ModelAnalyzer\Analyzers\IndexAnalyzer;
use Devlin\ModelAnalyzer\Analyzers\InverseDetector;
use Devlin\ModelAnalyzer\Analyzers\ModelRelationshipParser;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;
use Devlin\ModelAnalyzer\Models\ModelInfo;
use Devlin\ModelAnalyzer\Support\ModelScanner;

class ModelAnalyzer
{
    /** @var array */
    private $config;

    /** @var DatabaseSchemaReader */
    private $schemaReader;

    /** @var ModelRelationshipParser */
    private $relationshipParser;

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults(), $config);

        $this->schemaReader = new DatabaseSchemaReader(
            $this->config['database_connection'] ?? null,
            $this->config['excluded_tables']
        );

        $this->relationshipParser = new ModelRelationshipParser();
    }

    /**
     * Run a full analysis and return the populated AnalysisResult.
     *
     * @param string[]|null $onlyModels  Optional allowlist of short/FQ model names
     * @param callable|null $progress Optional callback receiving ($event, $payload)
     * @return AnalysisResult
     */
    public function analyze($onlyModels = null, $progress = null)
    {
        $result = new AnalysisResult();

        // 1. Scan models
        $scanner = new ModelScanner(
            $this->config['model_paths'],
            $this->config['excluded_models']
        );

        $classes = $scanner->scan();

        // Optional filter
        if ($onlyModels !== null && count($onlyModels) > 0) {
            $classes = $this->filterClasses($classes, $onlyModels);
        }

        if (is_callable($progress)) {
            $progress('scan_complete', ['models' => count($classes)]);
        }

        // 2. Build ModelInfo objects
        foreach ($classes as $class) {
            $shortName = class_basename($class);

            if (is_callable($progress)) {
                $progress('model_start', [
                    'class' => $class,
                    'short_name' => $shortName,
                ]);
            }

            try {
                $reflection    = new \ReflectionClass($class);
                /** @var \Illuminate\Database\Eloquent\Model $instance */
                $instance      = $reflection->newInstanceWithoutConstructor();
                $relationships = $this->relationshipParser->parse($class);
                $parseErrors   = $this->relationshipParser->consumeErrors();

                $result->models[] = new ModelInfo(
                    $class,
                    $shortName,
                    $instance->getTable(),
                    $relationships
                );

                foreach ($parseErrors as $error) {
                    $result->addIssue(new Issue(
                        'relationship_parse_error',
                        'warning',
                        $shortName,
                        sprintf(
                            'Failed to inspect relationship method %s::%s(): %s',
                            $shortName,
                            $error['method'],
                            $error['error']
                        ),
                        'Review this relationship method for side effects and return a valid Eloquent relation.',
                        [
                            'model' => $class,
                            'method' => $error['method'],
                        ]
                    ));
                }

                if (is_callable($progress)) {
                    $progress('model_done', [
                        'class' => $class,
                        'short_name' => $shortName,
                        'relationships' => count($relationships),
                    ]);
                }
            } catch (\Throwable $e) {
                $result->addIssue(new Issue(
                    'model_analysis_error',
                    'error',
                    $shortName,
                    sprintf(
                        'Failed to analyze model %s: %s',
                        $shortName,
                        $e->getMessage()
                    ),
                    'Check constructor/property initialization and relationship methods for this model.',
                    ['model' => $class]
                ));

                if (is_callable($progress)) {
                    $progress('model_error', [
                        'class' => $class,
                        'short_name' => $shortName,
                        'error' => $e->getMessage(),
                    ]);
                }

                continue;
            }
        }

        // 3. Capture schema snapshot
        try {
            $result->schema = $this->buildSchemaSnapshot($result);
        } catch (\Throwable $e) {
            $result->addIssue(new Issue(
                'schema_read_error',
                'error',
                'system',
                sprintf('Failed to read database schema: %s', $e->getMessage()),
                'Check your database connection and configuration.',
                []
            ));
        }

        // 4. Run detectors
        $detectors = [
            new InverseDetector(),
            new CircularDependencyDetector(),
            new DatabaseMismatchDetector($this->schemaReader),
            new IndexAnalyzer($this->schemaReader),
        ];

        foreach ($detectors as $detector) {
            try {
                $detector->detect($result);
            } catch (\Throwable $e) {
                $result->addIssue(new Issue(
                    'detector_error',
                    'error',
                    'system',
                    sprintf('%s failed: %s', class_basename($detector), $e->getMessage()),
                    'This is an internal analysis error. Please report it.',
                    ['detector' => get_class($detector)]
                ));
            }
        }

        // 5. Calculate health score
        $result->healthScore = $this->calculateHealthScore($result);

        return $result;
    }

    /**
     * Build a compact schema snapshot (table => columns) for the result DTO.
     *
     * @param AnalysisResult $result
     * @return array
     */
    private function buildSchemaSnapshot(AnalysisResult $result)
    {
        $schema = [];

        foreach ($this->schemaReader->getTables() as $table) {
            $schema[$table] = array_keys($this->schemaReader->getColumns($table));
        }

        return $schema;
    }

    /**
     * Calculate a 0–100 health score based on issue counts and weights.
     *
     * @param AnalysisResult $result
     * @return int
     */
    private function calculateHealthScore(AnalysisResult $result)
    {
        $totalRelationships = $result->totalRelationships();

        if ($totalRelationships === 0) {
            return 100;
        }

        $weights = $this->config['health_weights'];
        $maxScore = array_sum($weights);

        // Count issues by type
        $typeCounts = [];
        foreach ($result->issues as $issue) {
            $typeCounts[$issue->type] = ($typeCounts[$issue->type] ?? 0) + 1;
        }

        $deductions = 0;

        // Deduct based on severity and weights
        $errors   = count($result->getErrors());
        $warnings = count($result->getWarnings());

        // Proportional deduction: each error costs more than a warning
        $errorWeight   = 2;
        $warningWeight = 1;

        $weightedIssues = ($errors * $errorWeight) + ($warnings * $warningWeight);
        $maxIssues      = $totalRelationships * $errorWeight;

        $deductionRatio = min(1.0, $weightedIssues / $maxIssues);
        $score = (int) round(100 * (1 - $deductionRatio));

        return max(0, min(100, $score));
    }

    /**
     * Filter class list to only include those matching the given names.
     *
     * @param string[] $classes
     * @param string[] $filter
     * @return string[]
     */
    private function filterClasses(array $classes, array $filter)
    {
        return array_filter($classes, function ($class) use ($filter) {
            foreach ($filter as $name) {
                if ($class === $name || class_basename($class) === $name) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Default configuration values.
     *
     * @return array
     */
    private function defaults()
    {
        return [
            'model_paths'           => [],
            'excluded_models'       => [],
            'excluded_tables'       => [],
            'relationship_types'    => [],
            'database_connection'   => null,
            'strict_mode'           => false,
            'health_weights'        => [
                'has_inverse'     => 30,
                'no_circular'     => 30,
                'column_exists'   => 20,
                'has_index'       => 10,
                'has_foreign_key' => 10,
            ],
        ];
    }
}
