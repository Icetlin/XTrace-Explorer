<template>
  <div class="lc" :class="{ 'lc--open': open }">
    <button class="lc__handle" @click="$emit('close')" :title="open ? 'Hide live console' : 'Show live console'">
      <span class="lc__dot" :class="{ 'lc__dot--err': backendErrors.length > 0, 'lc__dot--warn': frontendLogs.length > 0 && backendErrors.length === 0 }" />
      <span class="lc__count" v-if="totalCount > 0">{{ totalCount }}</span>
      <span class="lc__label">{{ open ? '▾' : '▴' }}</span>
      <span class="lc__label-text">Live console</span>
    </button>

    <div v-if="open" class="lc__panel">
      <header class="lc__head">
        <span class="lc__title">Live console</span>
        <span class="lc__sub">{{ totalCount }} entries</span>

        <label class="lc__switch" :title="captureFrontend ? 'Capturing console.log/error/warn' : 'Frontend logs not captured'">
          <input type="checkbox" v-model="captureFrontend" />
          <span class="lc__switch-knob" />
          <span class="lc__switch-label">frontend logs</span>
        </label>

        <button class="lc__btn" @click="copyAll" :disabled="!entries.length" title="Copy all entries as JSON to clipboard">Copy</button>
        <button class="lc__btn" @click="refresh" :disabled="loading" title="Reload from server">↻</button>
        <button class="lc__btn" @click="clear" :disabled="!entries.length" title="Clear all (backend + frontend)">Clear</button>
        <button class="lc__btn" @click="$emit('close')" title="Close">✕</button>
      </header>

      <!-- Filter tabs -->
      <div class="lc__tabs">
        <button :class="['lc__tab', { on: filter === 'all' }]" @click="filter = 'all'">all</button>
        <button :class="['lc__tab', { on: filter === 'backend' }]" @click="filter = 'backend'">backend</button>
        <button :class="['lc__tab', { on: filter === 'frontend' }]" @click="filter = 'frontend'">frontend</button>
      </div>

      <div class="lc__list">
        <div v-if="loading && !entries.length" class="lc__empty">Loading…</div>
        <div v-else-if="!filtered.length" class="lc__empty">
          No entries yet. Backend errors (5xx) and frontend console.* calls
          (when "frontend logs" is on) appear here.
        </div>
        <article
          v-for="(e, i) in filtered"
          :key="(e.ts ?? '') + '-' + (e.seq ?? i)"
          class="lc__row"
          :class="'lc__row--' + e.kind + ' lc__row--' + e.severity"
        >
          <div class="lc__row-head">
            <span class="lc__row-ts">{{ formatTime(e.ts) }}</span>
            <span class="lc__row-source" v-if="e.kind === 'frontend'">fe</span>
            <span class="lc__row-source" v-else>be</span>
            <span class="lc__row-level" v-if="e.severity">{{ e.severity }}</span>
            <span class="lc__row-status" v-if="e.status">{{ e.status }}</span>
            <span class="lc__row-method" v-if="e.method">{{ e.method }}</span>
            <span class="lc__row-path">{{ e.path || e.location || '' }}</span>
            <button class="lc__row-toggle" @click="toggle(i)">{{ openSet.has(i) ? '▴' : '▾' }}</button>
          </div>
          <div class="lc__row-msg">
            <span v-if="e.kind === 'frontend'" v-html="highlightFe(e.message)" />
            <span v-else>{{ e.message }}</span>
          </div>
          <div v-if="e.class" class="lc__row-class">{{ e.class }}</div>
          <div v-if="openSet.has(i) && e.trace?.length" class="lc__trace">
            <div v-for="(f, fi) in e.trace" :key="fi" class="lc__trace-frame">
              <span class="lc__trace-call">{{ f.call }}</span>
              <span class="lc__trace-loc">{{ f.file }}:{{ f.line }}</span>
            </div>
          </div>
        </article>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
  /** Show panel by default. */
  open: { type: Boolean, default: false },
  /** Poll interval in ms for backend errors. */
  interval: { type: Number, default: 2000 },
})
const emit = defineEmits(['close', 'badge'])

const backendErrors = ref([])   // from GET /api/errors
const frontendLogs  = ref([])   // from console.* intercept
const loading       = ref(false)
const openSet       = ref(new Set())
const captureFrontend = ref(false)
const filter         = ref('all')   // all | backend | frontend
let pollHandle = null
let seqCounter = 0

const entries = computed(() => {
  // Backend first, then frontend, both newest-last
  return [...backendErrors.value, ...frontendLogs.value]
})
const filtered = computed(() => {
  if (filter.value === 'backend') return backendErrors.value
  if (filter.value === 'frontend') return frontendLogs.value
  return entries.value
})
const totalCount = computed(() => entries.value.length)
watch(totalCount, (n) => emit('badge', n), { immediate: true })

async function refresh() {
  loading.value = true
  try {
    const { data } = await axios.get('/api/errors', { params: { limit: 200 } })
    backendErrors.value = Array.isArray(data.errors) ? data.errors : []
  } catch {
    // Don't pollute the console with its own errors
  } finally {
    loading.value = false
  }
}

