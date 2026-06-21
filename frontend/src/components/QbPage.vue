<template>
  <div class="qb-page" :class="{ 'qb-page--settings-open': qb.settingsOpen }">
    <!-- ── Top toolbar ── -->
    <header class="qb-toolbar">
      <div class="qb-toolbar__left">
        <button class="qb-back" @click="$emit('close')" title="Close (Esc)">←</button>
        <div class="qb-title">
          <h2>DB Queries</h2>
          <span v-if="store.currentFile" class="qb-title__file">
            {{ store.currentFile.name }}
          </span>
        </div>
      </div>

      <div class="qb-toolbar__center">
        <div class="qb-mode">
          <span class="qb-mode__dot" :class="'qb-mode__dot--' + qb.mode" />
          <span class="qb-mode__label">
            <template v-if="qb.mode === 'profiler'">
              Profiler+
              <span v-if="qb.snapshot" class="qb-mode__sub">
                · {{ qb.snapshot.token.slice(0, 6) }}
                <span v-if="qb.snapshot.request_method">· {{ qb.snapshot.request_method }} {{ qb.snapshot.request_path }}</span>
              </span>
            </template>
            <template v-else-if="qb.mode === 'loading'">Loading…</template>
            <template v-else-if="qb.mode === 'error'">Error</template>
            <template v-else>Trace (heuristic)</template>
          </span>
        </div>
        <div v-if="qb.error" class="qb-mode__err">{{ qb.error }}</div>
      </div>

      <div class="qb-toolbar__right">
        <input v-model="qb.filter" class="qb-filter" placeholder="filter SQL…" spellcheck="false" />

        <div class="qb-view-toggle">
          <button :class="{ on: qb.viewMode === 'flat' }" @click="qb.setViewMode('flat')">list</button>
          <button :class="{ on: qb.viewMode === 'grouped' }" @click="qb.setViewMode('grouped')">by caller</button>
          <button
            :class="{ on: qb.viewMode === 'tree' }"
            :title="qb.mode === 'profiler' ? 'Call graph from profiler backtraces' : 'Call tree from xdebug trace chain'"
            @click="qb.setViewMode('tree')"
          >tree</button>
        </div>

        <button class="qb-action" @click="qb.refresh(fileId)" :disabled="!qb.snapshot || qb.loading" title="Re-fetch from profiler">
          ↻
        </button>
        <button class="qb-action" @click="qb.openSettings()" title="Profiler+ settings">
          ⚙
        </button>
        <button class="qb-action qb-action--close" @click="$emit('close')" title="Close (Esc)">✕</button>
      </div>
    </header>

    <!-- ── Profiler+ status bar (only when enabled but no snapshot yet) ── -->
    <div v-if="qb.profilerConfig?.enabled && !qb.snapshot && qb.mode !== 'loading'" class="qb-find-bar">
      <div class="qb-find-bar__text">
        <strong>Profiler+ on</strong> — but no snapshot linked to this trace yet.
      </div>
      <div class="qb-find-bar__actions">
        <button class="qb-btn qb-btn--primary" @click="qb.findAuto(fileId)" :disabled="qb.loading">
          Find automatically
        </button>
        <button class="qb-btn" @click="openManualLink">
          Link token manually…
        </button>
      </div>
    </div>

    <!-- ── Manual link inline form ── -->
    <div v-if="manualLinkOpen" class="qb-manual-link">
      <input
        v-model="manualToken"
        class="qb-manual-link__input"
        placeholder="paste profiler token or full URL (e.g. https://systeme.local/_profiler/abc123)"
        spellcheck="false"
        @keydown.enter="doManualLink"
      />
      <button class="qb-btn qb-btn--primary" @click="doManualLink" :disabled="!manualToken.trim() || qb.loading">
        Link
      </button>
      <button class="qb-btn" @click="manualLinkOpen = false">Cancel</button>
    </div>

    <!-- ── Linked snapshot bar (controls: unlink, see token, etc.) ── -->
    <div v-if="qb.snapshot" class="qb-snapshot-bar">
      <span class="qb-snapshot-bar__label">
        Linked to
        <a :href="qb.snapshot.base_url + '/_profiler/' + qb.snapshot.token" target="_blank" rel="noopener">
          {{ qb.snapshot.base_url }}/_profiler/{{ qb.snapshot.token.slice(0, 12) }}…
        </a>
        <span class="qb-snapshot-bar__status" :class="'qb-snapshot-bar__status--' + qb.snapshot.status">
          {{ qb.snapshot.status }}
        </span>
        <span class="qb-snapshot-bar__time">captured {{ formatTime(qb.snapshot.captured_at) }}</span>
      </span>
      <button class="qb-btn qb-btn--ghost" @click="qb.unlink(fileId)">Unlink</button>
    </div>

    <!-- ── Backtrace-missing banner (target app's doctrine.yaml disables it) ── -->
    <div v-if="qb.backtraceMissing" class="qb-bt-warn" role="alert">
      <span class="qb-bt-warn__icon" aria-hidden="true">⚠</span>
      <div class="qb-bt-warn__body">
        <strong>Query backtraces are not being collected.</strong>
        <span>{{ qb.backtraceMissingReason }}</span>
        <span class="qb-bt-warn__detail">
          Totals, N+1 groups and timings are still correct — only the call-graph (tree view)
          and "first app frame" attribution are missing.
        </span>
      </div>
    </div>

    <!-- ── Main content ── -->
    <main class="qb-main">
      <!-- Loading state -->
      <div v-if="qb.mode === 'loading'" class="qb-state">Loading…</div>

      <!-- Error state -->
      <div v-else-if="qb.mode === 'error'" class="qb-state qb-state--err">
        {{ qb.error }}
      </div>

      <!-- Empty state: no trace opened -->
      <div v-else-if="!store.currentFile" class="qb-state">
        Open a trace file to see its DB queries.
      </div>

      <!-- Profiler+ on but no snapshot yet — don't fall back to trace data,
           just show the "find automatically" prompt (the find-bar above). -->
      <div v-else-if="qb.mode === 'trace' && qb.profilerConfig?.enabled && !qb.snapshot" class="qb-state">
        Profiler+ is on, waiting for a snapshot. Click <strong>Find automatically</strong> above
        (or <a @click.prevent="openManualLink">link a token manually</a>) to pull this trace's
        DB panel from the profiler.
      </div>

      <!-- Empty state: trace opened, no queries found -->
      <div v-else-if="!visibleQueries.length" class="qb-state">
        No SQL queries in this trace.
        <span v-if="qb.mode === 'profiler'" class="qb-state__hint">
          Profiler+ snapshot is linked but empty — try <a @click.prevent="qb.refresh(fileId)">refresh</a>.
        </span>
        <span v-else class="qb-state__hint">Try reparse to regenerate sql.json.</span>
      </div>

      <template v-else>
        <!-- ── Summary bar (only when profiler+ is on, we get aggregates for free) ── -->
        <div v-if="qb.mode === 'profiler' && qb.analysis" class="qb-summary">
          <span class="qb-summary__total">
            <strong>{{ qb.totalQueries }}</strong> queries
            <span class="qb-summary__sep">·</span>
            <strong>{{ qb.totalMs.toFixed(1) }}ms</strong> total
          </span>
          <template v-if="qb.analysis.n_plus_one?.length">
            <span class="qb-summary__sep">·</span>
            <span class="qb-summary__warn">⚠ {{ qb.analysis.n_plus_one.length }} N+1</span>
          </template>
          <span class="qb-summary__sep">·</span>
          <span class="qb-summary__hint">all data from Symfony Profiler</span>
        </div>

        <!-- ── Render based on view mode + data source ── -->
        <template v-if="qb.viewMode === 'tree' && qb.mode === 'profiler'">
          <!-- Real call graph built from profiler backtraces:
               controller → service → repository → SQL.
               Sub-totals roll up the tree so each node shows its full cost. -->
          <QbCallGraph />
        </template>

        <template v-else-if="qb.viewMode === 'tree' && qb.mode === 'trace'">
          <!-- Trace-mode tree: built from xdebug-trace `chain` field.
               Same SqlTreeNode component as the modal SqlPage, but in a
               full-page container. -->
          <section v-for="tg in traceCallTree" :key="tg.toc" class="qb-toc-group">
            <div class="toc-header">
              <span class="toc-name">{{ tg.toc }}</span>
              <span class="caller-total">{{ tg.total }} quer{{ tg.total === 1 ? 'y' : 'ies' }}</span>
            </div>
            <div class="tree-children">
              <SqlTreeNode
                v-for="(rootNode, rk) in tg.roots"
                :key="rk"
                :node="rootNode"
                :filter="qb.filter.value"
                :depth="0"
                :file-id="fileId"
              />
            </div>
          </section>
        </template>

        <template v-else-if="qb.viewMode === 'grouped' && qb.mode === 'profiler'">
          <!-- N+1 groups from profiler analysis -->
          <section v-if="qb.analysis?.n_plus_one?.length" class="qb-section">
            <header class="qb-section__header">N+1 candidates</header>
            <div
              v-for="(g, gi) in qb.analysis.n_plus_one"
              :key="gi"
              class="qb-n1-row"
            >
              <span class="qb-n1-row__count">×{{ g.count }}</span>
              <span class="qb-n1-row__time">{{ g.total_ms.toFixed(1) }}ms <em>({{ g.avg_ms.toFixed(2) }}ms/q)</em></span>
              <code class="qb-n1-row__sql">{{ truncate(g.sample_sql, 140) }}</code>
            </div>
          </section>

          <!-- Top callers -->
          <section v-if="qb.analysis?.callers?.length" class="qb-section">
            <header class="qb-section__header">Top callers</header>
            <div
              v-for="(c, ci) in qb.analysis.callers.slice(0, 20)"
              :key="ci"
              class="qb-caller-row"
            >
              <span class="qb-caller-row__count">×{{ c.count }}</span>
              <span class="qb-caller-row__sig">{{ c.class }}<span class="qb-caller-row__arrow">→</span>{{ c.method }}()</span>
              <span v-if="c.host_path || c.file" class="qb-caller-row__loc">
                <a @click.prevent="openInIde(c.host_path || c.file, c.line)">
                  {{ shortPath(c.host_path || c.file) }}:{{ c.line }}
                </a>
              </span>
            </div>
          </section>

          <!-- Per-query list (grouped by caller) -->
          <section class="qb-section">
            <header class="qb-section__header">All queries</header>
            <QbQueryRow
              v-for="q in visibleQueries"
              :key="q.n"
              :query="q"
            />
          </section>
        </template>

        <template v-else-if="qb.viewMode === 'grouped' && qb.mode === 'trace'">
          <!-- Trace-mode "by caller": two-level toc → caller → unique SQL.
               Same shape as the modal SqlPage's grouped view. -->
          <section v-for="tg in traceCallerGroups" :key="tg.toc" class="qb-toc-group">
            <header class="toc-header">
              <span class="toc-name">{{ tg.toc }}</span>
              <span class="caller-total">{{ tg.totalCount }} quer{{ tg.totalCount === 1 ? 'y' : 'ies' }}</span>
            </header>
            <div class="tree-children">
              <div v-for="cg in tg.callers" :key="cg.sig" class="qb-caller-block">
                <div class="caller-header">
                  <span class="caller-chevron">▸</span>
                  <span class="caller-sig">{{ cg.sig }}</span>
                  <span class="caller-count-badge">×{{ cg.count }}</span>
                  <span v-if="cg.totalMs" class="caller-time-badge">~{{ cg.totalMs.toFixed(1) }}ms</span>
                  <span v-if="cg.file" class="caller-file">{{ cg.file.split('/').slice(-2).join('/') }}</span>
                </div>
                <div v-for="uq in cg.uniqueQueries" :key="uq.key" class="uq-row">
                  <span v-if="uq.count > 1" class="uq-badge">×{{ uq.count }}</span>
                  <code class="uq-sql">{{ truncate(uq.sql, 200) }}</code>
                </div>
              </div>
            </div>
          </section>
        </template>

        <template v-else>
          <!-- Flat list (works in both modes) -->
          <QbQueryRow
            v-for="q in visibleQueries"
            :key="q.n"
            :query="q"
          />
        </template>
      </template>
    </main>

    <!-- ── Settings sidebar ── -->
    <transition name="qb-settings">
      <QbSettingsPanel
        v-if="qb.settingsOpen"
        @close="qb.closeSettings()"
      />
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useTraceStore } from '../stores/trace'
import { useQbStore } from '../stores/qb'
import QbQueryRow from './QbQueryRow.vue'
import QbSettingsPanel from './QbSettingsPanel.vue'
import QbCallGraph from './QbCallGraph.vue'
import SqlTreeNode from './SqlTreeNode.vue'
import { usePerfTrack } from '../perfTrack'

