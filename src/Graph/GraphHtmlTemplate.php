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
    --edge-grouping: #38bdf8;
    --edge-reference: #f59e0b;
    --header-h: 45px;
    --sidebar-w: 220px;
    --panel-w: 320px;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; background: var(--bg); color: var(--text); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; overflow: hidden; }
  #app { position: relative; width: 100vw; height: 100vh; }
  header { position: absolute; top: 0; left: 0; right: 0; height: var(--header-h); z-index: 10; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; border-bottom: 1px solid var(--border); background: rgba(10,10,15,0.85); backdrop-filter: blur(4px); }
  header h1 { font-size: 14px; margin: 0; color: var(--accent); font-weight: 600; }
  header .header-right { display: flex; align-items: center; gap: 12px; }
  header .stats { font-size: 12px; color: var(--muted); }
  header button { font: inherit; font-size: 11px; background: var(--panel); color: var(--text); border: 1px solid var(--border); border-radius: 4px; padding: 4px 10px; cursor: pointer; }
  header button:hover { border-color: var(--accent); }
  #graph { display: block; width: 100%; height: 100%; cursor: grab; }
  #graph:active { cursor: grabbing; }
  .edge { stroke-width: 1; fill: none; pointer-events: none; }
  .edge-structural { stroke: var(--border); }
  .edge-grouping { stroke: var(--edge-grouping); stroke-dasharray: 5 3; }
  .edge-reference { stroke: var(--edge-reference); stroke-dasharray: 1 3; stroke-linecap: round; }
  .edge-hidden { display: none; }
  .node circle { stroke: var(--bg); stroke-width: 1.5; cursor: pointer; }
  .node text { fill: var(--text); font-size: 9px; pointer-events: none; }
  .node-hidden { display: none; }
  #message { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; flex-direction: column; gap: 8px; padding: 24px; text-align: center; }
  #message.visible { display: flex; }
  #message p { max-width: 520px; color: var(--muted); font-size: 13px; line-height: 1.6; margin: 0; }
  #message code { background: var(--panel); border: 1px solid var(--border); padding: 2px 6px; border-radius: 4px; color: var(--accent); }

  #sidebar { position: absolute; top: var(--header-h); left: 0; bottom: 0; width: var(--sidebar-w); overflow-y: auto; background: var(--panel); border-right: 1px solid var(--border); z-index: 9; padding: 10px 0; }
  #sidebar .sidebar-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); padding: 0 12px 8px; }
  .kind-row { display: flex; align-items: center; gap: 8px; padding: 5px 12px; font-size: 12px; cursor: pointer; }
  .kind-row:hover { background: rgba(255,255,255,0.03); }
  .kind-row .swatch { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .kind-row .kind-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .kind-row .kind-count { color: var(--muted); font-size: 11px; }

  #edge-key { position: absolute; left: calc(var(--sidebar-w) + 16px); bottom: 16px; z-index: 11; background: var(--panel); border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; }
  #edge-key .edge-key-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 6px; }
  .edge-row { display: flex; align-items: center; gap: 8px; font-size: 12px; padding: 3px 0; cursor: pointer; }
  .edge-row .edge-sample { display: block; flex-shrink: 0; width: 20px; height: 10px; }

  #inspect-panel { position: absolute; top: var(--header-h); right: 0; bottom: 0; width: var(--panel-w); overflow-y: auto; background: var(--panel); border-left: 1px solid var(--border); z-index: 9; padding: 16px; transform: translateX(100%); transition: transform 0.15s ease; }
  #inspect-panel.open { transform: translateX(0); }
  #inspect-close { position: absolute; top: 10px; right: 10px; background: none; border: none; color: var(--muted); font-size: 16px; cursor: pointer; line-height: 1; }
  #inspect-close:hover { color: var(--text); }
  #inspect-empty { color: var(--muted); font-size: 12px; }
  #inspect-body { display: none; }
  #inspect-body.visible { display: block; }
  #inspect-title { font-size: 14px; margin: 0 24px 10px 0; word-break: break-word; }
  .inspect-meta { font-size: 11px; color: var(--muted); margin-bottom: 14px; }
  .inspect-meta code { color: var(--text); word-break: break-all; }
  .inspect-section { display: none; margin-bottom: 16px; }
  .inspect-section.visible { display: block; }
  .inspect-section h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin: 0 0 6px; }
  .inspect-section dl { margin: 0; }
  .inspect-section dt { font-size: 11px; color: var(--muted); margin-top: 6px; }
  .inspect-section dd { margin: 0; font-size: 12px; word-break: break-word; }
  .inspect-section dd code { background: var(--bg); border: 1px solid var(--border); padding: 1px 4px; border-radius: 3px; }
  .inspect-section ul { margin: 0; padding-left: 16px; font-size: 12px; }
  .inspect-section .empty { color: var(--muted); font-size: 12px; }
