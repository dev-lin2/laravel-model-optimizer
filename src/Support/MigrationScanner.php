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
                // Skip files that cannot be parsed
            }
        }

        return $this->tables;
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

    private function processFile($file)
    {
        $src = @file_get_contents($file);
        if ($src === false) {
            return;
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
                    unset($this->tables[$table]);
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

            $this->applyMethod($table, $method, $argsContent);
        }
    }

    private function applyMethod($table, $method, $args)
    {
        $col = $this->firstStringArg($args);

        switch ($method) {
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
                }
                return;

            // ---- FK helper that derives column from model class ----
            case 'foreignIdFor':
                // foreignIdFor(User::class) → user_id
                if (preg_match('/(\w+)::class/', $args, $m)) {
                    $this->tables[$table][Str::snake($m[1]) . '_id'] = 'bigint unsigned';
                } elseif ($col !== null) {
                    // foreignIdFor('App\Model\User') → user_id
                    $parts = explode('\\', $col);
                    $this->tables[$table][Str::snake(end($parts)) . '_id'] = 'bigint unsigned';
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