async function clear() {
  // Clear both backend and frontend
  try { await axios.delete('/api/errors') } catch {}
  backendErrors.value = []
  frontendLogs.value = []
  openSet.value = new Set()
}

async function copyAll() {
  try {
    const text = JSON.stringify(entries.value, null, 2)
    await navigator.clipboard.writeText(text)
    showToast('Copied ' + entries.value.length + ' entries')
  } catch (e) {
    // Fallback for older browsers
    const ta = document.createElement('textarea')
    ta.value = JSON.stringify(entries.value, null, 2)
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
  }
}

function showToast(msg) {
  // Tiny transient indicator in the title — we don't depend on a
  // global toast system.
  const orig = document.title
  document.title = msg
  setTimeout(() => { document.title = orig }, 1500)
}

function toggle(i) {
  const s = new Set(openSet.value)
  if (s.has(i)) s.delete(i); else s.add(i)
  openSet.value = s
}

function formatTime(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleTimeString() + '.' + String(d.getMilliseconds()).padStart(3, '0')
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function highlightFe(s) {
  // Simple %c-style ANSI stripper + token highlighter for console output.
  let out = escapeHtml(s)
  out = out.replace(/%c/g, '')
       .replace(/\b(console|undefined|null|true|false|Error|Warning)\b/g,
         '<span style="color:#f6c64a">$1</span>')
       .replace(/(["'`])([^"'`\n]{1,80})\1/g,
         '<span style="color:#5cd97a">$1$2$1</span>')
  return out
}

// ── Frontend console.* interception ────────────────────────────────
// Saves the originals once, then patches console.log/info/warn/error
// to also append to frontendLogs.
let originals = null
function installConsoleInterceptors() {
  if (originals) return
  originals = {
    log: console.log.bind(console),
    info: console.info.bind(console),
    warn: console.warn.bind(console),
    error: console.error.bind(console),
    debug: console.debug.bind(console),
  }
  const wrap = (level) => (...args) => {
    originals[level](...args)
    // Only capture when panel is open OR we have a recent error
    const msg = args.map(a => {
      if (typeof a === 'string') return a
      try { return JSON.stringify(a) } catch { return String(a) }
    }).join(' ')
    frontendLogs.value.push({
      ts: new Date().toISOString(),
      seq: ++seqCounter,
      kind: 'frontend',
      severity: level,
      message: msg.slice(0, 5000),
      location: location.pathname,
    })
    // Keep at most 200 frontend entries to avoid memory bloat
    if (frontendLogs.value.length > 200) {
      frontendLogs.value = frontendLogs.value.slice(-200)
    }
  }
  console.log = wrap('log')
  console.info = wrap('info')
  console.warn = wrap('warn')
  console.error = wrap('error')
  console.debug = wrap('debug')
}
function uninstallConsoleInterceptors() {
  if (!originals) return
  console.log = originals.log
  console.info = originals.info
  console.warn = originals.warn
  console.error = originals.error
  console.debug = originals.debug
  originals = null
}
watch(captureFrontend, (on) => {
  if (on) installConsoleInterceptors()
  else uninstallConsoleInterceptors()
})

onMounted(() => {
  refresh()
  pollHandle = setInterval(refresh, props.interval)
})
onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
  uninstallConsoleInterceptors()
})
</script>

<style scoped>
.lc {
  position: fixed;
  /* Sits above the ctrl menu (which is at bottom: 48px). */
  bottom: 120px;
  right: 20px;
  z-index: 60;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: #e6e6e6;
}

.lc__handle {
  background: rgba(20, 25, 40, 0.92);
  border: 1px solid rgba(80, 100, 140, 0.5);
  border-radius: 8px;
  padding: 5px 10px;
  color: inherit;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
  /* Reserve space for the badge so the handle doesn't grow/shrink as
     entries come and go. */
  min-width: 150px;
}
.lc__handle:hover { background: rgba(30, 35, 55, 0.95); }

.lc__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #5cd97a;
  box-shadow: 0 0 6px rgba(92, 217, 122, 0.5);
}
.lc__dot--err { background: #ef5b5b; box-shadow: 0 0 6px rgba(239, 91, 91, 0.6); }
.lc__dot--warn { background: #f6c64a; box-shadow: 0 0 6px rgba(246, 198, 74, 0.6); }

.lc__count {
  background: #ef5b5b;
  color: #fff;
  border-radius: 8px;
  padding: 0 6px;
  font-size: 10px;
  min-width: 16px;
  text-align: center;
}

.lc__label { color: #888; }
.lc__label-text { color: #c5f0c5; }

.lc__panel {
  position: absolute;
  bottom: 36px;
  right: 0;
  width: 560px;
  max-height: 70vh;
  background: rgba(15, 20, 35, 0.97);
  border: 1px solid rgba(80, 100, 140, 0.5);
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  overflow: hidden;
}

.lc__head {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  background: rgba(30, 35, 55, 0.95);
  border-bottom: 1px solid rgba(80, 100, 140, 0.4);
  flex-shrink: 0;
}
.lc__title { font-weight: 600; }
.lc__sub { color: #888; font-size: 10px; flex: 1; }

.lc__switch {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-size: 10px;
  color: #888;
  user-select: none;
}
.lc__switch input { display: none; }
.lc__switch-knob {
  position: relative;
  width: 26px;
  height: 14px;
  background: #2a2e36;
  border-radius: 8px;
  transition: background 0.15s;
}
.lc__switch-knob::after {
  content: '';
  position: absolute;
  top: 2px; left: 2px;
  width: 10px; height: 10px;
  background: #e6e6e6;
  border-radius: 50%;
  transition: left 0.15s;
}
.lc__switch input:checked + .lc__switch-knob { background: #5cd97a; }
.lc__switch input:checked + .lc__switch-knob::after { left: 14px; }
.lc__switch-label { text-transform: uppercase; letter-spacing: 0.04em; }

.lc__btn {
  background: rgba(60, 70, 100, 0.4);
  border: 1px solid rgba(80, 100, 140, 0.4);
  color: inherit;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  cursor: pointer;
  font-family: inherit;
}
.lc__btn:hover { background: rgba(80, 100, 140, 0.5); }
.lc__btn:disabled { opacity: 0.4; cursor: not-allowed; }

.lc__tabs {
  display: flex;
  gap: 4px;
  padding: 4px 10px;
  background: rgba(25, 30, 45, 0.9);
  border-bottom: 1px solid rgba(80, 100, 140, 0.3);
  flex-shrink: 0;
}
.lc__tab {
  background: transparent;
  border: 1px solid rgba(80, 100, 140, 0.3);
  color: #888;
  padding: 2px 10px;
  border-radius: 4px;
  font-size: 10px;
  cursor: pointer;
  font-family: inherit;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.lc__tab.on { background: rgba(92, 217, 122, 0.18); color: #5cd97a; border-color: rgba(92, 217, 122, 0.4); }

.lc__list {
  overflow-y: auto;
  flex: 1;
  padding: 4px;
}

.lc__empty {
  text-align: center;
  padding: 24px 12px;
  color: #888;
  font-size: 11px;
  line-height: 1.5;
}

.lc__row {
  background: rgba(20, 25, 40, 0.6);
  border: 1px solid rgba(80, 100, 140, 0.3);
  border-radius: 4px;
  padding: 5px 8px;
  margin-bottom: 4px;
  font-size: 11px;
  border-left-width: 3px;
}
.lc__row--backend.lc__row--error { border-left-color: #ef5b5b; }
.lc__row--backend.lc__row--warning { border-left-color: #f6c64a; }
.lc__row--frontend.lc__row--error,
.lc__row--frontend.lc__row--warn { border-left-color: #f08080; }
.lc__row--frontend.lc__row--info { border-left-color: #6da0ff; }
.lc__row--frontend.lc__row--log { border-left-color: #5cd97a; }
.lc__row--frontend.lc__row--debug { border-left-color: #888; }

.lc__row-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 2px;
}
.lc__row-ts { color: #888; font-variant-numeric: tabular-nums; }
.lc__row-source {
  background: rgba(60, 70, 100, 0.4);
  padding: 0 5px;
  border-radius: 3px;
  font-size: 10px;
  color: #c5f0c5;
}
.lc__row-level {
  padding: 0 5px;
  border-radius: 3px;
  font-size: 10px;
  text-transform: uppercase;
  background: rgba(60, 70, 100, 0.4);
  color: #c5f0c5;
}
.lc__row-status {
  background: #ef5b5b;
  color: #fff;
  padding: 0 5px;
  border-radius: 3px;
  font-size: 10px;
}
.lc__row-method { color: #6da0ff; font-weight: 600; }
.lc__row-path { color: #c5f0c5; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lc__row-toggle {
  background: transparent;
  border: 0;
  color: #888;
  cursor: pointer;
  padding: 0 4px;
}

.lc__row-msg { color: #f0c5c5; word-break: break-word; }
.lc__row--warning .lc__row-msg { color: #f0e0a8; }
.lc__row--frontend.lc__row--error .lc__row-msg,
.lc__row--frontend.lc__row--warn  .lc__row-msg { color: #f0c5c5; }
.lc__row--frontend.lc__row--info  .lc__row-msg { color: #c5d0f0; }
.lc__row--frontend.lc__row--log   .lc__row-msg { color: #c5f0c5; }
.lc__row-class { color: #888; font-size: 10px; margin-top: 2px; }

.lc__trace {
  margin-top: 4px;
  padding-top: 4px;
  border-top: 1px solid rgba(80, 100, 140, 0.3);
  font-size: 10px;
}
.lc__trace-frame {
  display: flex;
  gap: 6px;
  padding: 1px 0;
}
.lc__trace-call { color: #c5f0c5; }
.lc__trace-loc { color: #888; margin-left: auto; }
</style>