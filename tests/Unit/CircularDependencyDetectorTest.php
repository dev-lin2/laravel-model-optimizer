<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Analyzers\CircularDependencyDetector;
use Devlin\ModelAnalyzer\Analyzers\ModelRelationshipParser;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\ModelInfo;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Comment;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Post;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Profile;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\User;
use Devlin\ModelAnalyzer\Tests\TestCase;

class CircularDependencyDetectorTest extends TestCase
{
    /** @var CircularDependencyDetector */
    private $detector;

    /** @var ModelRelationshipParser */
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CircularDependencyDetector();
        $this->parser   = new ModelRelationshipParser();
    }

    private function buildResult(array $classes)
    {
        $result = new AnalysisResult();

        foreach ($classes as $class) {
            $instance = new $class();
            $result->models[] = new ModelInfo(
                $class,
                class_basename($class),
                $instance->getTable(),
                $this->parser->parse($class)
            );
        }

        return $result;
    }

    /** @test */
    public function it_detects_simple_circular_dependency()
    {
        // User hasOne Profile → Profile hasOne User → circular
        $result = $this->buildResult([User::class, Profile::class]);
        $this->detector->detect($result);

        $circularIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'circular_dependency';
        });

        $this->assertGreaterThan(0, count($circularIssues));
    }

    /** @test */
    public function it_does_not_report_false_positives()
    {
        // Post → Comment → Post (belongsTo) is fine, not circular in a problematic sense
        // but Post hasMany Comment, Comment belongsTo Post IS technically circular via DFS.
        // We just ensure no spurious reports for non-cyclic graphs.
        $result = new AnalysisResult();

        // User → Post (one direction only, Post has no rel back to User in this test)
        $userRels = array_filter(
            $this->parser->parse(User::class),
            function ($r) { return $r->name === 'posts'; }
        );
        $result->models[] = new ModelInfo(User::class, 'User', 'users', array_values($userRels));

        // Post with no relationships back
        $result->models[] = new ModelInfo(Post::class, 'Post', 'posts', []);

        $this->detector->detect($result);

        $circularIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'circular_dependency';
        });

        $this->assertCount(0, $circularIssues);
    }

    /** @test */
    public function it_identifies_all_models_in_cycle()
    {
        $result = $this->buildResult([User::class, Profile::class]);
        $this->detector->detect($result);

        $circularIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'circular_dependency';
        });

        $this->assertNotEmpty($circularIssues);

        $issue = reset($circularIssues);
        $cycle = $issue->context['cycle'];

        // Cycle should contain both User and Profile
        $this->assertContains(User::class, $cycle);
        $this->assertContains(Profile::class, $cycle);
    }

    /** @test */
    public function it_includes_cycle_path_in_message()
    {
        $result = $this->buildResult([User::class, Profile::class]);
        $this->detector->detect($result);

        $circularIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'circular_dependency';
        });

        $issue = reset($circularIssues);
        $this->assertStringContainsString('→', $issue->message);
    }
}
