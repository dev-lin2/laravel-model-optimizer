<?php

namespace Devlin\ModelAnalyzer\Schema;

use Devlin\ModelAnalyzer\Analyzers\DatabaseSchemaReader;

/**
 * Builds schema sources from package config and a --source selector.
 *
 * Resolution never throws: an unknown selector falls back to the default and
 * an unbuildable source yields an unavailable snapshot.
 */
class SchemaSourceFactory
{
    const SOURCE_DATABASE   = 'database';
    const SOURCE_MIGRATIONS = 'migrations';
    const SOURCE_BOTH       = 'both';

    /** @var array */
    private $config;

    /**
     * @param array $config Package config (model-analyzer.*)
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Accepted --source values.
     *
     * @return string[]
     */
    public static function validSelectors()
    {
        return [self::SOURCE_DATABASE, self::SOURCE_MIGRATIONS, self::SOURCE_BOTH];
    }

    /**
     * Normalize a user-supplied selector. Aliases 'db' and 'migration' are
     * accepted; anything unrecognized returns null so the caller can warn.
     *
     * @param  string|null $selector
     * @return string|null
     */
    public static function normalizeSelector($selector)
    {
        $selector = strtolower(trim((string) $selector));

        if ($selector === '') {
            return self::SOURCE_DATABASE;
        }

        $aliases = [
            'db'         => self::SOURCE_DATABASE,
            'database'   => self::SOURCE_DATABASE,
            'migration'  => self::SOURCE_MIGRATIONS,
            'migrations' => self::SOURCE_MIGRATIONS,
            'both'       => self::SOURCE_BOTH,
            'all'        => self::SOURCE_BOTH,
        ];

        return isset($aliases[$selector]) ? $aliases[$selector] : null;
    }

    /**
     * @return DatabaseSchemaSource
     */
    public function database()
    {
        $reader = new DatabaseSchemaReader(
            isset($this->config['database_connection']) ? $this->config['database_connection'] : null,
            isset($this->config['excluded_tables']) ? (array) $this->config['excluded_tables'] : []
        );

        return new DatabaseSchemaSource($reader);
    }

    /**
     * @return MigrationSchemaSource
     */
    public function migrations()
    {
        return new MigrationSchemaSource(
            isset($this->config['migration_paths']) ? (array) $this->config['migration_paths'] : []
        );
    }

    /**
     * Snapshots for a normalized selector, keyed by source name.
     *
     * @param  string $selector
     * @return array<string, SchemaSnapshot>
     */
    public function snapshots($selector)
    {
        if ($selector === self::SOURCE_MIGRATIONS) {
            return ['migrations' => $this->migrations()->snapshot()];
        }

        if ($selector === self::SOURCE_BOTH) {
            return [
                'database'   => $this->database()->snapshot(),
                'migrations' => $this->migrations()->snapshot(),
            ];
        }

        return ['database' => $this->database()->snapshot()];
    }

    /**
     * The snapshot that should drive rendering for a selector. For 'both' the
     * database is primary, falling back to migrations when it is unavailable.
     *
     * @param  array<string, SchemaSnapshot> $snapshots
     * @return SchemaSnapshot
     */
    public static function primary(array $snapshots)
    {
        if (isset($snapshots['database']) && $snapshots['database']->available
            && !$snapshots['database']->isEmpty()) {
            return $snapshots['database'];
        }

        if (isset($snapshots['migrations']) && $snapshots['migrations']->available
            && !$snapshots['migrations']->isEmpty()) {
            return $snapshots['migrations'];
        }

        foreach ($snapshots as $snapshot) {
            return $snapshot;
        }

        return SchemaSnapshot::unavailable('unknown', 'No schema source produced a snapshot.');
    }
}
