<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Analyzers\DatabaseSchemaReader;
use Devlin\ModelAnalyzer\Tests\TestCase;

class DatabaseSchemaReaderTest extends TestCase
{
    /** @var DatabaseSchemaReader */
    private $reader;

    protected function setUp(): void
    {
        parent::setUp();
        // Use default connection (SQLite :memory: set up by TestCase)
        $this->reader = new DatabaseSchemaReader(null, ['migrations']);
    }

    /** @test */
    public function it_reads_all_tables_from_database()
    {
        $tables = $this->reader->getTables();

        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
        $this->assertContains('profiles', $tables);
        $this->assertContains('comments', $tables);
    }

    /** @test */
    public function it_excludes_configured_tables()
    {
        $tables = $this->reader->getTables();

        $this->assertNotContains('migrations', $tables);
    }

    /** @test */
    public function it_reads_columns_for_a_table()
    {
        $columns = $this->reader->getColumns('users');

        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('name', $columns);
        $this->assertArrayHasKey('email', $columns);
    }

    /** @test */
    public function it_returns_true_when_table_exists()
    {
        $this->assertTrue($this->reader->tableExists('users'));
    }

    /** @test */
    public function it_returns_false_when_table_does_not_exist()
    {
        $this->assertFalse($this->reader->tableExists('non_existent_table_xyz'));
    }

    /** @test */
    public function it_returns_true_when_column_exists()
    {
        $this->assertTrue($this->reader->columnExists('posts', 'user_id'));
    }

    /** @test */
    public function it_returns_false_when_column_does_not_exist()
    {
        $this->assertFalse($this->reader->columnExists('posts', 'non_existent_column'));
    }

    /** @test */
    public function it_caches_table_results()
    {
        // Call twice — second call should use cache (no exception thrown)
        $first  = $this->reader->getTables();
        $second = $this->reader->getTables();

        $this->assertSame($first, $second);
    }

    /** @test */
    public function it_clears_cache_correctly()
    {
        $first = $this->reader->getTables();
        $this->reader->clearCache();
        $second = $this->reader->getTables();

        $this->assertEquals($first, $second); // same data, fresh query
    }
}
