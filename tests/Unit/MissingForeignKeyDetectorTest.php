<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Analyzers\MissingForeignKeyDetector;
use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;
use Devlin\ModelAnalyzer\Tests\TestCase;

class MissingForeignKeyDetectorTest extends TestCase
{
    /**
     * @param  array $tables table => [columns => [...], foreignKeys => [...], indexes => [...]]
     * @return SchemaSnapshot
     */
    private function snapshot(array $tables)
    {
        $snapshot = new SchemaSnapshot('test');

        foreach ($tables as $table => $spec) {
            $snapshot->ensureTable($table);

            foreach (isset($spec['columns']) ? $spec['columns'] : [] as $column) {
                $snapshot->tables[$table]['columns'][$column] = [
                    'name'     => $column,
                    'type'     => 'bigint unsigned',
                    'nullable' => false,
                    'key'      => '',
                    'default'  => null,
                ];
            }

            $snapshot->tables[$table]['foreignKeys'] = isset($spec['foreignKeys']) ? $spec['foreignKeys'] : [];
            $snapshot->tables[$table]['indexes']     = isset($spec['indexes']) ? $spec['indexes'] : [];
        }

        return $snapshot;
    }

    public function test_it_flags_an_id_column_whose_target_table_exists()
    {
        $snapshot = $this->snapshot([
            'users'  => ['columns' => ['id']],
            'orders' => ['columns' => ['id', 'user_id']],
        ]);

        $findings = (new MissingForeignKeyDetector())->detect($snapshot);

        $this->assertCount(1, $findings);
        $this->assertSame('orders', $findings[0]['table']);
        $this->assertSame('user_id', $findings[0]['column']);
        $this->assertSame('users', $findings[0]['on']);
        $this->assertStringContainsString("->references('id')->on('users')", $findings[0]['suggestion']);
    }

    public function test_it_skips_columns_whose_target_table_does_not_exist()
    {
        $snapshot = $this->snapshot([
            'logs' => ['columns' => ['id', 'external_id']],
        ]);

        $this->assertSame([], (new MissingForeignKeyDetector())->detect($snapshot));
    }

    public function test_it_skips_columns_that_already_have_a_constraint()
    {
        $snapshot = $this->snapshot([
            'users'  => ['columns' => ['id']],
            'orders' => [
                'columns'     => ['id', 'user_id'],
                'foreignKeys' => [[
                    'column'     => 'user_id',
                    'references' => 'id',
                    'on'         => 'users',
                    'name'       => 'orders_user_id_foreign',
                ]],
            ],
        ]);

        $this->assertSame([], (new MissingForeignKeyDetector())->detect($snapshot));
    }

    public function test_it_skips_polymorphic_columns()
    {
        $snapshot = $this->snapshot([
            'posts'     => ['columns' => ['id']],
            'taggables' => ['columns' => ['taggable_id', 'taggable_type']],
        ]);

        $this->assertSame([], (new MissingForeignKeyDetector())->detect($snapshot));
    }

    public function test_it_reports_whether_the_column_is_indexed()
    {
        $snapshot = $this->snapshot([
            'users'  => ['columns' => ['id']],
            'orders' => [
                'columns' => ['id', 'user_id'],
                'indexes' => [[
                    'name'    => 'orders_user_id_index',
                    'columns' => ['user_id'],
                    'unique'  => false,
                ]],
            ],
        ]);

        $findings = (new MissingForeignKeyDetector())->detect($snapshot);

        $this->assertTrue($findings[0]['has_index']);
    }

    public function test_it_returns_nothing_for_an_unavailable_snapshot()
    {
        $snapshot = SchemaSnapshot::unavailable('database', 'no connection');

        $this->assertSame([], (new MissingForeignKeyDetector())->detect($snapshot));
    }

    public function test_it_matches_a_singular_table_name_when_no_plural_exists()
    {
        $snapshot = $this->snapshot([
            'staff'   => ['columns' => ['id']],
            'tickets' => ['columns' => ['id', 'staff_id']],
        ]);

        $findings = (new MissingForeignKeyDetector())->detect($snapshot);

        $this->assertCount(1, $findings);
        $this->assertSame('staff', $findings[0]['on']);
    }
}
