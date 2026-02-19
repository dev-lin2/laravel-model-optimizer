<?php

namespace Devlin\ModelAnalyzer\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Devlin\ModelAnalyzer\Tests\TestCase;

class ListModelsCommandTest extends TestCase
{
    /** @test */
    public function it_lists_discovered_models()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:list-models');
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Found', $output);
    }

    /** @test */
    public function it_outputs_json_list()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:list-models', ['--json' => true]);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Fixtures\\\\Models', $output);
    }

    /** @test */
    public function it_shows_relationship_counts_with_flag()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:list-models', ['--with-relationships' => true]);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Relationships', $output);
    }
}
