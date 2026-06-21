<template>
  <aside class="qbsp">
    <header class="qbsp__header">
      <h3>Profiler+ settings</h3>
      <button class="qbsp__close" @click="$emit('close')">✕</button>
    </header>

    <div class="qbsp__intro">
      Profiler+ pulls SQL queries with full backtraces from the Symfony
      WebProfilerBundle of the target app, instead of relying on xdebug-trace
      heuristics.
      <br><br>
      Only the <strong>base URL</strong> comes from <code>.env</code>.
      The on/off toggle is a per-user preference stored in
      <code>settings.json</code>. Path mapping is shared with the inline
      source view (uses <code>SOURCE_HOST_DIR</code> / <code>SOURCE_CONTAINER_DIR</code>).
    </div>

    <!-- ── On/off toggle (persisted in settings.json) ── -->
    <section class="qbsp__section">
      <label class="qbsp__toggle-row">
        <span>Enable Profiler+</span>
        <span class="qbsp__switch" :class="{ on: enabled, busy: saving }" @click="toggle">
          <span class="qbsp__switch-knob" />
        </span>
        <span v-if="status?.usable" class="qbsp__pill ok">on</span>
        <span v-else class="qbsp__pill off">off</span>
      </label>
    </section>

    <!-- ── Status ── -->
    <section class="qbsp__section">
      <div class="qbsp__status" :class="status?.usable ? 'ok' : 'no'">
        {{ statusMessage }}
      </div>
      <div class="qbsp__row">
        <button class="qbsp__btn" @click="testConnection" :disabled="!status?.usable || testing">
          {{ testing ? 'Testing…' : 'Test connection' }}
        </button>
        <span v-if="pingResult" class="qbsp__ping" :class="pingResult.ok ? 'ok' : 'no'">
          {{ pingResult.ok
              ? `✓ ${pingResult.recent_count} recent tokens`
              : `✗ ${pingResult.error ?? 'failed'}` }}
        </span>
      </div>
    </section>

    <!-- ── Read-only infrastructure config ── -->
    <section class="qbsp__section">
      <h4>Connection (from .env)</h4>
      <div class="qbsp__field">
        <label>PROFILER_BASE_URL</label>
        <code class="qbsp__value">{{ status?.base_url ?? '— not set in .env —' }}</code>
      </div>
      <div class="qbsp__field">
        <label>src prefix <small>(in-container)</small></label>
        <code class="qbsp__value">{{ status?.src_prefix ?? '— SOURCE_CONTAINER_DIR not set —' }}</code>
      </div>
      <div class="qbsp__field">
        <label>host prefix <small>(local checkout)</small></label>
        <code class="qbsp__value">{{ status?.host_prefix ?? '— SOURCE_HOST_DIR not set —' }}</code>
      </div>
    </section>
  </aside>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { useQbStore } from '../stores/qb'

const emit = defineEmits(['close'])
const qb = useQbStore()
const status = ref(null)
const enabled = ref(false)
const saving = ref(false)
const testing = ref(false)
const pingResult = ref(null)

const statusMessage = computed(() => {
  if (!status.value) return 'Loading…'
  if (!status.value.enabled) return 'Profiler+ is off — toggle above to enable.'
  if (!status.value.base_url) return 'Profiler+ is on but PROFILER_BASE_URL is not set in .env.'
  return `✓ Talking to ${status.value.base_url}`
})

async function load() {
  // Read status from the qb store so we share one source of truth with
  // the QbPage banner — toggling here must update the banner instantly.
  await qb.loadProfilerConfig()
  const s = qb.profilerConfig
  status.value = s
  enabled.value = !!s?.enabled
}

async function save() {
  saving.value = true
  try {
    await axios.post('/api/profiler/toggle', { enabled: enabled.value })
    // Refresh profiler config in store; QbPage's watcher on
    // qb.profilerConfig.enabled will reload snapshot + trace queries.
    await load()
  } catch (e) {
    console.error('Failed to save Profiler+ toggle:', e)
  } finally {
    saving.value = false
  }
}

