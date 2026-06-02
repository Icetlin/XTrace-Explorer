<template>
  <div class="settings-page">
    <!-- Sidebar -->
    <nav class="settings-nav">
      <button
        v-for="s in sections"
        :key="s.id"
        class="nav-item"
        :class="{ 'nav-item--active': activeSection === s.id }"
        @click="activeSection = s.id"
      >
        <span class="nav-icon">{{ s.icon }}</span>
        <span class="nav-label">{{ s.label }}</span>
      </button>
    </nav>

    <!-- Content -->
    <div class="settings-content">

      <!-- ── General ── -->
      <template v-if="activeSection === 'general'">
        <div class="section-title">General</div>

        <div class="field-group">
          <label class="field-label">Traces directory</label>
          <div class="field-desc">
            Host path to the folder where xdebug writes <code>.xt</code> files.
            Saved to <code>docker-compose.yml</code> — requires container restart to take effect.
          </div>
          <input v-model="form.traces_host_path" class="field-input" placeholder="/path/to/xdebug_traces" spellcheck="false" />
        </div>

        <div class="field-group">
          <label class="field-label">Project name</label>
          <div class="field-desc">Short label shown in the UI for context.</div>
          <input v-model="form.project_name" class="field-input field-input--short" placeholder="My App" spellcheck="false" />
        </div>

        <div class="field-group">
          <label class="field-label">Project source path</label>
          <div class="field-desc">
            Host path to your PHP project root — used to resolve relative file paths in trace nodes.
          </div>
          <input v-model="form.project_path" class="field-input" placeholder="/home/me/projects/my-app" spellcheck="false" />
        </div>

        <div class="field-group">
          <label class="field-label">App namespaces</label>
          <div class="field-desc">
            PHP namespaces that belong to your project. Listeners and events from these namespaces
            get a custom badge in the TOC instead of being treated as unknown vendor code.
          </div>
          <div v-for="(ns, idx) in form.app_namespaces" :key="idx" class="ns-row">
            <input v-model="ns.namespace" class="field-input" placeholder="App\" spellcheck="false" />
            <input v-model="ns.label" class="field-input field-input--label" placeholder="app" spellcheck="false" />
            <button class="fav-del ns-del" @click="removeNamespace(idx)">✕</button>
          </div>
          <button class="btn btn--add ns-add" @click="addNamespace">+ Add namespace</button>
        </div>

        <div class="btn-row">
          <button class="btn btn--save" :disabled="saving" @click="save">
            <span v-if="saving" class="spinner" />
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
          <button
            class="btn btn--restart"
            :disabled="restarting || !savedOnce"
            :title="savedOnce ? 'Restart container to apply volume changes' : 'Save settings first'"
            @click="restart"
          >
            <span v-if="restarting" class="spinner" />
            {{ restarting ? 'Restarting…' : 'Restart container' }}
          </button>
        </div>

        <transition name="toast"><div v-if="toast" class="toast" :class="'toast--' + toast.type">{{ toast.msg }}</div></transition>

        <div class="howto">
          <div class="howto-title">Setup guide</div>
          <div class="howto-step"><span class="step-num">1</span><span>Configure xdebug in your PHP app to write trace files. In <code>php.ini</code>:</span></div>
          <pre class="code-block">xdebug.mode = trace
