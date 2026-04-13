<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Models\AnalysisResult;

class SvgErdGenerator
{
    const COL_HEIGHT = 22;
    const HEADER_HEIGHT = 32;
    const TABLE_WIDTH = 220;
    const TABLE_PADDING = 8;
    const GRID_SPACING_X = 340;
    const GRID_SPACING_Y = 300;

    /**
     * Generate a standalone SVG file with an ERD diagram.
     *
     * @param AnalysisResult $result
     * @return string
     */
    public function generate(AnalysisResult $result)
    {
        $erdGenerator = new ErdGenerator();
        $erdData = $erdGenerator->buildErdData($result);

        $tables = $erdData['tables'];
        $relationships = $erdData['relationships'];

        $modelCount = count($result->models);
        $relationshipCount = $result->totalRelationships();
        $errorCount = count($result->getErrors());
        $warningCount = count($result->getWarnings());
        $healthScore = $result->healthScore;

        // Compute box heights
        foreach ($tables as &$t) {
            $t['boxHeight'] = self::HEADER_HEIGHT + count($t['columns']) * self::COL_HEIGHT + self::TABLE_PADDING;
        }
        unset($t);

        // Grid layout
        $cols = max(1, (int) ceil(sqrt(count($tables))));
        $offsetX = 240;
        $offsetY = 40;

        foreach ($tables as $i => &$t) {
            $col = $i % $cols;
            $row = (int) floor($i / $cols);
            $t['x'] = $offsetX + $col * self::GRID_SPACING_X;
            $t['y'] = $offsetY + $row * self::GRID_SPACING_Y;
        }
        unset($t);

        // Compute SVG dimensions
        $maxX = 0;
        $maxY = 0;
        foreach ($tables as $t) {
            $maxX = max($maxX, $t['x'] + self::TABLE_WIDTH + 60);
            $maxY = max($maxY, $t['y'] + $t['boxHeight'] + 60);
        }
        $svgWidth = max($maxX, 800);
        $svgHeight = max($maxY, 600);

        // Build table lookup
        $tableMap = [];
        foreach ($tables as &$t) {
            $tableMap[$t['name']] = &$t;
        }
        unset($t);

        // Group relationships by pair for offset
        $pairGroups = [];
        foreach ($relationships as $i => $rel) {
            $pair = [$rel['from'], $rel['to']];
            sort($pair);
            $key = implode('||', $pair);
            $pairGroups[$key][] = $i;
        }
        foreach ($pairGroups as $group) {
            $total = count($group);
            foreach ($group as $idx => $ri) {
                $relationships[$ri]['curveIndex'] = $idx;
                $relationships[$ri]['curveTotal'] = $total;
            }
        }

        // Start SVG
        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svgWidth . ' ' . $svgHeight . '" width="' . $svgWidth . '" height="' . $svgHeight . '" style="background:#0f172a">' . "\n";
        $font = '-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif';

        // Marker defs for crow's foot
        $svg .= '<defs>' . "\n";
        // Many marker
        $svg .= '  <marker id="crowfoot-many" viewBox="0 0 12 12" refX="12" refY="6" markerWidth="12" markerHeight="12" orient="auto">' . "\n";
        $svg .= '    <path d="M0,0 L12,6 L0,12" fill="none" stroke="#64748b" stroke-width="1.5"/>' . "\n";
        $svg .= '  </marker>' . "\n";
        // One marker
        $svg .= '  <marker id="crowfoot-one" viewBox="0 0 12 12" refX="12" refY="6" markerWidth="12" markerHeight="12" orient="auto">' . "\n";
        $svg .= '    <path d="M8,0 L8,12 M12,6 L8,6" fill="none" stroke="#64748b" stroke-width="1.5"/>' . "\n";
        $svg .= '  </marker>' . "\n";
        // Issue markers (red versions)
        $svg .= '  <marker id="crowfoot-many-issue" viewBox="0 0 12 12" refX="12" refY="6" markerWidth="12" markerHeight="12" orient="auto">' . "\n";
        $svg .= '    <path d="M0,0 L12,6 L0,12" fill="none" stroke="#f87171" stroke-width="1.5"/>' . "\n";
        $svg .= '  </marker>' . "\n";
        $svg .= '  <marker id="crowfoot-one-issue" viewBox="0 0 12 12" refX="12" refY="6" markerWidth="12" markerHeight="12" orient="auto">' . "\n";
        $svg .= '    <path d="M8,0 L8,12 M12,6 L8,6" fill="none" stroke="#f87171" stroke-width="1.5"/>' . "\n";
        $svg .= '  </marker>' . "\n";
        $svg .= '</defs>' . "\n";

        // Draw relationship lines (behind tables)
        foreach ($relationships as $rel) {
            $fromTable = $tableMap[$rel['from']] ?? null;
            $toTable = $tableMap[$rel['to']] ?? null;
            if (!$fromTable || !$toTable) {
                continue;
            }

            $pts = $this->getConnectionPoints($fromTable, $toTable, $rel['curveIndex'] ?? 0, $rel['curveTotal'] ?? 1);
            $isIssue = $rel['hasIssue'] ?? false;
            $suffix = $isIssue ? '-issue' : '';

            // Determine markers
            $markerStart = '';
            $markerEnd = '';
            $card = $rel['cardinality'] ?? '1-1';
            if ($card === '1-*') {
                $markerStart = "url(#crowfoot-one{$suffix})";
                $markerEnd = "url(#crowfoot-many{$suffix})";
            } elseif ($card === '*-1') {
                $markerStart = "url(#crowfoot-many{$suffix})";
                $markerEnd = "url(#crowfoot-one{$suffix})";
            } elseif ($card === '*-*') {
                $markerStart = "url(#crowfoot-many{$suffix})";
                $markerEnd = "url(#crowfoot-many{$suffix})";
            } else {
                $markerStart = "url(#crowfoot-one{$suffix})";
                $markerEnd = "url(#crowfoot-one{$suffix})";
            }

            $mx = ($pts['sx'] + $pts['tx']) / 2;
            $my = ($pts['sy'] + $pts['ty']) / 2;
            $path = "M{$pts['sx']},{$pts['sy']} C{$mx},{$pts['sy']} {$mx},{$pts['ty']} {$pts['tx']},{$pts['ty']}";

            $strokeColor = $isIssue ? '#f87171' : '#64748b';
            $strokeWidth = $isIssue ? 2 : 1.5;
            $dash = $isIssue ? ' stroke-dasharray="6,3"' : '';

            $svg .= '  <path d="' . $path . '" fill="none" stroke="' . $strokeColor . '" stroke-width="' . $strokeWidth . '"' . $dash;
            if ($markerStart) {
                $svg .= ' marker-start="' . $markerStart . '"';
            }
            if ($markerEnd) {
                $svg .= ' marker-end="' . $markerEnd . '"';
            }
            $svg .= '/>' . "\n";

            // Relationship label
            $label = htmlspecialchars($rel['label'] ?? '', ENT_XML1);
            $svg .= '  <text x="' . round($mx, 1) . '" y="' . round($my - 6, 1) . '" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="' . $font . '">' . $label . '</text>' . "\n";
        }

        // Draw table boxes
        foreach ($tables as $table) {
            $x = $table['x'];
            $y = $table['y'];
            $h = $table['boxHeight'];
            $hasError = $table['hasError'] ?? false;
            $hasWarning = $table['hasWarning'] ?? false;

            // Shadow
            $svg .= '  <rect x="' . ($x + 3) . '" y="' . ($y + 3) . '" width="' . self::TABLE_WIDTH . '" height="' . $h . '" rx="6" fill="rgba(0,0,0,0.3)"/>' . "\n";

            // Body
            $borderColor = $hasError ? '#f87171' : ($hasWarning ? '#fbbf24' : '#334155');
            $borderWidth = ($hasError || $hasWarning) ? 2 : 1;
            $svg .= '  <rect x="' . $x . '" y="' . $y . '" width="' . self::TABLE_WIDTH . '" height="' . $h . '" rx="6" fill="#1e293b" stroke="' . $borderColor . '" stroke-width="' . $borderWidth . '"/>' . "\n";

            // Header background
            $headerColor = $hasError ? '#7f1d1d' : ($hasWarning ? '#78350f' : '#312e81');
            $svg .= '  <rect x="' . $x . '" y="' . $y . '" width="' . self::TABLE_WIDTH . '" height="' . self::HEADER_HEIGHT . '" rx="6" fill="' . $headerColor . '"/>' . "\n";
            // Bottom corners cover
            $svg .= '  <rect x="' . $x . '" y="' . ($y + self::HEADER_HEIGHT - 6) . '" width="' . self::TABLE_WIDTH . '" height="6" fill="' . $headerColor . '"/>' . "\n";

            // Table name
            $name = htmlspecialchars($table['name'], ENT_XML1);
            $svg .= '  <text x="' . ($x + self::TABLE_WIDTH / 2) . '" y="' . ($y + self::HEADER_HEIGHT / 2 + 4) . '" text-anchor="middle" fill="#f8fafc" font-size="13" font-weight="700" font-family="' . $font . '">' . $name . '</text>' . "\n";

            // Columns
            foreach ($table['columns'] as $ci => $col) {
                $cy = $y + self::HEADER_HEIGHT + $ci * self::COL_HEIGHT;

                // Alternating row background
                if ($ci % 2 === 0) {
                    $svg .= '  <rect x="' . $x . '" y="' . $cy . '" width="' . self::TABLE_WIDTH . '" height="' . self::COL_HEIGHT . '" fill="rgba(255,255,255,0.03)"/>' . "\n";
                }

                // Icon
                $icon = '';
                $iconColor = '#64748b';
                if ($col['isPrimary'] ?? false) {
                    $icon = 'PK';
                    $iconColor = '#818cf8';
                } elseif ($col['isForeign'] ?? false) {
                    $icon = 'FK';
                    $iconColor = '#fb923c';
                }
                if ($col['isMissing'] ?? false) {
                    $icon = '!!';
                    $iconColor = '#f87171';
                }

                if ($icon) {
                    $svg .= '  <text x="' . ($x + 8) . '" y="' . ($cy + self::COL_HEIGHT / 2 + 3) . '" fill="' . $iconColor . '" font-size="9" font-weight="700" font-family="' . $font . '">' . $icon . '</text>' . "\n";
                }

                // Column name
                $colName = htmlspecialchars($col['name'], ENT_XML1);
                $colFill = ($col['isMissing'] ?? false) ? '#f87171' : '#e2e8f0';
                $fontStyle = ($col['isMissing'] ?? false) ? ' font-style="italic"' : '';
                $svg .= '  <text x="' . ($x + 30) . '" y="' . ($cy + self::COL_HEIGHT / 2 + 3) . '" fill="' . $colFill . '" font-size="11"' . $fontStyle . ' font-family="' . $font . '">' . $colName . '</text>' . "\n";

                // Column type
                $colType = htmlspecialchars($col['type'] ?? '', ENT_XML1);
                $typeFill = ($col['isMissing'] ?? false) ? '#f87171' : '#64748b';
                $svg .= '  <text x="' . ($x + self::TABLE_WIDTH - 8) . '" y="' . ($cy + self::COL_HEIGHT / 2 + 3) . '" text-anchor="end" fill="' . $typeFill . '" font-size="10" font-family="' . $font . '">' . $colType . '</text>' . "\n";
            }
        }

        // Info panel (top-left)
        $panelX = 12;
        $panelY = 12;
        $panelW = 210;
        $panelH = 200;

        $svg .= '  <rect x="' . $panelX . '" y="' . $panelY . '" width="' . $panelW . '" height="' . $panelH . '" rx="8" fill="#1e293b" fill-opacity="0.95" stroke="#334155"/>' . "\n";

        $ty = $panelY + 24;
        $svg .= '  <text x="' . ($panelX + 14) . '" y="' . $ty . '" fill="#f8fafc" font-size="13" font-weight="600" font-family="' . $font . '">Entity Relationship Diagram</text>' . "\n";

        $stats = [
            ['Tables', (string)$modelCount, '#e2e8f0'],
            ['Relationships', (string)$relationshipCount, '#e2e8f0'],
            ['Errors', (string)$errorCount, '#f87171'],
            ['Warnings', (string)$warningCount, '#fbbf24'],
            ['Health Score', "{$healthScore}/100", '#e2e8f0'],
        ];

        $ty += 20;
        foreach ($stats as $j => $stat) {
            if ($j === 4) {
                $svg .= '  <line x1="' . ($panelX + 14) . '" y1="' . ($ty - 2) . '" x2="' . ($panelX + $panelW - 14) . '" y2="' . ($ty - 2) . '" stroke="#334155"/>' . "\n";
                $ty += 6;
            }
            $svg .= '  <text x="' . ($panelX + 14) . '" y="' . $ty . '" fill="#94a3b8" font-size="11" font-family="' . $font . '">' . $stat[0] . '</text>' . "\n";
            $svg .= '  <text x="' . ($panelX + $panelW - 14) . '" y="' . $ty . '" text-anchor="end" fill="' . $stat[2] . '" font-size="11" font-weight="600" font-family="' . $font . '">' . $stat[1] . '</text>' . "\n";
            $ty += 18;
        }

        // Legend
        $ty += 4;
        $legendItems = [
            ['#818cf8', 'Primary Key'],
            ['#fb923c', 'Foreign Key'],
            ['#f87171', 'Missing Column'],
        ];
        foreach ($legendItems as $item) {
            $svg .= '  <circle cx="' . ($panelX + 20) . '" cy="' . ($ty - 3) . '" r="4" fill="' . $item[0] . '"/>' . "\n";
            $svg .= '  <text x="' . ($panelX + 30) . '" y="' . $ty . '" fill="#94a3b8" font-size="10" font-family="' . $font . '">' . $item[1] . '</text>' . "\n";
            $ty += 16;
        }

        $svg .= '</svg>' . "\n";

        return $svg;
    }

