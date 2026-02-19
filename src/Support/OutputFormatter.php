<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\Issue;
use Illuminate\Console\OutputStyle;

class OutputFormatter
{
    /** @var OutputStyle */
    private $output;

    /**
     * @param OutputStyle $output
     */
    public function __construct(OutputStyle $output)
    {
        $this->output = $output;
    }

    /**
     * Print the full analysis report to the console.
     *
     * @param AnalysisResult $result
     * @return void
     */
    public function printAnalysis(AnalysisResult $result)
    {
        $this->output->newLine();
        $this->output->writeln('<info>Analyzing relationships...</info>');
        $this->output->newLine();

        $this->printHealthScore($result->healthScore);
        $this->printStats($result);
        $this->printIssues($result);
    }

    /**
     * Print the health report (used by HealthCommand).
     *
     * @param AnalysisResult $result
     * @return void
     */
    public function printHealthReport(AnalysisResult $result)
    {
        $this->output->newLine();
        $this->output->writeln('<options=bold>Relationship Health Report</>');
        $this->output->writeln(str_repeat('=', 45));
        $this->output->newLine();

        $this->printHealthScore($result->healthScore);
        $this->output->newLine();

        $this->printGroupedIssues($result);
        $this->printRecommendations($result);
    }

    /**
     * Print the health score line with colour.
     *
     * @param int $score
     * @return void
     */
    private function printHealthScore($score)
    {
        if ($score >= 80) {
            $tag = 'info';
            $label = 'HEALTHY';
        } elseif ($score >= 60) {
            $tag = 'comment';
            $label = 'WARNING';
        } else {
            $tag = 'error';
            $label = 'CRITICAL';
        }

        $this->output->writeln(
            sprintf('<options=bold>Health Score:</> <%s>%d/100 (%s)</>', $tag, $score, $label)
        );
    }

    /**
     * Print summary statistics table.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function printStats(AnalysisResult $result)
    {
        $this->output->newLine();

        $errors   = count($result->getErrors());
        $warnings = count($result->getWarnings());
        $infos    = count($result->getInfos());

        $rows = [
            ['Models',        count($result->models)],
            ['Tables',        count($result->schema)],
            ['Relationships', $result->totalRelationships()],
            ['<fg=red>Errors</>',   "<fg=red>{$errors}</>"],
            ['<fg=yellow>Warnings</>', "<fg=yellow>{$warnings}</>"],
            ['<fg=blue>Info</>',    "<fg=blue>{$infos}</>"],
        ];

        $this->output->table(['Metric', 'Count'], $rows);
    }

    /**
     * Print issues grouped by severity.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function printIssues(AnalysisResult $result)
    {
        $errors   = $result->getErrors();
        $warnings = $result->getWarnings();
        $infos    = $result->getInfos();

        if (count($errors) === 0 && count($warnings) === 0 && count($infos) === 0) {
            $this->output->writeln('<info>No issues found. All relationships look healthy!</info>');
            return;
        }

        if (count($errors) > 0) {
            $this->output->newLine();
            $this->output->writeln(sprintf('<fg=red;options=bold>ERRORS (%d)</>', count($errors)));
            foreach ($errors as $issue) {
                $this->printIssue($issue);
            }
        }

        if (count($warnings) > 0) {
            $this->output->newLine();
            $this->output->writeln(sprintf('<fg=yellow;options=bold>WARNINGS (%d)</>', count($warnings)));
            foreach ($warnings as $issue) {
                $this->printIssue($issue);
            }
        }

        if (count($infos) > 0) {
            $this->output->newLine();
            $this->output->writeln(sprintf('<fg=blue;options=bold>INFO (%d)</>', count($infos)));
            foreach ($infos as $issue) {
                $this->printIssue($issue);
            }
        }
    }

    /**
     * Print a single issue with optional suggestion.
     *
     * @param Issue $issue
     * @return void
     */
    private function printIssue(Issue $issue)
    {
        $this->output->writeln('  • ' . $issue->message);

        if ($issue->suggestion !== null) {
            foreach (explode("\n", $issue->suggestion) as $line) {
                $this->output->writeln('    <comment>' . $line . '</comment>');
            }
        }
    }

    /**
     * Print issues grouped for the health report.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function printGroupedIssues(AnalysisResult $result)
    {
        $errors   = $result->getErrors();
        $warnings = $result->getWarnings();
        $infos    = $result->getInfos();

        if (count($errors) > 0) {
            $this->output->writeln(sprintf('<fg=red>CRITICAL (%d)</>', count($errors)));
            foreach ($errors as $issue) {
                $this->output->writeln('  ' . $issue->model . ': ' . $issue->message);
            }
            $this->output->newLine();
        }

        if (count($warnings) > 0) {
            $this->output->writeln(sprintf('<fg=yellow>WARNINGS (%d)</>', count($warnings)));
            foreach ($warnings as $issue) {
                $this->output->writeln('  ' . $issue->model . ': ' . $issue->message);
            }
            $this->output->newLine();
        }

        if (count($infos) > 0) {
            $this->output->writeln(sprintf('<fg=blue>INFO (%d)</>', count($infos)));
            foreach ($infos as $issue) {
                $this->output->writeln('  ' . $issue->model . ': ' . $issue->message);
            }
            $this->output->newLine();
        }
    }

    /**
     * Print actionable recommendations.
     *
     * @param AnalysisResult $result
     * @return void
     */
    private function printRecommendations(AnalysisResult $result)
    {
        $recommendations = $this->buildRecommendations($result);

        if (count($recommendations) === 0) {
            $this->output->writeln('<info>No recommendations — everything looks great!</info>');
            return;
        }

        $this->output->writeln('<options=bold>RECOMMENDATIONS</>');
        foreach ($recommendations as $i => $rec) {
            $this->output->writeln(sprintf('  %d. %s', $i + 1, $rec));
        }
        $this->output->newLine();
    }

    /**
     * Build a list of recommendation strings from the result.
     *
     * @param AnalysisResult $result
     * @return string[]
     */
    private function buildRecommendations(AnalysisResult $result)
    {
        $recs     = [];
        $typeCounts = [];

        foreach ($result->issues as $issue) {
            $typeCounts[$issue->type] = ($typeCounts[$issue->type] ?? 0) + 1;
        }

        if (isset($typeCounts['missing_inverse'])) {
            $recs[] = sprintf('Add %d missing inverse relationship(s)', $typeCounts['missing_inverse']);
        }

        if (isset($typeCounts['circular_dependency'])) {
            $recs[] = sprintf('Review %d circular relationship(s)', $typeCounts['circular_dependency']);
        }

        if (isset($typeCounts['missing_table'])) {
            $recs[] = sprintf('Create %d missing table(s) via migrations', $typeCounts['missing_table']);
        }

        if (isset($typeCounts['missing_column'])) {
            $recs[] = sprintf('Add %d missing column(s) via migrations', $typeCounts['missing_column']);
        }

        if (isset($typeCounts['missing_foreign_key'])) {
            $recs[] = sprintf('Add %d missing foreign key constraint(s)', $typeCounts['missing_foreign_key']);
        }

        if (isset($typeCounts['missing_index'])) {
            $recs[] = sprintf('Add %d missing index(es) on foreign key columns', $typeCounts['missing_index']);
        }

        if (isset($typeCounts['orphaned_foreign_key'])) {
            $recs[] = sprintf(
                'Review %d orphaned foreign key column(s) that have no model relationship',
                $typeCounts['orphaned_foreign_key']
            );
        }

        return $recs;
    }
}
