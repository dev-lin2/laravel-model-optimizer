<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Models\AnalysisResult;

class SvgGraphGenerator
{
    /**
     * Generate a standalone SVG file with a circular-layout relationship graph.
     *
     * @param AnalysisResult $result
     * @return string
     */
    public function generate(AnalysisResult $result)
    {
        $htmlGenerator = new HtmlGraphGenerator();
        $graphData = $htmlGenerator->buildGraphData($result);

        $nodes = $graphData['nodes'];
        $links = $graphData['links'];

        $modelCount = count($result->models);
        $relationshipCount = $result->totalRelationships();
        $errorCount = count($result->getErrors());
        $warningCount = count($result->getWarnings());
        $healthScore = $result->healthScore;

        // Layout constants
        $nodeCount = count($nodes);
        $radius = max(150, $nodeCount * 45);
        $nodeRadius = 28;
        $centerX = $radius + 200;
        $centerY = $radius + 80;
        $svgWidth = ($radius + 200) * 2;
        $svgHeight = ($radius + 80) * 2 + 40;

        // Position nodes in a circle
        $nodePositions = [];
        foreach ($nodes as $i => $node) {
            $angle = ($i / max(1, $nodeCount)) * 2 * M_PI - M_PI / 2;
            $x = $centerX + $radius * cos($angle);
            $y = $centerY + $radius * sin($angle);
            $nodePositions[$node['id']] = ['x' => $x, 'y' => $y];
        }

        // Group links by undirected pair for parallel curve offsets
        $pairGroups = [];
        foreach ($links as $i => $link) {
            $pair = [$link['source'], $link['target']];
            sort($pair);
            $pairKey = implode('||', $pair);
            $pairGroups[$pairKey][] = $i;
        }

        $curveData = [];
        foreach ($pairGroups as $group) {
            $total = count($group);
            foreach ($group as $idx => $linkIndex) {
                $curveData[$linkIndex] = [
                    'index' => $idx,
                    'total' => $total,
                ];
            }
        }

        $curveOffset = 30;

        // Start building SVG
        $svg = '';
        $svg .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svgWidth . ' ' . $svgHeight . '" width="' . $svgWidth . '" height="' . $svgHeight . '" style="background:#0f172a">' . "\n";

        // Defs for arrow markers
        $svg .= '<defs>' . "\n";
        $svg .= '  <marker id="arrow" viewBox="0 -5 10 10" refX="' . ($nodeRadius + 8) . '" refY="0" markerWidth="8" markerHeight="8" orient="auto">' . "\n";
        $svg .= '    <path d="M0,-4L8,0L0,4" fill="#475569"/>' . "\n";
        $svg .= '  </marker>' . "\n";
        $svg .= '</defs>' . "\n";

        // Draw links
        foreach ($links as $i => $link) {
            $source = $nodePositions[$link['source']] ?? null;
            $target = $nodePositions[$link['target']] ?? null;
            if (!$source || !$target) {
                continue;
            }

            $sx = $source['x'];
            $sy = $source['y'];
            $tx = $target['x'];
            $ty = $target['y'];

            $ci = $curveData[$i]['index'];
            $ct = $curveData[$i]['total'];
            $offset = 0;
            if ($ct > 1) {
                $mid = ($ct - 1) / 2;
                $offset = ($ci - $mid) * $curveOffset;
            }

            if ($offset === 0) {
                // Straight line
                $path = "M{$sx},{$sy} L{$tx},{$ty}";
                $labelX = ($sx + $tx) / 2;
                $labelY = ($sy + $ty) / 2 - 6;
            } else {
                // Quadratic Bézier
                $dx = $tx - $sx;
                $dy = $ty - $sy;
                $len = sqrt($dx * $dx + $dy * $dy) ?: 1;
                $px = -$dy / $len;
                $py = $dx / $len;
                $cx = ($sx + $tx) / 2 + $px * $offset;
                $cy = ($sy + $ty) / 2 + $py * $offset;
                $path = "M{$sx},{$sy} Q{$cx},{$cy} {$tx},{$ty}";
                $labelX = ($sx + $tx) / 2 + $px * $offset * 0.5;
                $labelY = ($sy + $ty) / 2 + $py * $offset * 0.5 - 6;
            }

            $svg .= '  <path d="' . $path . '" fill="none" stroke="#475569" stroke-width="1.5" marker-end="url(#arrow)"/>' . "\n";

            // Link label
            $escapedType = htmlspecialchars($link['type'], ENT_XML1);
            $svg .= '  <text x="' . round($labelX, 1) . '" y="' . round($labelY, 1) . '" text-anchor="middle" fill="#64748b" font-size="9" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">' . $escapedType . '</text>' . "\n";
        }

        // Draw nodes
        foreach ($nodes as $node) {
            $pos = $nodePositions[$node['id']];
            $x = $pos['x'];
            $y = $pos['y'];
            $color = $node['color'];

            // Node circle (filled with low opacity + stroke)
            $svg .= '  <circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="' . $nodeRadius . '" fill="' . $color . '" fill-opacity="0.15" stroke="' . $color . '" stroke-width="2.5"/>' . "\n";

            // Node label
            $escapedName = htmlspecialchars($node['id'], ENT_XML1);
            $svg .= '  <text x="' . round($x, 1) . '" y="' . round($y + 4, 1) . '" text-anchor="middle" fill="#f8fafc" font-size="11" font-weight="600" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">' . $escapedName . '</text>' . "\n";
        }

        // Info panel (top-left)
        $panelX = 16;
        $panelY = 16;
        $panelW = 200;
        $panelH = 180;

        $svg .= '  <rect x="' . $panelX . '" y="' . $panelY . '" width="' . $panelW . '" height="' . $panelH . '" rx="8" fill="#1e293b" fill-opacity="0.95" stroke="#334155"/>' . "\n";

        $ty = $panelY + 24;
        $svg .= '  <text x="' . ($panelX + 14) . '" y="' . $ty . '" fill="#f8fafc" font-size="13" font-weight="600" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">Model Relationship Graph</text>' . "\n";

        $stats = [
            ['Models', (string)$modelCount, '#e2e8f0'],
            ['Relationships', (string)$relationshipCount, '#e2e8f0'],
            ['Errors', (string)$errorCount, '#f87171'],
            ['Warnings', (string)$warningCount, '#fbbf24'],
            ['Health Score', "{$healthScore}/100", '#e2e8f0'],
        ];

        $ty += 20;
        foreach ($stats as $j => $stat) {
            if ($j === 4) {
                // Divider before health score
                $svg .= '  <line x1="' . ($panelX + 14) . '" y1="' . ($ty - 2) . '" x2="' . ($panelX + $panelW - 14) . '" y2="' . ($ty - 2) . '" stroke="#334155"/>' . "\n";
                $ty += 6;
            }
            $svg .= '  <text x="' . ($panelX + 14) . '" y="' . $ty . '" fill="#94a3b8" font-size="11" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">' . $stat[0] . '</text>' . "\n";
            $svg .= '  <text x="' . ($panelX + $panelW - 14) . '" y="' . $ty . '" text-anchor="end" fill="' . $stat[2] . '" font-size="11" font-weight="600" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">' . $stat[1] . '</text>' . "\n";
            $ty += 18;
        }

        // Legend
        $ty += 4;
        $legendItems = [
            ['#28a745', 'No issues'],
            ['#ffc107', 'Warnings'],
            ['#dc3545', 'Errors'],
        ];
        foreach ($legendItems as $item) {
            $svg .= '  <circle cx="' . ($panelX + 20) . '" cy="' . ($ty - 3) . '" r="4" fill="' . $item[0] . '"/>' . "\n";
            $svg .= '  <text x="' . ($panelX + 30) . '" y="' . $ty . '" fill="#94a3b8" font-size="10" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">' . $item[1] . '</text>' . "\n";
            $ty += 16;
        }

        $svg .= '</svg>' . "\n";

        return $svg;
    }
}
