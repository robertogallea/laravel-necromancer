<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

/**
 * The self-contained graph.html shell: inline CSS/JS, no CDN dependencies,
 * no network fetch. The graph data is embedded directly into the page as a
 * `<script type="application/json">` tag at write time, so the file works
 * standalone when opened via file:// — unlike a fetch()-based loader, it
 * needs no HTTP server. graph.json is still written alongside it as an
 * independently useful artifact for other tooling; this page just doesn't
 * depend on fetching it.
 *
 * The embedded JSON is encoded without JSON_UNESCAPED_SLASHES, so any
 * `</script>` sequence inside a label or annotation value becomes the
 * inert `<\/script>` and can never terminate the tag early.
 */
final class GraphHtmlTemplate
{
    private const DATA_PLACEHOLDER = '__NECROMANCER_GRAPH_DATA__';

    public static function render(ArtifactGraph $graph): string
    {
        $json = json_encode($graph, JSON_THROW_ON_ERROR);

        return str_replace(self::DATA_PLACEHOLDER, $json, self::shell());
    }

    private static function shell(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Necromancer Artifact Graph</title>
<style>
  :root {
    color-scheme: dark;
    --bg: #0a0a0f;
    --panel: #12121a;
    --border: #262632;
    --text: #e5e5ea;
    --muted: #8a8a99;
    --accent: #f87171;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; background: var(--bg); color: var(--text); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; overflow: hidden; }
  #app { position: relative; width: 100vw; height: 100vh; }
  header { position: absolute; top: 0; left: 0; right: 0; z-index: 10; display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--border); background: rgba(10,10,15,0.85); backdrop-filter: blur(4px); }
  header h1 { font-size: 14px; margin: 0; color: var(--accent); font-weight: 600; }
  header .stats { font-size: 12px; color: var(--muted); }
  svg { display: block; width: 100%; height: 100%; cursor: grab; }
  svg:active { cursor: grabbing; }
  .edge { stroke: var(--border); stroke-width: 1; }
  .node circle { stroke: var(--bg); stroke-width: 1.5; cursor: pointer; }
  .node text { fill: var(--text); font-size: 9px; pointer-events: none; }
  #message { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; flex-direction: column; gap: 8px; padding: 24px; text-align: center; }
  #message.visible { display: flex; }
  #message p { max-width: 520px; color: var(--muted); font-size: 13px; line-height: 1.6; margin: 0; }
  #message code { background: var(--panel); border: 1px solid var(--border); padding: 2px 6px; border-radius: 4px; color: var(--accent); }
</style>
</head>
<body>
<div id="app">
  <header>
    <h1>Necromancer Artifact Graph</h1>
    <div class="stats" id="stats"></div>
  </header>
  <svg id="graph"></svg>
  <div id="message">
    <p id="message-text"></p>
  </div>
</div>
<script id="graph-data" type="application/json">__NECROMANCER_GRAPH_DATA__</script>
<script>
(function () {
  'use strict';

  var svg = document.getElementById('graph');
  var stats = document.getElementById('stats');
  var messageEl = document.getElementById('message');
  var messageText = document.getElementById('message-text');
  var NS = 'http://www.w3.org/2000/svg';

  function showMessage(html) {
    messageText.innerHTML = html;
    messageEl.classList.add('visible');
  }

  function colorForKind(kind) {
    var hash = 0;
    for (var i = 0; i < kind.length; i++) {
      hash = (hash * 31 + kind.charCodeAt(i)) >>> 0;
    }
    var hue = hash % 360;
    return 'hsl(' + hue + ', 65%, 60%)';
  }

  function layout(nodes, edges, width, height) {
    var byId = {};
    nodes.forEach(function (n, i) {
      var angle = (i / nodes.length) * Math.PI * 2;
      n.x = width / 2 + Math.cos(angle) * Math.min(width, height) * 0.3;
      n.y = height / 2 + Math.sin(angle) * Math.min(width, height) * 0.3;
      n.vx = 0;
      n.vy = 0;
      byId[n.id] = n;
    });

    var links = edges
      .map(function (e) { return { source: byId[e.from], target: byId[e.to] }; })
      .filter(function (l) { return l.source && l.target; });

    var centerX = width / 2, centerY = height / 2;

    function tick() {
      // Repulsion between all node pairs.
      for (var i = 0; i < nodes.length; i++) {
        for (var j = i + 1; j < nodes.length; j++) {
          var a = nodes[i], b = nodes[j];
          var dx = a.x - b.x, dy = a.y - b.y;
          var distSq = Math.max(dx * dx + dy * dy, 1);
          var force = 1200 / distSq;
          var dist = Math.sqrt(distSq);
          var fx = (dx / dist) * force, fy = (dy / dist) * force;
          a.vx += fx; a.vy += fy;
          b.vx -= fx; b.vy -= fy;
        }
      }

      // Spring attraction along edges.
      links.forEach(function (l) {
        var dx = l.target.x - l.source.x, dy = l.target.y - l.source.y;
        var dist = Math.max(Math.sqrt(dx * dx + dy * dy), 1);
        var force = (dist - 80) * 0.02;
        var fx = (dx / dist) * force, fy = (dy / dist) * force;
        l.source.vx += fx; l.source.vy += fy;
        l.target.vx -= fx; l.target.vy -= fy;
      });

      // Weak centering pull, damping, integration.
      nodes.forEach(function (n) {
        if (n.fixed) { n.vx = 0; n.vy = 0; return; }
        n.vx += (centerX - n.x) * 0.001;
        n.vy += (centerY - n.y) * 0.001;
        n.vx *= 0.85;
        n.vy *= 0.85;
        n.x += n.vx;
        n.y += n.vy;
      });
    }

    return { tick: tick, links: links };
  }

  function render(data) {
    var width = window.innerWidth, height = window.innerHeight;
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);

    var nodes = data.nodes || [];
    var edges = data.edges || [];

    var kinds = {};
    nodes.forEach(function (n) { kinds[n.kind] = true; });
    stats.textContent = nodes.length + ' node' + (nodes.length === 1 ? '' : 's') + ' · ' +
      Object.keys(kinds).length + ' kind' + (Object.keys(kinds).length === 1 ? '' : 's') + ' · ' +
      edges.length + ' edge' + (edges.length === 1 ? '' : 's');

    if (nodes.length === 0) {
      showMessage('No artifacts found in the graph. Run <code>php artisan necromancer:scan</code>, then <code>php artisan necromancer:graph</code>.');
      return;
    }

    var sim = layout(nodes, edges, width, height);

    var edgeGroup = document.createElementNS(NS, 'g');
    var edgeLines = sim.links.map(function () {
      var line = document.createElementNS(NS, 'line');
      line.setAttribute('class', 'edge');
      edgeGroup.appendChild(line);
      return line;
    });
    svg.appendChild(edgeGroup);

    var nodeGroup = document.createElementNS(NS, 'g');
    nodes.forEach(function (n) {
      var g = document.createElementNS(NS, 'g');
      g.setAttribute('class', 'node');

      var circle = document.createElementNS(NS, 'circle');
      circle.setAttribute('r', 6);
      circle.setAttribute('fill', colorForKind(n.kind));
      g.appendChild(circle);

      var label = document.createElementNS(NS, 'text');
      label.setAttribute('x', 9);
      label.setAttribute('y', 3);
      label.textContent = n.label;
      g.appendChild(label);

      g.addEventListener('pointerdown', function (event) {
        n.fixed = true;
        var move = function (moveEvent) {
          var rect = svg.getBoundingClientRect();
          n.x = moveEvent.clientX - rect.left;
          n.y = moveEvent.clientY - rect.top;
        };
        var up = function () {
          n.fixed = false;
          window.removeEventListener('pointermove', move);
          window.removeEventListener('pointerup', up);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        event.preventDefault();
      });

      nodeGroup.appendChild(g);
      n._el = g;
      n._circle = circle;
    });
    svg.appendChild(nodeGroup);

    (function frame() {
      sim.tick();

      sim.links.forEach(function (l, i) {
        edgeLines[i].setAttribute('x1', l.source.x);
        edgeLines[i].setAttribute('y1', l.source.y);
        edgeLines[i].setAttribute('x2', l.target.x);
        edgeLines[i].setAttribute('y2', l.target.y);
      });

      nodes.forEach(function (n) {
        n._el.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
      });

      requestAnimationFrame(frame);
    })();
  }

  try {
    var data = JSON.parse(document.getElementById('graph-data').textContent);
    render(data);
  } catch (e) {
    showMessage('Could not read the embedded graph data. Regenerate it with <code>php artisan necromancer:graph</code>.');
  }
})();
</script>
</body>
</html>
HTML;
    }
}
