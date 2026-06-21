<template>
  <div class="qbr" :class="{ 'qbr--open': open }">
    <div class="qbr__head" @click="open = !open">
      <span class="qbr__n">#{{ query.n }}</span>
      <span class="qbr__time">{{ formatMs(query.time_ms ?? query.duration_ms) }}</span>
      <!-- Caller sig: profiler shape has caller.class, trace shape has caller.sig. -->
      <span v-if="query.caller?.class" class="qbr__caller">
        {{ shortName(query.caller.class) }}<span class="qbr__arrow">→</span>{{ query.caller.method }}()
      </span>
      <span v-else-if="query.caller?.sig" class="qbr__caller">
        {{ shortName(query.caller.sig) }}
      </span>
      <code class="qbr__sql">{{ truncate(query.sql, 180) }}</code>
      <span class="qbr__chev">{{ open ? '▾' : '▸' }}</span>
    </div>
    <div v-if="open" class="qbr__body">
      <pre class="qbr__sql-full"><code v-html="highlightSql(query.sql)" /></pre>

      <div v-if="hasParams" class="qbr__section">
        <span class="qbr__label">params</span>
        <span v-for="(v, k) in normalizedParams" :key="k" class="qbr__param">
          <code>{{ k }}</code> = <code>{{ v }}</code>
        </span>
      </div>

      <!-- Profiler mode: first app frame from the backtrace. -->
      <div v-if="query.caller?.class" class="qbr__section">
        <span class="qbr__label">first app frame</span>
        <span class="qbr__caller-full">
          <code>{{ query.caller.class }}->{{ query.caller.method }}()</code>
          <a
            v-if="query.caller.host_path || query.caller.file"
            @click.prevent="openInIde(query.caller.host_path || query.caller.file, query.caller.line)"
            class="qbr__loc"
          >
            {{ shortPath(query.caller.host_path || query.caller.file) }}:{{ query.caller.line }}
          </a>
        </span>
      </div>

      <!-- Profiler mode: full backtrace. -->
      <div v-if="query.backtrace?.length" class="qbr__section">
        <span class="qbr__label">full backtrace ({{ query.backtrace.length }} frames)</span>
        <ol class="qbr__bt">
          <li
            v-for="f in query.backtrace"
            :key="f.n"
            :class="['qbr__bt-frame', f.is_src ? 'qbr__bt-frame--src' : f.is_vendor ? 'qbr__bt-frame--vendor' : 'qbr__bt-frame--other']"
          >
            <span class="qbr__bt-marker">{{ f.is_src ? '🟢' : f.is_vendor ? '⚪' : '🟡' }}</span>
            <span class="qbr__bt-n">#{{ f.n }}</span>
            <span class="qbr__bt-call">{{ f.call }}</span>
            <a
              v-if="f.file"
              @click.prevent="openInIde(f.host_path || f.file, f.line)"
              class="qbr__bt-loc"
            >
              {{ shortPath(f.host_path || f.file) }}:{{ f.line }}
            </a>
          </li>
        </ol>
      </div>

      <!-- Trace mode: chain of App\ calls that produced this query. -->
      <div v-if="query.chain?.length" class="qbr__section">
        <span class="qbr__label">App\ call chain ({{ query.chain.length }} frames)</span>
        <ol class="qbr__bt">
          <li
            v-for="(f, i) in query.chain"
            :key="i"
            class="qbr__bt-frame qbr__bt-frame--src"
          >
            <span class="qbr__bt-marker">→</span>
            <span class="qbr__bt-n">{{ i + 1 }}</span>
            <span class="qbr__bt-call">{{ f.sig || '?' }}</span>
            <a
              v-if="f.file"
              @click.prevent="openInIde(f.file, f.line_no)"
              class="qbr__bt-loc"
            >
              {{ shortPath(f.file) }}:{{ f.line_no }}
            </a>
          </li>
        </ol>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  query: { type: Object, required: true },
})
const open = ref(false)

