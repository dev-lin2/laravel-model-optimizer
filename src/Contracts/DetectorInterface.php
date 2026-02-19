<?php

namespace Devlin\ModelAnalyzer\Contracts;

use Devlin\ModelAnalyzer\Models\AnalysisResult;

interface DetectorInterface
{
    /**
     * Run the detector and append issues to the result.
     *
     * @param AnalysisResult $result
     * @return void
     */
    public function detect(AnalysisResult $result);
}
