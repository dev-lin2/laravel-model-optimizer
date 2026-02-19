<?php

namespace Devlin\ModelAnalyzer\Tests\Feature;

use Devlin\ModelAnalyzer\ModelAnalyzer;
use Devlin\ModelAnalyzer\Tests\TestCase;

class FullAnalysisTest extends TestCase
{
    /** @var ModelAnalyzer */
    private $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = $this->app->make(ModelAnalyzer::class);
    }

    /** @test */
    public function it_performs_complete_analysis()
    {
        $result = $this->analyzer->analyze();

        $this->assertNotEmpty($result->models);
        $this->assertIsInt($result->healthScore);
        $this->assertGreaterThanOrEqual(0, $result->healthScore);
        $this->assertLessThanOrEqual(100, $result->healthScore);
    }

    /** @test */
    public function it_finds_all_fixture_models()
    {
        $result = $this->analyzer->analyze();

        $classes = array_map(function ($m) { return $m->class; }, $result->models);

        $this->assertContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\User',
            $classes
        );
        $this->assertContains(
            'Devlin\ModelAnalyzer\Tests\Fixtures\Models\Post',
            $classes
        );
    }

    /** @test */
    public function it_detects_circular_dependency_in_fixtures()
    {
        $result = $this->analyzer->analyze();

        $circularIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'circular_dependency';
        });

        // User hasOne Profile, Profile hasOne User → circular
        $this->assertGreaterThan(0, count($circularIssues));
    }

    /** @test */
    public function it_calculates_a_health_score()
    {
        $result = $this->analyzer->analyze();

        $this->assertIsInt($result->healthScore);
        $this->assertGreaterThanOrEqual(0, $result->healthScore);
        $this->assertLessThanOrEqual(100, $result->healthScore);
    }

    /** @test */
    public function it_returns_serializable_result()
    {
        $result = $this->analyzer->analyze();
        $array  = $result->toArray();

        $this->assertArrayHasKey('health', $array);
        $this->assertArrayHasKey('score', $array['health']);
        $this->assertArrayHasKey('stats', $array['health']);
        $this->assertArrayHasKey('issues', $array);
    }

    /** @test */
    public function it_generates_actionable_suggestions_for_issues()
    {
        $result = $this->analyzer->analyze();

        $issuesWithSuggestions = array_filter($result->issues, function ($i) {
            return $i->suggestion !== null;
        });

        // At minimum the circular dependency issues should have suggestions
        $this->assertGreaterThan(0, count($issuesWithSuggestions));
    }
}
