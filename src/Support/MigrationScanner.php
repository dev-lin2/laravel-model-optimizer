<?php

namespace Devlin\ModelAnalyzer\Support;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Parses Laravel migration files statically (no code execution) to build a
 * table → column map representing the intended database schema.
 *
 * Processing order follows migration filenames (which are timestamp-prefixed),
 * so ALTER migrations correctly modify the columns accumulated from earlier
 * CREATE migrations.
 */
class MigrationScanner
{
    /**
     * Accumulated schema: table_name => [column_name => simplified_type]
     *
     * @var array<string, array<string, string>>
     */
    private $tables = [];

    /**
     * Foreign key constraints declared in migrations.
     *
     * table_name => [['column', 'references', 'on', 'name']]
     *
     * @var array<string, array[]>
     */
    private $foreignKeys = [];

    /**
     * Indexes declared in migrations.
     *
     * table_name => [['name', 'columns', 'unique']]
     *
     * @var array<string, array[]>
     */
    private $indexes = [];

    /**
     * Per-column metadata that does not fit the flat type map.
     *
     * table_name => [column_name => ['nullable' => bool]]
     *
     * @var array<string, array<string, array>>
     */
    private $columnMeta = [];

    /**
     * Non-fatal problems encountered while parsing (unreadable files, etc).
     *
     * @var string[]
     */
    private $warnings = [];

    /**
     * Scan the given paths and return a table → columns map.
     *
     * @param  string[] $paths
     * @return array<string, array<string, string>>
     */
    public function scan(array $paths)
    {
        foreach ($this->collectFiles($paths) as $file) {
            try {
                $this->processFile($file);
            } catch (\Throwable $e) {
                // Skip files that cannot be parsed, but record why.
                $this->warnings[] = sprintf(
                    'Could not parse migration "%s": %s',
                    basename($file),
                    $e->getMessage()
                );
            }
        }

        return $this->tables;
    }

    /**
     * Foreign key constraints collected during the last scan().
     *
     * @return array<string, array[]>
     */
    public function getForeignKeys()
    {
        return $this->foreignKeys;
    }

    /**
     * Indexes collected during the last scan().
     *
     * @return array<string, array[]>
     */
    public function getIndexes()
    {
        return $this->indexes;
    }

    /**
     * Per-column metadata (nullability) collected during the last scan().
     *
     * @return array<string, array<string, array>>
     */
    public function getColumnMeta()
    {
        return $this->columnMeta;
    }