    /**
     * Compute connection points between two tables for relationship lines.
     *
     * @param array $fromTable
     * @param array $toTable
     * @param int $curveIndex
     * @param int $curveTotal
     * @return array
     */
    private function getConnectionPoints($fromTable, $toTable, $curveIndex, $curveTotal)
    {
        $fCx = $fromTable['x'] + self::TABLE_WIDTH / 2;
        $fCy = $fromTable['y'] + $fromTable['boxHeight'] / 2;
        $tCx = $toTable['x'] + self::TABLE_WIDTH / 2;
        $tCy = $toTable['y'] + $toTable['boxHeight'] / 2;

        $dx = $tCx - $fCx;
        $dy = $tCy - $fCy;
        $spread = 20;

        if (abs($dx) > abs($dy)) {
            // Horizontal connection
            if ($dx > 0) {
                $sx = $fromTable['x'] + self::TABLE_WIDTH;
                $tx = $toTable['x'];
            } else {
                $sx = $fromTable['x'];
                $tx = $toTable['x'] + self::TABLE_WIDTH;
            }
            $fMid = $fromTable['y'] + $fromTable['boxHeight'] / 2;
            $tMid = $toTable['y'] + $toTable['boxHeight'] / 2;
            $offset = $curveTotal > 1 ? ($curveIndex - ($curveTotal - 1) / 2) * $spread : 0;
            $sy = $fMid + $offset;
            $ty = $tMid + $offset;
        } else {
            // Vertical connection
            if ($dy > 0) {
                $sy = $fromTable['y'] + $fromTable['boxHeight'];
                $ty = $toTable['y'];
            } else {
                $sy = $fromTable['y'];
                $ty = $toTable['y'] + $toTable['boxHeight'];
            }
            $fMid = $fromTable['x'] + self::TABLE_WIDTH / 2;
            $tMid = $toTable['x'] + self::TABLE_WIDTH / 2;
            $offset = $curveTotal > 1 ? ($curveIndex - ($curveTotal - 1) / 2) * $spread : 0;
            $sx = $fMid + $offset;
            $tx = $tMid + $offset;
        }

        return ['sx' => $sx, 'sy' => $sy, 'tx' => $tx, 'ty' => $ty];
    }
}
