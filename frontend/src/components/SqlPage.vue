<template>
  <div class="sql-page">
    <!-- Loading / empty states -->
    <div v-if="loading" class="sql-state">Loading…</div>
    <div v-else-if="error" class="sql-state sql-state--err">{{ error }}</div>
    <div v-else-if="!queries.length" class="sql-state">
      No SQL queries found.<br>
      <span class="sql-state__hint">Reparse the file to generate sql.json.</span>
    </div>

    <template v-else>
      <!-- Summary bar -->
      <div class="sql-summary">
        <span class="sql-summary__total">{{ queries.length }} queries</span>
        <template v-if="dupeGroups.length">
          <span class="sql-summary__sep">·</span>
          <span class="sql-summary__dupes">⚠ {{ dupeGroups.length }} N+1 candidates</span>
        </template>
        <span class="sql-summary__sep">·</span>

        <!-- View mode toggle -->
        <div class="view-toggle">
          <button
            class="view-btn"
            :class="{ 'view-btn--active': viewMode === 'flat' }"
            @click="viewMode = 'flat'"
          >list</button>
          <button
            class="view-btn"
            :class="{ 'view-btn--active': viewMode === 'grouped' }"
            @click="viewMode = 'grouped'"
          >by caller</button>
          <button
            class="view-btn"
            :class="{ 'view-btn--active': viewMode === 'tree' }"
            @click="viewMode = 'tree'"
          >tree</button>
        </div>

        <template v-if="viewMode === 'flat'">
          <span class="sql-summary__sep">·</span>
          <label class="sql-summary__toggle">
            <span class="sql-cb" :class="{ 'sql-cb--on': groupDupes }" @click="groupDupes = !groupDupes">
              <svg v-if="groupDupes" width="9" height="9" viewBox="0 0 9 9" fill="none">
                <path d="M1.5 4.5L3.5 6.5L7.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            group dupes
          </label>
        </template>

        <span class="sql-summary__sep">·</span>
        <input v-model="filter" class="sql-summary__filter" placeholder="filter SQL…" spellcheck="false" />
        <button class="sql-copy-all" @click="copyAll" title="Copy SQL analysis to clipboard">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
            <rect x="4" y="4" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
            <path d="M2.5 9H2a1 1 0 01-1-1V2a1 1 0 011-1h6a1 1 0 011 1v.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          </svg>
          Copy
        </button>
        <button class="sql-reparse-btn" @click="reparse" :disabled="reparsing" title="Reparse SQL from trace file">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" :class="{ 'sql-reparse-btn__spin': reparsing }">
            <path d="M10 6A4 4 0 1 1 6 2v0" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            <path d="M6 2l1.5-1.5L6 0" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          {{ reparsing ? '…' : 'Reparse' }}
        </button>
      </div>

      <!-- ── FLAT VIEW ── -->
      <template v-if="viewMode === 'flat'">
        <!-- N+1 summary (collapsed by default) -->
        <div v-if="dupeGroups.length" class="dupe-section">
          <div class="dupe-section__header" @click="dupesOpen = !dupesOpen">
            <span class="dupe-section__chevron">{{ dupesOpen ? '▾' : '▸' }}</span>
            N+1 candidates
            <span class="dupe-section__count">{{ dupeGroups.length }}</span>
          </div>
          <div v-if="dupesOpen" class="dupe-list">
            <div
              v-for="g in dupeGroups"
              :key="g.key"
              class="dupe-row"
              :class="{ 'dupe-row--active': activeKey === g.key }"
              @click="activeKey = activeKey === g.key ? null : g.key"
            >
              <span class="dupe-badge">×{{ g.count }}</span>
              <span class="dupe-sql">{{ g.preview }}</span>
            </div>
          </div>
        </div>

        <!-- Query list -->
        <div class="sql-list" ref="listEl">
          <template v-for="q in visibleQueries" :key="q.n">
            <div
              class="sql-row"
              :class="{
                'sql-row--dupe': dupeCountFor(q) > 1,
                'sql-row--highlight': activeKey && normalizeKey(q.sql) === activeKey,
              }"
              @click="toggleExpand(q.n)"
            >
              <span class="sql-row__n">#{{ q.n }}</span>
              <span class="sql-row__dupe" v-if="dupeCountFor(q) > 1" :title="`Executed ${dupeCountFor(q)} times`">×{{ dupeCountFor(q) }}</span>
              <span class="sql-row__sql">{{ truncate(q.sql, 120) }}</span>
              <span class="sql-row__toc" v-if="q.toc">{{ shortToc(q.toc) }}</span>
              <span class="sql-row__line">:{{ q.line_no }}</span>
            </div>
            <!-- Expanded detail -->
            <div v-if="expanded.has(q.n)" class="sql-detail">
              <pre class="sql-detail__sql" v-html="highlightSql(q.sql)"></pre>
              <span v-if="q.sql && q.sql.endsWith('...')" class="sql-truncated">⚠ truncated by xdebug</span>
              <div v-if="q.params && q.params.length" class="sql-detail__params">
                <span class="sql-detail__label">params</span>
                <span v-for="(p, i) in q.params" :key="i" class="sql-detail__param">{{ p }}</span>
              </div>
              <div v-if="q.caller" class="sql-detail__caller">
                <span class="sql-detail__label">caller</span>
                <span class="sql-detail__caller-sig">{{ shortSig(q.caller.sig) }}</span>
                <span class="sql-detail__caller-file">{{ q.caller.file }}</span>
              </div>
              <div class="sql-detail__meta">
                <span>line {{ q.line_no }}</span>
                <span>depth {{ q.depth }}</span>
                <span v-if="q.toc">{{ q.toc }}</span>
              </div>
            </div>
          </template>
        </div>
      </template>

      <!-- ── GROUPED VIEW ── -->
      <div v-else-if="viewMode === 'grouped'" class="sql-list">
        <div
          v-for="tocGroup in visibleCallerGroups"
          :key="tocGroup.toc"
          class="toc-group"
        >
          <!-- TOC header (controller/event) -->
          <div class="toc-header" @click="toggleGroup(tocGroup.toc)">
            <span class="caller-chevron">{{ collapsedGroups.has(tocGroup.toc) ? '▸' : '▾' }}</span>
            <span class="toc-name">{{ tocGroup.label }}</span>
            <span class="caller-total">{{ tocGroup.totalCount }} quer{{ tocGroup.totalCount === 1 ? 'y' : 'ies' }}</span>
          </div>

          <!-- Caller sub-groups -->
          <div v-if="!collapsedGroups.has(tocGroup.toc)" class="caller-list">
            <div
              v-for="callerGroup in tocGroup.callers"
              :key="callerGroup.sig"
              class="caller-group"
            >
              <!-- Caller header (Repository/Service method) -->
              <div class="caller-header" @click="toggleGroup(tocGroup.toc + '|' + callerGroup.sig)">
                <span class="caller-chevron">{{ collapsedGroups.has(tocGroup.toc + '|' + callerGroup.sig) ? '▸' : '▾' }}</span>
                <span class="caller-name">{{ shortSig(callerGroup.sig) }}</span>
                <span v-if="callerGroup.count > 1" class="caller-count-badge">×{{ callerGroup.count }}</span>
                <span v-if="callerGroup.totalMs > 0" class="caller-time-badge">~{{ fmtMs(callerGroup.totalMs) }}</span>
                <span class="caller-file">{{ callerGroup.file }}</span>
              </div>

              <!-- Unique queries within this caller -->
              <div v-if="!collapsedGroups.has(tocGroup.toc + '|' + callerGroup.sig)" class="caller-queries">
                <div
                  v-for="uq in callerGroup.uniqueQueries"
                  :key="uq.key"
                  class="uq-row"
                >
                  <div class="uq-main" @click="toggleExpandUq(tocGroup.toc + '|' + callerGroup.sig + '|' + uq.key)">
                    <span v-if="uq.count > 1" class="uq-badge">×{{ uq.count }}</span>
                    <span v-if="uq.kind === 'trigger'" class="uq-kind uq-kind--trigger" title="Main query — triggered EAGER loads below">◆ trigger</span>
                    <span v-if="uq.kind === 'eager'" class="uq-kind uq-kind--eager" title="Auto-loaded by Doctrine EAGER fetch">⚡ eager</span>
                    <span class="uq-sql">{{ truncate(uq.sql, 160) }}</span>
                    <span v-if="uq.totalMs > 0" class="uq-time">
                      ~{{ fmtMs(uq.count > 1 ? uq.totalMs / uq.count : uq.totalMs) }}/q<template v-if="uq.count > 1"> × {{ uq.count }} = ~{{ fmtMs(uq.totalMs) }}</template>
                    </span>
                    <button
                      v-if="uq.kind === 'trigger' && uq.callerLineNo"
                      class="uq-qb-btn"
                      :class="{ 'uq-qb-btn--loading': qbLoading.has(uq.key), 'uq-qb-btn--active': qbData.has(uq.key) }"
                      @click.stop="toggleQb(uq)"
                      title="Show QueryBuilder chain"
                    >QB</button>
                    <button class="uq-copy" @click.stop="copySql(uq.sql)" title="Copy formatted SQL">⎘</button>
                  </div>
                  <!-- Expanded SQL -->
                  <div v-if="expandedUq.has(tocGroup.toc + '|' + callerGroup.sig + '|' + uq.key)" class="uq-detail">
                    <pre class="uq-detail__sql" v-html="highlightSql(uq.sql)"></pre>
                    <span v-if="uq.sql && uq.sql.endsWith('...')" class="sql-truncated">⚠ truncated by xdebug</span>
                    <div v-if="uq.sampleParams && uq.sampleParams.length" class="sql-detail__params">
                      <span class="sql-detail__label">params (sample)</span>
                      <span v-for="(p, i) in uq.sampleParams" :key="i" class="sql-detail__param">{{ p }}</span>
                    </div>
                    <div class="uq-detail__instances">
                      <span class="sql-detail__label">occurrences</span>
                      <span v-for="inst in uq.instances.slice(0, 8)" :key="inst.line_no" class="uq-line">:{{ inst.line_no }}</span>
                      <span v-if="uq.instances.length > 8" class="uq-line uq-line--more">+{{ uq.instances.length - 8 }} more</span>
                    </div>
                  </div>
                  <!-- QB chain panel -->
                  <div v-if="qbData.has(uq.key)" class="qb-panel">
                    <div class="qb-panel__header">
                      <span class="qb-panel__label">QueryBuilder chain</span>
                      <span class="qb-panel__hint">from trace — exact calls</span>
                      <span v-if="qbData.get(uq.key).length === 0" class="qb-panel__none">no QB calls found (raw SQL or not captured)</span>
                    </div>
                    <div v-if="qbData.get(uq.key).length > 0" class="qb-chain">
                      <div class="qb-chain__root">$qb</div>
                      <div v-for="(call, ci) in qbData.get(uq.key)" :key="ci" class="qb-call">
                        <span class="qb-call__arrow">→</span><span class="qb-call__method">{{ call.method }}</span><span class="qb-call__paren">(</span><span class="qb-call__args">{{ call.argsFormatted }}</span><span class="qb-call__paren">)</span>
                      </div>
                      <div class="qb-chain__end">→getResult()</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="!visibleCallerGroups.length" class="sql-state">No matches for "{{ filter }}"</div>
      </div>

      <!-- ── TREE VIEW ── -->
      <!-- Full N-level call hierarchy: each toc → tree of App\ calls
           (controller → service → repo → entity getter → query).
           A ×56 getSelfLabel leaf is meaningless without seeing what called
           it; this view exposes the call chain that produced each query so
           N+1 patterns are visible as dense subtrees, not flat counts. -->
      <div v-else class="sql-list sql-tree-list">
        <div v-if="!visibleTreeGroups.length" class="sql-state">No matches for "{{ filter }}"</div>
        <div
          v-for="tg in visibleTreeGroups"
          :key="tg.toc"
          class="toc-group"
        >
          <div class="toc-header" @click="toggleGroup(tg.toc)">
            <span class="caller-chevron">{{ collapsedGroups.has(tg.toc) ? '▸' : '▾' }}</span>
            <span class="toc-name">{{ tg.label }}</span>
            <span class="caller-total">{{ tg.totalCount }} quer{{ tg.totalCount === 1 ? 'y' : 'ies' }}</span>
          </div>
          <div v-if="!collapsedGroups.has(tg.toc)" class="tree-children">
            <SqlTreeNode
              v-for="(rootNode, rk) in tg.roots"
              :key="rk"
              :node="rootNode"
              :filter="filter"
              :depth="0"
              :file-id="store.activeTabFileId"
            />
          </div>
        </div>
      </div>
    </template>

    <!-- Copy toast -->
    <div class="copy-toast" :class="{ 'copy-toast--visible': copyToast }">Copied!</div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useTraceStore } from '../stores/trace'
