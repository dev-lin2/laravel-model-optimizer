<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Schema\SchemaDiff;
use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;

/**
 * Renders schema findings (problems), as opposed to DocsGenerator which
 * renders the schema itself.
 *
 * Covers: missing foreign key constraints, foreign keys without a supporting
 * index, and drift between two sources. Source errors and warnings are
 * included as their own section and can be filtered out by the caller.
 */
class ReportGenerator
{
    /** @var bool */
    private $includeErrors;

    /** @var bool */
    private $includeWarnings;

    /**
     * @param bool $includeErrors
     * @param bool $includeWarnings
     */
    public function __construct($includeErrors = true, $includeWarnings = true)
    {
        $this->includeErrors   = (bool) $includeErrors;
        $this->includeWarnings = (bool) $includeWarnings;
    }

    /**
     * Assemble the structured report payload shared by every format.
     *
     * @param  array<string, SchemaSnapshot> $snapshots
     * @param  array<string, array[]>        $findings  source => FK findings
     * @param  array|null                    $diff      SchemaDiff::compute() result
     * @return array
     */
    public function build(array $snapshots, array $findings, array $diff = null)
    {
        $sources = [];

        foreach ($snapshots as $name => $snapshot) {
            $sources[$name] = [
                'source'    => $snapshot->source,
                'available' => $snapshot->available,
                'tables'    => count($snapshot->tables),
                'errors'    => $this->includeErrors ? array_values($snapshot->errors) : [],
                'warnings'  => $this->includeWarnings ? array_values($snapshot->warnings) : [],
            ];
        }

        $missingIndexes = [];
        $totalMissingFk = 0;

        foreach ($findings as $source => $items) {
            $totalMissingFk += count($items);

            foreach ($items as $item) {
                if (empty($item['has_index'])) {
                    $missingIndexes[$source][] = $item;
                }
            }
        }

        return [
            'generated_at'         => date('c'),
            'sources'              => $sources,
            'missing_foreign_keys' => $findings,
            'unindexed_candidates' => $missingIndexes,
            'drift'                => $diff,
            'summary'              => [
                'missing_foreign_keys' => $totalMissingFk,
                'unindexed_candidates' => array_sum(array_map('count', $missingIndexes)),
                'drift_items'          => $diff !== null && $diff['comparable']
                    ? SchemaDiff::count($diff)
                    : 0,
            ],
        ];
    }