xdebug.output_dir = /path/to/xdebug_traces
xdebug.trace_output_name = trace.%t.%p</pre>
          <div class="howto-step"><span class="step-num">2</span><span>Set <strong>Traces directory</strong> above to that same path on your host.</span></div>
          <div class="howto-step"><span class="step-num">3</span><span>Click <strong>Save</strong> — this patches <code>docker-compose.yml</code> with the new volume mount.</span></div>
          <div class="howto-step"><span class="step-num">4</span><span>Click <strong>Restart container</strong> — Docker remounts the volume. New traces appear in <strong>+</strong> file browser.</span></div>
          <div class="howto-step"><span class="step-num">5</span><span>To trigger a trace: enable xdebug tracing for one request (e.g. via a browser extension or <code>XDEBUG_TRIGGER=1</code> cookie), then open the resulting <code>.xt</code> file here.</span></div>
        </div>
      </template>

      <!-- ── Favourites ── -->
      <template v-if="activeSection === 'favourites'">
        <div class="section-title">Tracked patterns</div>
        <div class="section-desc">
          Patterns highlighted in the TOC and call tree. Right-click any call node to add one, or add manually here.
          Matched nodes get colored badges and bubble-up indicators in their parent listeners.
        </div>

        <div class="fav-add">
          <input
            v-model="newPattern"
            class="field-input"
            placeholder="pattern  (e.g. sio_u, setCookie, kernel.response)"
            spellcheck="false"
            @keydown.enter="addFav"
          />
          <input
            v-model="newLabel"
            class="field-input field-input--label"
            placeholder="label (optional)"
            spellcheck="false"
            @keydown.enter="addFav"
          />
          <button class="btn btn--add" @click="addFav">+ Add</button>
        </div>

        <div v-if="!store.favourites.length" class="empty-msg">
          No tracked patterns yet.
        </div>

        <div v-for="fav in store.favourites" :key="fav.id" class="fav-row">
          <span class="fav-dot" :style="{ background: favColor(fav.pattern).borderLeft }"></span>
          <span class="fav-pattern" :style="{ color: favColor(fav.pattern).text }">{{ fav.pattern }}</span>
          <span v-if="fav.label" class="fav-label" :style="{ color: favColor(fav.pattern).textDim, background: favColor(fav.pattern).bg, borderColor: favColor(fav.pattern).border }">{{ fav.label }}</span>
          <button class="fav-del" @click="store.deleteFavourite(fav.id)">✕</button>
        </div>
      </template>

      <!-- ── Xdebug ── -->
      <template v-if="activeSection === 'xdebug'">
        <div class="section-title">Xdebug mode</div>

        <div class="xd-status-row">
          <span class="xd-status-label">Current mode:</span>
          <span class="xd-status-val" :class="'xd-mode--' + xdStatus">{{ xdStatusLabel }}</span>
          <button class="xd-refresh" :disabled="xdLoading" @click="refreshXdStatus" title="Refresh">⟳</button>
        </div>

        <div class="xd-cards">
          <button
            v-for="m in xdModes"
            :key="m.id"
            class="xd-card"
            :class="{ 'xd-card--active': xdStatus === m.id, 'xd-card--loading': xdSetting === m.id }"
            :disabled="xdLoading || xdStatus === m.id"
            @click="setXdMode(m.id)"
          >
            <span class="xd-card-icon">{{ m.icon }}</span>
            <span class="xd-card-name">{{ m.label }}</span>
            <span class="xd-card-desc">{{ m.desc }}</span>
            <span v-if="xdSetting === m.id" class="xd-card-spinner spinner" />
          </button>
        </div>

        <div v-if="xdStatus === 'debug+trace'" class="xd-clear-row">
          <button class="btn btn--danger" :disabled="xdLoading" @click="clearTraces">Clear trace files</button>
          <span class="xd-clear-hint">Deletes <code>trace_*.xt</code> from the container trace dir</span>
        </div>

        <transition name="toast"><div v-if="toast" class="toast" :class="'toast--' + toast.type">{{ toast.msg }}</div></transition>
      </template>

      <!-- ── Listener Filters ── -->
      <template v-if="activeSection === 'filters'">
        <div class="section-title">Listener filters</div>
        <div class="section-desc">
          Listeners whose full class name contains any of these patterns will be hidden from the TOC.
          Useful to suppress noisy infrastructure listeners (Stopwatch, Profiler, Security voters, etc.).
          Changes take effect immediately — no restart needed.
        </div>

        <div class="filter-add">
          <input
            v-model="newFilter"
            class="field-input"
            placeholder="substring to match  (e.g. Stopwatch, DataCollector, Profiler)"
            spellcheck="false"
            @keydown.enter="addFilter"
          />
          <button class="btn btn--add" @click="addFilter">+ Add</button>
        </div>

        <div v-if="!store.listenerFilters.length" class="empty-msg">
          No filters yet — all listeners are shown.
        </div>

        <div v-for="f in store.listenerFilters" :key="f" class="filter-row">
          <span class="filter-icon">⊘</span>
          <span class="filter-text">{{ f }}</span>
          <button class="fav-del" @click="removeFilter(f)">✕</button>
        </div>

        <transition name="toast"><div v-if="toast" class="toast" :class="'toast--' + toast.type">{{ toast.msg }}</div></transition>

        <div class="howto">
          <div class="howto-title">Examples</div>
          <div class="filter-example" v-for="ex in filterExamples" :key="ex.pattern">
            <button class="ex-add" @click="addFilterValue(ex.pattern)" :disabled="store.listenerFilters.includes(ex.pattern)">+</button>
            <code class="ex-pattern">{{ ex.pattern }}</code>
            <span class="ex-desc">{{ ex.desc }}</span>
          </div>
        </div>
      </template>

      <!-- ── AI / MCP ── -->
      <template v-if="activeSection === 'mcp'">
        <div class="section-title">AI / MCP</div>
        <div class="section-desc">
          Connect any MCP-compatible AI assistant to XTrace Explorer via Server-Sent Events (SSE).
          The MCP server exposes tools for trace analysis, parser validation, worker restart, and log inspection.
        </div>

        <div class="mcp-server-status" :class="mcpStatusClass">
          <span class="mcp-status-dot" />
          <span class="mcp-status-text">{{ mcpStatusText }}</span>
          <span v-if="mcpStatus?.ok && mcpStatus.connections > 0" class="mcp-status-badge">
            {{ mcpStatus.connections }} connected
          </span>
          <span v-else-if="mcpStatus?.ok && mcpStatus.total_tool_calls > 0" class="mcp-status-badge mcp-status-badge--dim">
            {{ mcpStatus.total_tool_calls }} calls total
          </span>
        </div>

        <div class="mcp-url-block">
          <span class="mcp-url-label">SSE endpoint</span>
          <code class="mcp-url-val">{{ mcpSseUrl }}</code>
        </div>

        <div class="mcp-clients">
          <div v-for="client in mcpClients" :key="client.id" class="mcp-card">
            <div class="mcp-card__header">
              <span class="mcp-card__name">{{ client.name }}</span>
              <span class="mcp-card__desc">{{ client.desc }}</span>
              <button
                class="mcp-card__copy"
                :class="{ 'mcp-card__copy--ok': mcpCopiedId === client.id }"
                @click="mcpCopy(client)"
              >
                {{ mcpCopiedId === client.id ? '✓ Copied' : 'Copy' }}
              </button>
            </div>
            <pre class="mcp-card__cmd">{{ client.cmd }}</pre>
          </div>
        </div>

        <div class="howto">
          <div class="howto-title">Available MCP tools</div>
          <div class="mcp-tools-grid">
            <div class="mcp-tool" v-for="t in mcpTools" :key="t.name">
              <code class="mcp-tool__name">{{ t.name }}</code>
              <span class="mcp-tool__desc">{{ t.desc }}</span>
            </div>
          </div>
        </div>
      </template>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'

