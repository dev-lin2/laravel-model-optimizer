<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Support\ModelScanner;
use Devlin\ModelAnalyzer\Tests\TestCase;

class ModelScannerTest extends TestCase
{
    /** @test */
    public function it_finds_all_eloquent_models()
    {
        $scanner = new ModelScanner([
            __DIR__ . '/../Fixtures/Models',
        ]);

        $models = $scanner->scan();

        $this->assertContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\User',
            $models
        );
        $this->assertContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\Post',
            $models
        );
        $this->assertContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\Profile',
            $models
        );
        $this->assertContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\Comment',
            $models
        );
    }

    /** @test */
    public function it_returns_at_least_four_fixture_models()
    {
        $scanner = new ModelScanner([
            __DIR__ . '/../Fixtures/Models',
        ]);

        $models = $scanner->scan();

        $this->assertGreaterThanOrEqual(4, count($models));
    }

    /** @test */
    public function it_excludes_configured_models()
    {
        $scanner = new ModelScanner(
            [__DIR__ . '/../Fixtures/Models'],
            ['Devlin\ModelAnalyzer\Tests\Fixtures\Models\Comment']
        );

        $models = $scanner->scan();

        $this->assertNotContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\Comment',
            $models
        );
    }

    /** @test */
    public function it_returns_empty_array_for_nonexistent_path()
    {
        $scanner = new ModelScanner(['/nonexistent/path/to/models']);
        $models  = $scanner->scan();

        $this->assertSame([], $models);
    }

    /** @test */
    public function it_handles_multiple_model_directories()
    {
        $scanner = new ModelScanner([
            __DIR__ . '/../Fixtures/Models',
            __DIR__ . '/../Fixtures/Models', // duplicate — should deduplicate
        ]);

        $models = $scanner->scan();

        // No duplicates
        $this->assertSame(count($models), count(array_unique($models)));
    }

    /** @test */
    public function it_extracts_correct_namespace_and_class_name()
    {
        $scanner = new ModelScanner([__DIR__ . '/../Fixtures/Models']);
        $models  = $scanner->scan();

        foreach ($models as $class) {
            $this->assertStringStartsWith(
                'Devlin\\ModelAnalyzer\\Tests\\Fixtures\\Models\\',
                $class,
                "Model {$class} has unexpected namespace"
            );
        }
    }
}