import { usePerfTrack } from '../perfTrack'
import SqlTreeNode from './SqlTreeNode.vue'

const store = useTraceStore()
usePerfTrack('SqlPage', { category: 'render' })
const queries = ref([])
const loading = ref(false)
const error = ref(null)
const filter = ref('')
const groupDupes = ref(false)
const dupesOpen = ref(true)
const activeKey = ref(null)
const expanded = ref(new Set())
const expandedUq = ref(new Set())
const collapsedGroups = ref(new Set())
const listEl = ref(null)
const viewMode = ref('grouped')
const copyToast = ref(false)
const reparsing = ref(false)
const qbData = ref(new Map())
const qbLoading = ref(new Set())
let copyToastTimer = null

// normalize SQL for grouping: strip aliases u0_, p1_, collapse whitespace
function normalizeKey(sql) {
  if (!sql) return ''
  return sql.replace(/\b[a-z]\d+_/g, '').replace(/\s+/g, ' ').trim().slice(0, 150)
}

const dupeCounts = computed(() => {
  const m = {}
  for (const q of queries.value) {
    const k = normalizeKey(q.sql)
    m[k] = (m[k] || 0) + 1
  }
  return m
})

const dupeGroups = computed(() => {
  return Object.entries(dupeCounts.value)
    .filter(([, c]) => c > 1)
    .sort((a, b) => b[1] - a[1])
    .map(([key, count]) => ({
      key,
      count,
      preview: key.slice(0, 100),
    }))
})

function dupeCountFor(q) {
  return dupeCounts.value[normalizeKey(q.sql)] || 1
}

// ── Grouped view ──────────────────────────────────────────────────────────────

