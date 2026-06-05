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
        <label class="sql-summary__toggle">
          <span class="sql-cb" :class="{ 'sql-cb--on': groupDupes }" @click="groupDupes = !groupDupes">
            <svg v-if="groupDupes" width="9" height="9" viewBox="0 0 9 9" fill="none">
              <path d="M1.5 4.5L3.5 6.5L7.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
          group dupes
        </label>
        <span class="sql-summary__sep">·</span>
        <input v-model="filter" class="sql-summary__filter" placeholder="filter SQL…" spellcheck="false" />
      </div>

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
            <pre class="sql-detail__sql">{{ q.sql }}</pre>
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
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useTraceStore } from '../stores/trace'

const store = useTraceStore()
const queries = ref([])
const loading = ref(false)
const error = ref(null)
const filter = ref('')
const groupDupes = ref(false)
const dupesOpen = ref(true)
const activeKey = ref(null)
const expanded = ref(new Set())
const listEl = ref(null)

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

const visibleQueries = computed(() => {
  let list = queries.value
  const f = filter.value.trim().toLowerCase()
  if (f) list = list.filter(q => (q.sql || '').toLowerCase().includes(f) || (q.toc || '').toLowerCase().includes(f))
  if (groupDupes.value && activeKey.value === null) {
    // show only first occurrence of each dupe group
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
  // App\Repository\User\UserDomainRepository->findByDomainName → UserDomainRepository->findByDomainName
  const arrow = sig.lastIndexOf('\\')
  return arrow >= 0 ? sig.slice(arrow + 1) : sig
}

function shortToc(toc) {
  if (!toc) return ''
  const parts = toc.split('\\')
  const last = parts[parts.length - 1] || toc
  return last.length > 30 ? last.slice(0, 28) + '…' : last
}

async function load() {
  const fileId = store.activeTabFileId
  if (!fileId) return
  loading.value = true
  error.value = null
  queries.value = []
  expanded.value = new Set()
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
html[data-theme="light"] .sql-summary__total { color: rgba(20,50,140,0.85); }
html[data-theme="light"] .sql-summary__dupes { color: rgba(180,100,20,0.9); }
html[data-theme="light"] .sql-summary__filter {
  background: rgba(255,255,255,0.7);
  border-color: rgba(120,145,210,0.5);
  color: #101828;
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
  border-radius: 0;
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

/* Query list */
.sql-list {
  flex: 1;
  overflow-y: auto;
  padding: 4px 0 16px;
}
.sql-list::-webkit-scrollbar { width: 4px; }
.sql-list::-webkit-scrollbar-thumb { background: rgba(60,80,140,0.3); border-radius: 2px; }

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

/* Expanded detail */
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
.sql-detail__label { color: rgba(100,120,180,0.55); font-size: 10px; }
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
</style>
