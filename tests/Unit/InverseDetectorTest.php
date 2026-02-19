<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Analyzers\InverseDetector;
use Devlin\ModelAnalyzer\Analyzers\ModelRelationshipParser;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\ModelInfo;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Comment;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Post;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Profile;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\User;
use Devlin\ModelAnalyzer\Tests\TestCase;

class InverseDetectorTest extends TestCase
{
    /** @var InverseDetector */
    private $detector;

    /** @var ModelRelationshipParser */
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new InverseDetector();
        $this->parser   = new ModelRelationshipParser();
    }

    private function buildResult(array $classes)
    {
        $result = new AnalysisResult();

        foreach ($classes as $class) {
            $instance      = new $class();
            $relationships = $this->parser->parse($class);
            $result->models[] = new ModelInfo(
                $class,
                class_basename($class),
                $instance->getTable(),
                $relationships
            );
        }

        return $result;
    }

    /** @test */
    public function it_does_not_report_when_has_many_has_inverse()
    {
        // User hasMany Post, Post belongsTo User — both correct
        $result = $this->buildResult([User::class, Post::class]);
        $this->detector->detect($result);

        $inverseIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'missing_inverse'
                && $i->model === 'User'
                && strpos($i->message, 'posts') !== false;
        });

        $this->assertCount(0, $inverseIssues);
    }

    /** @test */
    public function it_detects_missing_inverse_when_belongs_to_is_absent()
    {
        // Build result where Post has no belongsTo User
        $result = new AnalysisResult();

        // User with hasMany Post
        $userRels = $this->parser->parse(User::class);
        $result->models[] = new ModelInfo(User::class, 'User', 'users', $userRels);

        // Post with comments only — no belongsTo User
        $postRels = array_filter(
            $this->parser->parse(Post::class),
            function ($r) { return $r->name !== 'user'; }
        );
        $result->models[] = new ModelInfo(Post::class, 'Post', 'posts', array_values($postRels));

        $this->detector->detect($result);

        $inverseIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'missing_inverse';
        });

        $this->assertGreaterThan(0, count($inverseIssues));
    }

    /** @test */
    public function it_detects_correct_inverse_exists_for_post_and_comment()
    {
        $result = $this->buildResult([Post::class, Comment::class]);
        $this->detector->detect($result);

        // Post hasMany Comment with inverse → no missing_inverse for that pair
        $issues = array_filter($result->issues, function ($i) {
            return $i->type === 'missing_inverse'
                && $i->model === 'Post'
                && strpos($i->message, 'comments') !== false;
        });

        $this->assertCount(0, $issues);
    }

    /** @test */
    public function it_generates_suggestion_for_missing_inverse()
    {
        $result = new AnalysisResult();

        $userRels = $this->parser->parse(User::class);
        $result->models[] = new ModelInfo(User::class, 'User', 'users', $userRels);

        // Post without the belongsTo User
        $postRels = array_filter(
            $this->parser->parse(Post::class),
            function ($r) { return $r->name !== 'user'; }
        );
        $result->models[] = new ModelInfo(Post::class, 'Post', 'posts', array_values($postRels));

        $this->detector->detect($result);

        $inverseIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'missing_inverse';
        });

        foreach ($inverseIssues as $issue) {
            $this->assertNotNull($issue->suggestion);
            $this->assertStringContainsString('belongsTo', $issue->suggestion);
        }
    }
}
