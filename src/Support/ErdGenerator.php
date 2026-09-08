<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Models\AnalysisResult;

class ErdGenerator
{
    /**
     * Generate a standalone HTML file with an interactive ERD diagram.
     *
     * @param AnalysisResult $result
     * @return string
     */
    public function generate(AnalysisResult $result)
    {
        $erdData = $this->buildErdData($result);
        $jsonData = json_encode($erdData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $modelCount = count($result->models);
        $relationshipCount = $result->totalRelationships();
        $errorCount = count($result->getErrors());
        $warningCount = count($result->getWarnings());
        $healthScore = $result->healthScore;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entity Relationship Diagram - Laravel Model Analyzer</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; overflow: hidden; }
#erd-container { width: 100vw; height: 100vh; }
svg { width: 100%; height: 100%; }

.info-panel {
    position: fixed; top: 16px; left: 16px;
    background: rgba(30, 41, 59, 0.95); border: 1px solid #334155;
    border-radius: 12px; padding: 20px; min-width: 240px;
    backdrop-filter: blur(8px); z-index: 10;
}
.info-panel h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #f8fafc; }
.info-panel .stat { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
.info-panel .stat-value { font-weight: 600; }
.info-panel .divider { border-top: 1px solid #334155; margin: 10px 0; }

.legend { margin-top: 12px; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; margin: 6px 0; }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.legend-line { width: 30px; height: 0; border-top: 2px solid; flex-shrink: 0; }

.tooltip {
    position: fixed; background: rgba(15, 23, 42, 0.96); border: 1px solid #475569;
    border-radius: 8px; padding: 14px; font-size: 13px; pointer-events: none;
    opacity: 0; transition: opacity 0.15s; z-index: 20; max-width: 320px;
    backdrop-filter: blur(8px);
}
.tooltip.visible { opacity: 1; }

#reset-btn {
    position: fixed; bottom: 16px; right: 16px;
    background: #4f46e5; color: white; border: none; border-radius: 8px;
    padding: 8px 16px; font-size: 13px; cursor: pointer; z-index: 10;
}
#reset-btn:hover { background: #4338ca; }
</style>
</head>
<body>

<div class="info-panel">
    <h2>Entity Relationship Diagram</h2>
    <div class="stat"><span>Tables</span><span class="stat-value">{$modelCount}</span></div>
    <div class="stat"><span>Relationships</span><span class="stat-value">{$relationshipCount}</span></div>
    <div class="stat"><span>Errors</span><span class="stat-value" style="color:#f87171">{$errorCount}</span></div>
    <div class="stat"><span>Warnings</span><span class="stat-value" style="color:#fbbf24">{$warningCount}</span></div>
    <div class="divider"></div>
    <div class="stat"><span>Health Score</span><span class="stat-value">{$healthScore}/100</span></div>
    <div class="divider"></div>
    <div class="legend">
        <div class="legend-item"><span class="legend-dot" style="background:#818cf8"></span> Primary Key</div>
        <div class="legend-item"><span class="legend-dot" style="background:#fb923c"></span> Foreign Key</div>
        <div class="legend-item"><span class="legend-dot" style="background:#f87171"></span> Missing Column</div>
        <div class="legend-item"><span class="legend-line" style="border-color:#64748b"></span> Relationship</div>
    </div>
</div>

<div class="tooltip" id="tooltip"></div>

<button id="reset-btn">Reset View</button>

<div id="erd-container">
    <svg id="erd"></svg>
</div>

<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
const data = {$jsonData};

const svg = d3.select('#erd');
const container = svg.append('g');
const width = window.innerWidth;
const height = window.innerHeight;
const tooltip = document.getElementById('tooltip');

// Constants for table rendering
const COL_HEIGHT = 22;
const HEADER_HEIGHT = 32;
const TABLE_WIDTH = 220;
const TABLE_PADDING = 8;
const ICON_WIDTH = 16;

// Zoom
const zoom = d3.zoom()
    .scaleExtent([0.1, 4])
    .on('zoom', (event) => container.attr('transform', event.transform));
svg.call(zoom);

// Compute table box heights
data.tables.forEach(t => {
    t.boxHeight = HEADER_HEIGHT + t.columns.length * COL_HEIGHT + TABLE_PADDING;
});

// Grid layout: arrange tables in a grid
const cols = Math.ceil(Math.sqrt(data.tables.length));
const gridSpacingX = TABLE_WIDTH + 120;
const gridSpacingY = 300;
const offsetX = (width - cols * gridSpacingX) / 2 + 60;
const offsetY = 100;

data.tables.forEach((t, i) => {
    const col = i % cols;
    const row = Math.floor(i / cols);
    t.x = offsetX + col * gridSpacingX;
    t.y = offsetY + row * gridSpacingY;
});

// Crow's foot marker definitions
const defs = svg.append('defs');

// "Many" marker (crow's foot)
defs.append('marker')
    .attr('id', 'crowfoot-many')
    .attr('viewBox', '0 0 12 12')
    .attr('refX', 12).attr('refY', 6)
    .attr('markerWidth', 12).attr('markerHeight', 12)
    .attr('orient', 'auto')
    .append('path')
    .attr('d', 'M0,0 L12,6 L0,12')
    .attr('fill', 'none').attr('stroke', '#64748b').attr('stroke-width', 1.5);

// "One" marker (single line)
defs.append('marker')
    .attr('id', 'crowfoot-one')
    .attr('viewBox', '0 0 12 12')
    .attr('refX', 12).attr('refY', 6)
    .attr('markerWidth', 12).attr('markerHeight', 12)
    .attr('orient', 'auto')
    .append('path')
    .attr('d', 'M8,0 L8,12 M12,6 L8,6')
    .attr('fill', 'none').attr('stroke', '#64748b').attr('stroke-width', 1.5);

// Build a lookup for table data by name
const tableMap = {};
data.tables.forEach(t => { tableMap[t.name] = t; });

// Group relationships by table pair for offset
const relPairCounts = {};
data.relationships.forEach(rel => {
    const key = [rel.from, rel.to].sort().join('||');
    if (!relPairCounts[key]) relPairCounts[key] = [];
    relPairCounts[key].push(rel);
});
Object.values(relPairCounts).forEach(group => {
    group.forEach((rel, i) => { rel.curveIndex = i; rel.curveTotal = group.length; });
});

function getConnectionPoints(fromTable, toTable, curveIndex, curveTotal) {
    const f = fromTable, t = toTable;
    const fCx = f.x + TABLE_WIDTH / 2, fCy = f.y + f.boxHeight / 2;
    const tCx = t.x + TABLE_WIDTH / 2, tCy = t.y + t.boxHeight / 2;

    let sx, sy, tx, ty;

    // Determine which side to connect from
    const dx = tCx - fCx, dy = tCy - fCy;
    if (Math.abs(dx) > Math.abs(dy)) {
        // Horizontal connection
        if (dx > 0) {
            sx = f.x + TABLE_WIDTH; tx = t.x;
        } else {
            sx = f.x; tx = t.x + TABLE_WIDTH;
        }
        // Distribute vertically along the side for multiple relations
        const fMid = f.y + f.boxHeight / 2;
        const tMid = t.y + t.boxHeight / 2;
        const spread = 20;
        const fOffset = curveTotal > 1 ? (curveIndex - (curveTotal - 1) / 2) * spread : 0;
        const tOffset = fOffset;
        sy = fMid + fOffset;
        ty = tMid + tOffset;
    } else {
        // Vertical connection
        if (dy > 0) {
            sy = f.y + f.boxHeight; ty = t.y;
        } else {
            sy = f.y; ty = t.y + t.boxHeight;
        }
        const fMid = f.x + TABLE_WIDTH / 2;
        const tMid = t.x + TABLE_WIDTH / 2;
        const spread = 20;
        const fOffset = curveTotal > 1 ? (curveIndex - (curveTotal - 1) / 2) * spread : 0;
        sx = fMid + fOffset;
        tx = tMid + fOffset;
    }

    return { sx, sy, tx, ty };
}

// Draw relationship lines
const relGroup = container.append('g');

data.relationships.forEach(rel => {
    const fromTable = tableMap[rel.from];
    const toTable = tableMap[rel.to];
    if (!fromTable || !toTable) return;

    const pts = getConnectionPoints(fromTable, toTable, rel.curveIndex, rel.curveTotal);

    // Determine markers based on cardinality
    let markerStart = '', markerEnd = '';
    if (rel.cardinality === '1-*') {
        markerStart = 'url(#crowfoot-one)';
        markerEnd = 'url(#crowfoot-many)';
    } else if (rel.cardinality === '*-1') {
        markerStart = 'url(#crowfoot-many)';
        markerEnd = 'url(#crowfoot-one)';
    } else if (rel.cardinality === '*-*') {
        markerStart = 'url(#crowfoot-many)';
        markerEnd = 'url(#crowfoot-many)';
    } else {
        markerStart = 'url(#crowfoot-one)';
        markerEnd = 'url(#crowfoot-one)';
    }

    // Draw a smooth path with a midpoint bend
    const mx = (pts.sx + pts.tx) / 2;
    const my = (pts.sy + pts.ty) / 2;
    const path = 'M' + pts.sx + ',' + pts.sy + ' C' + mx + ',' + pts.sy + ' ' + mx + ',' + pts.ty + ' ' + pts.tx + ',' + pts.ty;

    relGroup.append('path')
        .attr('d', path)
        .attr('fill', 'none')
        .attr('stroke', rel.hasIssue ? '#f87171' : '#64748b')
        .attr('stroke-width', rel.hasIssue ? 2 : 1.5)
        .attr('stroke-dasharray', rel.hasIssue ? '6,3' : 'none')
        .attr('marker-start', markerStart)
        .attr('marker-end', markerEnd);

    // Relationship label at midpoint
    relGroup.append('text')
        .attr('x', mx)
        .attr('y', my - 6)
        .attr('text-anchor', 'middle')
        .attr('fill', '#94a3b8')
        .attr('font-size', '10px')
        .text(rel.label);
});

// Draw table boxes
const tableGroups = container.selectAll('.table-group')
    .data(data.tables)
    .join('g')
    .attr('class', 'table-group')
    .attr('transform', d => 'translate(' + d.x + ',' + d.y + ')')
    .style('cursor', 'grab');

// Table shadow
tableGroups.append('rect')
    .attr('x', 3).attr('y', 3)
    .attr('width', TABLE_WIDTH).attr('height', d => d.boxHeight)
    .attr('rx', 6).attr('fill', 'rgba(0,0,0,0.3)');

// Table body
tableGroups.append('rect')
    .attr('width', TABLE_WIDTH).attr('height', d => d.boxHeight)
    .attr('rx', 6).attr('fill', '#1e293b')
    .attr('stroke', d => d.hasError ? '#f87171' : d.hasWarning ? '#fbbf24' : '#334155')
    .attr('stroke-width', d => (d.hasError || d.hasWarning) ? 2 : 1);

// Table header background
tableGroups.append('rect')
    .attr('width', TABLE_WIDTH).attr('height', HEADER_HEIGHT)
    .attr('rx', 6).attr('fill', d => d.hasError ? '#7f1d1d' : d.hasWarning ? '#78350f' : '#312e81');

// Header bottom corners cover (to make bottom of header square)
tableGroups.append('rect')
    .attr('y', HEADER_HEIGHT - 6)
    .attr('width', TABLE_WIDTH).attr('height', 6)
    .attr('fill', d => d.hasError ? '#7f1d1d' : d.hasWarning ? '#78350f' : '#312e81');

// Table name
tableGroups.append('text')
    .attr('x', TABLE_WIDTH / 2).attr('y', HEADER_HEIGHT / 2 + 1)
    .attr('text-anchor', 'middle').attr('dominant-baseline', 'middle')
    .attr('fill', '#f8fafc').attr('font-size', '13px').attr('font-weight', '700')
    .text(d => d.name);

// Columns
tableGroups.each(function(table) {
    const g = d3.select(this);
    table.columns.forEach((col, i) => {
        const y = HEADER_HEIGHT + i * COL_HEIGHT;

        // Alternating row background
        if (i % 2 === 0) {
            g.append('rect')
                .attr('y', y)
                .attr('width', TABLE_WIDTH).attr('height', COL_HEIGHT)
                .attr('fill', 'rgba(255,255,255,0.03)');
        }

        // Icon
        let icon = '';
        let iconColor = '#64748b';
        if (col.isPrimary) { icon = 'PK'; iconColor = '#818cf8'; }
        else if (col.isForeign) { icon = 'FK'; iconColor = '#fb923c'; }
        else if (col.isMissing) { icon = '!!'; iconColor = '#f87171'; }

        if (icon) {
            g.append('text')
                .attr('x', 8).attr('y', y + COL_HEIGHT / 2 + 1)
                .attr('dominant-baseline', 'middle')
                .attr('fill', iconColor).attr('font-size', '9px').attr('font-weight', '700')
                .text(icon);
        }

        // Column name
        g.append('text')
            .attr('x', 30).attr('y', y + COL_HEIGHT / 2 + 1)
            .attr('dominant-baseline', 'middle')
            .attr('fill', col.isMissing ? '#f87171' : '#e2e8f0')
            .attr('font-size', '11px')
            .attr('font-style', col.isMissing ? 'italic' : 'normal')
            .text(col.name);

        // Column type
        g.append('text')
            .attr('x', TABLE_WIDTH - 8).attr('y', y + COL_HEIGHT / 2 + 1)
            .attr('text-anchor', 'end').attr('dominant-baseline', 'middle')
            .attr('fill', col.isMissing ? '#f87171' : '#64748b')
            .attr('font-size', '10px')
            .text(col.type);
    });
});

// Drag behavior for tables
const drag = d3.drag()
    .on('start', function() { d3.select(this).style('cursor', 'grabbing').raise(); })
    .on('drag', function(event, d) {
        d.x = event.x; d.y = event.y;
        d3.select(this).attr('transform', 'translate(' + d.x + ',' + d.y + ')');
        redrawRelationships();
    })
    .on('end', function() { d3.select(this).style('cursor', 'grab'); });

tableGroups.call(drag);

function redrawRelationships() {
    relGroup.selectAll('*').remove();
    data.relationships.forEach(rel => {
        const fromTable = tableMap[rel.from];
        const toTable = tableMap[rel.to];
        if (!fromTable || !toTable) return;

        const pts = getConnectionPoints(fromTable, toTable, rel.curveIndex, rel.curveTotal);

        let markerStart = '', markerEnd = '';
        if (rel.cardinality === '1-*') {
            markerStart = 'url(#crowfoot-one)';
            markerEnd = 'url(#crowfoot-many)';
        } else if (rel.cardinality === '*-1') {
            markerStart = 'url(#crowfoot-many)';
            markerEnd = 'url(#crowfoot-one)';
        } else if (rel.cardinality === '*-*') {
            markerStart = 'url(#crowfoot-many)';
            markerEnd = 'url(#crowfoot-many)';
        } else {
            markerStart = 'url(#crowfoot-one)';
            markerEnd = 'url(#crowfoot-one)';
        }

        const mx = (pts.sx + pts.tx) / 2;
        const my = (pts.sy + pts.ty) / 2;
        const path = 'M' + pts.sx + ',' + pts.sy + ' C' + mx + ',' + pts.sy + ' ' + mx + ',' + pts.ty + ' ' + pts.tx + ',' + pts.ty;

        relGroup.append('path')
            .attr('d', path)
            .attr('fill', 'none')
            .attr('stroke', rel.hasIssue ? '#f87171' : '#64748b')
            .attr('stroke-width', rel.hasIssue ? 2 : 1.5)
            .attr('stroke-dasharray', rel.hasIssue ? '6,3' : 'none')
            .attr('marker-start', markerStart)
            .attr('marker-end', markerEnd);

        relGroup.append('text')
            .attr('x', mx).attr('y', my - 6)
            .attr('text-anchor', 'middle')
            .attr('fill', '#94a3b8').attr('font-size', '10px')
            .text(rel.label);
    });
}

// Reset
document.getElementById('reset-btn').addEventListener('click', () => {
    svg.transition().duration(500).call(zoom.transform, d3.zoomIdentity);
});
</script>
</body>
</html>
HTML;
    }

    /**
     * Transform analysis result into ERD-friendly data with tables and relationships.
     *
     * @param AnalysisResult $result
     * @return array{tables: array, relationships: array}
     */
    public function buildErdData(AnalysisResult $result)
    {
        $issuesByModel = [];
        foreach ($result->issues as $issue) {
            $issuesByModel[$issue->model][] = $issue;
        }

        // Build set of missing columns from issues
        $missingColumns = [];
        foreach ($result->issues as $issue) {
            if (isset($issue->context['column']) && isset($issue->context['table'])) {
                $missingColumns[$issue->context['table'] . '.' . $issue->context['column']] = true;
            }
        }

        $tables = [];
        foreach ($result->models as $model) {
            $modelIssues = $issuesByModel[$model->shortName] ?? [];
            $hasError = false;
            $hasWarning = false;
            foreach ($modelIssues as $issue) {
                if ($issue->severity === 'error') {
                    $hasError = true;
                } elseif ($issue->severity === 'warning') {
                    $hasWarning = true;
                }
            }

            // Build columns from schema if available
            $columns = [];
            $schemaColumns = $result->schema[$model->table] ?? [];

            if (!empty($schemaColumns)) {
                foreach ($schemaColumns as $colName => $colInfo) {
                    $isPrimary = ($colName === 'id');
                    $isForeign = (bool) preg_match('/_id$/', $colName);
                    $type = $colInfo['type'] ?? 'unknown';

                    $columns[] = [
                        'name' => $colName,
                        'type' => $type,
                        'isPrimary' => $isPrimary,
                        'isForeign' => $isForeign,
                        'isMissing' => false,
                    ];
                }
            } else {
                // Fallback: infer basic columns from relationships
                $columns[] = ['name' => 'id', 'type' => 'bigint', 'isPrimary' => true, 'isForeign' => false, 'isMissing' => false];

                foreach ($model->relationships as $rel) {
                    if (in_array($rel->type, ['BelongsTo']) && $rel->foreignKey) {
                        $columns[] = [
                            'name' => $rel->foreignKey,
                            'type' => 'bigint',
                            'isPrimary' => false,
                            'isForeign' => true,
                            'isMissing' => isset($missingColumns[$model->table . '.' . $rel->foreignKey]),
                        ];
                    }
                }

                $columns[] = ['name' => 'created_at', 'type' => 'timestamp', 'isPrimary' => false, 'isForeign' => false, 'isMissing' => false];
                $columns[] = ['name' => 'updated_at', 'type' => 'timestamp', 'isPrimary' => false, 'isForeign' => false, 'isMissing' => false];
            }

            // Check for missing columns from issues and add if not already present
            foreach ($modelIssues as $issue) {
                if (isset($issue->context['column'])) {
                    $colName = $issue->context['column'];
                    $exists = false;
                    foreach ($columns as $col) {
                        if ($col['name'] === $colName) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        // Insert before timestamps
                        $insertAt = max(0, count($columns) - 2);
                        array_splice($columns, $insertAt, 0, [[
                            'name' => $colName,
                            'type' => 'missing',
                            'isPrimary' => false,
                            'isForeign' => true,
                            'isMissing' => true,
                        ]]);
                    }
                }
            }

            $tables[] = [
                'name' => $model->shortName,
                'fullClass' => $model->class,
                'tableName' => $model->table,
                'columns' => $columns,
                'hasError' => $hasError,
                'hasWarning' => $hasWarning,
            ];
        }

        // Build relationships with cardinality
        $knownModels = [];
        foreach ($result->models as $model) {
            $knownModels[$model->class] = $model->shortName;
        }

        $relationships = [];
        $seen = [];

        foreach ($result->models as $model) {
            foreach ($model->relationships as $rel) {
                $targetShort = $knownModels[$rel->related] ?? null;
                if ($targetShort === null) {
                    continue;
                }

                $key = $model->shortName . '->' . $targetShort . ':' . $rel->name;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                // Determine cardinality from relationship type
                $cardinality = '1-1';
                $label = $rel->type;
                switch ($rel->type) {
                    case 'HasMany':
                        $cardinality = '1-*';
                        break;
                    case 'BelongsTo':
                        $cardinality = '*-1';
                        break;
                    case 'BelongsToMany':
                        $cardinality = '*-*';
                        break;
                    case 'HasOne':
                        $cardinality = '1-1';
                        break;
                    case 'MorphMany':
                        $cardinality = '1-*';
                        break;
                    case 'MorphTo':
                        $cardinality = '*-1';
                        break;
                }

                // Check if this relationship has issues
                $hasIssue = false;
                foreach ($result->issues as $issue) {
                    if ($issue->model === $model->shortName && stripos($issue->message, $rel->name) !== false) {
                        $hasIssue = true;
                        break;
                    }
                }

                $relationships[] = [
                    'from' => $model->shortName,
                    'to' => $targetShort,
                    'type' => $rel->type,
                    'label' => $rel->name,
                    'cardinality' => $cardinality,
                    'foreignKey' => $rel->foreignKey,
                    'hasIssue' => $hasIssue,
                ];
            }
        }

        return [
            'tables' => $tables,
            'relationships' => $relationships,
        ];
    }
}