    /**
     * @param  array $report
     * @return string
     */
    public function toJson(array $report)
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? "{}\n" : $json . "\n";
    }

    /**
     * @param  array $report
     * @return string
     */
    public function toMarkdown(array $report)
    {
        $lines = [];

        $lines[] = '# Schema Report';
        $lines[] = '';
        $lines[] = sprintf('_Generated %s_', $report['generated_at']);
        $lines[] = '';

        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = '| Metric | Count |';
        $lines[] = '|---|---|';
        $lines[] = sprintf('| Missing foreign keys | %d |', $report['summary']['missing_foreign_keys']);
        $lines[] = sprintf('| FK candidates without index | %d |', $report['summary']['unindexed_candidates']);
        $lines[] = sprintf('| Drift items | %d |', $report['summary']['drift_items']);
        $lines[] = '';

        $lines[] = '## Sources';
        $lines[] = '';

        foreach ($report['sources'] as $source) {
            $lines[] = sprintf(
                '- **%s** — %s, %d tables',
                $source['source'],
                $source['available'] ? 'available' : '**unavailable**',
                $source['tables']
            );

            foreach ($source['errors'] as $error) {
                $lines[] = '  - error: ' . $error;
            }

            foreach ($source['warnings'] as $warning) {
                $lines[] = '  - warning: ' . $warning;
            }
        }

        $lines[] = '';

        foreach ($report['missing_foreign_keys'] as $source => $items) {
            $lines[] = sprintf('## Missing foreign keys (%s)', $source);
            $lines[] = '';

            if (count($items) === 0) {
                $lines[] = '_None found._';
                $lines[] = '';
                continue;
            }

            $lines[] = '| Table | Column | Should reference | Indexed | Suggested fix |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($items as $item) {
                $lines[] = sprintf(
                    '| `%s` | `%s` | `%s.%s` | %s | `%s` |',
                    $item['table'],
                    $item['column'],
                    $item['on'],
                    $item['references'],
                    !empty($item['has_index']) ? 'yes' : 'no',
                    $item['suggestion']
                );
            }

            $lines[] = '';
        }

        if (isset($report['drift']) && $report['drift'] !== null) {
            $lines = array_merge($lines, $this->markdownDrift($report['drift']));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  array $drift
     * @return string[]
     */
    private function markdownDrift(array $drift)
    {
        $lines   = [];
        $lines[] = '## Schema drift';
        $lines[] = '';

        if (!$drift['comparable']) {
            $lines[] = '_Not computed: ' . $drift['reason'] . '_';
            $lines[] = '';

            return $lines;
        }

        $total = SchemaDiff::count($drift);

        if ($total === 0) {
            $lines[] = sprintf('_No drift between %s and %s._', $drift['left'], $drift['right']);
            $lines[] = '';

            return $lines;
        }

        foreach ([
            'tables_only_in_left'   => sprintf('Tables only in %s', $drift['left']),
            'tables_only_in_right'  => sprintf('Tables only in %s', $drift['right']),
        ] as $key => $heading) {
            if (count($drift[$key]) > 0) {
                $lines[] = '**' . $heading . '**';
                $lines[] = '';

                foreach ($drift[$key] as $table) {
                    $lines[] = '- `' . $table . '`';
                }

                $lines[] = '';
            }
        }

        foreach ([
            'columns_only_in_left'  => sprintf('Columns only in %s', $drift['left']),
            'columns_only_in_right' => sprintf('Columns only in %s', $drift['right']),
        ] as $key => $heading) {
            if (count($drift[$key]) > 0) {
                $lines[] = '**' . $heading . '**';
                $lines[] = '';

                foreach ($drift[$key] as $entry) {
                    $lines[] = sprintf('- `%s.%s`', $entry['table'], $entry['column']);
                }

                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * @param  array $report
     * @return string
     */
    public function toHtml(array $report)
    {
        $summary = sprintf(
            '<div class="cards">
                <div class="card"><span class="n">%d</span><span class="l">Missing foreign keys</span></div>
                <div class="card"><span class="n">%d</span><span class="l">FK candidates without index</span></div>
                <div class="card"><span class="n">%d</span><span class="l">Drift items</span></div>
             </div>',
            $report['summary']['missing_foreign_keys'],
            $report['summary']['unindexed_candidates'],
            $report['summary']['drift_items']
        );

        $body = '<h2>Sources</h2><ul>';

        foreach ($report['sources'] as $source) {
            $body .= sprintf(
                '<li><strong>%s</strong> — %s, %d tables',
                $this->escape($source['source']),
                $source['available'] ? 'available' : '<span class="bad">unavailable</span>',
                $source['tables']
            );

            if (count($source['errors']) > 0 || count($source['warnings']) > 0) {
                $body .= '<ul>';

                foreach ($source['errors'] as $error) {
                    $body .= '<li class="bad">error: ' . $this->escape($error) . '</li>';
                }

                foreach ($source['warnings'] as $warning) {
                    $body .= '<li class="warn">warning: ' . $this->escape($warning) . '</li>';
                }

                $body .= '</ul>';
            }

            $body .= '</li>';
        }

        $body .= '</ul>';

        foreach ($report['missing_foreign_keys'] as $source => $items) {
            $body .= '<h2>Missing foreign keys (' . $this->escape($source) . ')</h2>';

            if (count($items) === 0) {
                $body .= '<p class="empty">None found.</p>';
                continue;
            }

            $body .= '<table><thead><tr><th>Table</th><th>Column</th><th>Should reference</th>'
                . '<th>Indexed</th><th>Suggested fix</th></tr></thead><tbody>';

            foreach ($items as $item) {
                $body .= sprintf(
                    '<tr><td><code>%s</code></td><td><code>%s</code></td><td><code>%s.%s</code></td>'
                    . '<td>%s</td><td><code>%s</code></td></tr>',
                    $this->escape($item['table']),
                    $this->escape($item['column']),
                    $this->escape($item['on']),
                    $this->escape($item['references']),
                    !empty($item['has_index']) ? 'yes' : '<span class="warn">no</span>',
                    $this->escape($item['suggestion'])
                );
            }

            $body .= '</tbody></table>';
        }

        if (isset($report['drift']) && $report['drift'] !== null) {
            $body .= $this->htmlDrift($report['drift']);
        }

        return $this->shell('Schema Report', $report['generated_at'], $summary . $body);
    }

    /**
     * @param  array $drift
     * @return string
     */
    private function htmlDrift(array $drift)
    {
        $html = '<h2>Schema drift</h2>';

        if (!$drift['comparable']) {
            return $html . '<p class="empty">Not computed: ' . $this->escape($drift['reason']) . '</p>';
        }

        if (SchemaDiff::count($drift) === 0) {
            return $html . '<p class="empty">No drift between '
                . $this->escape($drift['left']) . ' and ' . $this->escape($drift['right']) . '.</p>';
        }

        $sections = [
            'tables_only_in_left'   => 'Tables only in ' . $drift['left'],
            'tables_only_in_right'  => 'Tables only in ' . $drift['right'],
        ];

        foreach ($sections as $key => $heading) {
            if (count($drift[$key]) === 0) {
                continue;
            }

            $html .= '<h3>' . $this->escape($heading) . '</h3><ul>';

            foreach ($drift[$key] as $table) {
                $html .= '<li><code>' . $this->escape($table) . '</code></li>';
            }

            $html .= '</ul>';
        }

        $columnSections = [
            'columns_only_in_left'  => 'Columns only in ' . $drift['left'],
            'columns_only_in_right' => 'Columns only in ' . $drift['right'],
        ];

        foreach ($columnSections as $key => $heading) {
            if (count($drift[$key]) === 0) {
                continue;
            }

            $html .= '<h3>' . $this->escape($heading) . '</h3><ul>';

            foreach ($drift[$key] as $entry) {
                $html .= '<li><code>' . $this->escape($entry['table'] . '.' . $entry['column']) . '</code></li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * @param  string $title
     * @param  string $generatedAt
     * @param  string $body
     * @return string
     */
    private function shell($title, $generatedAt, $body)
    {
        $css = <<<'CSS'
:root { color-scheme: light dark; --bg:#ffffff; --fg:#1a1a1a; --muted:#6b7280;
  --line:#e5e7eb; --accent:#2563eb; --head:#f9fafb; --code:#f3f4f6;
  --bad:#dc2626; --warn:#b45309; }
@media (prefers-color-scheme: dark) { :root { --bg:#0f1115; --fg:#e6e6e6;
  --muted:#9aa1ab; --line:#272b33; --accent:#7aa2f7; --head:#171a20; --code:#1b1f27;
  --bad:#f87171; --warn:#fbbf24; } }
* { box-sizing:border-box; }
body { margin:0; padding:2rem; background:var(--bg); color:var(--fg);
  font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
h1 { margin:0 0 .25rem; font-size:1.6rem; }
h2 { margin:2rem 0 .5rem; font-size:1.15rem; border-bottom:1px solid var(--line); padding-bottom:.35rem; }
h3 { margin:1rem 0 .35rem; font-size:.9rem; color:var(--muted);
  text-transform:uppercase; letter-spacing:.04em; }
.meta { color:var(--muted); margin-bottom:1.5rem; }
.cards { display:flex; gap:1rem; flex-wrap:wrap; margin:1rem 0 2rem; }
.card { flex:1 1 180px; border:1px solid var(--line); border-radius:8px;
  padding:1rem; background:var(--head); }
.card .n { display:block; font-size:2rem; font-weight:600; line-height:1; }
.card .l { display:block; color:var(--muted); font-size:.8rem; margin-top:.35rem; }
table { border-collapse:collapse; width:100%; margin:.5rem 0; display:block; overflow-x:auto; }
th, td { text-align:left; padding:.45rem .7rem; border-bottom:1px solid var(--line); white-space:nowrap; }
th { background:var(--head); font-weight:600; font-size:.8rem;
  text-transform:uppercase; letter-spacing:.03em; color:var(--muted); }
code { background:var(--code); padding:.1rem .35rem; border-radius:3px; font-size:.85em; }
.bad { color:var(--bad); }
.warn { color:var(--warn); }
.empty { color:var(--muted); font-style:italic; }
ul { margin:.35rem 0; }
@media (max-width:800px) { body { padding:1rem; } }
CSS;

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . $this->escape($title) . "</title>\n<style>\n" . $css . "\n</style>\n"
            . "</head>\n<body>\n"
            . '<h1>' . $this->escape($title) . "</h1>\n"
            . '<p class="meta">Generated ' . $this->escape($generatedAt) . "</p>\n"
            . $body
            . "\n</body>\n</html>\n";
    }

    /**
     * @param  mixed $value
     * @return string
     */
    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
