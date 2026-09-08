<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;

/**
 * Describes each table in prose rather than as a column grid.
 *
 * Everything stated is derived from the snapshot: column counts, primary keys,
 * foreign keys in both directions, Laravel's timestamp and soft-delete
 * conventions, uniqueness and index coverage. Nothing is inferred about
 * business meaning, which the schema does not contain.
 *
 * Any fact that cannot be established is simply left unsaid, so a
 * migration-sourced snapshot (which knows less than a live connection) still
 * reads as complete sentences.
 */
class DefinitionsGenerator
{
    /**
     * table => ['model' => string, 'short_name' => string, 'relationships' => array[]]
     *
     * @var array<string, array>
     */
    private $models;

    /**
     * @param array<string, array> $modelsByTable
     */
    public function __construct(array $modelsByTable = [])
    {
        $this->models = $modelsByTable;
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @return string
     */
    public function generateMarkdown(SchemaSnapshot $snapshot)
    {
        $lines = [];

        $lines[] = '# Table Definitions';
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

        $referencedBy = $this->buildReverseReferences($snapshot);

        foreach ($tables as $table) {
            $lines[] = '## ' . $table;
            $lines[] = '';

            foreach ($this->describe($snapshot, $table, $referencedBy) as $paragraph) {
                $lines[] = $paragraph;
                $lines[] = '';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @return string
     */
    public function generateHtml(SchemaSnapshot $snapshot)
    {
        $body = '';

        if (!$snapshot->available) {
            $body .= '<div class="notice error"><strong>Schema unavailable.</strong><ul>';

            foreach ($snapshot->errors as $error) {
                $body .= '<li>' . $this->escape($error) . '</li>';
            }

            $body .= '</ul></div>';

            return $this->shell('Table Definitions', $snapshot->source, 0, $body);
        }

        $tables = $snapshot->tableNames();
        sort($tables);

        if (count($tables) === 0) {
            $body .= '<div class="notice">No tables found in this source.</div>';
        }

        $referencedBy = $this->buildReverseReferences($snapshot);

        foreach ($tables as $table) {
            $body .= '<section id="' . $this->escape($this->anchor($table)) . '">';
            $body .= '<h2>' . $this->escape($table) . '</h2>';

            foreach ($this->describe($snapshot, $table, $referencedBy) as $paragraph) {
                $body .= '<p>' . $this->inlineCodeToHtml($paragraph) . '</p>';
            }

            $body .= '</section>';
        }

        return $this->shell('Table Definitions', $snapshot->source, count($tables), $body);
    }

    /**
     * Build the prose paragraphs describing one table.
     *
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @param  array          $referencedBy
     * @return string[]
     */
    private function describe(SchemaSnapshot $snapshot, $table, array $referencedBy)
    {
        $columns    = $snapshot->columns($table);
        $paragraphs = [];

        if (count($columns) === 0) {
            return ['No columns are recorded for this table in the ' . $snapshot->source . ' schema.'];
        }

        $paragraphs[] = $this->openingSentence($snapshot, $table, $columns);

        $outgoing = $this->outgoingSentence($snapshot, $table);
        if ($outgoing !== null) {
            $paragraphs[] = $outgoing;
        }

        $incoming = $this->incomingSentence($table, $referencedBy);
        if ($incoming !== null) {
            $paragraphs[] = $incoming;
        }

        $conventions = $this->conventionsSentence($columns);
        if ($conventions !== null) {
            $paragraphs[] = $conventions;
        }

        $indexes = $this->indexSentence($snapshot, $table);
        if ($indexes !== null) {
            $paragraphs[] = $indexes;
        }

        $relationships = $this->relationshipSentence($table);
        if ($relationships !== null) {
            $paragraphs[] = $relationships;
        }

        return $paragraphs;
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @param  array          $columns
     * @return string
     */
    private function openingSentence(SchemaSnapshot $snapshot, $table, array $columns)
    {
        $count   = count($columns);
        $primary = $this->primaryKey($columns);

        $model = isset($this->models[$table]['model']) ? $this->models[$table]['model'] : null;

        $sentence = $model !== null
            ? sprintf('`%s` is backed by the `%s` model.', $table, $model)
            : sprintf('`%s` has no Eloquent model in the scanned paths.', $table);

        $sentence .= sprintf(
            ' It holds %d %s',
            $count,
            $count === 1 ? 'column' : 'columns'
        );

        $sentence .= $primary !== null
            ? sprintf(', keyed by `%s`.', $primary)
            : ', with no primary key recorded.';

        $required = $this->requiredColumns($columns, $primary);

        if (count($required) > 0 && count($required) <= 6) {
            $sentence .= sprintf(
                ' Required values: %s.',
                $this->joinCode($required)
            );
        }

        return $sentence;
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @return string|null
     */
    private function outgoingSentence(SchemaSnapshot $snapshot, $table)
    {
        $foreignKeys = $snapshot->foreignKeys($table);

        if (count($foreignKeys) === 0) {
            return null;
        }

        $columns = $snapshot->columns($table);
        $parts   = [];

        foreach ($foreignKeys as $fk) {
            if (!isset($fk['column'], $fk['on'])) {
                continue;
            }

            $nullable = isset($columns[$fk['column']]['nullable'])
                ? $columns[$fk['column']]['nullable']
                : null;

            $parts[] = sprintf(
                '%s one `%s` record via `%s`',
                $nullable === true ? 'optionally references' : 'references',
                $fk['on'],
                $fk['column']
            );
        }

        if (count($parts) === 0) {
            return null;
        }

        return 'Each row ' . $this->joinClauses($parts) . '.';
    }

    /**
     * @param  string $table
     * @param  array  $referencedBy
     * @return string|null
     */
    private function incomingSentence($table, array $referencedBy)
    {
        if (!isset($referencedBy[$table]) || count($referencedBy[$table]) === 0) {
            return null;
        }

        $parts = [];

        foreach ($referencedBy[$table] as $ref) {
            $parts[] = sprintf('`%s` via `%s`', $ref['table'], $ref['column']);
        }

        return sprintf(
            'Referenced by %s.',
            $this->joinClauses($parts)
        );
    }

    /**
     * Laravel conventions that are readable straight off the column list.
     *
     * @param  array $columns
     * @return string|null
     */
    private function conventionsSentence(array $columns)
    {
        $notes = [];

        if (isset($columns['created_at']) && isset($columns['updated_at'])) {
            $notes[] = 'records creation and update times';
        } elseif (isset($columns['created_at'])) {
            $notes[] = 'records a creation time';
        }

        if (isset($columns['deleted_at'])) {
            $notes[] = 'supports soft deletion via `deleted_at`';
        }

        if (isset($columns['remember_token'])) {
            $notes[] = 'stores an authentication remember token';
        }

        $morphs = $this->morphPairs($columns);

        foreach ($morphs as $morph) {
            $notes[] = sprintf('carries a polymorphic reference through `%s_type` and `%s_id`', $morph, $morph);
        }

        if (count($notes) === 0) {
            return null;
        }

        return 'The table ' . $this->joinClauses($notes) . '.';
    }

    /**
     * @param  SchemaSnapshot $snapshot
     * @param  string         $table
     * @return string|null
     */
    private function indexSentence(SchemaSnapshot $snapshot, $table)
    {
        $indexes = $snapshot->indexes($table);
        $unique  = [];
        $plain   = [];

        foreach ($indexes as $index) {
            $columns = isset($index['columns']) ? $index['columns'] : [];

            if (count($columns) === 0) {
                continue;
            }

            $label = count($columns) === 1
                ? sprintf('`%s`', $columns[0])
                : sprintf('`%s` together', implode('` + `', $columns));

            if (!empty($index['unique'])) {
                $unique[] = $label;
            } else {
                $plain[] = $label;
            }
        }

        $parts = [];

        if (count($unique) > 0) {
            $parts[] = sprintf('%s must be unique', $this->joinClauses($unique));
        }

        if (count($plain) > 0) {
            $parts[] = sprintf('%s %s indexed', $this->joinClauses($plain), count($plain) === 1 ? 'is' : 'are');
        }

        // Foreign keys with no index are worth calling out.
        $unindexed = [];

        foreach ($snapshot->foreignKeys($table) as $fk) {
            if (isset($fk['column']) && !$snapshot->columnHasIndex($table, $fk['column'])) {
                $unindexed[] = sprintf('`%s`', $fk['column']);
            }
        }

        if (count($unindexed) > 0) {
            $parts[] = sprintf(
                '%s %s a foreign key but no index',
                $this->joinClauses($unindexed),
                count($unindexed) === 1 ? 'has' : 'have'
            );
        }

        if (count($parts) === 0) {
            return null;
        }

        return ucfirst($this->joinClauses($parts)) . '.';
    }

    /**
     * @param  string $table
     * @return string|null
     */
    private function relationshipSentence($table)
    {
        $relationships = isset($this->models[$table]['relationships'])
            ? $this->models[$table]['relationships']
            : [];

        if (count($relationships) === 0) {
            return null;
        }

        $parts = [];

        foreach ($relationships as $rel) {
            $parts[] = sprintf(
                '`%s()` (%s)',
                isset($rel['method']) ? $rel['method'] : '?',
                isset($rel['type']) ? $rel['type'] : '?'
            );
        }

        return sprintf(
            'The model declares %s: %s.',
            count($parts) === 1 ? 'one relationship' : count($parts) . ' relationships',
            implode(', ', $parts)
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * table => [['table' => referencing table, 'column' => column]]
     *
     * @param  SchemaSnapshot $snapshot
     * @return array
     */
    private function buildReverseReferences(SchemaSnapshot $snapshot)
    {
        $map = [];

        foreach ($snapshot->tableNames() as $table) {
            foreach ($snapshot->foreignKeys($table) as $fk) {
                if (!isset($fk['on'], $fk['column'])) {
                    continue;
                }

                $map[$fk['on']][] = ['table' => $table, 'column' => $fk['column']];
            }
        }

        return $map;
    }

    /**
     * @param  array $columns
     * @return string|null
     */
    private function primaryKey(array $columns)
    {
        foreach ($columns as $name => $meta) {
            if (isset($meta['key']) && $meta['key'] === 'PRI') {
                return $name;
            }
        }

        return isset($columns['id']) ? 'id' : null;
    }

    /**
     * Non-nullable columns other than the key and Laravel's own bookkeeping.
     *
     * @param  array       $columns
     * @param  string|null $primary
     * @return string[]
     */
    private function requiredColumns(array $columns, $primary)
    {
        $skip     = ['created_at', 'updated_at', 'deleted_at', 'remember_token'];
        $required = [];

        foreach ($columns as $name => $meta) {
            if ($name === $primary || in_array($name, $skip, true)) {
                continue;
            }

            // Only state this when nullability is actually known.
            if (isset($meta['nullable']) && $meta['nullable'] === false) {
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * @param  array $columns
     * @return string[]
     */
    private function morphPairs(array $columns)
    {
        $pairs = [];

        foreach (array_keys($columns) as $name) {
            if (substr($name, -5) !== '_type') {
                continue;
            }

            $base = substr($name, 0, -5);

            if (isset($columns[$base . '_id'])) {
                $pairs[] = $base;
            }
        }

        return $pairs;
    }

    /**
     * @param  string[] $items
     * @return string
     */
    private function joinCode(array $items)
    {
        return $this->joinClauses(array_map(function ($item) {
            return '`' . $item . '`';
        }, $items));
    }

    /**
     * "a", "a and b", "a, b and c"
     *
     * @param  string[] $items
     * @return string
     */
    private function joinClauses(array $items)
    {
        $items = array_values($items);
        $count = count($items);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
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
     * Render `code` spans from a prose paragraph, escaping everything else.
     *
     * @param  string $text
     * @return string
     */
    private function inlineCodeToHtml($text)
    {
        $parts = explode('`', $text);
        $html  = '';

        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1
                ? '<code>' . $this->escape($part) . '</code>'
                : $this->escape($part);
        }

        return $html;
    }

    /**
     * @param  string $title
     * @param  string $source
     * @param  int    $tableCount
     * @param  string $body
     * @return string
     */
    private function shell($title, $source, $tableCount, $body)
    {
        $css = <<<'CSS'
:root { color-scheme: light dark; --bg:#ffffff; --fg:#1a1a1a; --muted:#6b7280;
  --line:#e5e7eb; --accent:#2563eb; --head:#f9fafb; --code:#f3f4f6; }
@media (prefers-color-scheme: dark) { :root { --bg:#0f1115; --fg:#e6e6e6;
  --muted:#9aa1ab; --line:#272b33; --accent:#7aa2f7; --head:#171a20; --code:#1b1f27; } }
* { box-sizing:border-box; }
body { margin:0; padding:2rem; background:var(--bg); color:var(--fg);
  font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  max-width:70ch; margin-inline:auto; }
h1 { margin:0 0 .25rem; font-size:1.6rem; }
h2 { margin:2.5rem 0 .5rem; font-size:1.15rem; border-bottom:1px solid var(--line);
  padding-bottom:.35rem; }
.meta { color:var(--muted); margin-bottom:2rem; }
p { margin:.6rem 0; }
code { background:var(--code); padding:.1rem .35rem; border-radius:3px; font-size:.85em; }
.notice { padding:.75rem 1rem; border-left:3px solid var(--muted);
  background:var(--head); margin:1rem 0; }
.notice.error { border-color:#dc2626; }
section { margin-bottom:1.5rem; }
@media (max-width:800px) { body { padding:1rem; } }
CSS;

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . $this->escape($title) . "</title>\n<style>\n" . $css . "\n</style>\n"
            . "</head>\n<body>\n"
            . '<h1>' . $this->escape($title) . "</h1>\n"
            . '<p class="meta">Source: <strong>' . $this->escape($source) . '</strong> &middot; '
            . $tableCount . ' tables &middot; generated ' . $this->escape(date('Y-m-d H:i')) . "</p>\n"
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
