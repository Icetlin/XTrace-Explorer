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

        <div v-if="!form.listener_filters.length" class="empty-msg">
          No filters yet — all listeners are shown.
        </div>

        <div v-for="(f, idx) in form.listener_filters" :key="idx" class="filter-row">
          <span class="filter-icon">⊘</span>
          <span class="filter-text">{{ f }}</span>
          <button class="fav-del" @click="removeFilter(idx)">✕</button>
        </div>

        <div v-if="form.listener_filters.length" class="btn-row btn-row--top">
          <button class="btn btn--save" :disabled="saving" @click="save">
            <span v-if="saving" class="spinner" />
            {{ saving ? 'Saving…' : 'Save filters' }}
          </button>
        </div>
        <transition name="toast"><div v-if="toast" class="toast" :class="'toast--' + toast.type">{{ toast.msg }}</div></transition>

        <div class="howto">
          <div class="howto-title">Examples</div>
          <div class="filter-example" v-for="ex in filterExamples" :key="ex.pattern">
            <button class="ex-add" @click="addFilterValue(ex.pattern)" :disabled="form.listener_filters.includes(ex.pattern)">+</button>
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

const form = ref({ traces_host_path: '', project_path: '', project_name: '', listener_filters: [] })
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
      listener_filters: [...(data.listener_filters || [])],
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
    const data = await store.saveSettings(form.value)
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

async function addFav() {
  const p = newPattern.value.trim()
  if (!p) return
  await store.addFavourite(p, newLabel.value.trim() || null)
  newPattern.value = ''
  newLabel.value = ''
}

function addFilter() {
  const v = newFilter.value.trim()
  if (!v || form.value.listener_filters.includes(v)) return
  form.value.listener_filters = [...form.value.listener_filters, v]
  newFilter.value = ''
  save()
}

function addFilterValue(v) {
  if (form.value.listener_filters.includes(v)) return
  form.value.listener_filters = [...form.value.listener_filters, v]
  save()
}