import { useTraceStore } from '../stores/trace'
import { favColor } from '../favColor.js'
import axios from 'axios'

const store = useTraceStore()

const sections = [
  { id: 'general',    icon: '⚙', label: 'General' },
  { id: 'favourites', icon: '★', label: 'Favourites' },
  { id: 'filters',    icon: '⊘', label: 'Listener filters' },
  { id: 'xdebug',    icon: '⚡', label: 'Xdebug' },
  { id: 'mcp',        icon: '⬡', label: 'AI / MCP' },
]
const activeSection = ref('general')

const form = ref({ traces_host_path: '', project_path: '', project_name: '', app_namespaces: [] })
const saving = ref(false)
const restarting = ref(false)
const savedOnce = ref(false)
const toast = ref(null)
let toastTimer = null

// Favourites
const newPattern = ref('')
const newLabel = ref('')

// Filters
const newFilter = ref('')

// Xdebug
const xdStatus  = ref(null)   // null | 'off' | 'debug' | 'debug+trace'
const xdLoading = ref(false)
const xdSetting = ref(null)
const xdModes = [
  { id: 'off',         icon: '○', label: 'Off',         desc: 'xdebug disabled entirely' },
  { id: 'debug',       icon: '⬤', label: 'Debug only',  desc: 'Breakpoints on XDEBUG_TRIGGER' },
  { id: 'debug+trace', icon: '◉', label: 'Debug+Trace',  desc: 'Full trace file per request' },
]
const xdStatusLabel = computed(() => {
  if (xdStatus.value === null) return '…'
  if (xdStatus.value === 'off') return 'off'
  if (xdStatus.value === 'debug') return 'debug'
  if (xdStatus.value === 'debug+trace') return 'debug + trace'
  return xdStatus.value
})

const filterExamples = [
  { pattern: 'DataCollector',      desc: 'Symfony profiler data collectors' },
  { pattern: 'Stopwatch',          desc: 'Stopwatch-based instrumentation listeners' },
  { pattern: 'Monolog',            desc: 'Log handlers and processors' },
  { pattern: 'FirewallListener',   desc: 'Security firewall checks' },
  { pattern: 'RouterListener',     desc: 'Routing listener' },
  { pattern: 'LocaleListener',     desc: 'Locale negotiation' },
  { pattern: 'SessionListener',    desc: 'Session management' },
  { pattern: 'CacheListener',      desc: 'HTTP cache listeners' },
]

onMounted(async () => {
  await store.loadFavourites()
  try {
    const data = await store.loadSettings()
    form.value = {
      traces_host_path: data.traces_host_path || '',
      project_path:     data.project_path     || '',
      project_name:     data.project_name     || '',
      app_namespaces:   data.app_namespaces ? JSON.parse(JSON.stringify(data.app_namespaces)) : [],
    }
    savedOnce.value = !!(data.traces_host_path || data.project_path)
    refreshXdStatus()
  } catch {}
})

function showToast(msg, type = 'ok') {
  clearTimeout(toastTimer)
  toast.value = { msg, type }
  toastTimer = setTimeout(() => { toast.value = null }, 3500)
}

async function save() {
  saving.value = true
  try {
    const data = await store.saveSettings(formPayload())
    savedOnce.value = true
    showToast(data.compose_patched ? 'Saved — docker-compose.yml updated' : 'Saved')
  } catch {
    showToast('Failed to save', 'err')
  } finally {
    saving.value = false
  }
}

async function restart() {
  restarting.value = true
  try {
    await axios.post('/api/settings/restart')
    showToast('Restart signal sent — page will reload in 8s')
    setTimeout(() => window.location.reload(), 8000)
  } catch {
    showToast('Restart failed — run "docker compose restart app" manually', 'err')
  } finally {
    restarting.value = false
  }
}