const callerGroups = computed(() => {
  // Two-level grouping: toc (controller/event) → caller (repository/service method) → unique SQL
  const tocMap = {}
  for (const q of queries.value) {
    const tocKey = q.toc || '(no context)'
    if (!tocMap[tocKey]) tocMap[tocKey] = { toc: tocKey, label: shortToc(tocKey), callerMap: {}, total: 0, firstLine: q.line_no }
    const tg = tocMap[tocKey]
    tg.total++
    if (q.line_no < tg.firstLine) tg.firstLine = q.line_no

    const callerSig = (q.caller && q.caller.sig) ? q.caller.sig : '(no caller)'
    const callerFile = (q.caller && q.caller.file) ? q.caller.file : ''
    const callerLineNo = (q.caller && q.caller.line_no) ? q.caller.line_no : null
    if (!tg.callerMap[callerSig]) {
      tg.callerMap[callerSig] = { sig: callerSig, file: callerFile, count: 0, sqlMap: {}, firstLine: q.line_no }
    }
    const cg = tg.callerMap[callerSig]
    cg.count++
    if (q.line_no < cg.firstLine) cg.firstLine = q.line_no

    const k = normalizeKey(q.sql)
    if (!cg.sqlMap[k]) cg.sqlMap[k] = {
      key: k, sql: q.sql, count: 0, totalMs: 0, instances: [],
      sampleParams: q.params, callerLineNo: callerLineNo, callerDepth: q.caller?.depth,
      firstLine: q.line_no,
    }
    cg.sqlMap[k].count++
    if (q.duration_ms != null) cg.sqlMap[k].totalMs += q.duration_ms
    cg.sqlMap[k].instances.push({ line_no: q.line_no, n: q.n, duration_ms: q.duration_ms })
    if (q.line_no < cg.sqlMap[k].firstLine) cg.sqlMap[k].firstLine = q.line_no
  }

  return Object.values(tocMap)
    .map(tg => ({
      toc: tg.toc,
      label: tg.label,
      totalCount: tg.total,
      firstLine: tg.firstLine,
      callers: Object.values(tg.callerMap)
        .map(cg => {
          const uqs = Object.values(cg.sqlMap)
            .map(uq => ({ ...uq, kind: classifyUq(uq) }))
            .sort((a, b) => {
              // Chronological: earliest line first
              return a.firstLine - b.firstLine
            })
          const totalMs = uqs.reduce((s, uq) => s + uq.totalMs, 0)
          return { sig: cg.sig, file: cg.file, count: cg.count, totalMs, uniqueQueries: uqs, firstLine: cg.firstLine }
        })
        // Chronological by first query line, not by count
        .sort((a, b) => a.firstLine - b.firstLine),
    }))
    // Chronological: earliest first query in the group
    .sort((a, b) => a.firstLine - b.firstLine)
})

const visibleCallerGroups = computed(() => {
  if (!filter.value.trim()) return callerGroups.value
  const f = filter.value.trim().toLowerCase()
  return callerGroups.value
    .map(tg => ({
      ...tg,
      callers: tg.callers
        .map(cg => ({
          ...cg,
          uniqueQueries: cg.uniqueQueries.filter(uq => uq.sql && uq.sql.toLowerCase().includes(f)),
        }))
        .filter(cg => cg.uniqueQueries.length > 0 || cg.sig.toLowerCase().includes(f)),
    }))
    .filter(tg => tg.callers.length > 0 || tg.label.toLowerCase().includes(f))
})

// ── Tree view ────────────────────────────────────────────────────────────────
// Build an N-level tree from query chains. Each query's `chain` is the ordered
// list of App\ calls (root → leaf) that were on the stack at executeQuery time.
// Nodes are merged by sig+file so the same call in different invocations
// collapses into a single node, but its child calls branch out from there.
//
// Tree shape (per toc):
//   roots: { [sig]: node, ... }  ← one entry per outermost App\ call
//   node: { sig, file, line_no, depth, count, totalMs, queries[], children: { sig: node } }

function buildQueryTree(qs) {
  const roots = {}
  for (const q of qs) {
    const chain = Array.isArray(q.chain) ? q.chain : []
    if (chain.length === 0) {
      // No App\ caller (e.g. a cron job or pure-vendor path) — bucket separately
      const key = '(no App caller)'
      if (!roots[key]) {
        roots[key] = {
          sig: key, file: '', line_no: null, depth: null,
          count: 0, totalMs: 0, queries: [], children: {},
        }
      }
      roots[key].queries.push(q)
      roots[key].count++
      if (q.duration_ms) roots[key].totalMs += q.duration_ms
      continue
    }
    // Walk the chain, creating/finding nodes at each level
    let level = roots
    let parentNode = null
    for (let i = 0; i < chain.length; i++) {
      const frame = chain[i]
      const key = frame.sig
      if (!level[key]) {
        level[key] = {
          sig: frame.sig,
          file: frame.file,
          line_no: frame.line_no,
          depth: frame.depth,
          count: 0, totalMs: 0, queries: [],
          children: {},
        }
      }
      const node = level[key]
      node.count++
      if (q.duration_ms) node.totalMs += q.duration_ms
      // If this is the last frame in the chain, attach the query to this node
      if (i === chain.length - 1) {
        node.queries.push(q)
      }
      parentNode = node
      level = node.children
    }
  }
  return roots
}

const queryTree = computed(() => {
  // Group by toc (controller/event), like the existing grouped view, but the
  // body of each group is an N-level tree of App\ calls.
  const tocMap = {}
  for (const q of queries.value) {
    const tocKey = q.toc || '(no context)'
    if (!tocMap[tocKey]) {
      tocMap[tocKey] = { toc: tocKey, label: shortToc(tocKey), queries: [], firstLine: q.line_no }
    }
    tocMap[tocKey].queries.push(q)
    if (q.line_no < tocMap[tocKey].firstLine) tocMap[tocKey].firstLine = q.line_no
  }
  return Object.values(tocMap)
    .map(tg => ({
      toc: tg.toc,
      label: tg.label,
      totalCount: tg.queries.length,
      roots: buildQueryTree(tg.queries),
      firstLine: tg.firstLine,
    }))
    .sort((a, b) => a.firstLine - b.firstLine)
})

const visibleTreeGroups = computed(() => {
  if (!filter.value.trim()) return queryTree.value
  const f = filter.value.trim().toLowerCase()
  // Filter queries at the leaf level. A node is kept if it has any matching
  // query OR its sig matches the filter OR any descendant has matches.
  function pruneNode(node) {
    const matchingQueries = node.queries.filter(q => (q.sql || '').toLowerCase().includes(f))
    const sigMatches = node.sig.toLowerCase().includes(f)
    const prunedChildren = {}
    for (const [k, child] of Object.entries(node.children)) {
      const pruned = pruneNode(child)
      if (pruned) prunedChildren[k] = pruned
    }
    if (matchingQueries.length === 0 && !sigMatches && Object.keys(prunedChildren).length === 0) {
      return null
    }
    return {
      ...node,
      queries: matchingQueries.length > 0 ? matchingQueries : node.queries,
      children: prunedChildren,
      count: matchingQueries.length + Object.values(prunedChildren).reduce((s, c) => s + c.count, 0),
    }
  }
  return queryTree.value
    .map(tg => {
      const prunedRoots = {}
      for (const [k, node] of Object.entries(tg.roots)) {
        const pruned = pruneNode(node)
        if (pruned) prunedRoots[k] = pruned
      }
      return { ...tg, roots: prunedRoots }
    })
    .filter(tg => Object.keys(tg.roots).length > 0)
})

function toggleGroup(toc) {
  const s = new Set(collapsedGroups.value)
  s.has(toc) ? s.delete(toc) : s.add(toc)
  collapsedGroups.value = s
}

function toggleExpandUq(key) {
  const s = new Set(expandedUq.value)
  s.has(key) ? s.delete(key) : s.add(key)
  expandedUq.value = s
}

async function copySql(sql) {
  const text = formatSqlForCopy(sql)
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    const ta = document.createElement('textarea')
    ta.value = text
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
  }
  copyToast.value = true
  clearTimeout(copyToastTimer)
  copyToastTimer = setTimeout(() => { copyToast.value = false }, 1500)
}

