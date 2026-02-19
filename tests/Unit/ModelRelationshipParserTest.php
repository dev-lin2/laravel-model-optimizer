<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Analyzers\ModelRelationshipParser;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Comment;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Post;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\Profile;
use Devlin\ModelAnalyzer\Tests\Fixtures\Models\User;
use Devlin\ModelAnalyzer\Tests\TestCase;

class ModelRelationshipParserTest extends TestCase
{
    /** @var ModelRelationshipParser */
    private $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ModelRelationshipParser();
    }

    /** @test */
    public function it_detects_has_many_relationship()
    {
        $rels = $this->parser->parse(User::class);

        $types = array_map(function ($r) { return $r->type; }, $rels);
        $this->assertContains('HasMany', $types);
    }

    /** @test */
    public function it_detects_belongs_to_relationship()
    {
        $rels = $this->parser->parse(Post::class);

        $types = array_map(function ($r) { return $r->type; }, $rels);
        $this->assertContains('BelongsTo', $types);
    }

    /** @test */
    public function it_detects_has_one_relationship()
    {
        $rels = $this->parser->parse(User::class);

        $types = array_map(function ($r) { return $r->type; }, $rels);
        $this->assertContains('HasOne', $types);
    }

    /** @test */
    public function it_extracts_correct_related_class()
    {
        $rels = $this->parser->parse(User::class);

        $relatedClasses = array_map(function ($r) { return $r->related; }, $rels);

        $this->assertContains(Post::class, $relatedClasses);
        $this->assertContains(Profile::class, $relatedClasses);
    }

    /** @test */
    public function it_extracts_foreign_key_for_has_many()
    {
        $rels = $this->parser->parse(User::class);

        $postRel = null;
        foreach ($rels as $rel) {
            if ($rel->name === 'posts') {
                $postRel = $rel;
                break;
            }
        }

        $this->assertNotNull($postRel);
        $this->assertSame('user_id', $postRel->foreignKey);
    }

    /** @test */
    public function it_extracts_related_table_name()
    {
        $rels = $this->parser->parse(User::class);

        $postRel = null;
        foreach ($rels as $rel) {
            if ($rel->name === 'posts') {
                $postRel = $rel;
                break;
            }
        }

        $this->assertNotNull($postRel);
        $this->assertSame('posts', $postRel->table);
    }

    /** @test */
    public function it_handles_polymorphic_morph_to()
    {
        $rels = $this->parser->parse(Comment::class);

        $types = array_map(function ($r) { return $r->type; }, $rels);
        $this->assertContains('MorphTo', $types);
    }

    /** @test */
    public function it_ignores_non_relationship_methods()
    {
        $rels = $this->parser->parse(User::class);

        // Only relationship methods should be returned
        foreach ($rels as $rel) {
            $this->assertNotEmpty($rel->type);
            $this->assertNotEmpty($rel->related);
        }

        // Should not include things like getTable(), getFillable(), etc.
        $names = array_map(function ($r) { return $r->name; }, $rels);
        $this->assertNotContains('getTable', $names);
        $this->assertNotContains('getFillable', $names);
    }

    /** @test */
    public function it_returns_empty_array_for_invalid_class()
    {
        $rels = $this->parser->parse('App\Models\NonExistentModel');
        $this->assertSame([], $rels);
    }
}
