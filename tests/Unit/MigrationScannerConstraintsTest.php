<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Support\MigrationScanner;
use Devlin\ModelAnalyzer\Tests\TestCase;

class MigrationScannerConstraintsTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ma-migrations-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param string $name
     * @param string $body
     * @return void
     */
    private function migration($name, $body)
    {
        file_put_contents($this->dir . '/' . $name, "<?php\n" . $body);
    }

    /**
     * @return MigrationScanner
     */
    private function scan()
    {
        $scanner = new MigrationScanner();
        $scanner->scan([$this->dir]);

        return $scanner;
    }

    public function test_it_records_foreign_key_from_constrained_without_argument()
    {
        $this->migration('2020_01_01_000001_create_orders.php', <<<'PHP'
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->timestamps();
});
PHP
        );

        $fks = $this->scan()->getForeignKeys();

        $this->assertArrayHasKey('orders', $fks);
        $this->assertCount(1, $fks['orders']);
        $this->assertSame('user_id', $fks['orders'][0]['column']);
        $this->assertSame('users', $fks['orders'][0]['on']);
        $this->assertSame('id', $fks['orders'][0]['references']);
    }

    public function test_it_records_foreign_key_from_constrained_with_explicit_table()
    {
        $this->migration('2020_01_01_000001_create_ledger.php', <<<'PHP'
Schema::create('ledger', function (Blueprint $table) {
    $table->foreignId('owner_id')->constrained('accounts');
});
PHP
        );

        $fks = $this->scan()->getForeignKeys();

        $this->assertSame('accounts', $fks['ledger'][0]['on']);
        $this->assertSame('owner_id', $fks['ledger'][0]['column']);
    }

    public function test_it_records_foreign_key_from_references_on_chain()
    {
        $this->migration('2020_01_01_000001_create_posts.php', <<<'PHP'
Schema::create('posts', function (Blueprint $table) {
    $table->unsignedBigInteger('author_id');
    $table->foreign('author_id')->references('uuid')->on('writers');
});
PHP
        );

        $fks = $this->scan()->getForeignKeys();

        $this->assertCount(1, $fks['posts']);
        $this->assertSame('author_id', $fks['posts'][0]['column']);
        $this->assertSame('writers', $fks['posts'][0]['on']);
        $this->assertSame('uuid', $fks['posts'][0]['references']);
    }

    public function test_it_records_indexes_and_unique_constraints()
    {
        $this->migration('2020_01_01_000001_create_people.php', <<<'PHP'
Schema::create('people', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique();
    $table->string('slug')->index();
    $table->string('a');
    $table->string('b');
    $table->index(['a', 'b']);
});
PHP
        );

        $indexes = $this->scan()->getIndexes();
        $byCols  = [];

        foreach ($indexes['people'] as $index) {
            $byCols[implode(',', $index['columns'])] = $index['unique'];
        }

        $this->assertArrayHasKey('email', $byCols);
        $this->assertTrue($byCols['email']);
        $this->assertArrayHasKey('slug', $byCols);
        $this->assertFalse($byCols['slug']);
        $this->assertArrayHasKey('a,b', $byCols);
    }

    public function test_it_records_nullable_column_metadata()
    {
        $this->migration('2020_01_01_000001_create_notes.php', <<<'PHP'
Schema::create('notes', function (Blueprint $table) {
    $table->string('title');
    $table->text('body')->nullable();
});
PHP
        );

        $meta = $this->scan()->getColumnMeta();

        $this->assertTrue($meta['notes']['body']['nullable']);
        $this->assertArrayNotHasKey('title', isset($meta['notes']) ? $meta['notes'] : []);
    }

    public function test_it_records_composite_index_for_morphs()
    {
        $this->migration('2020_01_01_000001_create_taggables.php', <<<'PHP'
Schema::create('taggables', function (Blueprint $table) {
    $table->morphs('taggable');
});
PHP
        );

        $indexes = $this->scan()->getIndexes();

        $this->assertCount(1, $indexes['taggables']);
        $this->assertSame(
            ['taggable_type', 'taggable_id'],
            $indexes['taggables'][0]['columns']
        );
    }

    public function test_drop_foreign_removes_a_previously_declared_constraint()
    {
        $this->migration('2020_01_01_000001_create_items.php', <<<'PHP'
Schema::create('items', function (Blueprint $table) {
    $table->foreignId('user_id')->constrained();
});
PHP
        );

        $this->migration('2020_01_01_000002_drop_fk.php', <<<'PHP'
Schema::table('items', function (Blueprint $table) {
    $table->dropForeign(['user_id']);
});
PHP
        );

        $fks = $this->scan()->getForeignKeys();

        $this->assertSame([], $fks['items']);
    }

    public function test_it_does_not_throw_on_unparseable_migration()
    {
        $this->migration('2020_01_01_000001_broken.php', 'this is not ( valid php at all');

        $scanner = new MigrationScanner();
        $tables  = $scanner->scan([$this->dir]);

        $this->assertIsArray($tables);
    }

    public function test_down_method_does_not_erase_the_table_created_by_up()
    {
        $this->migration('2020_01_01_000001_create_users_table.php', <<<'PHP'
class CreateUsersTable extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
}
PHP
        );

        $scanner = new MigrationScanner();
        $tables  = $scanner->scan([$this->dir]);

        $this->assertArrayHasKey('users', $tables);
        $this->assertArrayHasKey('email', $tables['users']);
    }

    public function test_a_later_migration_can_still_drop_a_table_from_its_up_method()
    {
        $this->migration('2020_01_01_000001_create_legacy.php', <<<'PHP'
class CreateLegacy extends Migration
{
    public function up()
    {
        Schema::create('legacy', function (Blueprint $table) {
            $table->id();
        });
    }
}
PHP
        );

        $this->migration('2020_01_01_000002_drop_legacy.php', <<<'PHP'
class DropLegacy extends Migration
{
    public function up()
    {
        Schema::dropIfExists('legacy');
    }
}
PHP
        );

        $tables = (new MigrationScanner())->scan([$this->dir]);

        $this->assertArrayNotHasKey('legacy', $tables);
    }

    public function test_scan_return_shape_is_unchanged()
    {
        $this->migration('2020_01_01_000001_create_users.php', <<<'PHP'
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});
PHP
        );

        $scanner = new MigrationScanner();
        $tables  = $scanner->scan([$this->dir]);

        $this->assertSame(
            ['id' => 'bigint unsigned', 'name' => 'varchar'],
            $tables['users']
        );
    }
}