async function copyAll() {
  const lines = []
  const total = queries.value.length
  const dupes = dupeGroups.value.length
  lines.push(`SQL Analysis: ${total} quer${total === 1 ? 'y' : 'ies'}${dupes ? `, ${dupes} N+1 candidate${dupes > 1 ? 's' : ''}` : ''}`)
  lines.push('')

  for (const tg of callerGroups.value) {
    lines.push(`=== ${tg.label} (${tg.totalCount} queries) ===`)
    for (const cg of tg.callers) {
      const callerName = shortSig(cg.sig)
      const fileHint = cg.file ? ` [${cg.file}]` : ''
      lines.push(`\n  ${callerName}${fileHint} — ${cg.count} quer${cg.count === 1 ? 'y' : 'ies'}`)
      for (const uq of cg.uniqueQueries) {
        const label = uq.kind === 'eager' ? ' ⚠ N+1 candidate' : ''
        const timing = uq.totalMs > 0 ? ` (${uq.count > 1 ? uq.totalMs + 'ms total' : uq.totalMs + 'ms'})` : ''
        const countStr = uq.count > 1 ? `${uq.count}×` : '1×'
        lines.push(`    ${countStr}${timing}${label}`)
        lines.push(`    ${formatSqlForCopy(uq.sql)}`)
        if (uq.sampleParams && uq.sampleParams.length) {
          lines.push(`    params: [${uq.sampleParams.join(', ')}]`)
        }
      }
    }
    lines.push('')
  }

  const text = lines.join('\n')
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    const ta = document.createElement('textarea')
    ta.value = text
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
  }
  copyToast.value = true
  clearTimeout(copyToastTimer)
  copyToastTimer = setTimeout(() => { copyToast.value = false }, 1500)
}

// ── Flat view ─────────────────────────────────────────────────────────────────

const visibleQueries = computed(() => {
  let list = queries.value
  const f = filter.value.trim().toLowerCase()
  if (f) list = list.filter(q => (q.sql || '').toLowerCase().includes(f) || (q.toc || '').toLowerCase().includes(f))
  if (groupDupes.value && activeKey.value === null) {
    const seen = new Set()
    list = list.filter(q => {
      const k = normalizeKey(q.sql)
      if (dupeCountFor(q) <= 1) return true
      if (seen.has(k)) return false
      seen.add(k)
      return true
    })
  }
  if (activeKey.value) {
    list = list.filter(q => normalizeKey(q.sql) === activeKey.value)
  }
  return list
})

function toggleExpand(n) {
  const s = new Set(expanded.value)
  s.has(n) ? s.delete(n) : s.add(n)
  expanded.value = s
}

function truncate(s, n) {
  if (!s) return '?'
  return s.length > n ? s.slice(0, n) + '…' : s
}

function shortSig(sig) {
  if (!sig) return ''
  const arrow = sig.lastIndexOf('\\')
  return arrow >= 0 ? sig.slice(arrow + 1) : sig
}

function classifyUq(uq) {
  const sql = uq.sql || ''
  const hasJoin = /\bJOIN\b/i.test(sql)
  const hasMultiTableAlias = /\b[a-z]\d+_\.\w/.test(sql) // u0_., u1_. etc
  const singleTableSelect = /^\s*SELECT\s.+\bFROM\s+\w+\s+\w+\s+WHERE\b/i.test(sql) && !hasJoin
  const fewParams = (uq.sampleParams || []).length <= 2
  if (uq.count > 1 && singleTableSelect && fewParams) return 'eager'
  if (uq.count <= 2 && (hasJoin || hasMultiTableAlias)) return 'trigger'
  return null
}

function formatSqlForCopy(sql) {
  if (!sql) return ''
  // Find FROM position (outside strings - Doctrine SQL has no string literals usually)
  const fromMatch = sql.match(/\bFROM\b/i)
  let formatted = sql
  if (fromMatch) {
    const fromIdx = fromMatch.index
    const selectCols = sql.slice(0, fromIdx)
    const rest = sql.slice(fromIdx)
    // Split SELECT columns at top-level commas (track paren depth)
    const cols = []
    let depth = 0, start = selectCols.replace(/^SELECT\s+/i, m => ' '.repeat(m.length)).search(/\S/)
    const colsRaw = selectCols.replace(/^SELECT\s+/i, '')
    let s = 0
    for (let i = 0; i <= colsRaw.length; i++) {
      const c = colsRaw[i]
      if (c === '(') depth++
      else if (c === ')') depth--
      else if ((c === ',' || i === colsRaw.length) && depth === 0) {
        const col = colsRaw.slice(s, i).trim()
        if (col) cols.push(col)
        s = i + 1
      }
    }
    const formattedSelect = 'SELECT\n  ' + cols.join(',\n  ')
    const formattedRest = rest
      .replace(/\s+(INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|FULL\s+JOIN|CROSS\s+JOIN|JOIN)\s+/gi, '\n$1 ')
      .replace(/\s+\b(WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|OFFSET)\s+/gi, '\n$1 ')
      .replace(/\s+\bAND\s+/gi, '\n  AND ')
      .replace(/\s+\bOR\s+/gi, '\n  OR ')
    formatted = formattedSelect + '\n' + formattedRest
  }
  return formatted
}

function esc(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function highlightSql(sql) {
  if (!sql) return ''
  // Single-pass tokenizer — priority order: string literal → keyword → function → placeholder → number → operator → char
  const re = /('(?:[^'\\]|\\.)*'(?:\.\.\.)?)|(\b(?:SELECT|FROM|WHERE|AND|OR|NOT|INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|FULL\s+OUTER\s+JOIN|FULL\s+JOIN|CROSS\s+JOIN|JOIN|ON|AS|WITH|HAVING|GROUP\s+BY|ORDER\s+BY|LIMIT|OFFSET|INSERT\s+INTO|INSERT|INTO|UPDATE|DELETE|SET|VALUES|UNION\s+ALL|UNION|ALL|DISTINCT|EXISTS|BETWEEN|LIKE|ILIKE|IS\s+NOT\s+NULL|IS\s+NULL|IS\s+NOT|IS|NULL|TRUE|FALSE|CASE|WHEN|THEN|ELSE|END|ASC|DESC|BY|RETURNING)\b)|(\b(?:COUNT|SUM|MAX|MIN|AVG|COALESCE|IFNULL|NULLIF|IF|NOW|DATE|YEAR|MONTH|DAY|CAST|CONVERT|LENGTH|UPPER|LOWER|TRIM|CONCAT|SUBSTRING|REPLACE|EXTRACT|ROUND|FLOOR|CEIL|ABS)\b\s*(?=\())|(\?)|(\b\d+(?:\.\d+)?\b)|(<>|!=|>=|<=|[=<>])/gi
  let result = ''
  let last = 0
  let m
  while ((m = re.exec(sql)) !== null) {
    if (m.index > last) result += esc(sql.slice(last, m.index))
    if (m[1]) result += `<span class="sql-str">${esc(m[1])}</span>`
    else if (m[2]) result += `<span class="sql-kw">${esc(m[2])}</span>`
    else if (m[3]) result += `<span class="sql-fn">${esc(m[3])}</span>`
    else if (m[4]) result += `<span class="sql-ph">${esc(m[4])}</span>`
    else if (m[5]) result += `<span class="sql-num">${esc(m[5])}</span>`
    else if (m[6]) result += `<span class="sql-op">${esc(m[6])}</span>`
    else result += esc(m[0])
    last = m.index + m[0].length
  }
  if (last < sql.length) result += esc(sql.slice(last))
  return result
}