function addNamespace() {
  form.value.app_namespaces = [...form.value.app_namespaces, { namespace: '', label: '' }]
}

function removeNamespace(idx) {
  form.value.app_namespaces = form.value.app_namespaces.filter((_, i) => i !== idx)
}

async function addFav() {
  const p = newPattern.value.trim()
  if (!p) return
  await store.addFavourite(p, newLabel.value.trim() || null)
  newPattern.value = ''
  newLabel.value = ''
}

async function addFilter() {
  const v = newFilter.value.trim()
  if (!v) return
  newFilter.value = ''
  await store.addListenerFilter(v)
}

async function addFilterValue(v) {
  await store.addListenerFilter(v)
}

function formPayload(overrides = {}) {
  return {
    traces_host_path: form.value.traces_host_path,
    project_path:     form.value.project_path,
    project_name:     form.value.project_name,
    app_namespaces:   form.value.app_namespaces,
    listener_filters: store.listenerFilters,
    ...overrides,
  }
}

async function refreshXdStatus() {
  xdLoading.value = true
  try {
    const { data } = await axios.get('/api/xdebug/status')
    xdStatus.value = data.running ? (data.mode || 'off') : null
  } catch {
    xdStatus.value = null
  } finally {
    xdLoading.value = false
  }
}

async function setXdMode(mode) {
  xdSetting.value = mode
  xdLoading.value = true
  try {
    const { data } = await axios.post('/api/xdebug/set', { mode })
    if (data.ok) {
      xdStatus.value = mode
      showToast(`Xdebug set to: ${mode}`)
    } else {
      showToast(data.error || 'Failed', 'err')
    }
  } catch (e) {
    showToast('Request failed', 'err')
  } finally {
    xdSetting.value = null
    xdLoading.value = false
  }
}

async function clearTraces() {
  xdLoading.value = true
  try {
    const { data } = await axios.post('/api/xdebug/clear')
    showToast(data.ok ? 'Trace files cleared' : (data.output || 'Error'), data.ok ? 'ok' : 'err')
  } catch {
    showToast('Clear failed', 'err')
  } finally {
    xdLoading.value = false
  }
}

async function removeFilter(pattern) {
  await store.saveSettings(formPayload({ listener_filters: store.listenerFilters.filter(f => f !== pattern) }))
}

// ── MCP ──
const mcpCopiedId = ref(null)
const mcpStatus = ref(null)   // null = unknown, { ok, connections } = fetched
let mcpPollTimer = null

async function fetchMcpStatus() {
  const url = `http://${window.location.hostname}:8766/status`
  try {
    const r = await fetch(url, { signal: AbortSignal.timeout(2500) })
    mcpStatus.value = r.ok ? await r.json() : { ok: false, connections: 0 }
  } catch {
    mcpStatus.value = { ok: false, connections: 0 }
  }
}

watch(() => activeSection.value, (s) => {
  if (s === 'mcp') {
    fetchMcpStatus()
    mcpPollTimer = setInterval(fetchMcpStatus, 5000)
  } else {
    clearInterval(mcpPollTimer)
  }
})

onUnmounted(() => clearInterval(mcpPollTimer))

const mcpStatusClass = computed(() => {
  if (mcpStatus.value === null) return 'mcp-server-status--unknown'
  if (!mcpStatus.value.ok) return 'mcp-server-status--offline'
  if (mcpStatus.value.connections > 0) return 'mcp-server-status--active'
  if (mcpStatus.value.last_tool_call_ago !== null && mcpStatus.value.last_tool_call_ago < 300) return 'mcp-server-status--recent'
  return 'mcp-server-status--online'
})

const mcpStatusText = computed(() => {
  if (mcpStatus.value === null) return 'Checking…'
  if (!mcpStatus.value.ok) return 'MCP server offline'
  if (mcpStatus.value.connections > 0) return 'MCP server online'
  const ago = mcpStatus.value.last_tool_call_ago
  if (ago === null) return 'MCP server online — never used'
  if (ago < 60) return `MCP server online — last used ${ago}s ago`
  if (ago < 3600) return `MCP server online — last used ${Math.round(ago / 60)}m ago`
  return 'MCP server online — no active connections'
})

const mcpSseUrl = computed(() => {
  const host = window.location.hostname
  return `http://${host}:8766/sse`
})

const mcpClients = computed(() => [
  {
    id: 'claude-code',
    name: 'Claude Code',
    desc: 'Run in terminal',
    cmd: `claude mcp add xtrace --transport sse ${mcpSseUrl.value} --scope user`,
  },
  {
    id: 'vscode',
    name: 'VS Code',
    desc: 'Add to settings.json → "mcp" key',
    cmd: `"xtrace": { "type": "sse", "url": "${mcpSseUrl.value}" }`,
  },
  {
    id: 'cursor',
    name: 'Cursor',
    desc: 'Add to .cursor/mcp.json',
    cmd: `"xtrace": { "url": "${mcpSseUrl.value}" }`,
  },
  {
    id: 'windsurf',
    name: 'Windsurf',
    desc: 'Add to ~/.codeium/windsurf/mcp_config.json',
    cmd: `"xtrace": { "serverUrl": "${mcpSseUrl.value}", "disabled": false }`,
  },
])