const props = defineProps({
  fileId: { type: Number, default: null },
})
const emit = defineEmits(['close'])

const store = useTraceStore()
const qb = useQbStore()
usePerfTrack('QbPage', { category: 'render' })

const manualLinkOpen = ref(false)
const manualToken = ref('')

const sourceQueries = computed(() => {
  if (qb.mode === 'profiler') return qb.queries
  return qb.traceQueries
})

const visibleQueries = computed(() => {
  let list = sourceQueries.value
  if (qb.filter.value) {
    const f = qb.filter.value.toLowerCase()
    list = list.filter(q => (q.sql || '').toLowerCase().includes(f))
  }
  return list
})

// ── Trace-mode helpers: by-caller grouping + call tree ──
//
// Same logic that SqlPage uses to render "by caller" and "tree" from the
// xdebug trace's caller/chain fields. We duplicate it here instead of
// mounting the modal SqlPage, so the QbPage is a single full-page view.

// Two-level grouping: toc (event) → caller (Class->method) → unique SQL.
// Mirrors SqlPage.vue:callerGroups.
const traceCallerGroups = computed(() => {
  const tocMap = {}
  const normKey = (sql) => (sql || '').replace(/\b[a-z]\d+_/g, '').replace(/\s+/g, ' ').trim().slice(0, 150)
  for (const q of sourceQueries.value) {
    const tocKey = q.toc || '(no context)'
    if (!tocMap[tocKey]) {
      tocMap[tocKey] = { toc: tocKey, label: tocKey, callerMap: {}, total: 0, firstLine: q.line_no || 0 }
    }
    const tg = tocMap[tocKey]
    tg.total++
    if ((q.line_no || 0) < tg.firstLine) tg.firstLine = q.line_no || 0
    const caller = q.caller || {}
    const callerSig = caller.sig || '(no caller)'
    const callerFile = caller.file || ''
    if (!tg.callerMap[callerSig]) {
      tg.callerMap[callerSig] = { sig: callerSig, file: callerFile, count: 0, sqlMap: {}, firstLine: q.line_no || 0 }
    }
    const cg = tg.callerMap[callerSig]
    cg.count++
    const k = normKey(q.sql)
    if (!cg.sqlMap[k]) {
      cg.sqlMap[k] = { key: k, sql: q.sql, count: 0, totalMs: 0, instances: [], firstLine: q.line_no || 0 }
    }
    cg.sqlMap[k].count++
    if (q.duration_ms != null) cg.sqlMap[k].totalMs += q.duration_ms
    cg.sqlMap[k].instances.push({ line_no: q.line_no, n: q.n, duration_ms: q.duration_ms })
  }
  return Object.values(tocMap)
    .map(tg => ({
      ...tg,
      callers: Object.values(tg.callerMap)
        .map(cg => ({
          ...cg,
          uniqueQueries: Object.values(cg.sqlMap).sort((a, b) => a.firstLine - b.firstLine),
        }))
        .sort((a, b) => a.firstLine - b.firstLine),
    }))
    .sort((a, b) => a.firstLine - b.firstLine)
})

