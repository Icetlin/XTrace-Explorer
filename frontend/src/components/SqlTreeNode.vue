<!--
  SqlTreeNode — recursive node for the SQL tree view.

  Renders one App\ call in the call chain that produced a query:
    [chevron]  method-name  ×N  ~time  ⚠ N+1  src/File.php:line

  A node can have:
    - children: deeper App\ calls that fired further queries (recursive)
    - queries:   the actual SQL queries that terminated at this exact chain

  N+1 detection: a leaf node (no children) with all queries having the same
  normalizeKey(sql) — classic N+1 lazy-load / in-loop-query pattern.
-->
<template>
  <div class="tree-node" :class="{ 'tree-node--n1': isN1 }">
    <div class="tree-node__row" @click="open = !open">
      <span class="caller-chevron">{{ open ? '▾' : '▸' }}</span>
      <span class="tree-node__sig">{{ shortSig(node.sig) }}</span>
      <span v-if="node.count > 1" class="caller-count-badge">×{{ node.count }}</span>
      <span v-if="node.totalMs > 0" class="caller-time-badge">~{{ fmtMs(node.totalMs) }}</span>
      <span v-if="isN1" class="n1-badge" title="All queries at this node share the same SQL — classic N+1">⚠ N+1</span>
      <span v-if="node.file" class="tree-node__file">{{ shortFile(node.file) }}:{{ node.line_no }}</span>
    </div>

    <div v-if="open" class="tree-node__body">
      <!-- Recurse into deeper App\ calls -->
      <div v-for="[childKey, childNode] in childEntries" :key="childKey" class="tree-node__child">
        <SqlTreeNode :node="childNode" :filter="filter" :depth="depth + 1" :file-id="fileId" />
      </div>

      <!-- Leaf queries (the actual SQL) -->
      <div v-if="hasQueries" class="tree-node__queries">
        <div class="tree-node__queries-toggle" @click="queriesOpen = !queriesOpen">
          <span class="caller-chevron">{{ queriesOpen ? '▾' : '▸' }}</span>
          <span class="tree-node__queries-label">{{ node.queries.length }} quer{{ node.queries.length === 1 ? 'y' : 'ies' }}</span>
        </div>
        <div v-if="queriesOpen" class="tree-node__queries-list">
          <div v-for="q in node.queries.slice(0, 50)" :key="q.n" class="tree-leaf-q">
            <span class="tree-leaf-q__n">#{{ q.n }}</span>
            <span class="tree-leaf-q__sql" v-html="highlightSql(q.sql || '')"></span>
            <span v-if="q.sql && q.sql.endsWith('...')" class="sql-truncated">⚠ truncated</span>
            <span class="tree-leaf-q__line">:{{ q.line_no }}</span>
            <button class="uq-copy" @click.stop="copySql(q.sql)" title="Copy SQL">⎘</button>
          </div>
          <div v-if="node.queries.length > 50" class="tree-leaf-q__more">+{{ node.queries.length - 50 }} more — filter to narrow</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  node: { type: Object, required: true },
  filter: { type: String, default: '' },
  depth: { type: Number, default: 0 },
  fileId: { type: [Number, String], default: null },
})

// Auto-expand first two levels so the user immediately sees the hierarchy
// shape, deeper levels stay collapsed until clicked.
const open = ref(props.depth < 2)
const queriesOpen = ref(false)

const hasChildren = computed(() => Object.keys(props.node.children || {}).length > 0)
const hasQueries = computed(() => (props.node.queries || []).length > 0)

// N+1: leaf (no children) where every query normalizes to the same SQL.
const isN1 = computed(() => {
  if (hasChildren.value) return false
  const qs = props.node.queries || []
  if (qs.length < 2) return false
  const keys = new Set(qs.map(q => normalizeKey(q.sql || '')))
  return keys.size === 1
})

// Sort children by count desc — N+1 branches (high count) bubble to top
const childEntries = computed(() => {
  const entries = Object.entries(props.node.children || {})
  entries.sort((a, b) => b[1].count - a[1].count)
  return entries
})

function shortSig(sig) {
  if (!sig) return ''
  const arrow = sig.lastIndexOf('\\')
  return arrow >= 0 ? sig.slice(arrow + 1) : sig
}

function fmtMs(ms) {
  if (ms == null || ms === 0) return ''
  if (ms >= 1000) return (ms / 1000).toFixed(1) + 's'
  if (ms >= 10) return Math.round(ms) + 'ms'
  return ms.toFixed(1) + 'ms'
}

function shortFile(file) {
  if (!file) return ''
  const parts = file.split('/')
  return parts.slice(Math.max(0, parts.length - 2)).join('/')
}

function normalizeKey(sql) {
  if (!sql) return ''
  return sql.replace(/\b[a-z]\d+_/g, '').replace(/\s+/g, ' ').trim().slice(0, 150)
}