function removeFilter(idx) {
  form.value.listener_filters = form.value.listener_filters.filter((_, i) => i !== idx)
  save()
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
  width: 160px;
  flex-shrink: 0;
  border-right: 1px solid #111120;
  padding: 24px 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: rgba(0,0,0,0.12);
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
  color: #3a3a58;
  text-align: left;
  border-left: 2px solid transparent;
  transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.nav-item:hover { color: #6a7a9a; background: rgba(255,255,255,0.02); }
.nav-item--active {
  color: #8aafdd;
  border-left-color: #4a6a9a;
  background: rgba(255,255,255,0.03);
}

.nav-icon { font-size: 13px; width: 16px; text-align: center; flex-shrink: 0; }
.nav-label { white-space: nowrap; }

/* ── Content area ── */
.settings-content {
  flex: 1;
  overflow-y: auto;
  padding: 28px 36px;
  max-width: 660px;
}

.section-title {
  font-size: 11px;
  font-weight: 600;
  color: #3a3a58;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  margin-bottom: 24px;
}

.section-desc {
  font-size: 12px;
  color: #383856;
  line-height: 1.7;
  margin-bottom: 20px;
}

/* ── Fields ── */
.field-group { margin-bottom: 22px; }

.field-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #7a99bb;
  margin-bottom: 5px;
  letter-spacing: 0.02em;
}

.field-desc {
  font-size: 11px;
  color: #32324a;
  line-height: 1.65;
  margin-bottom: 9px;
}
.field-desc code {
  color: #5a7aaa;
  background: #0e0e1a;
  padding: 1px 5px;
  border-radius: 3px;
}

.field-input {
  background: #0c0c18;
  border: 1px solid #1a1a2e;
  border-radius: 7px;
  color: #9ab;
  font-family: monospace;
  font-size: 12px;
  padding: 9px 14px;
  width: 100%;
  box-sizing: border-box;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.field-input:focus { border-color: #3a4a6a; box-shadow: 0 0 0 2px rgba(90,120,180,0.1); }
.field-input--short { width: 200px; }
.field-input--label { width: 180px; }

/* ── Buttons ── */
.btn-row {
  display: flex;
  gap: 10px;
  margin-top: 6px;
  flex-wrap: wrap;
}
.btn-row--top { margin-top: 16px; margin-bottom: 0; }

.btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 9px 18px;
  border-radius: 7px;
  font-family: monospace;
  font-size: 12px;
  cursor: pointer;
  border: 1px solid;
  transition: opacity 0.15s, background 0.15s;
}
.btn:disabled { opacity: 0.35; cursor: not-allowed; }

.btn--save { background: #0e1e2e; color: #7aadff; border-color: #2a4a6a; }
.btn--save:not(:disabled):hover { background: #122030; }

.btn--restart { background: #1a0e28; color: #b07aff; border-color: #3a2a5a; }
.btn--restart:not(:disabled):hover { background: #1e1030; }

.btn--add { background: #141428; color: #666; border-color: #2a2a42; white-space: nowrap; }
.btn--add:hover { color: #9ecbff; border-color: #3a5a8a; }

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
  border-radius: 7px;
  font-size: 12px;
  border: 1px solid;
}
.toast--ok  { color: #7dcc7d; background: #0a180a; border-color: #1a3a1a; }
.toast--err { color: #cc7070; background: #180a0a; border-color: #3a1a1a; }
.toast-enter-active, .toast-leave-active { transition: opacity 0.25s, transform 0.25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-4px); }

/* ── Howto ── */
.howto {
  margin-top: 36px;
  padding-top: 24px;
  border-top: 1px solid #111120;
}

.howto-title {
  font-size: 10px;
  font-weight: 600;
  color: #2e2e48;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 16px;
}

.howto-step {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  font-size: 11.5px;
  color: #32324a;
  line-height: 1.7;
  margin-bottom: 12px;
}
.howto-step strong { color: #555577; }
.howto-step code { color: #4a6a8a; background: #0e0e1a; padding: 1px 5px; border-radius: 3px; }

.step-num {
  width: 20px; height: 20px;
  border-radius: 50%;
  background: #0e0e1e;
  border: 1px solid #1a1a32;
  color: #3a3a5a;
  font-size: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
}

.code-block {
  background: #090914;
  border: 1px solid #151525;
  border-radius: 6px;
  color: #5a7a9a;
  font-size: 11px;
  padding: 10px 14px;
  margin: 8px 0 14px;
  font-family: monospace;
  line-height: 1.6;
  white-space: pre;
  overflow-x: auto;
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
  color: #282840;
  font-size: 12px;
  font-style: italic;
  padding: 10px 0;
}

.fav-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 14px;
  border-radius: 7px;
  border: 1px solid #111120;
  margin-bottom: 5px;
  background: #0c0c18;
  transition: background 0.1s, border-color 0.1s;
}
.fav-row:hover { background: #0f0f1e; border-color: #1a1a2e; }

.fav-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
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
  color: #222234; font-size: 11px;
  cursor: pointer;
  padding: 3px 7px;
  border-radius: 4px;
  line-height: 1;
  transition: color 0.1s, background 0.1s;
}
.fav-del:hover { color: #cc6060; background: #1e1018; }

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
  border-radius: 7px;
  border: 1px solid #111120;
  margin-bottom: 5px;
  background: #0c0c16;
  transition: background 0.1s;
}
.filter-row:hover { background: #0f0f1c; }

.filter-icon { color: #3a3a5a; font-size: 13px; flex-shrink: 0; }
.filter-text { flex: 1; font-size: 13px; color: #6a6a9a; font-weight: 500; }

.filter-example {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px solid #0d0d1a;
  font-size: 11.5px;
}
.ex-add {
  background: #111122; border: 1px solid #202038;
  color: #4a4a6a; border-radius: 4px;
  width: 22px; height: 22px;
  cursor: pointer; font-size: 14px; line-height: 1;
  flex-shrink: 0;
  transition: color 0.1s, border-color 0.1s;
}
.ex-add:not(:disabled):hover { color: #9ecbff; border-color: #3a5a8a; }
.ex-add:disabled { opacity: 0.3; cursor: default; }
.ex-pattern { color: #6a8aaa; background: #0e0e1a; padding: 2px 7px; border-radius: 4px; flex-shrink: 0; }
.ex-desc { color: #2e2e46; }
</style>
