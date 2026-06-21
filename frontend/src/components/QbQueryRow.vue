<template>
  <div class="qbr" :class="{ 'qbr--open': open, 'qbr--lazy': query.lazy }" :data-query-n="query.n">
    <div class="qbr__head" @click="open = !open">
      <span class="qbr__n">#{{ query.n }}</span>
      <span class="qbr__time">{{ formatMs(query.time_ms ?? query.duration_ms) }}</span>
      <!-- 🐢 = Doctrine lazy-load (relation hydrator, not a query in your source).
           The fix is to add the relation to the parent's leftJoin(). -->
      <span v-if="query.lazy" class="qbr__lazy" title="Doctrine lazy-load — this query is not in your source code. Add the relation to the parent QueryBuilder's leftJoin() to fold it into the explicit query.">🐢 lazy</span>
      <!-- Memory column (trace mode only — the profiler DB panel does not
           expose per-query memory, only timing). Shows PHP heap at the
           moment `->executeQuery()` was logged by xdebug. -->
      <span v-if="query.mem_at_query != null" class="qbr__mem" :title="`PHP heap at query: ${formatBytes(query.mem_at_query)}`">
        {{ formatBytes(query.mem_at_query) }}
      </span>
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
      <div class="qbr__sql-bar">
        <span class="qbr__label">sql</span>
        <button
          class="qbr__copy"
          :title="copied ? 'Copied!' : 'Copy raw SQL to clipboard'"
          @click.stop="copySql"
        >
          {{ copied ? '✓' : '⧉' }}
        </button>
      </div>
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
const copied = ref(false)
async function copySql() {
  const sql = (props.query?.sql ?? '').toString()
  if (!sql) return
  try {
    await navigator.clipboard.writeText(sql)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch (e) {
    // Fallback for older browsers / non-secure contexts.
    const ta = document.createElement('textarea')
    ta.value = sql
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.select()
    try { document.execCommand('copy') } catch (_) {}
    document.body.removeChild(ta)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  }
}

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
function formatBytes(b) {
  if (b == null) return '–'
  if (b < 1024) return b + ' B'
  if (b < 1024 * 1024) return (b / 1024).toFixed(0) + ' KB'
  if (b < 1024 * 1024 * 1024) return (b / 1024 / 1024).toFixed(1) + ' MB'
  return (b / 1024 / 1024 / 1024).toFixed(2) + ' GB'
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
  // super-lightweight highlighting: keywords, strings, numbers.
  //
  // Critical: each regex pass must NOT see tokens inside the spans that the
  // previous pass inserted. Otherwise `\b(\d+)\b` happily matches the `600`
  // inside `font-weight:600` of the keyword span, producing nested
  // `<span style="font-weight:<span ...>600</span>">` which the browser
  // renders as literal `600">` next to the keyword. Two guards:
  //   1. Use `font-weight:bold` (no digit) instead of `font-weight:600`.
  //   2. Run the number pass FIRST on the raw escaped text, then keywords
  //      and strings — so keyword spans never wrap a digit that the next
  //      pass could re-match.
  const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  let out = esc(sql)
  // Numbers first (before any spans are inserted).
  out = out.replace(/\b(\d+)\b/g, '<span class="qbr__num">$1</span>')
  // Then strings (won't match spans because they look for single quotes).
  out = out.replace(/('[^']*')/g, '<span class="qbr__str">$1</span>')
  // Then keywords.
  out = out.replace(/(\b(?:SELECT|FROM|WHERE|AND|OR|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AS|GROUP|ORDER|BY|LIMIT|OFFSET|UNION|INSERT|UPDATE|DELETE|SET|INTO|VALUES|IS|NULL|NOT|EXISTS|IN|BETWEEN|LIKE|ILIKE|ASC|DESC|HAVING)\b)/gi,
    '<span class="qbr__kw">$1</span>')
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
.qbr__mem { color: #c98aff; min-width: 60px; font-variant-numeric: tabular-nums; font-size: 11px; }
.qbr__caller { color: #5cd97a; font-family: monospace; min-width: 200px; }
.qbr__lazy {
  display: inline-block;
  background: rgba(255, 138, 50, 0.12);
  color: #ff9d57;
  border: 1px solid rgba(255, 138, 50, 0.25);
  border-radius: 3px;
  padding: 1px 5px;
  font-size: 10px;
  letter-spacing: 0.02em;
  cursor: help;
}
.qbr--lazy .qbr__sql { color: #c98c66; }
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
.qbr__sql-full :deep(.qbr__kw) { color: #6da0ff; font-weight: bold; }
.qbr__sql-full :deep(.qbr__str) { color: #f6c64a; }
.qbr__sql-full :deep(.qbr__num) { color: #5cd97a; }
.qbr__sql-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.qbr__sql-bar .qbr__label { margin-bottom: 0; }
.qbr__copy {
  background: transparent;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 4px;
  color: #888;
  cursor: pointer;
  font-size: 12px;
  padding: 1px 6px;
  line-height: 1.4;
  font-family: monospace;
}
.qbr__copy:hover { color: #5cd97a; border-color: #5cd97a; }

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