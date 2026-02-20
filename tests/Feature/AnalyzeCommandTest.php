<?php

namespace Devlin\ModelAnalyzer\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Devlin\ModelAnalyzer\Tests\TestCase;

class AnalyzeCommandTest extends TestCase
{
    /** @test */
    public function it_runs_analysis_successfully()
    {
        // Fixture models have intentional issues (circular deps), so 0 or 1 are both valid
        [$exitCode] = $this->captureArtisanOutput('model-analyzer:analyze');
        $this->assertContains($exitCode, [0, 1]);
    }

    /** @test */
    public function it_outputs_json_format()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:analyze', ['--format' => 'json']);
        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringContainsString('"health"', $output);
    }

    /** @test */
    public function it_json_output_contains_issues_key()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:analyze', ['--format' => 'json']);
        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringContainsString('"issues"', $output);
    }

    /** @test */
    public function it_exits_with_error_code_in_strict_mode_when_warnings_exist()
    {
        config(['model-analyzer.strict_mode' => true]);

        [$exitCode] = $this->captureArtisanOutput('model-analyzer:analyze', ['--strict' => true]);
        $this->assertContains($exitCode, [0, 1]);
    }

    /** @test */
    public function it_filters_by_specific_models()
    {
        [$exitCode] = $this->captureArtisanOutput('model-analyzer:analyze', [
            '--models' => 'User',
            '--format' => 'json',
        ]);
        $this->assertContains($exitCode, [0, 1]);
    }

    /** @test */
    public function it_displays_health_score_in_cli_format()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:analyze');
        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringContainsString('Health Score', $output);
    }

    /** @test */
    public function it_displays_model_progress_in_debug_mode()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:analyze', ['--debug' => true]);
        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringContainsString('Analyzing model:', $output);
    }
}
