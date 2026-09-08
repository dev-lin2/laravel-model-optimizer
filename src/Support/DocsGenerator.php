<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;

/**
 * Renders a schema snapshot as a human-readable data dictionary.
 *
 * Describes the schema as it is; it does not judge it. Findings belong in
 * ReportGenerator. Every field degrades to a placeholder when unknown, so a
 * migration-sourced snapshot (which has no defaults or key metadata) renders
 * cleanly rather than erroring.
 */
class DocsGenerator
{
    /** @var string Rendered when a value is not known from the source */
    const UNKNOWN = '—';

    /**
     * table => ['model' => string, 'relationships' => [['type','method','related']]]
     *
     * @var array<string, array>
     */
    private $models;

    /** @var bool Whether model names and relationships may appear at all */
    private $includeModels;

    /**
     * @param array<string, array> $modelsByTable Optional model enrichment
     * @param bool                 $includeModels Set false for a pure schema document
     */
    public function __construct(array $modelsByTable = [], $includeModels = true)
    {
        $this->models        = $modelsByTable;
        $this->includeModels = (bool) $includeModels;
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @return string
     */
    public function generateMarkdown(SchemaSnapshot $snapshot)
    {
        $lines = [];

        $lines[] = '# Database Documentation';
        $lines[] = '';
        $lines[] = sprintf('_Source: **%s** — generated %s_', $snapshot->source, date('Y-m-d H:i'));
        $lines[] = '';

        if (!$snapshot->available) {
            $lines[] = '> **Schema unavailable.** No tables could be read from this source.';
            $lines[] = '';

            foreach ($snapshot->errors as $error) {
                $lines[] = '> - ' . $error;
            }

            return implode("\n", $lines) . "\n";
        }

        if ($snapshot->isEmpty()) {
            $lines[] = '> **No tables found.** The source was readable but contained no tables.';
            $lines[] = '';

            return implode("\n", $lines) . "\n";
        }

        $tables = $snapshot->tableNames();
        sort($tables);

        $lines[] = sprintf('%d tables.', count($tables));
        $lines[] = '';
        $lines[] = '## Contents';
        $lines[] = '';

        foreach ($tables as $table) {
            $lines[] = sprintf('- [%s](#%s)', $table, $this->anchor($table));
        }

        $lines[] = '';

        foreach ($tables as $table) {
            $lines = array_merge($lines, $this->markdownTable($snapshot, $table));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @return string[]
     */
    private function markdownTable(SchemaSnapshot $snapshot, $table)
    {
        $lines   = [];
        $lines[] = '## ' . $table;
        $lines[] = '';

        $model = $this->includeModels && isset($this->models[$table]['model'])
            ? $this->models[$table]['model']
            : null;

        // Omit the line entirely when there is no model, rather than stating
        // its absence: this document describes the schema, not the code.
        if ($model !== null) {
            $lines[] = sprintf('**Model:** `%s`', $model);
            $lines[] = '';
        }

        $columns = $snapshot->columns($table);

        if (count($columns) === 0) {
            $lines[] = '_No columns recorded for this table._';
            $lines[] = '';

            return $lines;
        }

        $fkByColumn = [];
        foreach ($snapshot->foreignKeys($table) as $fk) {
            if (isset($fk['column'])) {
                $fkByColumn[$fk['column']] = $fk;
            }
        }

        $lines[] = '| Column | Type | Nullable | Key | Default | References |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($columns as $name => $meta) {
            $fk = isset($fkByColumn[$name]) ? $fkByColumn[$name] : null;

            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s |',
                $name,
                $this->value(isset($meta['type']) ? $meta['type'] : null),
                $this->nullable(isset($meta['nullable']) ? $meta['nullable'] : null),
                $this->value(isset($meta['key']) && $meta['key'] !== '' ? $meta['key'] : null),
                $this->value(isset($meta['default']) ? $meta['default'] : null),
                $fk === null
                    ? self::UNKNOWN
                    : sprintf('`%s.%s`', $fk['on'], $fk['references'])
            );
        }

        $lines[] = '';

        $indexes = $snapshot->indexes($table);

        if (count($indexes) > 0) {
            $lines[] = '**Indexes**';
            $lines[] = '';

            foreach ($indexes as $index) {
                $lines[] = sprintf(
                    '- `%s` (%s)%s',
                    isset($index['name']) ? $index['name'] : 'unnamed',
                    implode(', ', isset($index['columns']) ? $index['columns'] : []),
                    !empty($index['unique']) ? ' — unique' : ''
                );
            }

            $lines[] = '';
        }

        $relationships = $this->includeModels && isset($this->models[$table]['relationships'])
            ? $this->models[$table]['relationships']
            : [];

        if (count($relationships) > 0) {
            $lines[] = '**Relationships**';
            $lines[] = '';

            foreach ($relationships as $rel) {
                $lines[] = sprintf(
                    '- `%s()` %s → `%s`',
                    isset($rel['method']) ? $rel['method'] : '?',
                    isset($rel['type']) ? $rel['type'] : '?',
                    isset($rel['related']) ? $rel['related'] : '?'
                );
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @return string
     */
    public function generateHtml(SchemaSnapshot $snapshot)
    {
        $tables = $snapshot->available ? $snapshot->tableNames() : [];
        sort($tables);

        $body = '';

        if (!$snapshot->available) {
            $body .= '<div class="notice error"><strong>Schema unavailable.</strong><ul>';
            foreach ($snapshot->errors as $error) {
                $body .= '<li>' . $this->escape($error) . '</li>';
            }
            $body .= '</ul></div>';
        } elseif (count($tables) === 0) {
            $body .= '<div class="notice">No tables found in this source.</div>';
        }

        $toc = '';

        foreach ($tables as $table) {
            $toc  .= sprintf(
                '<li><a href="#%s">%s</a></li>',
                $this->escape($this->anchor($table)),
                $this->escape($table)
            );
            $body .= $this->htmlTable($snapshot, $table);
        }

        $meta = sprintf(
            'Source: <strong>%s</strong> &middot; %d tables &middot; generated %s',
            $this->escape($snapshot->source),
            count($tables),
            $this->escape(date('Y-m-d H:i'))
        );

        return $this->htmlShell('Database Documentation', $meta, $toc, $body);
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @return string
     */
    private function htmlTable(SchemaSnapshot $snapshot, $table)
    {
        $html = sprintf(
            '<section id="%s"><h2>%s</h2>',
            $this->escape($this->anchor($table)),
            $this->escape($table)
        );

        if ($this->includeModels && isset($this->models[$table]['model'])) {
            $html .= '<p class="model">Model: <code>'
                . $this->escape($this->models[$table]['model'])
                . '</code></p>';
        }

        $columns = $snapshot->columns($table);

        if (count($columns) === 0) {
            return $html . '<p class="empty">No columns recorded for this table.</p></section>';
        }

        $fkByColumn = [];
        foreach ($snapshot->foreignKeys($table) as $fk) {
            if (isset($fk['column'])) {
                $fkByColumn[$fk['column']] = $fk;
            }
        }

        $html .= '<table><thead><tr>'
            . '<th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th><th>References</th>'
            . '</tr></thead><tbody>';

        foreach ($columns as $name => $meta) {
            $fk = isset($fkByColumn[$name]) ? $fkByColumn[$name] : null;

            $html .= sprintf(
                '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->escape($name),
                $this->escape($this->value(isset($meta['type']) ? $meta['type'] : null)),
                $this->escape($this->nullable(isset($meta['nullable']) ? $meta['nullable'] : null)),
                $this->escape($this->value(isset($meta['key']) && $meta['key'] !== '' ? $meta['key'] : null)),
                $this->escape($this->value(isset($meta['default']) ? $meta['default'] : null)),
                $fk === null
                    ? self::UNKNOWN
                    : '<code>' . $this->escape($fk['on'] . '.' . $fk['references']) . '</code>'
            );
        }

        $html .= '</tbody></table>';

        $indexes = $snapshot->indexes($table);

        if (count($indexes) > 0) {
            $html .= '<h3>Indexes</h3><ul>';

            foreach ($indexes as $index) {
                $html .= sprintf(
                    '<li><code>%s</code> (%s)%s</li>',
                    $this->escape(isset($index['name']) ? $index['name'] : 'unnamed'),
                    $this->escape(implode(', ', isset($index['columns']) ? $index['columns'] : [])),
                    !empty($index['unique']) ? ' &mdash; unique' : ''
                );
            }

            $html .= '</ul>';
        }

        $relationships = $this->includeModels && isset($this->models[$table]['relationships'])
            ? $this->models[$table]['relationships']
            : [];

        if (count($relationships) > 0) {
            $html .= '<h3>Relationships</h3><ul>';

            foreach ($relationships as $rel) {
                $html .= sprintf(
                    '<li><code>%s()</code> %s &rarr; <code>%s</code></li>',
                    $this->escape(isset($rel['method']) ? $rel['method'] : '?'),
                    $this->escape(isset($rel['type']) ? $rel['type'] : '?'),
                    $this->escape(isset($rel['related']) ? $rel['related'] : '?')
                );
            }

            $html .= '</ul>';
        }

        return $html . '</section>';
    }

    /**
     * @param  string $title
     * @param  string $meta
     * @param  string $toc
     * @param  string $body
     * @return string
     */
    private function htmlShell($title, $meta, $toc, $body)
    {
        $css = <<<'CSS'
:root { color-scheme: light dark; --bg:#ffffff; --fg:#1a1a1a; --muted:#6b7280;
  --line:#e5e7eb; --accent:#2563eb; --head:#f9fafb; --code:#f3f4f6; }
@media (prefers-color-scheme: dark) { :root { --bg:#0f1115; --fg:#e6e6e6;
  --muted:#9aa1ab; --line:#272b33; --accent:#7aa2f7; --head:#171a20; --code:#1b1f27; } }
* { box-sizing: border-box; }
body { margin:0; padding:2rem; background:var(--bg); color:var(--fg);
  font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
h1 { margin:0 0 .25rem; font-size:1.6rem; }
h2 { margin:2.5rem 0 .5rem; font-size:1.2rem; border-bottom:1px solid var(--line); padding-bottom:.35rem; }
h3 { margin:1.25rem 0 .5rem; font-size:.95rem; color:var(--muted);
  text-transform:uppercase; letter-spacing:.04em; }
.meta { color:var(--muted); margin-bottom:1.5rem; }
.model { color:var(--muted); margin:.25rem 0 1rem; }
table { border-collapse:collapse; width:100%; margin:.5rem 0; display:block; overflow-x:auto; }
th, td { text-align:left; padding:.45rem .7rem; border-bottom:1px solid var(--line); white-space:nowrap; }
th { background:var(--head); font-weight:600; font-size:.8rem;
  text-transform:uppercase; letter-spacing:.03em; color:var(--muted); }
code { background:var(--code); padding:.1rem .35rem; border-radius:3px; font-size:.85em; }
a { color:var(--accent); text-decoration:none; }
a:hover { text-decoration:underline; }
.toc { columns:3; list-style:none; padding:0; margin:0 0 1rem; }
.toc li { margin:.15rem 0; }
.notice { padding:.75rem 1rem; border-left:3px solid var(--muted);
  background:var(--head); margin:1rem 0; }
.notice.error { border-color:#dc2626; }
.empty { color:var(--muted); font-style:italic; }
section { margin-bottom:1rem; }
@media (max-width:800px) { .toc { columns:1; } body { padding:1rem; } }
CSS;

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . $this->escape($title) . "</title>\n<style>\n" . $css . "\n</style>\n"
            . "</head>\n<body>\n"
            . '<h1>' . $this->escape($title) . "</h1>\n"
            . '<p class="meta">' . $meta . "</p>\n"
            . ($toc !== '' ? '<ul class="toc">' . $toc . "</ul>\n" : '')
            . $body
            . "\n</body>\n</html>\n";
    }

    /**
     * @param  mixed $value
     * @return string
     */
    private function value($value)
    {
        if ($value === null || $value === '') {
            return self::UNKNOWN;
        }

        return (string) $value;
    }

    /**
     * @param  bool|null $nullable
     * @return string
     */
    private function nullable($nullable)
    {
        if ($nullable === null) {
            return self::UNKNOWN;
        }

        return $nullable ? 'yes' : 'no';
    }

    /**
     * @param  string $table
     * @return string
     */
    private function anchor($table)
    {
        return 'table-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($table));
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
