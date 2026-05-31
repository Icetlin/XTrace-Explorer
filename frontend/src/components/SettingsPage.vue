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

    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useTraceStore } from '../stores/trace'
import { favColor } from '../favColor.js'
import axios from 'axios'

const store = useTraceStore()

const sections = [
  { id: 'general',    icon: '⚙', label: 'General' },
  { id: 'favourites', icon: '★', label: 'Favourites' },
  { id: 'filters',    icon: '⊘', label: 'Listener filters' },
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
      project_path: data.project_path || '',
      project_name: data.project_name || '',
      app_namespaces: data.app_namespaces ? JSON.parse(JSON.stringify(data.app_namespaces)) : [],
    }
    savedOnce.value = !!(data.traces_host_path || data.project_path)
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
    project_path: form.value.project_path,
    project_name: form.value.project_name,
    app_namespaces: form.value.app_namespaces,
    listener_filters: store.listenerFilters,
    ...overrides,
  }
}

async function removeFilter(pattern) {
  await store.saveSettings(formPayload({ listener_filters: store.listenerFilters.filter(f => f !== pattern) }))
}
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
</style>
