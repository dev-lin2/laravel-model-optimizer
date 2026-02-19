<?php

namespace Devlin\ModelAnalyzer\Tests\Feature;

use Devlin\ModelAnalyzer\Tests\TestCase;

class HealthCommandTest extends TestCase
{
    /** @test */
    public function it_displays_health_report()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:health');
        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringContainsString('Health Score', $output);
    }

    /** @test */
    public function it_displays_recommendations_section()
    {
        [$exitCode, $output] = $this->captureArtisanOutput('model-analyzer:health');
        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringContainsString('RECOMMENDATION', $output);
    }

    /** @test */
    public function it_runs_without_throwing_exceptions()
    {
        $exceptionThrown = false;

        try {
            $this->captureArtisanOutput('model-analyzer:health');
        } catch (\Throwable $e) {
            $exceptionThrown = true;
        }

        $this->assertFalse($exceptionThrown);
    }
}