async function mcpCopy(client) {
  await navigator.clipboard.writeText(client.cmd)
  mcpCopiedId.value = client.id
  setTimeout(() => { mcpCopiedId.value = null }, 1500)
}

const mcpTools = [
  { name: 'get_files',       desc: 'List all trace files with status' },
  { name: 'get_toc',         desc: 'Events + listeners for a trace file' },
  { name: 'get_children',    desc: 'Child calls at a given line/depth' },
  { name: 'get_meta',        desc: 'Total lines, request/response info' },
  { name: 'search_trace',    desc: 'Search by signature fragment' },
  { name: 'get_schema',      desc: 'Aggregated call schema for selected items' },
  { name: 'reparse',         desc: 'Trigger reparse and wait for ready' },
  { name: 'restart_worker',  desc: 'Restart Messenger async worker' },
  { name: 'run_tests',       desc: 'Run PHPUnit inside container' },
  { name: 'validate_toc',    desc: 'Detect broken parser output' },
  { name: 'get_logs',        desc: 'Last N lines of container logs' },
  { name: 'get_worker_logs', desc: 'Worker/parse error lines only' },
]
</script>

<style scoped>
.settings-page {
  display: flex;
  height: 100%;
  font-family: 'JetBrains Mono', monospace;
  overflow: hidden;
}

/* ── Sidebar ── */
.settings-nav {
  width: 164px;
  flex-shrink: 0;
  border-right: 1px solid rgba(40, 40, 80, 0.35);
  padding: 24px 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: rgba(255, 255, 255, 0.018);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 18px;
  background: none;
  border: none;
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  color: #5a5a80;
  text-align: left;
  border-left: 2px solid transparent;
  transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.nav-item:hover {
  color: #8a9ec0;
  background: rgba(255, 255, 255, 0.028);
}
.nav-item--active {
  color: #aad0f0;
  border-left-color: rgba(90, 145, 210, 0.75);
  background: rgba(255, 255, 255, 0.045);
}

.nav-icon { font-size: 13px; width: 16px; text-align: center; flex-shrink: 0; opacity: 0.7; }
.nav-label { white-space: nowrap; }

/* ── Content area ── */
.settings-content {
  flex: 1;
  overflow-y: auto;
  padding: 30px 40px;
  max-width: 680px;
  background: rgba(255, 255, 255, 0.022);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}

.section-title {
  font-size: 10px;
  font-weight: 700;
  color: rgba(140, 165, 210, 0.65);
  text-transform: uppercase;
  letter-spacing: 0.14em;
  margin-bottom: 26px;
}

.section-desc {
  font-size: 12px;
  color: rgba(160, 165, 205, 0.55);
  line-height: 1.75;
  margin-bottom: 22px;
}

/* ── Fields ── */
.field-group { margin-bottom: 24px; }

.field-label {
  display: block;
  font-size: 11.5px;
  font-weight: 600;
  color: rgba(160, 190, 230, 0.85);
  margin-bottom: 5px;
  letter-spacing: 0.03em;
}

.field-desc {
  font-size: 11px;
  color: rgba(145, 150, 195, 0.6);
  line-height: 1.65;
  margin-bottom: 9px;
}
.field-desc code {
  color: rgba(100, 150, 210, 0.65);
  background: rgba(255, 255, 255, 0.04);
  padding: 1px 5px;
  border-radius: 3px;
  border: 1px solid rgba(80, 110, 160, 0.18);
}

.field-input {
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(60, 70, 110, 0.35);
  border-radius: 8px;
  color: rgba(160, 185, 215, 0.85);
  font-family: monospace;
  font-size: 12.5px;
  padding: 9px 14px;
  width: 100%;
  box-sizing: border-box;
  outline: none;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.field-input::placeholder { color: rgba(80, 90, 130, 0.5); }
.field-input:focus {
  border-color: rgba(80, 130, 200, 0.55);
  background: rgba(255, 255, 255, 0.055);
  box-shadow: 0 0 0 2px rgba(80, 130, 200, 0.1), inset 0 1px 0 rgba(255,255,255,0.04);
}
.field-input--short { width: 200px; }
.field-input--label { width: 130px; flex-shrink: 0; }

.ns-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 6px;
}
.ns-row .field-input { flex: 1; width: auto; }
.ns-del { flex-shrink: 0; }
.ns-add { margin-top: 4px; }

/* ── Buttons ── */
.btn-row {
  display: flex;
  gap: 10px;
  margin-top: 8px;
  flex-wrap: wrap;
}
.btn-row--top { margin-top: 16px; margin-bottom: 0; }

.btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 9px 18px;
  border-radius: 8px;
  font-family: monospace;
  font-size: 12px;
  cursor: pointer;
  border: 1px solid;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  transition: opacity 0.15s, background 0.15s, box-shadow 0.15s;
}
.btn:disabled { opacity: 0.3; cursor: not-allowed; }

