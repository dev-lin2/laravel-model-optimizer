<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;
use Devlin\ModelAnalyzer\Support\DefinitionsGenerator;
use Devlin\ModelAnalyzer\Tests\TestCase;

class DefinitionsGeneratorTest extends TestCase
{
    /**
     * @return SchemaSnapshot
     */
    private function snapshot()
    {
        $snapshot = new SchemaSnapshot('test');

        $snapshot->ensureTable('users');
        $snapshot->tables['users']['columns'] = [
            'id'         => ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'key' => 'PRI', 'default' => null],
            'email'      => ['name' => 'email', 'type' => 'varchar', 'nullable' => false, 'key' => '', 'default' => null],
            'created_at' => ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'key' => '', 'default' => null],
            'updated_at' => ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'key' => '', 'default' => null],
        ];
        $snapshot->tables['users']['indexes'] = [
            ['name' => 'users_email_unique', 'columns' => ['email'], 'unique' => true],
        ];

        $snapshot->ensureTable('orders');
        $snapshot->tables['orders']['columns'] = [
            'id'        => ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'key' => 'PRI', 'default' => null],
            'user_id'   => ['name' => 'user_id', 'type' => 'bigint', 'nullable' => false, 'key' => '', 'default' => null],
            'coupon_id' => ['name' => 'coupon_id', 'type' => 'bigint', 'nullable' => true, 'key' => '', 'default' => null],
            'deleted_at' => ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'key' => '', 'default' => null],
        ];
        $snapshot->tables['orders']['foreignKeys'] = [
            ['column' => 'user_id', 'references' => 'id', 'on' => 'users', 'name' => 'fk1'],
            ['column' => 'coupon_id', 'references' => 'id', 'on' => 'coupons', 'name' => 'fk2'],
        ];

        $snapshot->ensureTable('coupons');
        $snapshot->tables['coupons']['columns'] = [
            'id' => ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'key' => 'PRI', 'default' => null],
        ];

        return $snapshot;
    }

    public function test_it_describes_a_table_in_prose_with_column_count_and_key()
    {
        $md = (new DefinitionsGenerator())->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('# Table Definitions', $md);
        $this->assertStringContainsString('It holds 4 columns, keyed by `id`.', $md);
    }

    public function test_it_names_the_model_when_one_maps_to_the_table()
    {
        $generator = new DefinitionsGenerator([
            'orders' => ['model' => 'App\Models\Order', 'short_name' => 'Order', 'relationships' => []],
        ]);

        $md = $generator->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('`orders` is backed by the `App\Models\Order` model.', $md);
        $this->assertStringContainsString('`users` has no Eloquent model', $md);
    }

    public function test_it_describes_outgoing_references_and_marks_nullable_ones_optional()
    {
        $md = (new DefinitionsGenerator())->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('references one `users` record via `user_id`', $md);
        $this->assertStringContainsString('optionally references one `coupons` record via `coupon_id`', $md);
    }

    public function test_it_describes_incoming_references()
    {
        $md = (new DefinitionsGenerator())->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('Referenced by `orders` via `user_id`.', $md);
    }

    public function test_it_notes_laravel_conventions()
    {
        $md = (new DefinitionsGenerator())->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('records creation and update times', $md);
        $this->assertStringContainsString('supports soft deletion via `deleted_at`', $md);
    }

    public function test_it_reports_unique_constraints_and_unindexed_foreign_keys()
    {
        $md = (new DefinitionsGenerator())->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('`email` must be unique', $md);
        // Both FK columns lack an index, so the plural form is used.
        $this->assertStringContainsString(
            '`user_id` and `coupon_id` have a foreign key but no index',
            $md
        );
    }

    public function test_it_lists_model_relationships_when_available()
    {
        $generator = new DefinitionsGenerator([
            'orders' => [
                'model'         => 'App\Models\Order',
                'short_name'    => 'Order',
                'relationships' => [
                    ['method' => 'user', 'type' => 'BelongsTo', 'related' => 'App\Models\User'],
                ],
            ],
        ]);

        $md = $generator->generateMarkdown($this->snapshot());

        $this->assertStringContainsString('The model declares one relationship: `user()` (BelongsTo).', $md);
    }

    public function test_it_does_not_contain_a_column_grid()
    {
        $md = (new DefinitionsGenerator())->generateMarkdown($this->snapshot());

        $this->assertStringNotContainsString('| Column | Type |', $md);
        $this->assertStringNotContainsString('|---|', $md);
    }

    public function test_it_stays_silent_about_facts_the_source_does_not_know()
    {
        $snapshot = new SchemaSnapshot('migrations');
        $snapshot->ensureTable('notes');
        $snapshot->tables['notes']['columns'] = [
            'id'    => ['name' => 'id', 'type' => 'bigint', 'nullable' => null, 'key' => 'PRI', 'default' => null],
            'title' => ['name' => 'title', 'type' => 'varchar', 'nullable' => null, 'key' => '', 'default' => null],
        ];

        $md = (new DefinitionsGenerator())->generateMarkdown($snapshot);

        // Nullability is unknown, so no "Required values" claim may appear.
        $this->assertStringNotContainsString('Required values', $md);
        $this->assertStringContainsString('It holds 2 columns, keyed by `id`.', $md);
    }

    public function test_an_unavailable_snapshot_renders_a_notice_not_an_error()
    {
        $snapshot = SchemaSnapshot::unavailable('database', 'no connection');

        $md = (new DefinitionsGenerator())->generateMarkdown($snapshot);

        $this->assertStringContainsString('Schema unavailable', $md);
        $this->assertStringContainsString('no connection', $md);
    }

    public function test_html_output_escapes_and_renders_code_spans()
    {
        $html = (new DefinitionsGenerator())->generateHtml($this->snapshot());

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<code>orders</code>', $html);
        $this->assertStringNotContainsString('`', $html);
    }
}