// Trace-mode params come as a list of strings; profiler-mode as an object.
// We always render an object so the template stays the same.
const normalizedParams = computed(() => {
  const p = props.query.params
  if (!p) return {}
  if (Array.isArray(p)) {
    const out = {}
    p.forEach((v, i) => { out[String(i + 1)] = v })
    return out
  }
  return p
})
const hasParams = computed(() => Object.keys(normalizedParams.value).length > 0)

function truncate(s, n) {
  if (!s) return ''
  return s.length > n ? s.slice(0, n) + '…' : s
}
function shortName(cls) {
  if (!cls) return ''
  const parts = cls.split('\\')
  return parts[parts.length - 1]
}
function shortPath(p) {
  if (!p) return ''
  const parts = p.split('/src/')
  return parts.length > 1 ? 'src/' + parts[1] : p.split('/').slice(-2).join('/')
}
function formatMs(ms) {
  if (ms == null) return '–'
  if (ms < 1) return ms.toFixed(2) + 'ms'
  if (ms < 10) return ms.toFixed(1) + 'ms'
  return Math.round(ms) + 'ms'
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
  // super-lightweight highlighting: keywords, strings, numbers
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
.qbr { border-bottom: 1px solid rgba(255,255,255,0.04); }
.qbr__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 5px 8px;
  cursor: pointer;
  font-size: 12px;
}
.qbr__head:hover { background: var(--bg-hover, rgba(255,255,255,0.03)); }
.qbr__n { color: #888; min-width: 32px; font-family: monospace; }
.qbr__time { color: #f6c64a; min-width: 56px; font-variant-numeric: tabular-nums; }
.qbr__caller { color: #5cd97a; font-family: monospace; min-width: 200px; }
.qbr__arrow { color: #888; margin: 0 2px; }
.qbr__sql { flex: 1; color: #ccc; font-family: monospace; font-size: 11px; }
.qbr__chev { color: #888; }

.qbr__body {
  padding: 6px 8px 12px 50px;
  background: var(--bg-soft, rgba(255,255,255,0.02));
  font-size: 12px;
}
.qbr__sql-full {
  margin: 0;
  padding: 8px;
  background: var(--bg, rgba(0,0,0,0.3));
  border-radius: 4px;
  font-size: 11px;
  font-family: monospace;
  overflow-x: auto;
  white-space: pre-wrap;
  word-break: break-all;
}

.qbr__section { margin-top: 10px; }
.qbr__label {
  display: block;
  font-size: 10px;
  text-transform: uppercase;
  color: #888;
  letter-spacing: 0.05em;
  margin-bottom: 4px;
}
.qbr__caller-full { display: flex; gap: 8px; align-items: baseline; font-family: monospace; }
.qbr__caller-full code { color: #5cd97a; }
.qbr__loc { color: #6da0ff; cursor: pointer; font-size: 11px; }
.qbr__loc:hover { text-decoration: underline; }

.qbr__param {
  display: inline-block;
  margin: 2px 6px 2px 0;
  padding: 2px 6px;
  background: var(--bg, rgba(0,0,0,0.3));
  border-radius: 4px;
  font-family: monospace;
  font-size: 11px;
}
.qbr__param code { color: #f6c64a; }

.qbr__bt { margin: 4px 0 0; padding: 0; list-style: none; }
.qbr__bt-frame {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 1px 0;
  font-family: monospace;
  font-size: 11px;
}
.qbr__bt-marker { width: 14px; }
.qbr__bt-n { color: #888; min-width: 24px; }
.qbr__bt-frame--src .qbr__bt-call { color: #5cd97a; }
.qbr__bt-frame--vendor .qbr__bt-call { color: #888; }
.qbr__bt-frame--other .qbr__bt-call { color: #f6c64a; }
.qbr__bt-loc { color: #6da0ff; cursor: pointer; margin-left: auto; }
.qbr__bt-loc:hover { text-decoration: underline; }
</style>