.btn--save {
  background: rgba(20, 50, 90, 0.55);
  color: rgba(130, 185, 255, 0.9);
  border-color: rgba(60, 110, 180, 0.4);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
}
.btn--save:not(:disabled):hover {
  background: rgba(25, 60, 105, 0.65);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 2px 8px rgba(60,110,180,0.2);
}

.btn--restart {
  background: rgba(40, 15, 70, 0.55);
  color: rgba(185, 140, 255, 0.9);
  border-color: rgba(90, 50, 150, 0.4);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
}
.btn--restart:not(:disabled):hover {
  background: rgba(48, 20, 85, 0.65);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.07), 0 2px 8px rgba(90,50,150,0.2);
}

.btn--add {
  background: rgba(255, 255, 255, 0.04);
  color: rgba(100, 115, 155, 0.7);
  border-color: rgba(60, 65, 100, 0.35);
  white-space: nowrap;
}
.btn--add:hover {
  color: rgba(160, 200, 255, 0.85);
  border-color: rgba(70, 120, 190, 0.5);
  background: rgba(255, 255, 255, 0.06);
}

.spinner {
  width: 10px; height: 10px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Toast ── */
.toast {
  margin-top: 14px;
  padding: 9px 14px;
  border-radius: 8px;
  font-size: 12px;
  border: 1px solid;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}
.toast--ok  {
  color: rgba(120, 210, 120, 0.9);
  background: rgba(20, 60, 20, 0.5);
  border-color: rgba(40, 100, 40, 0.4);
}
.toast--err {
  color: rgba(210, 100, 100, 0.9);
  background: rgba(60, 15, 15, 0.5);
  border-color: rgba(100, 35, 35, 0.4);
}
.toast-enter-active, .toast-leave-active { transition: opacity 0.25s, transform 0.25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-4px); }

/* ── Howto ── */
.howto {
  margin-top: 36px;
  padding-top: 24px;
  border-top: 1px solid rgba(40, 40, 80, 0.3);
}

.howto-title {
  font-size: 9.5px;
  font-weight: 700;
  color: rgba(110, 125, 170, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.12em;
  margin-bottom: 16px;
}

.howto-step {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  font-size: 11.5px;
  color: rgba(145, 150, 195, 0.65);
  line-height: 1.7;
  margin-bottom: 12px;
}
.howto-step strong { color: rgba(165, 185, 220, 0.8); }
.howto-step code {
  color: rgba(120, 170, 230, 0.75);
  background: rgba(255, 255, 255, 0.04);
  padding: 1px 5px;
  border-radius: 3px;
  border: 1px solid rgba(70, 100, 150, 0.2);
}

.step-num {
  width: 20px; height: 20px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(50, 55, 90, 0.5);
  color: rgba(80, 90, 135, 0.6);
  font-size: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
}

.code-block {
  background: rgba(0, 0, 0, 0.3);
  border: 1px solid rgba(50, 60, 100, 0.3);
  border-radius: 7px;
  color: rgba(100, 145, 185, 0.7);
  font-size: 11px;
  padding: 11px 15px;
  margin: 8px 0 14px;
  font-family: monospace;
  line-height: 1.65;
  white-space: pre;
  overflow-x: auto;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}

/* ── Favourites ── */
.fav-add {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
  align-items: center;
  flex-wrap: wrap;
}

.empty-msg {
  color: rgba(70, 75, 115, 0.5);
  font-size: 12px;
  font-style: italic;
  padding: 10px 0;
}

.fav-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 14px;
  border-radius: 8px;
  border: 1px solid rgba(35, 38, 65, 0.6);
  margin-bottom: 5px;
  background: rgba(255, 255, 255, 0.03);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  transition: background 0.12s, border-color 0.12s;
}
.fav-row:hover {
  background: rgba(255, 255, 255, 0.045);
  border-color: rgba(55, 65, 110, 0.7);
}

.fav-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.fav-pattern { flex: 1; font-size: 13px; font-weight: 500; }
.fav-label {
  font-size: 10px;
  border: 1px solid;
  border-radius: 4px;
  padding: 2px 8px;
  flex-shrink: 0;
}

.fav-del {
  background: none; border: none;
  color: rgba(50, 50, 85, 0.6); font-size: 11px;
  cursor: pointer;
  padding: 3px 7px;
  border-radius: 4px;
  line-height: 1;
  transition: color 0.1s, background 0.1s;
}
.fav-del:hover { color: rgba(210, 90, 90, 0.85); background: rgba(120, 20, 20, 0.2); }