// For tree view in trace mode. Each query carries a `chain` of App\ calls
// (root → leaf). We walk every chain and merge identical sig+file branches.
const traceCallTree = computed(() => {
  // Map<toc, { sig, file, count, queries[], children: <same> }>
  const tocMap = {}
  const normKey = (sql) => (sql || '').replace(/\b[a-z]\d+_/g, '').replace(/\s+/g, ' ').trim().slice(0, 150)
  for (const q of sourceQueries.value) {
    const chain = Array.isArray(q.chain) ? q.chain : []
    if (chain.length === 0) continue
    const tocKey = q.toc || '(no context)'
    if (!tocMap[tocKey]) tocMap[tocKey] = { toc: tocKey, label: tocKey, roots: {}, total: 0 }
    const tg = tocMap[tocKey]
    tg.total++
    let cur = tg.roots
    for (let i = 0; i < chain.length; i++) {
      const frame = chain[i]
      const key = (frame.sig || '?') + '|' + (frame.file || '')
      if (!cur[key]) {
        cur[key] = {
          sig: frame.sig, file: frame.file, line_no: frame.line_no,
          count: 0, queries: [], children: {},
        }
      }
      cur[key].count++
      if (i === chain.length - 1) {
        // leaf: hang the SQL here
        const k = normKey(q.sql)
        let leafQ = cur[key].queries.find(x => x.key === k)
        if (!leafQ) {
          leafQ = { key: k, sql: q.sql, count: 0, totalMs: 0, instances: [] }
          cur[key].queries.push(leafQ)
        }
        leafQ.count++
        if (q.duration_ms != null) leafQ.totalMs += q.duration_ms
        leafQ.instances.push({ line_no: q.line_no, n: q.n, duration_ms: q.duration_ms })
      } else {
        cur = cur[key].children
      }
    }
  }
  return Object.values(tocMap)
    .map(tg => ({ ...tg, roots: Object.values(tg.roots) }))
    .sort((a, b) => a.toc.localeCompare(b.toc))
})