</style>
</head>
<body>
<div id="app">
  <header>
    <h1>Necromancer Artifact Graph</h1>
    <div class="header-right">
      <div class="stats" id="stats"></div>
      <button id="reset-view" type="button">Reset view</button>
    </div>
  </header>
  <div id="sidebar">
    <div class="sidebar-title">Kinds</div>
  </div>
  <svg id="graph"></svg>
  <div id="edge-key">
    <div class="edge-key-title">Edges</div>
    <label class="edge-row" data-edge-kind="structural">
      <input type="checkbox" checked>
      <svg class="edge-sample" width="20" height="10"><line x1="0" y1="5" x2="20" y2="5" class="edge edge-structural"/></svg>
      <span>Structural</span>
    </label>
    <label class="edge-row" data-edge-kind="grouping">
      <input type="checkbox" checked>
      <svg class="edge-sample" width="20" height="10"><line x1="0" y1="5" x2="20" y2="5" class="edge edge-grouping"/></svg>
      <span>Grouping</span>
    </label>
    <label class="edge-row" data-edge-kind="reference">
      <input type="checkbox" checked>
      <svg class="edge-sample" width="20" height="10"><line x1="0" y1="5" x2="20" y2="5" class="edge edge-reference"/></svg>
      <span>Reference</span>
    </label>
  </div>
  <aside id="inspect-panel">
    <button id="inspect-close" type="button" aria-label="Close inspect panel">&times;</button>
    <div id="inspect-empty">Select a node to inspect it.</div>
    <div id="inspect-body">
      <h2 id="inspect-title"></h2>
      <div class="inspect-meta">
        <div>id: <code id="inspect-id"></code></div>
        <div>kind: <span id="inspect-kind"></span></div>
      </div>
      <section class="inspect-section" id="inspect-context">
        <h3>Architectural Context</h3>
        <dl id="inspect-context-list"></dl>
      </section>
      <section class="inspect-section" id="inspect-facts">
        <h3>Discovered Facts</h3>
        <dl id="inspect-facts-list"></dl>
      </section>
      <section class="inspect-section" id="inspect-members">
        <h3 id="inspect-members-title"></h3>
        <ul id="inspect-members-list"></ul>
      </section>
    </div>
  </aside>
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
  var sidebarEl = document.getElementById('sidebar');
  var inspectPanelEl = document.getElementById('inspect-panel');
  var inspectEmptyEl = document.getElementById('inspect-empty');
  var inspectBodyEl = document.getElementById('inspect-body');
  var inspectTitleEl = document.getElementById('inspect-title');
  var inspectIdEl = document.getElementById('inspect-id');
  var inspectKindEl = document.getElementById('inspect-kind');
  var inspectContextEl = document.getElementById('inspect-context');
  var inspectContextListEl = document.getElementById('inspect-context-list');
  var inspectFactsEl = document.getElementById('inspect-facts');
  var inspectFactsListEl = document.getElementById('inspect-facts-list');
  var inspectMembersEl = document.getElementById('inspect-members');
  var inspectMembersTitleEl = document.getElementById('inspect-members-title');
  var inspectMembersListEl = document.getElementById('inspect-members-list');
  var NS = 'http://www.w3.org/2000/svg';
  // Must stay in sync with LaravelNecromancer\Metadata\AnnotationConfigurationResolver::
  // KNOWN_FIELDS — the Schema v1 field list. Field order here also determines the
  // Architectural Context row order, matching ArtifactConceptBuilder::architecturalContext().
  var ANNOTATION_FIELDS = ['domain', 'flow', 'capability', 'summary', 'risk', 'external_services', 'adrs'];
  // Must stay in sync with ArtifactGraphBuilder::groupAndReferenceNodes(), the
  // only place these three synthetic kinds are ever produced server-side.
  var SYNTHETIC_KINDS = { domain: true, flow: true, adr: true };

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

  function clientToSvgPoint(clientX, clientY) {
    var pt = svg.createSVGPoint();
    pt.x = clientX;
    pt.y = clientY;
    return pt.matrixTransform(svg.getScreenCTM().inverse());
  }

  function factVisible(value) {
    if (value === null || value === '') { return false; }
    if (Array.isArray(value) && value.length === 0) { return false; }
    if (value !== null && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0) { return false; }
    return true;
  }

  function factDisplay(value) {
    if (value === true) { return 'true'; }
    if (value === false) { return 'false'; }
    if (value !== null && typeof value === 'object') { return JSON.stringify(value); }
    return String(value);
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
      .map(function (e) { return { source: byId[e.from], target: byId[e.to], kind: e.kind }; })
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
    var vb = { x: 0, y: 0, w: width, h: height };

    function applyViewBox() {
      svg.setAttribute('viewBox', vb.x + ' ' + vb.y + ' ' + vb.w + ' ' + vb.h);
    }
    applyViewBox();

    var nodes = data.nodes || [];
    var edges = data.edges || [];

    var nodeById = {};
    nodes.forEach(function (n) { nodeById[n.id] = n; });

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

    var hiddenKinds = {};
    var edgeKindHidden = {};
    var selectedNodeId = null;

    var edgeGroup = document.createElementNS(NS, 'g');
    var edgeLines = sim.links.map(function (l) {
      var line = document.createElementNS(NS, 'line');
      line.setAttribute('class', 'edge edge-' + (l.kind || 'structural'));
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
        var startClientX = event.clientX, startClientY = event.clientY, startTime = Date.now(), moved = false;
        var move = function (moveEvent) {
          if (!moved) {
            var dx = moveEvent.clientX - startClientX, dy = moveEvent.clientY - startClientY;
            if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true;
          }
          var p = clientToSvgPoint(moveEvent.clientX, moveEvent.clientY);
          n.x = p.x;
          n.y = p.y;
        };
        var up = function () {
          n.fixed = false;
          window.removeEventListener('pointermove', move);
          window.removeEventListener('pointerup', up);
          if (!moved && (Date.now() - startTime) < 400) {
            openInspect(n);
          }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        event.preventDefault();
        event.stopPropagation();
      });

      nodeGroup.appendChild(g);
      n._el = g;
      n._circle = circle;
    });
    svg.appendChild(nodeGroup);

    function applyVisibility() {
      nodes.forEach(function (n) {
        n._el.classList.toggle('node-hidden', !!hiddenKinds[n.kind]);
      });
      sim.links.forEach(function (l, i) {
        var kind = l.kind || 'structural';
        var hide = !!hiddenKinds[l.source.kind] || !!hiddenKinds[l.target.kind] || !!edgeKindHidden[kind];
        edgeLines[i].classList.toggle('edge-hidden', hide);
      });
      if (selectedNodeId !== null) {
        var selected = nodeById[selectedNodeId];
        if (selected && hiddenKinds[selected.kind]) {
          closeInspect();
        }
      }
    }

    function buildSidebar() {
      var counts = {};
      nodes.forEach(function (n) { counts[n.kind] = (counts[n.kind] || 0) + 1; });

      Object.keys(counts).sort().forEach(function (kind) {
        var row = document.createElement('label');
        row.className = 'kind-row';

        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = true;
        checkbox.addEventListener('change', function () {
          hiddenKinds[kind] = !checkbox.checked;
          applyVisibility();
        });

        var swatch = document.createElement('span');
        swatch.className = 'swatch';
        swatch.style.background = colorForKind(kind);

        var name = document.createElement('span');
        name.className = 'kind-name';
        name.textContent = kind;

        var count = document.createElement('span');
        count.className = 'kind-count';
        count.textContent = String(counts[kind]);

        row.appendChild(checkbox);
        row.appendChild(swatch);
        row.appendChild(name);
        row.appendChild(count);
        sidebarEl.appendChild(row);
      });
    }
    buildSidebar();

    Array.prototype.forEach.call(document.querySelectorAll('#edge-key .edge-row'), function (row) {
      var kind = row.getAttribute('data-edge-kind');
      var checkbox = row.querySelector('input');
      checkbox.addEventListener('change', function () {
        edgeKindHidden[kind] = !checkbox.checked;
        applyVisibility();
      });
    });

    function clearInspectSection(el) {
      el.classList.remove('visible');
    }

    function appendDlRow(dl, key, displayValue) {
      var dt = document.createElement('dt');
      dt.textContent = key;
      var dd = document.createElement('dd');
      var code = document.createElement('code');
      code.textContent = displayValue;
      dd.appendChild(code);
      dl.appendChild(dt);
      dl.appendChild(dd);
    }

    function appendFactRow(dl, key, rawValue) {
      appendDlRow(dl, key, factDisplay(rawValue));
    }

    function populateInspect(n) {
      inspectTitleEl.textContent = n.label;
      inspectIdEl.textContent = n.id;
      inspectKindEl.textContent = n.kind;

      inspectContextListEl.innerHTML = '';
      inspectFactsListEl.innerHTML = '';
      inspectMembersListEl.innerHTML = '';
      clearInspectSection(inspectContextEl);
      clearInspectSection(inspectFactsEl);
      clearInspectSection(inspectMembersEl);

      if (SYNTHETIC_KINDS[n.kind]) {
        inspectMembersTitleEl.textContent = n.kind === 'adr' ? 'Referenced By' : 'Artifacts';

        var members = edges
          .filter(function (e) { return e.to === n.id; })
          .slice()
          .sort(function (a, b) { return a.from < b.from ? -1 : (a.from > b.from ? 1 : 0); })
          .map(function (e) { var src = nodeById[e.from]; return src ? src.label : e.from; });

        if (members.length === 0) {
          var empty = document.createElement('li');
          empty.className = 'empty';
          empty.textContent = n.kind === 'adr' ? 'No referencing artifacts.' : 'No member artifacts.';
          inspectMembersListEl.appendChild(empty);
        } else {
          members.forEach(function (label) {
            var li = document.createElement('li');
            li.textContent = label;
            inspectMembersListEl.appendChild(li);
          });
        }

        inspectMembersEl.classList.add('visible');
      } else {
        var annotations = n.annotations || {};
        var hasAnnotations = ANNOTATION_FIELDS.some(function (field) { return annotations[field] !== undefined && annotations[field] !== null && annotations[field] !== ''; });

        if (hasAnnotations) {
          ANNOTATION_FIELDS.forEach(function (field) {
            var value = annotations[field];
            if (value === undefined || value === null || value === '') { return; }
            var display = Array.isArray(value) ? value.join(', ') : String(value);
            appendDlRow(inspectContextListEl, field, display);
          });
          inspectContextEl.classList.add('visible');
        }

        var facts = n.facts || {};
        var factKeys = Object.keys(facts).filter(function (key) { return factVisible(facts[key]); });

        if (factKeys.length === 0) {
          var noFacts = document.createElement('div');
          noFacts.className = 'empty';
          noFacts.textContent = 'No discovered facts.';
          inspectFactsListEl.appendChild(noFacts);
        } else {
          factKeys.forEach(function (key) { appendFactRow(inspectFactsListEl, key, facts[key]); });
        }

        inspectFactsEl.classList.add('visible');
      }

      inspectEmptyEl.style.display = 'none';
      inspectBodyEl.classList.add('visible');
    }

    function openInspect(n) {
      selectedNodeId = n.id;
      populateInspect(n);
      inspectPanelEl.classList.add('open');
    }

    function closeInspect() {
      selectedNodeId = null;
      inspectPanelEl.classList.remove('open');
      inspectEmptyEl.style.display = '';
      inspectBodyEl.classList.remove('visible');
    }

    document.getElementById('inspect-close').addEventListener('click', closeInspect);

    svg.addEventListener('wheel', function (event) {
      event.preventDefault();
      var factor = event.deltaY < 0 ? 0.9 : 1.1;
      var pt = clientToSvgPoint(event.clientX, event.clientY);
      var newW = Math.min(Math.max(vb.w * factor, 100), 20000);
      var newH = Math.min(Math.max(vb.h * factor, 100), 20000);
      vb.x = pt.x - (pt.x - vb.x) * (newW / vb.w);
      vb.y = pt.y - (pt.y - vb.y) * (newH / vb.h);
      vb.w = newW;
      vb.h = newH;
      applyViewBox();
    }, { passive: false });

    svg.addEventListener('pointerdown', function (event) {
      if (event.target !== svg) { return; }
      var startClientX = event.clientX, startClientY = event.clientY;
      var startVb = { x: vb.x, y: vb.y };
      var scale = vb.w / svg.clientWidth;
      var move = function (moveEvent) {
        vb.x = startVb.x - (moveEvent.clientX - startClientX) * scale;
        vb.y = startVb.y - (moveEvent.clientY - startClientY) * scale;
        applyViewBox();
      };
      var up = function () {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
      };
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
    });

    function resetView() {
      var visible = nodes.filter(function (n) { return !hiddenKinds[n.kind]; });
      var pool = visible.length ? visible : nodes;
      var xs = pool.map(function (n) { return n.x; });
      var ys = pool.map(function (n) { return n.y; });
      var pad = 60;
      var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
      var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);
      vb.x = minX - pad;
      vb.y = minY - pad;
      vb.w = Math.max(maxX - minX + pad * 2, 100);
      vb.h = Math.max(maxY - minY + pad * 2, 100);
      applyViewBox();
    }
    document.getElementById('reset-view').addEventListener('click', resetView);

    applyVisibility();

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