function fmtMs(ms) {
  if (ms == null) return ''
  if (ms >= 1000) return (ms / 1000).toFixed(1) + 's'
  if (ms >= 10) return Math.round(ms) + 'ms'
  return ms.toFixed(1) + 'ms'
}

function shortToc(toc) {
  if (!toc) return ''
  const parts = toc.split('\\')
  return parts[parts.length - 1] || toc
}

const QB_METHODS = new Set(['select', 'addSelect', 'from', 'where', 'andWhere', 'orWhere',
  'innerJoin', 'leftJoin', 'join', 'rightJoin', 'orderBy', 'addOrderBy', 'groupBy', 'addGroupBy',
  'having', 'andHaving', 'orHaving', 'setParameter', 'setParameters', 'setFirstResult', 'setMaxResults',
  'getQuery', 'getResult'])

/**
 * Strips the $var = PHP-style prefix that xdebug emits and expands
 * variadic-array values inline so the chain reads like real code.
 *
 *   "$key = 'owner'"                    -> 'owner'
 *   '$value = User {…}'                 -> User {…}
 *   '$value = 163'                      -> 163
 *   "$select = ['uda', 'u', 'profile']" -> 'uda', 'u', 'profile'
 *   "$predicates = ['u.email IS NULL']" -> 'u.email IS NULL'
 */
function formatQbArgs(args) {
  if (!args || args.length === 0) return ''
  return args.map((arg) => {
    let val = String(arg)
    const m = val.match(/^\$?\w+\s*=\s*([\s\S]*)$/)
    if (m) val = m[1].trim()

    // Expand variadic array literal: ['a', 'b', 'c'] -> 'a', 'b', 'c'
    if (val.length >= 2 && val.startsWith('[') && val.endsWith(']')) {
      const inner = val.slice(1, -1).trim()
      if (inner === '') return ''
      const items = []
      let cur = ''
      let inQ = null
      let parens = 0
      for (let i = 0; i < inner.length; i++) {
        const c = inner[i]
        if (inQ) {
          cur += c
          if (c === inQ && inner[i - 1] !== '\\') inQ = null
          continue
        }
        if (c === "'" || c === '"') { inQ = c; cur += c; continue }
        if (c === '(') parens++
        else if (c === ')') parens--
        if (c === ',' && parens === 0 && inner[i + 1] === ' ') {
          items.push(cur.trim()); cur = ''; continue
        }
        cur += c
      }
      if (cur.trim()) items.push(cur.trim())
      if (items.length > 0) return items.join(', ')
    }
    return val
  }).join(', ')
}

async function toggleQb(uq) {
  console.log('[QB] toggleQb click', { key: uq.key, n: uq.n, sql_preview: (uq.sql||'').slice(0, 60) })
  if (qbData.value.has(uq.key)) {
    console.log('[QB] already loaded — toggling closed')
    const m = new Map(qbData.value)
    m.delete(uq.key)
    qbData.value = m
    return
  }
  const fileId = store.activeTabFileId
  console.log('[QB] fileId=', fileId, 'callerLineNo=', uq.callerLineNo, 'callerDepth=', uq.callerDepth)
  if (!fileId || !uq.callerLineNo) {
    console.warn('[QB] missing fileId or callerLineNo — abort')
    return
  }

  const s = new Set(qbLoading.value)
  s.add(uq.key)
  qbLoading.value = s

  const url = `/api/children/${fileId}`
  const params = { line_no: uq.callerLineNo, depth: uq.callerDepth ?? 0, raw: false }
  console.log('[QB] GET', url, params)

  try {
    const resp = await axios.get(url, { params })
    const data = resp.data
    const allChildren = (data && data.children) || []
    console.log(`[QB] response: total_children=${allChildren.length}, parent_return=`, data && data.parent_return)
    // Log every child for full visibility
    allChildren.forEach((c, i) => {
      console.log(`[QB]   child[${i}] line=${c.line_no} sig=${c.sig} args=${JSON.stringify(c.args)} return=${JSON.stringify(c.return)}`)
    })
    const calls = allChildren
      .filter(c => {
        const local = (c.sig || '').split('\\').pop() || ''
        const method = local.includes('->') ? local.split('->').pop() : null
        const hasQB = local.includes('QueryBuilder->')
        const inSet = method && QB_METHODS.has(method)
        if (!hasQB || !inSet) {
          console.log(`[QB]   filter SKIP line=${c.line_no} sig=${c.sig} hasQueryBuilder=${hasQB} method=${method} inSet=${inSet}`)
        }
        return hasQB && method && inSet
      })
      .map(c => {
        const local = (c.sig || '').split('\\').pop() || ''
        const method = local.includes('->') ? local.split('->').pop() : local
        return { method, argsFormatted: formatQbArgs(c.args || []) }
      })
    console.log(`[QB] filtered calls: ${calls.length}`, calls)
    const m = new Map(qbData.value)
    m.set(uq.key, calls)
    qbData.value = m
    console.log('[QB] stored in qbData, panel should now show', calls.length, 'calls')
  } catch (err) {
    console.error('[QB] axios error', err)
    const m = new Map(qbData.value)
    m.set(uq.key, [])
    qbData.value = m
  } finally {
    const s = new Set(qbLoading.value)
    s.delete(uq.key)
    qbLoading.value = s
  }
}

async function reparse() {
  const fileId = store.activeTabFileId
  if (!fileId || reparsing.value) return
  reparsing.value = true
  try {
    await axios.post(`/api/reparse-sql/${fileId}`)
    await load()
  } finally {
    reparsing.value = false
  }
}

async function load() {
  const fileId = store.activeTabFileId
  if (!fileId) return
  loading.value = true
  error.value = null
  queries.value = []
  expanded.value = new Set()
  expandedUq.value = new Set()
  collapsedGroups.value = new Set()
  qbData.value = new Map()
  qbLoading.value = new Set()
  activeKey.value = null
  try {
    const { data } = await axios.get(`/api/sql/${fileId}`)
    queries.value = data
  } catch (e) {
    error.value = e?.response?.data?.error || 'Failed to load SQL'
  }
  loading.value = false
}

watch(() => store.activeTabFileId, load, { immediate: true })
</script>

<style scoped>
.sql-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  overflow: hidden;
  position: relative;
}

.sql-state {
  padding: 32px 24px;
  color: rgba(140, 155, 200, 0.55);
  text-align: center;
  line-height: 1.8;
}
.sql-state--err { color: rgba(210, 90, 80, 0.8); }
.sql-state__hint { font-size: 10px; opacity: 0.6; }