/* ── Listener filters ── */
.filter-add {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
  align-items: center;
}

.filter-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 14px;
  border-radius: 8px;
  border: 1px solid rgba(35, 38, 65, 0.6);
  margin-bottom: 5px;
  background: rgba(255, 255, 255, 0.028);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  transition: background 0.12s;
}
.filter-row:hover { background: rgba(255, 255, 255, 0.042); }

.filter-icon { color: rgba(80, 85, 140, 0.55); font-size: 13px; flex-shrink: 0; }
.filter-text { flex: 1; font-size: 13px; color: rgba(140, 155, 210, 0.85); font-weight: 500; }

.filter-example {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px solid rgba(30, 30, 55, 0.4);
  font-size: 11.5px;
}
.ex-add {
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(50, 55, 90, 0.45);
  color: rgba(80, 90, 140, 0.65);
  border-radius: 4px;
  width: 22px; height: 22px;
  cursor: pointer; font-size: 14px; line-height: 1;
  flex-shrink: 0;
  transition: color 0.1s, border-color 0.1s, background 0.1s;
}
.ex-add:not(:disabled):hover {
  color: rgba(160, 200, 255, 0.85);
  border-color: rgba(70, 120, 190, 0.55);
  background: rgba(255, 255, 255, 0.06);
}
.ex-add:disabled { opacity: 0.25; cursor: default; }
.ex-pattern {
  color: rgba(130, 170, 220, 0.85);
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(70, 105, 160, 0.3);
  padding: 2px 7px;
  border-radius: 4px;
  flex-shrink: 0;
}
.ex-desc { color: rgba(120, 125, 170, 0.6); }


/* ── Xdebug section ── */
.xd-status-block {
  margin-top: 28px;
  padding: 18px 20px;
  border-radius: 10px;
  border: 1px solid rgba(40, 50, 100, 0.4);
  background: rgba(255, 255, 255, 0.022);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
}

.xd-status-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 18px;
  font-size: 12px;
}
.xd-status-label { color: rgba(90, 100, 150, 0.7); }
.xd-status-val {
  font-weight: 700;
  font-size: 12px;
  padding: 2px 10px;
  border-radius: 5px;
  border: 1px solid;
}
.xd-mode--off         { color: rgba(100, 100, 140, 0.7); border-color: rgba(60,60,100,0.3); background: rgba(255,255,255,0.025); }
.xd-mode--debug       { color: rgba(90, 190, 120, 0.9);  border-color: rgba(40,130,70,0.35); background: rgba(20,60,30,0.35); }
.xd-mode--debug\+trace { color: rgba(255, 190, 60, 0.95); border-color: rgba(170,120,20,0.4); background: rgba(60,40,5,0.5); }

.xd-refresh {
  background: none; border: none; cursor: pointer;
  color: rgba(70, 85, 135, 0.6); font-size: 15px; line-height: 1;
  padding: 2px 5px; border-radius: 4px;
  transition: color 0.12s;
}
.xd-refresh:hover { color: rgba(130, 170, 220, 0.9); }
.xd-refresh:disabled { opacity: 0.3; cursor: default; }