    /**
     * Non-fatal parse problems from the last scan().
     *
     * @return string[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

    // -------------------------------------------------------------------------
    // File collection
    // -------------------------------------------------------------------------

    /**
     * @param  string[] $paths
     * @return string[]
     */
    private function collectFiles(array $paths)
    {
        $files = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->name('*.php')->in($path)->sortByName();

            foreach ($finder as $file) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    // -------------------------------------------------------------------------
    // File parsing
    // -------------------------------------------------------------------------

    /**
     * Isolate the body of the migration's up() method.
     *
     * Returns null when the file has no up() method — anonymous fragments and
     * test fixtures are then scanned whole, preserving prior behaviour.
     *
     * @param  string $src
     * @return string|null
     */
    private function extractUpMethod($src)
    {
        if (!preg_match('/function\s+up\s*\(/i', $src, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $parenStart = strpos($src, '(', $m[0][1]);
        if ($parenStart === false) {
            return null;
        }

        $signature = $this->extractBalanced($src, $parenStart, '(', ')');
        $braceStart = strpos($src, '{', $parenStart + strlen($signature));

        if ($braceStart === false) {
            return null;
        }

        $body = $this->extractBalanced($src, $braceStart, '{', '}');

        return $body === '' ? null : $body;
    }

    private function processFile($file)
    {
        $src = @file_get_contents($file);
        if ($src === false) {
            return;
        }

        // Only the up() migration describes the intended schema. Scanning the
        // whole file would let the Schema::dropIfExists() in down() delete the
        // table that up() just created.
        $up = $this->extractUpMethod($src);
        if ($up !== null) {
            $src = $up;
        }

        $offset = 0;
        $srcLen = strlen($src);

        while (($pos = strpos($src, 'Schema::', $offset)) !== false) {
            $offset = $pos + 8;

            // Read method name
            $nameEnd = $offset;
            while ($nameEnd < $srcLen && ctype_alpha($src[$nameEnd])) {
                $nameEnd++;
            }
            $method  = substr($src, $offset, $nameEnd - $offset);
            $offset  = $nameEnd;

            $allowed = ['create', 'table', 'drop', 'dropIfExists', 'dropColumns', 'rename'];
            if (!in_array($method, $allowed, true)) {
                continue;
            }

            // Find and extract the argument list
            $parenPos = strpos($src, '(', $nameEnd);
            if ($parenPos === false) {
                continue;
            }

            $argsContent = $this->extractBalanced($src, $parenPos, '(', ')');
            $offset      = $parenPos + strlen($argsContent);

            // Handle drop
            if ($method === 'drop' || $method === 'dropIfExists') {
                $table = $this->firstStringArg($argsContent);
                if ($table !== null) {
                    unset($this->tables[$table], $this->foreignKeys[$table], $this->indexes[$table], $this->columnMeta[$table]);
                }
                continue;
            }

            // Handle rename
            if ($method === 'rename') {
                if (preg_match('/[\'"]([^\'"]+)[\'"][^\'",]*[\'"]([^\'"]+)[\'"]/', $argsContent, $m)) {
                    $from = $m[1];
                    $to   = $m[2];
                    if (isset($this->tables[$from])) {
                        $this->tables[$to] = $this->tables[$from];
                        unset($this->tables[$from]);

                        foreach (['foreignKeys', 'indexes', 'columnMeta'] as $bucket) {
                            if (isset($this->{$bucket}[$from])) {
                                $this->{$bucket}[$to] = $this->{$bucket}[$from];
                                unset($this->{$bucket}[$from]);
                            }
                        }
                    }
                }
                continue;
            }

            $table = $this->firstStringArg($argsContent);
            if ($table === null) {
                continue;
            }

            $closureBody = $this->extractClosureBody($argsContent);
            if ($closureBody === null) {
                continue;
            }

            if ($method === 'create' && !isset($this->tables[$table])) {
                $this->tables[$table] = [];
            }

            if (isset($this->tables[$table])) {
                $this->parseColumns($table, $closureBody);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Constraint / index collection
    // -------------------------------------------------------------------------

    /**
     * Interpret chained modifiers on a column definition:
     * ->constrained(), ->references()->on(), ->index(), ->unique(), ->nullable().
     *
     * @param  string $table
     * @param  string $column
     * @param  string $chain
     * @return void
     */
    private function applyChainModifiers($table, $column, $chain)
    {
        if ($chain === '' || $chain === null) {
            return;
        }

        if (strpos($chain, '->nullable(') !== false) {
            // nullable(false) explicitly marks the column NOT NULL.
            $notNull = (bool) preg_match('/->nullable\(\s*false\s*\)/', $chain);

            $this->columnMeta[$table][$column] = ['nullable' => !$notNull];
        }

        if (strpos($chain, '->constrained') !== false || strpos($chain, '->references') !== false) {
            $this->recordForeignKeyFromChain($table, $column, $chain);
        }

        if (preg_match('/->unique\(/', $chain)) {
            $this->recordIndex($table, [$column], true);
        } elseif (preg_match('/->index\(/', $chain)) {
            $this->recordIndex($table, [$column], false);
        }

        if (preg_match('/->primary\(/', $chain)) {
            $this->recordIndex($table, [$column], true);
        }
    }

    /**
     * Derive a foreign key definition from a chain such as
     * "->constrained('accounts')" or "->references('id')->on('users')".
     *
     * When constrained() has no argument, Laravel infers the table from the
     * column name (user_id → users), which we mirror here.
     *
     * @param  string $table
     * @param  string $column
     * @param  string $chain
     * @return void
     */
    private function recordForeignKeyFromChain($table, $column, $chain)
    {
        $references = 'id';
        $on         = null;

        if (preg_match('/->references\(\s*[\'"]([^\'"]+)[\'"]/', $chain, $m)) {
            $references = $m[1];
        }

        if (preg_match('/->on\(\s*[\'"]([^\'"]+)[\'"]/', $chain, $m)) {
            $on = $m[1];
        }

        if ($on === null && preg_match('/->constrained\(\s*[\'"]([^\'"]+)[\'"]/', $chain, $m)) {
            $on = $m[1];
        }

        if ($on === null && preg_match('/->constrained\(\s*(\w+)::class/', $chain, $m)) {
            $on = Str::plural(Str::snake($m[1]));
        }

        if ($on === null) {
            $on = $this->guessReferencedTable($column);
        }

        if ($on === null) {
            return;
        }

        $this->recordForeignKey($table, $column, $references, $on);
    }

    /**
     * user_id → users. Returns null when the column is not *_id.
     *
     * @param  string $column
     * @return string|null
     */
    private function guessReferencedTable($column)
    {
        if (substr($column, -3) !== '_id') {
            return null;
        }

        $base = substr($column, 0, -3);

        if ($base === '') {
            return null;
        }

        return Str::plural($base);
    }

    /**
     * @param  string $table
     * @param  string $column
     * @param  string $references
     * @param  string $on
     * @return void
     */
    private function recordForeignKey($table, $column, $references, $on)
    {
        if (!isset($this->foreignKeys[$table])) {
            $this->foreignKeys[$table] = [];
        }

        foreach ($this->foreignKeys[$table] as $existing) {
            if ($existing['column'] === $column) {
                return;
            }
        }

        $this->foreignKeys[$table][] = [
            'column'     => $column,
            'references' => $references,
            'on'         => $on,
            'name'       => sprintf('%s_%s_foreign', $table, $column),
        ];
    }

    /**
     * @param  string   $table
     * @param  string[] $columns
     * @param  bool     $unique
     * @return void
     */
    private function recordIndex($table, array $columns, $unique)
    {
        if (!isset($this->indexes[$table])) {
            $this->indexes[$table] = [];
        }

        $signature = implode(',', $columns);

        foreach ($this->indexes[$table] as $existing) {
            if (implode(',', $existing['columns']) === $signature) {
                return;
            }
        }

        $this->indexes[$table][] = [
            'name'    => sprintf('%s_%s_%s', $table, implode('_', $columns), $unique ? 'unique' : 'index'),
            'columns' => $columns,
            'unique'  => (bool) $unique,
        ];
    }

    /**
     * @param  string   $table
     * @param  string[] $columns
     * @return void
     */
    private function forgetForeignKeys($table, array $columns)
    {
        if (!isset($this->foreignKeys[$table]) || count($columns) === 0) {
            return;
        }

        $this->foreignKeys[$table] = array_values(array_filter(
            $this->foreignKeys[$table],
            function ($fk) use ($columns) {
                return !in_array($fk['column'], $columns, true)
                    && !in_array($fk['name'], $columns, true);
            }
        ));
    }

    /**
     * @param  string   $table
     * @param  string[] $columns
     * @return void
     */
    private function forgetIndexes($table, array $columns)
    {
        if (!isset($this->indexes[$table]) || count($columns) === 0) {
            return;
        }

        $this->indexes[$table] = array_values(array_filter(
            $this->indexes[$table],
            function ($index) use ($columns) {
                if (in_array($index['name'], $columns, true)) {
                    return false;
                }

                return implode(',', $index['columns']) !== implode(',', $columns);
            }
        ));
    }

    /**
     * Every quoted string argument, in order. Handles index(['a', 'b']).
     *
     * @param  string $args
     * @return string[]
     */
    private function allStringArgs($args)
    {
        if (!preg_match_all('/[\'"]([^\'"]+)[\'"]/', $args, $m)) {
            return [];
        }

        return $m[1];
    }

    // -------------------------------------------------------------------------
    // Column parsing
    // -------------------------------------------------------------------------

    private function parseColumns($table, $body)
    {
        $offset  = 0;
        $bodyLen = strlen($body);

        while (($pos = strpos($body, '$table->', $offset)) !== false) {
            $offset   = $pos + 8;
            $nameEnd  = $offset;

            while ($nameEnd < $bodyLen && (ctype_alnum($body[$nameEnd]) || $body[$nameEnd] === '_')) {
                $nameEnd++;
            }

            $method  = substr($body, $offset, $nameEnd - $offset);
            $offset  = $nameEnd;

            // Skip whitespace
            while ($offset < $bodyLen && $body[$offset] === ' ') {
                $offset++;
            }

            if ($offset >= $bodyLen || $body[$offset] !== '(') {
                continue;
            }

            $argsContent = $this->extractBalanced($body, $offset, '(', ')');
            $offset      = $offset + strlen($argsContent);

            // Capture the chained modifiers that follow, up to the statement
            // end, e.g. "->constrained()->nullable()" or
            // "->references('id')->on('users')".
            $chainEnd = strpos($body, ';', $offset);
            $chain    = $chainEnd === false
                ? substr($body, $offset)
                : substr($body, $offset, $chainEnd - $offset);

            $this->applyMethod($table, $method, $argsContent, $chain);
        }
    }

    private function applyMethod($table, $method, $args, $chain = '')
    {
        $col = $this->firstStringArg($args);

        switch ($method) {
            // ---- Standalone constraint / index declarations ----
            case 'foreign':
                if ($col !== null) {
                    $this->recordForeignKeyFromChain($table, $col, $chain);
                }
                return;

            case 'index':
            case 'unique':
            case 'fullText':
            case 'fullTextIndex':
            case 'spatialIndex':
                $columns = $this->allStringArgs($args);
                if (count($columns) > 0) {
                    $this->recordIndex($table, $columns, $method === 'unique');
                }
                return;

            case 'primary':
                $columns = $this->allStringArgs($args);
                if (count($columns) > 0) {
                    $this->recordIndex($table, $columns, true);
                }
                return;

            case 'dropForeign':
                $this->forgetForeignKeys($table, $this->allStringArgs($args));
                return;

            case 'dropIndex':
            case 'dropUnique':
            case 'dropPrimary':
                $this->forgetIndexes($table, $this->allStringArgs($args));
                return;

            // ---- No-argument column helpers ----
            case 'id':
                $this->tables[$table]['id'] = 'bigint unsigned';
                return;

            case 'timestamps':
            case 'nullableTimestamps':
            case 'timestampsTz':
                $this->tables[$table]['created_at'] = 'timestamp';
                $this->tables[$table]['updated_at']  = 'timestamp';
                return;

            case 'softDeletes':
            case 'softDeletesTz':
                $this->tables[$table]['deleted_at'] = 'timestamp';
                return;

            case 'rememberToken':
                $this->tables[$table]['remember_token'] = 'varchar';
                return;

            // ---- Polymorphic helpers ----
            case 'morphs':
            case 'nullableMorphs':
            case 'ulidMorphs':
            case 'nullableUlidMorphs':
            case 'uuidMorphs':
            case 'nullableUuidMorphs':
                if ($col !== null) {
                    $this->tables[$table][$col . '_id']   = 'bigint unsigned';
                    $this->tables[$table][$col . '_type'] = 'varchar';

                    // morphs() also creates a composite index on (type, id).
                    $this->recordIndex($table, [$col . '_type', $col . '_id'], false);
                }
                return;

            // ---- FK helper that derives column from model class ----
            case 'foreignIdFor':
                // foreignIdFor(User::class) → user_id
                $derived = null;

                if (preg_match('/(\w+)::class/', $args, $m)) {
                    $derived = Str::snake($m[1]) . '_id';
                } elseif ($col !== null) {
                    // foreignIdFor('App\Model\User') → user_id
                    $parts   = explode('\\', $col);
                    $derived = Str::snake(end($parts)) . '_id';
                }

                if ($derived !== null) {
                    $this->tables[$table][$derived] = 'bigint unsigned';
                    $this->applyChainModifiers($table, $derived, $chain);
                }
                return;

            // ---- Removal ----
            case 'dropColumn':
            case 'removeColumn':
                if ($col !== null) {
                    unset($this->tables[$table][$col]);
                }
                // dropColumn can also accept an array — handle that separately
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $args, $m)) {
                    foreach ($m[1] as $dropped) {
                        unset($this->tables[$table][$dropped]);
                    }
                }
                return;

            case 'renameColumn':
                if (preg_match('/[\'"]([^\'"]+)[\'"][^\'",]*[\'"]([^\'"]+)[\'"]/', $args, $m)) {
                    $old = $m[1];
                    $new = $m[2];
                    if (isset($this->tables[$table][$old])) {
                        $type = $this->tables[$table][$old];
                        unset($this->tables[$table][$old]);
                        $this->tables[$table][$new] = $type;
                    }
                }
                return;
        }

        // Single-column definition methods
        if ($col === null) {
            return;
        }

        $type = $this->resolveType($method);
        if ($type !== null) {
            $this->tables[$table][$col] = $type;
            $this->applyChainModifiers($table, $col, $chain);
        }
    }

    /**
     * Map a Blueprint method name to a simplified type label.
     * Returns null for non-column-defining methods (e.g. index, primary, etc.).
     *
     * @return string|null
     */
    private function resolveType($method)
    {
        static $map = [
            // Auto-increment
            'increments'             => 'int unsigned',
            'bigIncrements'          => 'bigint unsigned',
            'smallIncrements'        => 'smallint unsigned',
            'tinyIncrements'         => 'tinyint unsigned',
            'mediumIncrements'       => 'mediumint unsigned',
            // Integer
            'integer'                => 'int',
            'bigInteger'             => 'bigint',
            'smallInteger'           => 'smallint',
            'tinyInteger'            => 'tinyint',
            'mediumInteger'          => 'mediumint',
            'unsignedInteger'        => 'int unsigned',
            'unsignedBigInteger'     => 'bigint unsigned',
            'unsignedSmallInteger'   => 'smallint unsigned',
            'unsignedTinyInteger'    => 'tinyint unsigned',
            'unsignedMediumInteger'  => 'mediumint unsigned',
            // FK helpers
            'foreignId'              => 'bigint unsigned',
            'foreignUuid'            => 'char',
            'foreignUlid'            => 'char',
            // String / text
            'string'                 => 'varchar',
            'char'                   => 'char',
            'text'                   => 'text',
            'mediumText'             => 'mediumtext',
            'longText'               => 'longtext',
            'tinyText'               => 'tinytext',
            // Numeric
            'float'                  => 'float',
            'double'                 => 'double',
            'decimal'                => 'decimal',
            'unsignedDecimal'        => 'decimal unsigned',
            // Date / time
            'date'                   => 'date',
            'time'                   => 'time',
            'timeTz'                 => 'time',
            'dateTime'               => 'datetime',
            'dateTimeTz'             => 'datetime',
            'timestamp'              => 'timestamp',
            'timestampTz'            => 'timestamp',
            'year'                   => 'year',
            // Other
            'boolean'                => 'tinyint',
            'binary'                 => 'blob',
            'json'                   => 'json',
            'jsonb'                  => 'json',
            'uuid'                   => 'char',
            'ulid'                   => 'char',
            'ipAddress'              => 'varchar',
            'macAddress'             => 'varchar',
            'enum'                   => 'enum',
            'set'                    => 'set',
            'geometry'               => 'geometry',
            'point'                  => 'point',
            'lineString'             => 'linestring',
            'polygon'                => 'polygon',
            'geometryCollection'     => 'geometrycollection',
            'multiPoint'             => 'multipoint',
            'multiLineString'        => 'multilinestring',
            'multiPolygon'           => 'multipolygon',
        ];

        return $map[$method] ?? null;
    }

    // -------------------------------------------------------------------------
    // String / parsing utilities
    // -------------------------------------------------------------------------

    /**
     * Extract balanced content between the first matching open/close delimiter
     * pair starting at $start, including the delimiters themselves.
     */
    private function extractBalanced($src, $start, $open, $close)
    {
        $depth   = 0;
        $result  = '';
        $inStr   = false;
        $strChar = null;
        $len     = strlen($src);

        for ($i = $start; $i < $len; $i++) {
            $c = $src[$i];

            if ($inStr) {
                $result .= $c;
                if ($c === $strChar && ($i === 0 || $src[$i - 1] !== '\\')) {
                    $inStr = false;
                }
                continue;
            }

            if ($c === '"' || $c === "'") {
                $inStr   = true;
                $strChar = $c;
                $result .= $c;
                continue;
            }

            $result .= $c;

            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Extract the first single- or double-quoted string from an argument string.
     *
     * @return string|null
     */
    private function firstStringArg($args)
    {
        if (preg_match('/[\'"]([^\'"]+)[\'"]/', $args, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract the closure body (content inside { }) from Schema::create/table args.
     *
     * @return string|null
     */
    private function extractClosureBody($args)
    {
        $pos = strpos($args, '{');
        if ($pos === false) {
            return null;
        }
        $block = $this->extractBalanced($args, $pos, '{', '}');
        return substr($block, 1, -1); // strip outer braces
    }
}
