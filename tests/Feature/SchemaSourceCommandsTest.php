<?php

namespace Devlin\ModelAnalyzer\Tests\Feature;

use Devlin\ModelAnalyzer\Tests\TestCase;

/**
 * Covers the --source-aware commands, with particular attention to the
 * "never break" contract: every degraded path must still exit 0.
 */
class SchemaSourceCommandsTest extends TestCase
{
    /** @var string */
    private $out;

    protected function setUp(): void
    {
        parent::setUp();

        $this->out = sys_get_temp_dir() . '/ma-out-' . uniqid();
        mkdir($this->out, 0777, true);

        config()->set('model-analyzer.migration_paths', [
            __DIR__ . '/../Fixtures/database/migrations',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->out . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->out);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // docs
    // ---------------------------------------------------------------------

    public function test_docs_generates_markdown_from_the_database()
    {
        $path = $this->out . '/docs.md';

        [$exit] = $this->captureArtisanOutput('model-analyzer:docs', [
            '--source' => 'database',
            '--format' => 'md',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($path);

        $content = file_get_contents($path);

        $this->assertStringContainsString('# Database Documentation', $content);
        $this->assertStringContainsString('users', $content);
        $this->assertStringContainsString('| Column | Type |', $content);
    }

    public function test_docs_generates_from_migrations_without_touching_the_database()
    {
        $path = $this->out . '/docs-mig.md';

        [$exit] = $this->captureArtisanOutput('model-analyzer:docs', [
            '--source' => 'migrations',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);

        $content = file_get_contents($path);

        $this->assertStringContainsString('Source: **migrations**', $content);
        $this->assertStringContainsString('users', $content);
        $this->assertStringContainsString('posts', $content);
    }

    public function test_docs_generates_html()
    {
        $path = $this->out . '/docs.html';

        [$exit] = $this->captureArtisanOutput('model-analyzer:docs', [
            '--format' => 'html',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);

        $content = file_get_contents($path);

        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('<table>', $content);
    }

    // ---------------------------------------------------------------------
    // report
    // ---------------------------------------------------------------------

    public function test_report_emits_valid_json()
    {
        $path = $this->out . '/report.json';

        [$exit] = $this->captureArtisanOutput('model-analyzer:report', [
            '--format' => 'json',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);

        $decoded = json_decode(file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('missing_foreign_keys', $decoded);
        $this->assertArrayHasKey('sources', $decoded);
    }

    public function test_report_on_both_sources_includes_a_drift_section()
    {
        $path = $this->out . '/report.md';

        [$exit] = $this->captureArtisanOutput('model-analyzer:report', [
            '--source' => 'both',
            '--format' => 'md',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);

        $content = file_get_contents($path);

        $this->assertStringContainsString('## Schema drift', $content);
        $this->assertStringContainsString('Missing foreign keys (database)', $content);
        $this->assertStringContainsString('Missing foreign keys (migrations)', $content);
    }

    public function test_report_cli_format_renders_a_summary()
    {
        [$exit, $output] = $this->captureArtisanOutput('model-analyzer:report');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Schema Report', $output);
        $this->assertStringContainsString('Summary:', $output);
    }

    // ---------------------------------------------------------------------
    // visualize --source
    // ---------------------------------------------------------------------

    public function test_visualize_erd_from_migrations_includes_tables_without_models()
    {
        $path = $this->out . '/erd.html';

        [$exit] = $this->captureArtisanOutput('model-analyzer:visualize', [
            '--erd'    => true,
            '--source' => 'migrations',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);

        $content = file_get_contents($path);

        $this->assertStringContainsString('"tableName": "users"', $content);
        $this->assertStringContainsString('"tableName": "posts"', $content);
    }

    public function test_visualize_erd_renders_real_column_names_not_indexes()
    {
        $path = $this->out . '/erd-cols.html';

        $this->captureArtisanOutput('model-analyzer:visualize', [
            '--erd'    => true,
            '--source' => 'database',
            '--output' => $path,
        ]);

        $content = file_get_contents($path);

        // The pre-fix bug rendered columns as integer indexes with type
        // "unknown"; a real column name must appear instead.
        $this->assertStringContainsString('"name": "email"', $content);
        $this->assertStringNotContainsString('"name": "0"', $content);
    }

    public function test_visualize_svg_erd_from_source()
    {
        $path = $this->out . '/erd.svg';

        [$exit] = $this->captureArtisanOutput('model-analyzer:visualize', [
            '--erd'    => true,
            '--source' => 'database',
            '--format' => 'svg',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('<svg', file_get_contents($path));
    }

    // ---------------------------------------------------------------------
    // never break
    // ---------------------------------------------------------------------

    public function test_unknown_source_falls_back_and_still_succeeds()
    {
        $path = $this->out . '/fallback.md';

        [$exit, $output] = $this->captureArtisanOutput('model-analyzer:docs', [
            '--source' => 'banana',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
        $this->assertStringContainsString('Unknown --source', $output);
    }

    public function test_missing_migration_paths_do_not_break_docs()
    {
        config()->set('model-analyzer.migration_paths', ['/definitely/not/a/real/path']);

        $path = $this->out . '/no-migrations.md';

        [$exit] = $this->captureArtisanOutput('model-analyzer:docs', [
            '--source' => 'migrations',
            '--output' => $path,
        ]);

        $this->assertSame(0, $exit);

        $content = file_get_contents($path);

        $this->assertStringContainsString('Schema unavailable', $content);
    }

    public function test_empty_migration_paths_do_not_break_report()
    {
        config()->set('model-analyzer.migration_paths', []);

        [$exit, $output] = $this->captureArtisanOutput('model-analyzer:report', [
            '--source' => 'migrations',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('unavailable', $output);
    }

    public function test_nonexistent_output_directory_does_not_break()
    {
        [$exit] = $this->captureArtisanOutput('model-analyzer:docs', [
            '--output' => '/no/such/dir/docs.md',
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_hide_errors_and_warnings_suppress_notices()
    {
        config()->set('model-analyzer.migration_paths', ['/definitely/not/a/real/path']);

        [, $shown] = $this->captureArtisanOutput('model-analyzer:report', [
            '--source' => 'migrations',
        ]);

        [, $hidden] = $this->captureArtisanOutput('model-analyzer:report', [
            '--source'        => 'migrations',
            '--hide-errors'   => true,
            '--hide-warnings' => true,
        ]);

        $this->assertStringContainsString('None of the configured migration paths exist', $shown);
        $this->assertStringNotContainsString('None of the configured migration paths exist', $hidden);
    }

    public function test_issues_none_suppresses_all_notices()
    {
        config()->set('model-analyzer.migration_paths', ['/definitely/not/a/real/path']);

        [$exit, $output] = $this->captureArtisanOutput('model-analyzer:report', [
            '--source' => 'migrations',
            '--issues' => 'none',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('None of the configured migration paths exist', $output);
    }

    public function test_fail_on_findings_is_opt_in()
    {
        [$default] = $this->captureArtisanOutput('model-analyzer:report', [
            '--source' => 'both',
        ]);

        $this->assertSame(0, $default, 'report must exit 0 by default even with findings');
    }
}