.xd-cards {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.xd-card {
  flex: 1; min-width: 120px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 9px;
  border: 1px solid rgba(40, 45, 80, 0.55);
  background: rgba(255, 255, 255, 0.03);
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  text-align: left;
  transition: border-color 0.14s, background 0.14s, box-shadow 0.14s;
}
.xd-card:not(:disabled):hover {
  border-color: rgba(70, 120, 200, 0.5);
  background: rgba(255, 255, 255, 0.052);
  box-shadow: 0 2px 12px rgba(50, 90, 180, 0.12);
}
.xd-card:disabled { opacity: 0.45; cursor: default; }
.xd-card--active {
  border-color: rgba(80, 150, 230, 0.55);
  background: rgba(30, 60, 120, 0.2);
  box-shadow: 0 0 0 1px rgba(80, 150, 230, 0.2);
}
.xd-card--loading { opacity: 0.65; }

.xd-card-icon { font-size: 17px; color: rgba(100, 140, 200, 0.75); }
.xd-card--active .xd-card-icon { color: rgba(100, 190, 255, 0.9); }
.xd-card-name { font-size: 12px; font-weight: 700; color: rgba(160, 175, 220, 0.9); }
.xd-card-desc { font-size: 10.5px; color: rgba(90, 100, 150, 0.65); line-height: 1.5; }
.xd-card-spinner { margin-top: 4px; }

.xd-clear-row {
  margin-top: 16px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.btn--danger {
  background: rgba(80, 10, 10, 0.45);
  color: rgba(220, 100, 100, 0.9);
  border-color: rgba(130, 35, 35, 0.4);
}
.btn--danger:not(:disabled):hover {
  background: rgba(100, 15, 15, 0.55);
  border-color: rgba(160, 50, 50, 0.5);
}
.xd-clear-hint { font-size: 11px; color: rgba(80, 85, 135, 0.6); }
.xd-clear-hint code { color: rgba(120, 150, 200, 0.7); }

/* ── MCP ── */
.mcp-server-status {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  border-radius: 8px;
  border: 1px solid;
  margin-bottom: 16px;
  font-size: 12px;
  transition: border-color 0.2s, background 0.2s;
}
.mcp-server-status--unknown {
  border-color: rgba(50, 55, 90, 0.4);
  background: rgba(255, 255, 255, 0.02);
  color: rgba(80, 90, 135, 0.6);
}
.mcp-server-status--offline {
  border-color: rgba(120, 35, 35, 0.4);
  background: rgba(60, 10, 10, 0.25);
  color: rgba(200, 90, 90, 0.8);
}
.mcp-server-status--online {
  border-color: rgba(40, 100, 65, 0.4);
  background: rgba(15, 45, 28, 0.3);
  color: rgba(90, 175, 120, 0.8);
}
.mcp-server-status--recent {
  border-color: rgba(40, 100, 65, 0.45);
  background: rgba(15, 45, 28, 0.3);
  color: rgba(90, 175, 120, 0.8);
}
.mcp-server-status--active {
  border-color: rgba(50, 150, 90, 0.55);
  background: rgba(20, 65, 40, 0.35);
  color: rgba(100, 210, 140, 0.9);
}

.mcp-status-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
  background: currentColor;
}
.mcp-server-status--unknown .mcp-status-dot { opacity: 0.4; }
.mcp-server-status--active .mcp-status-dot {
  animation: mcp-pulse 1.8s ease-in-out infinite;
  box-shadow: 0 0 0 0 currentColor;
}
@keyframes mcp-pulse {
  0%   { box-shadow: 0 0 0 0 rgba(100, 210, 140, 0.5); }
  60%  { box-shadow: 0 0 0 5px rgba(100, 210, 140, 0); }
  100% { box-shadow: 0 0 0 0 rgba(100, 210, 140, 0); }
}

.mcp-status-text { flex: 1; }

.mcp-status-badge {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 10px;
  background: rgba(50, 150, 90, 0.25);
  border: 1px solid rgba(60, 180, 110, 0.35);
  color: rgba(100, 220, 150, 0.9);
  white-space: nowrap;
}
.mcp-status-badge--dim {
  background: rgba(40, 50, 80, 0.25);
  border-color: rgba(55, 70, 110, 0.35);
  color: rgba(90, 110, 160, 0.75);
}

.mcp-url-block {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border-radius: 8px;
  border: 1px solid rgba(40, 110, 80, 0.3);
  background: rgba(20, 50, 35, 0.25);
  margin-bottom: 24px;
}
.mcp-url-label {
  font-size: 10px;
  font-weight: 600;
  color: rgba(80, 160, 120, 0.7);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  white-space: nowrap;
}
.mcp-url-val {
  font-size: 12px;
  color: rgba(100, 200, 150, 0.85);
  font-family: monospace;
}

.mcp-clients {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 8px;
}

.mcp-card {
  border: 1px solid rgba(40, 55, 90, 0.5);
  border-radius: 9px;
  background: rgba(255, 255, 255, 0.028);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  overflow: hidden;
}

.mcp-card__header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 14px;
  border-bottom: 1px solid rgba(40, 50, 85, 0.4);
}
.mcp-card__name {
  font-size: 12px;
  font-weight: 600;
  color: rgba(160, 185, 230, 0.9);
  white-space: nowrap;
}
.mcp-card__desc {
  flex: 1;
  font-size: 10.5px;
  color: rgba(90, 100, 150, 0.65);
}
.mcp-card__copy {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(55, 65, 105, 0.45);
  border-radius: 5px;
  color: rgba(110, 135, 190, 0.8);
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  padding: 4px 10px;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.mcp-card__copy:hover {
  background: rgba(60, 100, 180, 0.15);
  color: rgba(150, 190, 255, 0.9);
  border-color: rgba(70, 120, 200, 0.5);
}
.mcp-card__copy--ok {
  background: rgba(30, 90, 55, 0.35);
  color: rgba(80, 210, 130, 0.9);
  border-color: rgba(50, 150, 90, 0.45);
}

.mcp-card__cmd {
  margin: 0;
  padding: 10px 14px;
  font-size: 11px;
  font-family: monospace;
  color: rgba(100, 175, 140, 0.8);
  background: rgba(0, 0, 0, 0.18);
  white-space: pre-wrap;
  word-break: break-all;
  line-height: 1.6;
}

.mcp-tools-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}

.mcp-tool {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 5px 0;
  border-bottom: 1px solid rgba(30, 32, 58, 0.4);
}
.mcp-tool__name {
  font-size: 11px;
  color: rgba(100, 175, 140, 0.85);
  white-space: nowrap;
  flex-shrink: 0;
}
.mcp-tool__desc {
  font-size: 10.5px;
  color: rgba(90, 100, 150, 0.6);
  line-height: 1.4;
}


</style>
