<template>
  <div class="qcgn" :class="{ 'qcgn--open': open }">
    <div class="qcgn__row" @click="open = !open">
      <span class="qcgn__chev">{{ open ? '▾' : '▸' }}</span>
      <span class="qcgn__icon">
        {{ node.queries.length > 0 ? '🍃' : (hasChildren ? '🌳' : '○') }}
      </span>
      <span class="qcgn__sig">
        <span class="qcgn__cls">{{ short(node.class) }}</span>
        <span class="qcgn__arrow">→</span>
        <span class="qcgn__method">{{ node.method }}()</span>
      </span>

      <!-- Aggregated stats (rolled up from this subtree) -->
      <span v-if="totalQueries > 0" class="qcgn__stat qcgn__stat--q">
        {{ totalQueries }} SQL
      </span>
      <span v-if="totalMs > 0" class="qcgn__stat qcgn__stat--t">
        {{ formatMs(totalMs) }}
      </span>

      <a
        v-if="node.hostPath || node.file"
        class="qcgn__loc"
        @click.stop="openInIde(node.hostPath || node.file, node.line)"
        :title="(node.hostPath || node.file) + ':' + (node.line ?? '')"
      >
        {{ shortPath(node.hostPath || node.file) }}:{{ node.line }}
      </a>
    </div>

    <div v-if="open" class="qcgn__body" :style="{ '--depth': depth }">
      <!-- Recurse into callees (deeper methods this one called) -->
      <QbCallGraphNode
        v-for="childKey in childKeys"
        :key="childKey"
        :node-key="childKey"
        :nodes="nodes"
        :depth="depth + 1"
      />

      <!-- Leaf: SQL queries hanging off this node -->
      <div v-if="node.queries.length" class="qcgn__queries">
        <div class="qcgn__queries-header">
          <span class="qcgn__queries-label">
            {{ node.queries.length }} quer{{ node.queries.length === 1 ? 'y' : 'ies' }}
            <span v-if="isN1" class="qcgn__n1" title="All queries share the same SQL — classic N+1">⚠ N+1</span>
          </span>
        </div>
        <div
          v-for="(q, i) in sortedQueries"
          :key="i"
          class="qcgn__q"
          :class="{ 'qcgn__q--n1': isN1 }"
          @click="selectedN = (selectedN === q.n ? null : q.n)"
        >
          <div class="qcgn__q-head">
            <span class="qcgn__q-n">#{{ q.n }}</span>
            <span class="qcgn__q-time">{{ formatMs(q.time_ms) }}</span>
            <code class="qcgn__q-sql">{{ truncate(q.sql, 200) }}</code>
            <span class="qcgn__q-chev">{{ selectedN === q.n ? '▾' : '▸' }}</span>
          </div>
          <!-- Expanded: show params + (optionally) a button to highlight this query elsewhere -->
          <div v-if="selectedN === q.n" class="qcgn__q-body">
            <pre v-if="q.sql" class="qcgn__q-sql-full"><code v-html="highlightSql(q.sql)" /></pre>
            <div v-if="queryByN(q.n)?.params && Object.keys(queryByN(q.n).params).length" class="qcgn__q-params">
              <span v-for="(v, k) in queryByN(q.n).params" :key="k" class="qcgn__q-param">
                <code>{{ k }}</code> = <code>{{ v }}</code>
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useQbStore } from '../stores/qb'

const props = defineProps({
  nodeKey: { type: String, required: true },
  nodes:  { type: Map, required: true },
  depth:  { type: Number, default: 0 },
})

const qb = useQbStore()
const open = ref(props.depth < 1)   // root auto-expanded, rest collapsed
const selectedN = ref(null)

const node = computed(() => props.nodes.get(props.nodeKey))

const childKeys = computed(() => {
  // Only render callees that actually exist in the graph (defensive — Set
  // may contain keys we couldn't create because the frame was incomplete).
  return (node.value?.callees ?? []).filter(k => props.nodes.has(k))
})

const hasChildren = computed(() => childKeys.value.length > 0)

// Walk the subtree and sum queries / time. Used for the badge on each node
// so users see "this method + everything below it triggered N queries / M ms".
const totalQueries = computed(() => walkSum(props.nodeKey, 'queries'))
const totalMs = computed(() => walkSum(props.nodeKey, 'ms'))

function walkSum(rootKey, field) {
  let total = 0
  const stack = [rootKey]
  const seen = new Set()
  while (stack.length) {
    const k = stack.pop()
    if (seen.has(k)) continue
    seen.add(k)
    const n = props.nodes.get(k)
    if (!n) continue
    if (field === 'queries') total += n.queries.length
    else if (field === 'ms') total += n.queries.reduce((acc, q) => acc + (q.time_ms || 0), 0)
    for (const c of n.callees) if (props.nodes.has(c)) stack.push(c)
  }
  return total
}

// N+1 = all queries on this node share the same normalised SQL.
const isN1 = computed(() => {
  const qs = node.value?.queries ?? []
  if (qs.length < 2) return false
  const first = normaliseSql(qs[0].sql)
  return qs.every(q => normaliseSql(q.sql) === first)
})

