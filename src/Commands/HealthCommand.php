<?php

namespace Devlin\ModelAnalyzer\Commands;

use Illuminate\Console\Command;
use Devlin\ModelAnalyzer\ModelAnalyzer;
use Devlin\ModelAnalyzer\Support\OutputFormatter;

class HealthCommand extends Command
{
    /** @var string */
    protected $signature = 'model-analyzer:health';

    /** @var string */
    protected $description = 'Display a relationship health score and grouped issue report';

    /**
     * Execute the console command.
     *
     * @param ModelAnalyzer $analyzer
     * @return int
     */
    public function handle(ModelAnalyzer $analyzer)
    {
        $result    = $analyzer->analyze();
        $formatter = new OutputFormatter($this->output);
        $formatter->printHealthReport($result);

        return count($result->getErrors()) > 0 ? 1 : 0;
    }
}