async function testConnection() {
  testing.value = true
  pingResult.value = null
  try {
    const { data } = await axios.get('/api/profiler/ping')
    pingResult.value = data
  } catch (e) {
    // axios throws on 4xx/5xx — prefer the server-supplied message when present.
    const msg = e?.response?.data?.error || e?.response?.data?.reason || e?.message || 'failed'
    pingResult.value = { ok: false, error: msg }
  } finally {
    testing.value = false
  }
}

async function toggle() {
  if (saving.value) return
  enabled.value = !enabled.value
  await save()
}

onMounted(load)
</script>

<style scoped>
.qbsp {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: 380px;
  background: var(--bg-soft, #151921);
  border-left: 1px solid var(--border, #2a2e36);
  display: flex;
  flex-direction: column;
  font-size: 13px;
  z-index: 10;
  overflow-y: auto;
}

.qbsp__header {
  display: flex;
  align-items: center;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border, #2a2e36);
  position: sticky;
  top: 0;
  background: var(--bg-soft, #151921);
  z-index: 2;
}
.qbsp__header h3 { margin: 0; flex: 1; font-size: 14px; }
.qbsp__close { background: transparent; border: 0; color: #888; cursor: pointer; font-size: 16px; }

.qbsp__intro {
  padding: 12px 14px;
  font-size: 12px;
  color: #aaa;
  line-height: 1.5;
  border-bottom: 1px solid var(--border, #2a2e36);
}
.qbsp__intro code {
  background: var(--bg, rgba(0,0,0,0.3));
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 11px;
}

.qbsp__section {
  padding: 12px 14px;
  border-bottom: 1px solid var(--border, #2a2e36);
}
.qbsp__section h4 {
  margin: 0 0 10px;
  font-size: 11px;
  text-transform: uppercase;
  color: #888;
  letter-spacing: 0.05em;
}

.qbsp__toggle-row {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  font-size: 13px;
  user-select: none;
}

.qbsp__switch {
  position: relative;
  width: 36px;
  height: 20px;
  background: #2a2e36;
  border-radius: 12px;
  transition: background 0.18s ease;
  flex-shrink: 0;
  border: 1px solid #2a2e36;
}
.qbsp__switch.on { background: #5cd97a; border-color: #5cd97a; }
.qbsp__switch.busy { opacity: 0.6; }
.qbsp__switch-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 14px;
  height: 14px;
  background: #e6e6e6;
  border-radius: 50%;
  transition: left 0.18s ease;
}
.qbsp__switch.on .qbsp__switch-knob { left: 18px; background: #151921; }
.qbsp__pill {
  font-size: 10px;
  font-family: monospace;
  padding: 1px 6px;
  border-radius: 3px;
  margin-left: auto;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.qbsp__pill.ok { background: #1a3320; color: #c5f0c5; }
.qbsp__pill.off { background: #2a2e36; color: #888; }

.qbsp__status {
  font-size: 11px;
  padding: 6px 8px;
  border-radius: 4px;
  margin-bottom: 8px;
  font-family: monospace;
}
.qbsp__status.ok { background: #1a3320; color: #c5f0c5; }
.qbsp__status.no { background: #3a1a1a; color: #f0c5c5; }

.qbsp__row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.qbsp__ping {
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 4px;
}
.qbsp__ping.ok { color: #5cd97a; }
.qbsp__ping.no { color: #ef5b5b; }

.qbsp__btn {
  background: #1a1f2a;
  border: 1px solid #2a2e36;
  color: inherit;
  padding: 5px 12px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
}
.qbsp__btn:hover { background: #1f2530; }
.qbsp__btn:disabled { opacity: 0.4; cursor: not-allowed; }

.qbsp__field {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-bottom: 10px;
}
.qbsp__field label {
  font-size: 11px;
  color: #aaa;
}
.qbsp__field label small { color: #666; font-size: 10px; }
.qbsp__value {
  font-family: monospace;
  font-size: 11px;
  background: var(--bg, rgba(0,0,0,0.3));
  padding: 4px 8px;
  border-radius: 3px;
  color: #c5f0c5;
  word-break: break-all;
  user-select: text;
}
</style>