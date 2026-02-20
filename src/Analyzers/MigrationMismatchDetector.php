<?php

namespace Devlin\ModelAnalyzer\Analyzers;

use Devlin\ModelAnalyzer\Contracts\DetectorInterface;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;

/**
 * Compares the schema derived from migration files against the actual database,
 * surfacing pending migrations, schema drift, and tables with no migration.
 */
class MigrationMismatchDetector implements DetectorInterface
{
    /**
     * Schema parsed from migration files: table → [column → simplified_type].
     *
     * @var array<string, array<string, string>>
     */
    private $migrationSchema;

    /** @var DatabaseSchemaReader */
    private $dbSchema;

    /**
     * @param array<string, array<string, string>> $migrationSchema
     * @param DatabaseSchemaReader                  $dbSchema
     */
    public function __construct(array $migrationSchema, DatabaseSchemaReader $dbSchema)
    {
        $this->migrationSchema = $migrationSchema;
        $this->dbSchema        = $dbSchema;
    }

    /**
     * {@inheritdoc}
     */
    public function detect(AnalysisResult $result)
    {
        if (empty($this->migrationSchema)) {
            return;
        }

        $this->checkMigrationsAgainstDb($result);
        $this->checkDbAgainstMigrations($result);
    }

    /**
     * For each table/column defined in migrations, verify it exists in the DB.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function checkMigrationsAgainstDb(AnalysisResult $result)
    {
        foreach ($this->migrationSchema as $table => $migrationColumns) {
            if (!$this->dbSchema->tableExists($table)) {
                $result->addIssue(new Issue(
                    'pending_migration',
                    'error',
                    'migrations',
                    sprintf(
                        'Table "%s" is defined in migrations but does not exist in the database',
                        $table
                    ),
                    'Run: php artisan migrate',
                    ['table' => $table]
                ));
                continue;
            }

            foreach ($migrationColumns as $column => $type) {
                if (!$this->dbSchema->columnExists($table, $column)) {
                    $result->addIssue(new Issue(
                        'column_not_in_db',
                        'warning',
                        'migrations',
                        sprintf(
                            'Column "%s.%s" (%s) is defined in migrations but missing from the database',
                            $table,
                            $column,
                            $type
                        ),
                        'Run: php artisan migrate',
                        ['table' => $table, 'column' => $column, 'migration_type' => $type]
                    ));
                }
            }
        }
    }

    /**
     * For each table in the DB, verify it has a migration and all its columns are covered.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function checkDbAgainstMigrations(AnalysisResult $result)
    {
        foreach ($this->dbSchema->getTables() as $table) {
            if (!isset($this->migrationSchema[$table])) {
                $result->addIssue(new Issue(
                    'no_migration_for_table',
                    'info',
                    'migrations',
                    sprintf(
                        'Table "%s" exists in the database but has no corresponding migration file',
                        $table
                    ),
                    sprintf(
                        'Create a migration: php artisan make:migration create_%s_table',
                        $table
                    ),
                    ['table' => $table]
                ));
                continue;
            }

            $migratedColumns = array_keys($this->migrationSchema[$table]);

            foreach (array_keys($this->dbSchema->getColumns($table)) as $column) {
                if (!in_array($column, $migratedColumns, true)) {
                    $result->addIssue(new Issue(
                        'db_column_not_in_migration',
                        'info',
                        'migrations',
                        sprintf(
                            'Column "%s.%s" exists in the database but is not defined in any migration',
                            $table,
                            $column
                        ),
                        sprintf(
                            'Add to a migration: php artisan make:migration add_%s_to_%s_table',
                            $column,
                            $table
                        ),
                        ['table' => $table, 'column' => $column]
                    ));
                }
            }
        }
    }
}