function truncate(s, n) {
  if (!s) return ''
  return s.length > n ? s.slice(0, n) + '…' : s
}
function shortPath(p) {
  if (!p) return ''
  const parts = p.split('/src/')
  return parts.length > 1 ? 'src/' + parts[1] : p.split('/').slice(-2).join('/')
}
function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString()
}
function openInIde(path, line) {
  if (!path) return
  fetch('/api/open-in-ide', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ path, line }),
  })
}
function openManualLink() { manualLinkOpen.value = true }
async function doManualLink() {
  const t = manualToken.value.trim()
  if (!t) return
  const ok = await qb.linkManual(props.fileId, t)
  if (ok) {
    manualLinkOpen.value = false
    manualToken.value = ''
  }
}

function onKey(e) {
  if (e.key === 'Escape') {
    if (qb.settingsOpen) qb.closeSettings()
    else emit('close')
  }
}

onMounted(async () => {
  document.addEventListener('keydown', onKey)
  await qb.loadProfilerConfig()
  await qb.loadSnapshot(props.fileId)
  await qb.loadTraceQueries(props.fileId)
})
onUnmounted(() => {
  document.removeEventListener('keydown', onKey)
})

watch(() => props.fileId, async (id) => {
  await qb.loadSnapshot(id)
  await qb.loadTraceQueries(id)
})