/* Summary bar */
.sql-summary {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  border-bottom: 1px solid rgba(40, 50, 90, 0.4);
  flex-shrink: 0;
  flex-wrap: wrap;
}
html[data-theme="light"] .sql-summary { border-bottom-color: rgba(140,160,210,0.3); }
.sql-summary__total { color: rgba(140, 170, 220, 0.8); font-weight: 600; }
.sql-summary__sep { color: rgba(80, 90, 130, 0.4); }
.sql-summary__dupes { color: rgba(230, 160, 60, 0.85); }
.sql-summary__toggle {
  display: flex; align-items: center; gap: 6px;
  color: rgba(120, 140, 190, 0.65); cursor: pointer; user-select: none;
}
.sql-cb {
  width: 13px; height: 13px;
  border: 1px solid rgba(60, 80, 140, 0.5);
  border-radius: 3px;
  background: rgba(255,255,255,0.04);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: border-color 0.12s, background 0.12s;
  color: rgba(120, 180, 255, 0.9);
}
.sql-cb--on {
  border-color: rgba(80, 130, 220, 0.7);
  background: rgba(50, 90, 180, 0.25);
}
html[data-theme="light"] .sql-cb {
  border-color: rgba(100, 130, 210, 0.5);
  background: rgba(255,255,255,0.7);
}
html[data-theme="light"] .sql-cb--on {
  border-color: rgba(40, 90, 200, 0.7);
  background: rgba(60, 110, 220, 0.15);
  color: rgba(20, 60, 180, 0.9);
}
.sql-summary__filter {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(55,65,110,0.4);
  border-radius: 4px;
  color: rgba(180,195,230,0.9);
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  padding: 3px 8px;
  outline: none;
  width: 160px;
  margin-left: auto;
}
.sql-summary__filter:focus { border-color: rgba(80,120,200,0.6); }
.sql-copy-all {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(50,80,160,0.12);
  border: 1px solid rgba(70,100,200,0.3);
  border-radius: 5px;
  color: rgba(120,155,230,0.8);
  font-size: 10px;
  font-family: 'JetBrains Mono', monospace;
  padding: 3px 8px;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.15s, color 0.15s;
}
.sql-copy-all:hover {
  background: rgba(60,100,200,0.22);
  color: rgba(160,190,255,0.95);
}
html[data-theme="light"] .sql-copy-all {
  background: rgba(40,80,200,0.07);
  border-color: rgba(60,100,200,0.35);
  color: rgba(30,70,180,0.8);
}
.sql-reparse-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(30,60,40,0.15);
  border: 1px solid rgba(50,120,70,0.3);
  border-radius: 5px;
  color: rgba(90,180,110,0.8);
  font-size: 10px;
  font-family: 'JetBrains Mono', monospace;
  padding: 3px 8px;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.15s, color 0.15s;
}
.sql-reparse-btn:hover:not(:disabled) {
  background: rgba(40,110,60,0.25);
  color: rgba(120,210,140,0.95);
}
.sql-reparse-btn:disabled { opacity: 0.5; cursor: default; }
@keyframes sql-spin { to { transform: rotate(360deg); } }
.sql-reparse-btn__spin { animation: sql-spin 0.8s linear infinite; }
html[data-theme="light"] .sql-reparse-btn {
  background: rgba(20,100,40,0.07);
  border-color: rgba(30,130,60,0.35);
  color: rgba(20,110,50,0.85);
}
html[data-theme="light"] .sql-summary__total { color: rgba(20,50,140,0.85); }
html[data-theme="light"] .sql-summary__dupes { color: rgba(180,100,20,0.9); }
html[data-theme="light"] .sql-summary__filter {
  background: rgba(255,255,255,0.7);
  border-color: rgba(120,145,210,0.5);
  color: #101828;
}

/* View mode toggle */
.view-toggle {
  display: flex;
  border: 1px solid rgba(55,65,110,0.4);
  border-radius: 4px;
  overflow: hidden;
}
.view-btn {
  background: none;
  border: none;
  color: rgba(120, 140, 190, 0.55);
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  padding: 2px 8px;
  cursor: pointer;
  transition: background 0.1s, color 0.1s;
}
.view-btn:hover { background: rgba(255,255,255,0.05); color: rgba(160,180,230,0.8); }
.view-btn--active {
  background: rgba(60,90,180,0.25);
  color: rgba(160,200,255,0.9);
}
html[data-theme="light"] .view-toggle { border-color: rgba(120,145,210,0.4); }
html[data-theme="light"] .view-btn { color: rgba(60,80,160,0.55); }
html[data-theme="light"] .view-btn--active {
  background: rgba(60,100,220,0.12);
  color: rgba(20,60,180,0.9);
}

/* N+1 section */
.dupe-section {
  flex-shrink: 0;
  border-bottom: 1px solid rgba(40,50,90,0.35);
}
html[data-theme="light"] .dupe-section { border-bottom-color: rgba(140,160,210,0.3); }
.dupe-section__header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 16px;
  color: rgba(230,160,60,0.75);
  cursor: pointer;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  transition: background 0.1s;
}
.dupe-section__header:hover { background: rgba(255,255,255,0.03); }
.dupe-section__chevron { font-size: 9px; }
.dupe-section__count {
  background: rgba(230,160,60,0.15);
  color: rgba(230,160,60,0.9);
  border-radius: 8px;
  padding: 1px 6px;
  font-size: 10px;
}
.dupe-list { padding: 2px 0 6px; max-height: 140px; overflow-y: auto; }
.dupe-list::-webkit-scrollbar { width: 3px; }
.dupe-list::-webkit-scrollbar-thumb { background: rgba(80,100,160,0.3); border-radius: 2px; }

