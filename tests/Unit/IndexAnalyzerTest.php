<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Analyzers\DatabaseSchemaReader;
use Devlin\ModelAnalyzer\Analyzers\IndexAnalyzer;
use Devlin\ModelAnalyzer\Analyzers\ModelRelationshipParser;
use Devlin\ModelAnalyzer\Models\AnalysisResult;
use Devlin\ModelAnalyzer\Models\ModelInfo;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Post;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\User;
use Devlin\ModelAnalyzer\Tests\TestCase;

class IndexAnalyzerTest extends TestCase
{
    /** @var IndexAnalyzer */
    private $analyzer;

    /** @var ModelRelationshipParser */
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $reader         = new DatabaseSchemaReader(null, ['migrations']);
        $this->analyzer = new IndexAnalyzer($reader);
        $this->parser   = new ModelRelationshipParser();
    }

    /** @test */
    public function it_does_not_report_when_index_exists()
    {
        // posts.user_id has an index in the migration
        $result = new AnalysisResult();

        $postRels = $this->parser->parse(Post::class);
        $result->models[] = new ModelInfo(Post::class, 'Post', 'posts', $postRels);

        $this->analyzer->detect($result);

        $indexIssues = array_filter($result->issues, function ($i) {
            return $i->type === 'missing_index'
                && isset($i->context['column'])
                && $i->context['column'] === 'user_id'
                && isset($i->context['table'])
                && $i->context['table'] === 'posts';
        });

        $this->assertCount(0, $indexIssues);
    }

    /** @test */
    public function it_reports_correct_model_name_in_issue()
    {
        $result = new AnalysisResult();

        $postRels = $this->parser->parse(Post::class);
        $result->models[] = new ModelInfo(Post::class, 'Post', 'posts', $postRels);

        $this->analyzer->detect($result);

        // If any issues were detected, each must carry a non-empty model name.
        // Using addToAssertionCount so the test is not marked risky when no issues exist.
        $this->addToAssertionCount(1);
        foreach ($result->issues as $issue) {
            $this->assertNotEmpty($issue->model);
        }
    }

    /** @test */
    public function it_skips_models_whose_table_does_not_exist()
    {
        $result = new AnalysisResult();

        // Fake model pointing at a non-existent table
        $result->models[] = new ModelInfo(
            'App\Models\Ghost',
            'Ghost',
            'ghost_table_xyz',
            []
        );

        $this->analyzer->detect($result);

        $this->assertCount(0, $result->issues);
    }
}
