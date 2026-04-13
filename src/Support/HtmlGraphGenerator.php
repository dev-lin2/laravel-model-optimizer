<?php

namespace Devlin\ModelAnalyzer\Support;

use Devlin\ModelAnalyzer\Models\AnalysisResult;

class HtmlGraphGenerator
{
    /**
     * Generate a standalone HTML file with an interactive D3.js graph.
     *
     * @param AnalysisResult $result
     * @return string
     */
    public function generate(AnalysisResult $result)
    {
        $graphData = $this->buildGraphData($result);
        $jsonData = json_encode($graphData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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
<title>Model Relationship Graph - Laravel Model Analyzer</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; overflow: hidden; }
#graph-container { width: 100vw; height: 100vh; }
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
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; margin: 4px 0; }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

.tooltip {
    position: fixed; background: rgba(15, 23, 42, 0.96); border: 1px solid #475569;
    border-radius: 8px; padding: 14px; font-size: 13px; pointer-events: none;
    opacity: 0; transition: opacity 0.15s; z-index: 20; max-width: 320px;
    backdrop-filter: blur(8px);
}
.tooltip.visible { opacity: 1; }
.tooltip h3 { font-size: 14px; font-weight: 600; margin-bottom: 6px; }
.tooltip .detail { color: #94a3b8; margin: 2px 0; }
.tooltip .issue { margin-top: 6px; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
.tooltip .issue-error { background: rgba(220, 53, 69, 0.2); color: #f87171; }
.tooltip .issue-warning { background: rgba(255, 193, 7, 0.2); color: #fbbf24; }

#reset-btn {
    position: fixed; bottom: 16px; right: 16px;
    background: #4f46e5; color: white; border: none; border-radius: 8px;
    padding: 8px 16px; font-size: 13px; cursor: pointer; z-index: 10;
}
#reset-btn:hover { background: #4338ca; }

.link-label { font-size: 10px; fill: #64748b; pointer-events: none; }
</style>
</head>
<body>

<div class="info-panel">
    <h2>Model Relationship Graph</h2>
    <div class="stat"><span>Models</span><span class="stat-value">{$modelCount}</span></div>
    <div class="stat"><span>Relationships</span><span class="stat-value">{$relationshipCount}</span></div>
    <div class="stat"><span>Errors</span><span class="stat-value" style="color:#f87171">{$errorCount}</span></div>
    <div class="stat"><span>Warnings</span><span class="stat-value" style="color:#fbbf24">{$warningCount}</span></div>
    <div class="divider"></div>
    <div class="stat"><span>Health Score</span><span class="stat-value">{$healthScore}/100</span></div>
    <div class="legend">
        <div class="legend-item"><span class="legend-dot" style="background:#28a745"></span> No issues</div>
        <div class="legend-item"><span class="legend-dot" style="background:#ffc107"></span> Warnings</div>
        <div class="legend-item"><span class="legend-dot" style="background:#dc3545"></span> Errors</div>
    </div>
</div>

<div class="tooltip" id="tooltip"></div>

<button id="reset-btn">Reset View</button>

<div id="graph-container">
    <svg id="graph"></svg>
</div>

<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
const data = {$jsonData};

const svg = d3.select('#graph');
const container = svg.append('g');
const width = window.innerWidth;
const height = window.innerHeight;
const tooltip = document.getElementById('tooltip');

// Arrow markers
svg.append('defs').selectAll('marker')
    .data(['arrow'])
    .join('marker')
    .attr('id', d => d)
    .attr('viewBox', '0 -5 10 10')
    .attr('refX', 28)
    .attr('refY', 0)
    .attr('markerWidth', 8)
    .attr('markerHeight', 8)
    .attr('orient', 'auto')
    .append('path')
    .attr('d', 'M0,-5L10,0L0,5')
    .attr('fill', '#475569');

// Zoom
const zoom = d3.zoom()
    .scaleExtent([0.2, 4])
    .on('zoom', (event) => container.attr('transform', event.transform));
svg.call(zoom);

// Group links by undirected pair to compute curve offsets
const pairCounts = {};
data.links.forEach(link => {
    const a = typeof link.source === 'object' ? link.source.id : link.source;
    const b = typeof link.target === 'object' ? link.target.id : link.target;
    const pairKey = [a, b].sort().join('||');
    if (!pairCounts[pairKey]) pairCounts[pairKey] = [];
    pairCounts[pairKey].push(link);
});

// Assign curveIndex and curveTotal to each link
Object.values(pairCounts).forEach(group => {
    const total = group.length;
    group.forEach((link, i) => {
        link.curveTotal = total;
        link.curveIndex = i;
    });
});

const CURVE_OFFSET = 40; // px perpendicular offset between parallel links

function computeCurveOffset(d) {
    if (d.curveTotal <= 1) return 0;
    // Center the offsets: e.g. for 2 links -> [-20, 20], for 3 -> [-40, 0, 40]
    const mid = (d.curveTotal - 1) / 2;
    return (d.curveIndex - mid) * CURVE_OFFSET;
}

function linkPath(d) {
    const sx = d.source.x, sy = d.source.y;
    const tx = d.target.x, ty = d.target.y;
    const offset = computeCurveOffset(d);
    if (offset === 0) {
        return 'M' + sx + ',' + sy + 'L' + tx + ',' + ty;
    }
    // Compute perpendicular control point
    const dx = tx - sx, dy = ty - sy;
    const len = Math.sqrt(dx * dx + dy * dy) || 1;
    // Perpendicular unit vector
    const px = -dy / len, py = dx / len;
    // Control point at midpoint + perpendicular offset
    const cx = (sx + tx) / 2 + px * offset;
    const cy = (sy + ty) / 2 + py * offset;
    return 'M' + sx + ',' + sy + 'Q' + cx + ',' + cy + ' ' + tx + ',' + ty;
}

function linkLabelPos(d) {
    const sx = d.source.x, sy = d.source.y;
    const tx = d.target.x, ty = d.target.y;
    const offset = computeCurveOffset(d);
    const dx = tx - sx, dy = ty - sy;
    const len = Math.sqrt(dx * dx + dy * dy) || 1;
    const px = -dy / len, py = dx / len;
    return {
        x: (sx + tx) / 2 + px * offset * 0.5,
        y: (sy + ty) / 2 + py * offset * 0.5
    };
}

// Simulation
const simulation = d3.forceSimulation(data.nodes)
    .force('link', d3.forceLink(data.links).id(d => d.id).distance(180))
    .force('charge', d3.forceManyBody().strength(-400))
    .force('center', d3.forceCenter(width / 2, height / 2))
    .force('collide', d3.forceCollide(60));

// Links — use <path> instead of <line> for curves
const link = container.append('g')
    .selectAll('path')
    .data(data.links)
    .join('path')
    .attr('stroke', '#475569')
    .attr('stroke-width', 1.5)
    .attr('fill', 'none')
    .attr('marker-end', 'url(#arrow)');

// Link labels
const linkLabel = container.append('g')
    .selectAll('text')
    .data(data.links)
    .join('text')
    .attr('class', 'link-label')
    .attr('text-anchor', 'middle')
    .text(d => d.type);

// Nodes
const node = container.append('g')
    .selectAll('g')
    .data(data.nodes)
    .join('g')
    .call(d3.drag()
        .on('start', (event, d) => {
            if (!event.active) simulation.alphaTarget(0.3).restart();
            d.fx = d.x; d.fy = d.y;
        })
        .on('drag', (event, d) => { d.fx = event.x; d.fy = event.y; })
        .on('end', (event, d) => {
            if (!event.active) simulation.alphaTarget(0);
            d.fx = null; d.fy = null;
        })
    );

node.append('circle')
    .attr('r', 22)
    .attr('fill', d => d.color)
    .attr('stroke', d => d.color)
    .attr('stroke-width', 3)
    .attr('fill-opacity', 0.15);

node.append('text')
    .text(d => d.id)
    .attr('text-anchor', 'middle')
    .attr('dy', '0.35em')
    .attr('fill', '#f8fafc')
    .attr('font-size', '12px')
    .attr('font-weight', '600');

// Tooltip
node.on('mouseover', (event, d) => {
    let html = '<h3>' + d.id + '</h3>';
    html += '<div class="detail">Class: ' + d.fullClass + '</div>';
    html += '<div class="detail">Table: ' + d.table + '</div>';
    html += '<div class="detail">Relationships: ' + d.relationshipCount + '</div>';
    if (d.issues && d.issues.length > 0) {
        d.issues.forEach(issue => {
            const cls = issue.severity === 'error' ? 'issue-error' : 'issue-warning';
            html += '<div class="issue ' + cls + '">' + issue.message + '</div>';
        });
    }
    tooltip.innerHTML = html;
    tooltip.classList.add('visible');
    tooltip.style.left = (event.clientX + 16) + 'px';
    tooltip.style.top = (event.clientY + 16) + 'px';
})
.on('mousemove', (event) => {
    tooltip.style.left = (event.clientX + 16) + 'px';
    tooltip.style.top = (event.clientY + 16) + 'px';
})
.on('mouseout', () => tooltip.classList.remove('visible'));

// Tick
simulation.on('tick', () => {
    link.attr('d', linkPath);
    linkLabel.each(function(d) {
        const pos = linkLabelPos(d);
        d3.select(this).attr('x', pos.x).attr('y', pos.y);
    });
    node.attr('transform', d => 'translate(' + d.x + ',' + d.y + ')');
});

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
     * Transform analysis result into graph-friendly node/link data.
     *
     * @param AnalysisResult $result
     * @return array
     */
    public function buildGraphData(AnalysisResult $result)
    {
        $issuesByModel = [];
        foreach ($result->issues as $issue) {
            $issuesByModel[$issue->model][] = [
                'severity' => $issue->severity,
                'message' => $issue->message,
            ];
        }

        $nodes = [];
        foreach ($result->models as $model) {
            $modelIssues = $issuesByModel[$model->shortName] ?? [];
            $hasError = false;
            $hasWarning = false;

            foreach ($modelIssues as $issue) {
                if ($issue['severity'] === 'error') {
                    $hasError = true;
                } elseif ($issue['severity'] === 'warning') {
                    $hasWarning = true;
                }
            }

            if ($hasError) {
                $color = '#dc3545';
                $status = 'error';
            } elseif ($hasWarning) {
                $color = '#ffc107';
                $status = 'warning';
            } else {
                $color = '#28a745';
                $status = 'ok';
            }

            $nodes[] = [
                'id' => $model->shortName,
                'fullClass' => $model->class,
                'table' => $model->table,
                'relationshipCount' => count($model->relationships),
                'color' => $color,
                'status' => $status,
                'issues' => $modelIssues,
            ];
        }

        $knownModels = [];
        foreach ($result->models as $model) {
            $knownModels[$model->class] = $model->shortName;
        }

        $links = [];
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

                $links[] = [
                    'source' => $model->shortName,
                    'target' => $targetShort,
                    'type' => $rel->type,
                    'relationshipName' => $rel->name,
                    'foreignKey' => $rel->foreignKey,
                ];
            }
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
        ];
    }
}