.dupe-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 16px;
  cursor: pointer;
  transition: background 0.1s;
}
.dupe-row:hover { background: rgba(255,255,255,0.04); }
.dupe-row--active { background: rgba(230,160,60,0.08); }
.dupe-badge {
  background: rgba(230,160,60,0.15);
  color: rgba(230,160,60,0.9);
  border-radius: 4px;
  padding: 1px 5px;
  font-size: 10px;
  flex-shrink: 0;
  min-width: 28px;
  text-align: center;
}
.dupe-sql {
  color: rgba(160,180,220,0.65);
  font-size: 10px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
html[data-theme="light"] .dupe-sql { color: rgba(30,50,120,0.6); }

/* Query list (shared scroll container) */
.sql-list {
  flex: 1;
  overflow-y: auto;
  padding: 4px 0 16px;
}
.sql-list::-webkit-scrollbar { width: 4px; }
.sql-list::-webkit-scrollbar-thumb { background: rgba(60,80,140,0.3); border-radius: 2px; }

/* Flat view rows */
.sql-row {
  display: flex;
  align-items: baseline;
  gap: 7px;
  padding: 5px 16px;
  cursor: pointer;
  border-bottom: 1px solid rgba(30,35,65,0.35);
  transition: background 0.1s;
  min-width: 0;
}
.sql-row:hover { background: rgba(255,255,255,0.04); }
.sql-row--dupe { border-left: 2px solid rgba(230,160,60,0.35); padding-left: 14px; }
.sql-row--highlight { background: rgba(230,160,60,0.07); }
html[data-theme="light"] .sql-row { border-bottom-color: rgba(160,175,220,0.3); }
html[data-theme="light"] .sql-row:hover { background: rgba(80,110,220,0.06); }

.sql-row__n { color: rgba(80,95,140,0.5); font-size: 10px; flex-shrink: 0; min-width: 28px; }
.sql-row__dupe {
  background: rgba(230,160,60,0.12);
  color: rgba(230,160,60,0.85);
  border-radius: 3px;
  padding: 0 4px;
  font-size: 9px;
  flex-shrink: 0;
}
.sql-row__sql {
  flex: 1;
  color: rgba(180,200,235,0.85);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
}
html[data-theme="light"] .sql-row__sql { color: rgba(15,35,100,0.9); }
.sql-row__toc {
  color: rgba(100,180,140,0.6);
  font-size: 10px;
  flex-shrink: 0;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sql-row__line { color: rgba(70,85,130,0.45); font-size: 10px; flex-shrink: 0; }

/* Expanded detail (flat view) */
.sql-detail {
  background: rgba(6,8,18,0.6);
  border-bottom: 1px solid rgba(30,35,65,0.5);
  padding: 10px 16px 12px 24px;
}
html[data-theme="light"] .sql-detail {
  background: rgba(230,235,255,0.5);
  border-bottom-color: rgba(160,175,220,0.35);
}
.sql-detail__sql {
  color: rgba(200,220,255,0.88);
  font-size: 11px;
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.6;
  margin-bottom: 8px;
}
html[data-theme="light"] .sql-detail__sql { color: rgba(10,30,100,0.9); }
.sql-detail__params {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.sql-detail__label { color: rgba(100,120,180,0.55); font-size: 10px; flex-shrink: 0; }
.sql-detail__param {
  background: rgba(40,60,120,0.3);
  color: rgba(160,200,255,0.8);
  border-radius: 4px;
  padding: 1px 7px;
  font-size: 10px;
}
html[data-theme="light"] .sql-detail__param {
  background: rgba(80,120,220,0.12);
  color: rgba(15,50,150,0.85);
}
.sql-detail__caller {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}
.sql-detail__caller-sig {
  color: rgba(120, 200, 150, 0.85);
  font-size: 11px;
}
.sql-detail__caller-file {
  color: rgba(80, 100, 150, 0.55);
  font-size: 10px;
}
html[data-theme="light"] .sql-detail__caller-sig { color: rgba(20, 120, 60, 0.9); }
html[data-theme="light"] .sql-detail__caller-file { color: rgba(60, 80, 140, 0.5); }
.sql-detail__meta {
  display: flex;
  gap: 12px;
  color: rgba(80,95,140,0.5);
  font-size: 10px;
}

/* ── Grouped view ─────────────────────────────────────────────────────────── */

/* TOC level (controller/event) */
.toc-group {
  border-bottom: 1px solid rgba(35,45,80,0.5);
}
html[data-theme="light"] .toc-group { border-bottom-color: rgba(130,150,210,0.25); }

.toc-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  cursor: pointer;
  transition: background 0.1s;
  position: sticky;
  top: 0;
  z-index: 2;
  background: rgba(8,10,22,0.98);
  border-bottom: 1px solid rgba(35,45,80,0.35);
}
.toc-header:hover { background: rgba(15,20,45,0.99); }
html[data-theme="light"] .toc-header {
  background: rgba(236,241,255,0.99);
  border-bottom-color: rgba(130,155,215,0.2);
}
html[data-theme="light"] .toc-header:hover { background: rgba(225,234,255,1); }

.toc-name {
  color: rgba(120,190,255,0.88);
  font-size: 12px;
  font-weight: 700;
  flex: 1;
  min-width: 0;
  word-break: break-word;
}
html[data-theme="light"] .toc-name { color: rgba(15,55,170,0.9); }

.caller-total {
  background: rgba(60,90,160,0.2);
  color: rgba(140,170,230,0.75);
  border-radius: 8px;
  padding: 1px 8px;
  font-size: 10px;
  flex-shrink: 0;
}
html[data-theme="light"] .caller-total {
  background: rgba(60,100,220,0.1);
  color: rgba(20,60,180,0.7);
}

/* Caller level (repository/service method) */
.caller-list { padding: 2px 0 4px; }

.caller-group {
  border-bottom: 1px solid rgba(25,30,60,0.3);
}
html[data-theme="light"] .caller-group { border-bottom-color: rgba(150,170,220,0.18); }

.caller-header {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 5px 16px 5px 28px;
  cursor: pointer;
  transition: background 0.1s;
  position: sticky;
  top: 33px;
  z-index: 1;
  background: rgba(12,15,32,0.97);
  border-bottom: 1px solid rgba(28,35,70,0.25);
}
.caller-header:hover { background: rgba(18,22,48,0.99); }
html[data-theme="light"] .caller-header {
  background: rgba(242,246,255,0.98);
  border-bottom-color: rgba(140,160,215,0.15);
}
html[data-theme="light"] .caller-header:hover { background: rgba(232,240,255,0.99); }

.caller-chevron { color: rgba(70,90,150,0.5); font-size: 9px; flex-shrink: 0; }
.caller-name {
  color: rgba(140,200,160,0.88);
  font-size: 11px;
  font-weight: 600;
  flex-shrink: 0;
  word-break: break-word;
}
html[data-theme="light"] .caller-name { color: rgba(15,100,50,0.88); }

.caller-count-badge {
  background: rgba(230,160,60,0.12);
  color: rgba(230,160,60,0.82);
  border-radius: 4px;
  padding: 0 5px;
  font-size: 10px;
  flex-shrink: 0;
}
.caller-time-badge {
  background: rgba(80,200,120,0.1);
  color: rgba(100,210,140,0.8);
  border-radius: 4px;
  padding: 0 5px;
  font-size: 10px;
  flex-shrink: 0;
}
html[data-theme="light"] .caller-time-badge {
  background: rgba(20,140,60,0.08);
  color: rgba(15,120,50,0.8);
}

.caller-file {
  color: rgba(70,90,140,0.45);
  font-size: 10px;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-left: 2px;
}
html[data-theme="light"] .caller-file { color: rgba(80,100,180,0.45); }

.caller-queries {
  padding: 2px 0 3px 0;
}

/* Unique query row */
.uq-row {
  border-bottom: 1px solid rgba(25,30,60,0.3);
}
.uq-row:last-child { border-bottom: none; }
html[data-theme="light"] .uq-row { border-bottom-color: rgba(160,175,220,0.18); }

.uq-main {
  display: flex;
  align-items: baseline;
  gap: 7px;
  padding: 5px 16px 5px 28px;
  cursor: pointer;
  transition: background 0.1s;
  min-width: 0;
}
.uq-main:hover { background: rgba(255,255,255,0.03); }
html[data-theme="light"] .uq-main:hover { background: rgba(80,110,220,0.05); }

.uq-badge {
  background: rgba(230,160,60,0.12);
  color: rgba(230,160,60,0.85);
  border-radius: 3px;
  padding: 0 5px;
  font-size: 10px;
  flex-shrink: 0;
  min-width: 28px;
  text-align: center;
}
.uq-kind {
  border-radius: 3px;
  padding: 0 5px;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.03em;
  flex-shrink: 0;
  text-transform: uppercase;
}
.uq-kind--trigger {
  background: rgba(80,160,255,0.12);
  color: rgba(100,175,255,0.85);
  border: 1px solid rgba(80,150,255,0.25);
}
.uq-kind--eager {
  background: rgba(230,160,50,0.1);
  color: rgba(230,165,60,0.85);
  border: 1px solid rgba(220,155,50,0.25);
}
html[data-theme="light"] .uq-kind--trigger {
  background: rgba(30,100,220,0.08);
  color: rgba(20,80,200,0.8);
  border-color: rgba(30,100,220,0.2);
}
html[data-theme="light"] .uq-kind--eager {
  background: rgba(180,100,0,0.07);
  color: rgba(150,80,0,0.85);
  border-color: rgba(180,110,0,0.2);
}
.uq-sql {
  flex: 1;
  color: rgba(175,195,235,0.82);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
}
html[data-theme="light"] .uq-sql { color: rgba(15,35,100,0.85); }

.uq-time {
  color: rgba(100,210,140,0.7);
  font-size: 10px;
  flex-shrink: 0;
}
html[data-theme="light"] .uq-time { color: rgba(15,120,50,0.7); }

.uq-copy {
  background: none;
  border: 1px solid rgba(60,80,140,0.3);
  border-radius: 3px;
  color: rgba(100,130,200,0.5);
  font-size: 11px;
  cursor: pointer;
  padding: 0 5px;
  line-height: 1.6;
  flex-shrink: 0;
  transition: border-color 0.1s, color 0.1s, background 0.1s;
}
.uq-copy:hover {
  border-color: rgba(80,120,220,0.6);
  color: rgba(140,180,255,0.9);
  background: rgba(60,90,180,0.15);
}
html[data-theme="light"] .uq-copy { border-color: rgba(100,130,210,0.35); color: rgba(40,80,180,0.5); }
html[data-theme="light"] .uq-copy:hover {
  border-color: rgba(40,90,200,0.6);
  color: rgba(20,60,180,0.9);
  background: rgba(60,110,220,0.1);
}

/* Expanded unique query */
.uq-detail {
  background: rgba(6,8,18,0.55);
  border-top: 1px solid rgba(30,35,65,0.4);
  padding: 10px 16px 12px 28px;
}
html[data-theme="light"] .uq-detail {
  background: rgba(228,234,255,0.5);
  border-top-color: rgba(160,175,220,0.3);
}
.uq-detail__sql {
  color: rgba(200,220,255,0.88);
  font-size: 11px;
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.6;
  margin-bottom: 8px;
}
html[data-theme="light"] .uq-detail__sql { color: rgba(10,30,100,0.9); }
.uq-detail__instances {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
  margin-top: 6px;
}
.uq-line {
  background: rgba(40,55,110,0.3);
  color: rgba(110,135,200,0.65);
  border-radius: 3px;
  padding: 1px 6px;
  font-size: 10px;
}
.uq-line--more { color: rgba(90,110,170,0.5); }
html[data-theme="light"] .uq-line {
  background: rgba(80,110,220,0.08);
  color: rgba(40,70,180,0.6);
}

/* ── Tree view ───────────────────────────────────────────────────────────── */
.sql-tree-list { padding: 4px 0 16px; }
.tree-children { padding: 2px 0 6px 0; }

/* SQL syntax highlight tokens — :deep() needed because v-html bypasses scoped attributes */
:deep(.sql-kw)  { color: rgba(86,156,214,1);    font-weight: 600; }
:deep(.sql-fn)  { color: rgba(220,220,170,0.9); }
:deep(.sql-str) { color: rgba(206,145,120,0.95); }
:deep(.sql-num) { color: rgba(181,206,168,0.9); }
:deep(.sql-ph)  { color: rgba(255,110,100,0.95); font-weight: 700; }
:deep(.sql-op)  { color: rgba(200,200,255,0.6); }
html[data-theme="light"] :deep(.sql-kw)  { color: rgba(0,80,180,1); }
html[data-theme="light"] :deep(.sql-fn)  { color: rgba(130,90,0,0.95); }
html[data-theme="light"] :deep(.sql-str) { color: rgba(160,40,0,0.9); }
html[data-theme="light"] :deep(.sql-num) { color: rgba(0,110,40,0.9); }
html[data-theme="light"] :deep(.sql-ph)  { color: rgba(200,0,0,0.9); }
html[data-theme="light"] :deep(.sql-op)  { color: rgba(60,60,120,0.6); }

.sql-truncated {
  display: inline-block;
  margin-top: 4px;
  color: rgba(230,150,50,0.75);
  font-size: 10px;
}
html[data-theme="light"] .sql-truncated { color: rgba(170,90,0,0.7); }

/* QB button */
.uq-qb-btn {
  background: rgba(60,120,80,0.12);
  border: 1px solid rgba(70,160,100,0.3);
  border-radius: 3px;
  color: rgba(90,190,120,0.75);
  font-size: 9px;
  font-family: 'JetBrains Mono', monospace;
  font-weight: 700;
  letter-spacing: 0.04em;
  cursor: pointer;
  padding: 0 5px;
  line-height: 1.6;
  flex-shrink: 0;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.uq-qb-btn:hover {
  background: rgba(60,160,90,0.2);
  color: rgba(120,220,150,0.95);
  border-color: rgba(70,180,110,0.5);
}
.uq-qb-btn--active {
  background: rgba(40,140,70,0.25);
  color: rgba(130,230,160,0.95);
  border-color: rgba(60,180,100,0.55);
}
.uq-qb-btn--loading { opacity: 0.55; cursor: default; }
html[data-theme="light"] .uq-qb-btn {
  background: rgba(20,120,50,0.07);
  border-color: rgba(30,140,70,0.3);
  color: rgba(15,110,45,0.8);
}

/* QB chain panel */
.qb-panel {
  background: rgba(4,8,20,0.7);
  border-top: 1px solid rgba(50,120,70,0.3);
  padding: 8px 16px 10px 28px;
}
html[data-theme="light"] .qb-panel {
  background: rgba(220,240,225,0.5);
  border-top-color: rgba(40,140,70,0.25);
}
.qb-panel__header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.qb-panel__label {
  color: rgba(90,190,120,0.8);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.qb-panel__hint {
  color: rgba(80,100,140,0.5);
  font-size: 9px;
}
.qb-panel__none { color: rgba(100,120,160,0.5); font-size: 10px; }
html[data-theme="light"] .qb-panel__label { color: rgba(15,110,50,0.85); }
html[data-theme="light"] .qb-panel__hint { color: rgba(60,90,140,0.45); }

.qb-chain {
  display: block;
  font-family: ui-monospace, 'SF Mono', 'Cascadia Code', 'Roboto Mono', Menlo, Consolas, monospace;
  font-size: 11.5px;
  line-height: 1.6;
  color: rgba(190,200,220,0.9);
  background: rgba(0,0,0,0.18);
  border-left: 2px solid rgba(100,160,220,0.35);
  border-radius: 0 4px 4px 0;
  padding: 6px 0 6px 10px;
  margin-top: 2px;
  white-space: pre-wrap;
  word-break: break-word;
}
html[data-theme="light"] .qb-chain {
  color: rgba(40,50,70,0.9);
  background: rgba(110,150,210,0.06);
  border-left-color: rgba(60,120,200,0.4);
}
.qb-chain__root {
  color: rgba(140,180,200,0.75);
  font-weight: 500;
  margin-bottom: 2px;
  font-style: italic;
}
html[data-theme="light"] .qb-chain__root { color: rgba(80,110,150,0.75); }
.qb-chain__end {
  color: rgba(100,180,140,0.7);
  font-style: italic;
  margin-top: 2px;
}
html[data-theme="light"] .qb-chain__end { color: rgba(60,130,80,0.8); }
.qb-call {
  display: block;
  padding-left: 1.2em;
  text-indent: -1.2em;
}
.qb-call__arrow {
  color: rgba(120,160,200,0.5);
  display: inline-block;
  width: 1.5em;
}
.qb-call__method {
  color: rgba(110,180,230,0.95);
  font-weight: 600;
}
.qb-call__paren { color: rgba(150,170,200,0.55); }
.qb-call__args {
  color: rgba(220,160,130,0.95);
}
html[data-theme="light"] .qb-call__method { color: rgba(20,90,180,0.95); }
html[data-theme="light"] .qb-call__args { color: rgba(170,50,0,0.95); }
html[data-theme="light"] .qb-call__paren { color: rgba(100,120,160,0.55); }
html[data-theme="light"] .qb-call__arrow { color: rgba(80,120,170,0.6); }

/* Copy toast */
.copy-toast {
  position: absolute;
  bottom: 20px;
  right: 20px;
  background: rgba(60,180,100,0.9);
  color: #fff;
  border-radius: 6px;
  padding: 6px 14px;
  font-size: 11px;
  pointer-events: none;
  opacity: 0;
  transform: translateY(4px);
  transition: opacity 0.15s, transform 0.15s;
  z-index: 100;
}
.copy-toast--visible {
  opacity: 1;
  transform: translateY(0);
}
</style>