// When the user toggles Profiler+ on/off, immediately re-evaluate the data
// source: drop the snapshot when going off, fetch it when going on. The
// mode computed picks up the new state automatically.
watch(() => qb.profilerConfig?.enabled, async (enabled) => {
  await qb.loadSnapshot(props.fileId)
  await qb.loadTraceQueries(props.fileId)
})
</script>

<style scoped>
.qb-page {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  flex-direction: column;
  background: var(--bg, #0e1116);
  color: var(--fg, #e6e6e6);
  font-family: inherit;
}

.qb-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 14px;
  border-bottom: 1px solid var(--border, #2a2e36);
  background: var(--bg-soft, #151921);
  flex: 0 0 auto;
}
.qb-toolbar__left, .qb-toolbar__right { display: flex; align-items: center; gap: 8px; }
.qb-toolbar__center { flex: 1; display: flex; align-items: center; gap: 12px; min-width: 0; }

.qb-back, .qb-action {
  background: transparent;
  border: 1px solid var(--border, #2a2e36);
  color: inherit;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}
.qb-back:hover, .qb-action:hover { background: var(--bg-hover, #1f2530); }
.qb-action:disabled { opacity: 0.4; cursor: not-allowed; }
.qb-action--close { color: #d66; }

.qb-title h2 { margin: 0; font-size: 14px; font-weight: 600; }
.qb-title__file { margin-left: 8px; font-size: 12px; color: #888; }

.qb-mode { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; }
.qb-mode__dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #888;
}
.qb-mode__dot--profiler { background: #5cd97a; box-shadow: 0 0 6px rgba(92, 217, 122, 0.5); }
.qb-mode__dot--loading  { background: #f6c64a; }
.qb-mode__dot--error    { background: #ef5b5b; }
.qb-mode__sub { color: #888; margin-left: 4px; }
.qb-mode__err { color: #ef5b5b; font-size: 12px; }

.qb-filter {
  background: var(--bg-input, #1a1f2a);
  border: 1px solid var(--border, #2a2e36);
  color: inherit;
  padding: 5px 10px;
  border-radius: 6px;
  font-size: 12px;
  width: 200px;
}

.qb-view-toggle { display: inline-flex; border: 1px solid var(--border, #2a2e36); border-radius: 6px; overflow: hidden; }
.qb-view-toggle button {
  background: transparent;
  border: 0;
  color: inherit;
  padding: 5px 10px;
  font-size: 11px;
  cursor: pointer;
}
.qb-view-toggle button.on { background: var(--bg-hover, #1f2530); color: #5cd97a; }

.qb-find-bar, .qb-snapshot-bar, .qb-manual-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 14px;
  background: var(--bg-soft, #151921);
  border-bottom: 1px solid var(--border, #2a2e36);
  font-size: 12px;
}
.qb-find-bar__actions { margin-left: auto; display: flex; gap: 8px; }
.qb-snapshot-bar__label { display: inline-flex; align-items: center; gap: 8px; }
.qb-snapshot-bar__label a { color: #5cd97a; }
.qb-snapshot-bar__status {
  font-size: 10px;
  padding: 2px 6px;
  border-radius: 4px;
  background: #2a2e36;
  text-transform: uppercase;
}
.qb-snapshot-bar__status--auto   { background: #1a3320; color: #5cd97a; }
.qb-snapshot-bar__status--manual { background: #1a2440; color: #6da0ff; }
.qb-snapshot-bar__status--error  { background: #3a1a1a; color: #ef5b5b; }
.qb-snapshot-bar__time { color: #888; font-size: 11px; }

/* Backtrace-missing hint — shown when target app's doctrine.yaml has
 * profiling_collect_backtrace disabled, so the DB panel parses fine but
 * every per-query backtrace is empty. */
.qb-bt-warn {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 10px 14px;
  background: linear-gradient(180deg, #3a2a1a 0%, #2a1f12 100%);
  border-bottom: 1px solid #5a3f1f;
  color: #f4c87a;
  font-size: 12px;
  line-height: 1.45;
}
.qb-bt-warn__icon { font-size: 16px; line-height: 1.2; }
.qb-bt-warn__body { display: flex; flex-direction: column; gap: 2px; }
.qb-bt-warn__body strong { color: #ffd394; }
.qb-bt-warn__detail { color: #b9a079; font-size: 11px; }

.qb-manual-link__input {
  flex: 1;
  background: var(--bg-input, #1a1f2a);
  border: 1px solid var(--border, #2a2e36);
  color: inherit;
  padding: 5px 10px;
  border-radius: 6px;
  font-size: 12px;
}

.qb-btn {
  background: var(--bg-input, #1a1f2a);
  border: 1px solid var(--border, #2a2e36);
  color: inherit;
  padding: 5px 12px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
}
.qb-btn:hover { background: var(--bg-hover, #1f2530); }
.qb-btn--primary { background: #2a4a2a; border-color: #3a5a3a; color: #c5f0c5; }
.qb-btn--primary:hover { background: #3a5a3a; }
.qb-btn--ghost { background: transparent; }
.qb-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.qb-main {
  flex: 1;
  overflow-y: auto;
  padding: 14px;
}

.qb-state {
  text-align: center;
  padding: 40px;
  color: #888;
}
.qb-state--err { color: #ef5b5b; }
.qb-state__hint { display: block; margin-top: 8px; font-size: 12px; }

.qb-summary {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 13px;
  padding: 8px 12px;
  background: var(--bg-soft, #151921);
  border-radius: 8px;
}
.qb-summary__sep { color: #555; }
.qb-summary__warn { color: #f6c64a; }
.qb-summary__hint { color: #888; font-size: 11px; margin-left: auto; }

.qb-section { margin-bottom: 18px; }
.qb-section__header {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  color: #888;
  letter-spacing: 0.04em;
  padding: 4px 0 8px;
}

.qb-n1-row, .qb-caller-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 0;
  font-size: 12px;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}
.qb-n1-row__count, .qb-caller-row__count {
  background: #2a4a2a;
  color: #c5f0c5;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 10px;
  min-width: 28px;
  text-align: center;
}
.qb-n1-row__time { color: #f6c64a; font-size: 11px; min-width: 80px; }
.qb-n1-row__time em { color: #888; font-style: normal; }
.qb-n1-row__sql { color: #888; flex: 1; font-family: monospace; }
.qb-caller-row__sig { font-family: monospace; flex: 1; }
.qb-caller-row__arrow { color: #5cd97a; margin: 0 2px; }
.qb-caller-row__loc { font-size: 11px; color: #888; }
.qb-caller-row__loc a { color: #6da0ff; cursor: pointer; }
.qb-caller-row__loc a:hover { text-decoration: underline; }

/* Trace-mode "by caller" / "tree" — reuse styles from SqlPage. */
.qb-toc-group { margin-bottom: 14px; }
.qb-toc-group .toc-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: var(--bg-soft, rgba(255,255,255,0.04));
  border-radius: 4px;
  font-size: 12px;
  margin-bottom: 4px;
}
.qb-toc-group .toc-name { font-weight: 600; }
.qb-toc-group .caller-total { color: #888; margin-left: auto; font-size: 11px; }
.qb-toc-group .tree-children { padding-left: 10px; }
.qb-toc-group .caller-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3px 6px;
  font-family: monospace;
  font-size: 11px;
  cursor: pointer;
}
.qb-toc-group .caller-header:hover { background: rgba(255,255,255,0.03); }
.qb-toc-group .caller-chevron { color: #888; }
.qb-toc-group .caller-sig { color: #5cd97a; }
.qb-toc-group .caller-count-badge {
  background: #2a4a2a; color: #c5f0c5; padding: 1px 6px;
  border-radius: 4px; font-size: 10px; min-width: 28px; text-align: center;
}
.qb-toc-group .caller-time-badge {
  color: #f6c64a; font-size: 10px; font-variant-numeric: tabular-nums;
}
.qb-toc-group .caller-file { color: #888; font-size: 10px; margin-left: auto; }
.qb-toc-group .uq-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 2px 6px 2px 22px;
  font-size: 11px;
}
.qb-toc-group .uq-badge {
  background: #2a4a2a; color: #c5f0c5; padding: 1px 6px;
  border-radius: 4px; font-size: 10px; min-width: 22px; text-align: center;
}
.qb-toc-group .uq-sql { color: #ccc; font-family: monospace; }
</style>