const sortedQueries = computed(() => {
  const qs = node.value?.queries ?? []
  return [...qs].sort((a, b) => (b.time_ms || 0) - (a.time_ms || 0))
})

function queryByN(n) {
  return qb.queries.find(q => q.n === n)
}

function short(cls) {
  if (!cls) return ''
  const parts = cls.split('\\')
  return parts[parts.length - 1]
}
function shortPath(p) {
  if (!p) return ''
  const parts = p.split('/src/')
  return parts.length > 1 ? 'src/' + parts[1] : p.split('/').slice(-2).join('/')
}
function truncate(s, n) {
  if (!s) return ''
  return s.length > n ? s.slice(0, n) + '…' : s
}
function formatMs(ms) {
  if (ms == null) return '–'
  if (ms < 1) return ms.toFixed(2) + 'ms'
  if (ms < 10) return ms.toFixed(1) + 'ms'
  return Math.round(ms) + 'ms'
}
function normaliseSql(sql) {
  return (sql || '').replace(/\s+/g, ' ').replace(/'[^']*'/g, "''").replace(/\b\d+\b/g, 'N').trim().slice(0, 200)
}
function openInIde(path, line) {
  if (!path) return
  fetch('/api/open-in-ide', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ path, line }),
  })
}
function highlightSql(sql) {
  if (!sql) return ''
  const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  let out = esc(sql)
  out = out.replace(/(\b(?:SELECT|FROM|WHERE|AND|OR|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AS|GROUP|ORDER|BY|LIMIT|OFFSET|UNION|INSERT|UPDATE|DELETE|SET|INTO|VALUES|IS|NULL|NOT|EXISTS|IN|BETWEEN|LIKE|ILIKE|ASC|DESC|HAVING)\b)/gi,
    '<span style="color:#6da0ff;font-weight:600">$1</span>')
  out = out.replace(/('[^']*')/g, '<span style="color:#f6c64a">$1</span>')
  out = out.replace(/\b(\d+)\b/g, '<span style="color:#5cd97a">$1</span>')
  return out
}
</script>

<style scoped>
.qcgn { padding-left: 0; }
.qcgn__row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3px 4px;
  cursor: pointer;
  border-radius: 4px;
}
.qcgn__row:hover { background: rgba(255,255,255,0.04); }
.qcgn__chev { color: #888; font-size: 10px; width: 10px; }
.qcgn__icon { font-size: 11px; }
.qcgn__sig { font-family: monospace; font-size: 12px; }
.qcgn__cls { color: #5cd97a; }
.qcgn__arrow { color: #888; margin: 0 2px; }
.qcgn__method { color: #c5f0c5; }

.qcgn__stat {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
}
.qcgn__stat--q { background: #1a2440; color: #6da0ff; }
.qcgn__stat--t { background: #3a2a1a; color: #f6c64a; }

.qcgn__loc {
  font-size: 10px;
  color: #888;
  cursor: pointer;
  margin-left: auto;
  font-family: monospace;
}
.qcgn__loc:hover { color: #6da0ff; text-decoration: underline; }

.qcgn__body {
  margin-left: 14px;
  border-left: 1px dashed rgba(255,255,255,0.08);
  padding-left: 10px;
}

.qcgn__queries {
  margin: 4px 0 6px;
  padding: 6px 8px;
  background: rgba(255,255,255,0.02);
  border-radius: 4px;
}
.qcgn__queries-header { display: flex; align-items: center; margin-bottom: 4px; }
.qcgn__queries-label {
  font-size: 10px;
  text-transform: uppercase;
  color: #888;
  letter-spacing: 0.05em;
}
.qcgn__n1 {
  margin-left: 6px;
  color: #f6c64a;
  text-transform: none;
  letter-spacing: 0;
}

.qcgn__q { font-family: monospace; font-size: 11px; }
.qcgn__q--n1 .qcgn__q-sql { color: #f6c64a; }
.qcgn__q-head {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 2px 4px;
  cursor: pointer;
  border-radius: 3px;
}
.qcgn__q-head:hover { background: rgba(255,255,255,0.04); }
.qcgn__q-n { color: #888; min-width: 32px; }
.qcgn__q-time { color: #f6c64a; min-width: 50px; font-variant-numeric: tabular-nums; }
.qcgn__q-sql { flex: 1; color: #ccc; }
.qcgn__q-chev { color: #888; }

.qcgn__q-body { padding: 4px 4px 6px 36px; }
.qcgn__q-sql-full {
  margin: 4px 0;
  padding: 6px;
  background: rgba(0,0,0,0.3);
  border-radius: 3px;
  font-size: 10px;
  overflow-x: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
.qcgn__q-params { display: flex; flex-wrap: wrap; gap: 4px; }
.qcgn__q-param {
  background: rgba(0,0,0,0.3);
  padding: 1px 5px;
  border-radius: 3px;
  font-size: 10px;
}
.qcgn__q-param code { color: #f6c64a; }
</style>