async function copySql(sql) {
  if (!sql) return
  try {
    await navigator.clipboard.writeText(sql)
  } catch {
    const ta = document.createElement('textarea')
    ta.value = sql
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
  }
}

function esc(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function highlightSql(sql) {
  if (!sql) return ''
  const re = /('(?:[^'\\]|\\.)*'(?:\.\.\.)?)|(\b(?:SELECT|FROM|WHERE|AND|OR|NOT|INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|JOIN|ON|AS|WITH|HAVING|GROUP\s+BY|ORDER\s+BY|LIMIT|OFFSET|INSERT|INTO|UPDATE|DELETE|SET|VALUES|UNION|ALL|DISTINCT|EXISTS|BETWEEN|LIKE|ILIKE|IS\s+NOT\s+NULL|IS\s+NULL|IS|NULL|TRUE|FALSE|CASE|WHEN|THEN|ELSE|END|ASC|DESC|BY|RETURNING)\b)|(\?)|(\b\d+(?:\.\d+)?\b)|(<>|!=|>=|<=|[=<>])/gi
  let result = ''
  let last = 0
  let m
  while ((m = re.exec(sql)) !== null) {
    if (m.index > last) result += esc(sql.slice(last, m.index))
    if (m[1]) result += `<span class="sql-str">${esc(m[1])}</span>`
    else if (m[2]) result += `<span class="sql-kw">${esc(m[2])}</span>`
    else if (m[3]) result += `<span class="sql-ph">${esc(m[3])}</span>`
    else if (m[4]) result += `<span class="sql-num">${esc(m[4])}</span>`
    else result += esc(m[0])
    last = m.index + m[0].length
  }
  if (last < sql.length) result += esc(sql.slice(last))
  return result
}
</script>

<style scoped>
.tree-node {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
}
.tree-node__row {
  display: flex;
  align-items: baseline;
  gap: 7px;
  padding: 3px 16px;
  cursor: pointer;
  transition: background 0.1s;
  border-radius: 3px;
  min-width: 0;
}
.tree-node__row:hover { background: rgba(255,255,255,0.04); }
.tree-node--n1 > .tree-node__row {
  background: rgba(230,160,60,0.06);
  border-left: 2px solid rgba(230,160,60,0.45);
  padding-left: 14px;
}
.tree-node--n1 > .tree-node__row:hover { background: rgba(230,160,60,0.1); }
.tree-node__sig {
  color: rgba(140,200,160,0.88);
  font-weight: 600;
  font-size: 11px;
  flex-shrink: 0;
  word-break: break-word;
}
.tree-node__file {
  color: rgba(80, 100, 150, 0.5);
  font-size: 10px;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  margin-left: 2px;
}
.n1-badge {
  background: rgba(230,160,60,0.18);
  color: rgba(230,160,60,0.95);
  border-radius: 3px;
  padding: 0 5px;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.04em;
  flex-shrink: 0;
}
.tree-node__body { padding-left: 18px; }
.tree-node__child { padding: 1px 0; }
.tree-node__queries {
  margin: 2px 0 4px;
  border-top: 1px dashed rgba(50,60,100,0.25);
  padding-top: 2px;
}
.tree-node__queries-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 2px 16px 2px 8px;
  cursor: pointer;
  color: rgba(140,170,220,0.6);
  font-size: 10px;
  user-select: none;
}
.tree-node__queries-toggle:hover { color: rgba(160,190,240,0.8); }
.tree-node__queries-label { font-weight: 600; }
.tree-node__queries-list { padding: 2px 0 2px 18px; }
.tree-leaf-q {
  display: flex;
  align-items: baseline;
  gap: 6px;
  padding: 2px 16px 2px 0;
  min-width: 0;
  border-bottom: 1px solid rgba(30,35,65,0.2);
}
.tree-leaf-q:last-of-type { border-bottom: none; }
.tree-leaf-q__n { color: rgba(80,95,140,0.5); font-size: 10px; flex-shrink: 0; min-width: 26px; }
.tree-leaf-q__sql {
  flex: 1;
  color: rgba(180,200,235,0.78);
  font-size: 10.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tree-leaf-q__line { color: rgba(70,85,130,0.4); font-size: 9px; flex-shrink: 0; }
.tree-leaf-q__more { color: rgba(90,110,170,0.5); font-size: 10px; padding: 2px 0; font-style: italic; }
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
.sql-truncated { color: rgba(230,150,50,0.75); font-size: 10px; }

/* SQL syntax highlight — same tokens as SqlPage.vue */
:deep(.sql-kw)  { color: rgba(86,156,214,1);    font-weight: 600; }
:deep(.sql-str) { color: rgba(206,145,120,0.95); }
:deep(.sql-num) { color: rgba(181,206,168,0.9); }
:deep(.sql-ph)  { color: rgba(255,110,100,0.95); font-weight: 700; }
</style>
