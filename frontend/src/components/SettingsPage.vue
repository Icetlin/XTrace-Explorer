<template>
  <div class="settings-page">
    <div class="settings-title">Settings</div>

    <section class="settings-section">
      <div class="section-label">Traces directory</div>
      <div class="section-desc">
        Host path to the folder with <code>.xt</code> xdebug trace files.
        This updates the Docker volume mount and requires a container restart to take effect.
      </div>
      <div class="field-row">
        <input
          v-model="form.traces_host_path"
          class="settings-input"
          placeholder="/path/to/xdebug_trace"
          spellcheck="false"
        />
      </div>
    </section>

    <section class="settings-section">
      <div class="section-label">Project name</div>
      <div class="section-desc">
        A short label for your project — shown in the UI for context.
      </div>
      <div class="field-row">
        <input
          v-model="form.project_name"
          class="settings-input settings-input--short"
          placeholder="My App"
          spellcheck="false"
        />
      </div>
    </section>

    <section class="settings-section">
      <div class="section-label">Project source path</div>
      <div class="section-desc">
        Host path to your PHP project root. Used to resolve relative file paths shown in trace nodes
        (e.g. <code>src/EventSubscriber/Foo.php:42</code> → clickable link in the future).
      </div>
      <div class="field-row">
        <input
          v-model="form.project_path"
          class="settings-input"
          placeholder="/home/me/projects/my-app"
          spellcheck="false"
        />
      </div>
    </section>

    <div class="settings-actions">
      <button class="action-btn action-btn--save" :disabled="saving" @click="save">
        <span v-if="saving" class="btn-spinner" />
        {{ saving ? 'Saving…' : 'Save settings' }}
      </button>

      <button
        class="action-btn action-btn--restart"
        :disabled="restarting || !savedOnce"
        :title="savedOnce ? 'Restart container to apply volume changes' : 'Save settings first'"
        @click="restart"
      >
        <span v-if="restarting" class="btn-spinner" />
        {{ restarting ? 'Restarting…' : 'Restart container' }}
      </button>
    </div>

    <transition name="toast">
      <div v-if="toast" class="settings-toast" :class="'settings-toast--' + toast.type">
        {{ toast.msg }}
      </div>
    </transition>

    <section class="settings-section settings-section--info">
      <div class="section-label">How it works</div>
      <div class="info-block">
        <div class="info-row">
          <span class="info-num">1</span>
          <span>Set the <strong>Traces directory</strong> to the folder on your host machine where xdebug writes <code>.xt</code> files.</span>
        </div>
        <div class="info-row">
          <span class="info-num">2</span>
          <span>Click <strong>Save settings</strong> — this patches <code>docker-compose.yml</code> with the new volume path.</span>
        </div>
        <div class="info-row">
          <span class="info-num">3</span>
          <span>Click <strong>Restart container</strong> — Docker remounts the volume and the new traces appear in the file browser.</span>
        </div>
        <div class="info-row">
          <span class="info-num">4</span>
          <span>Set <strong>Project source path</strong> to enable future features like opening files in your IDE.</span>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const form = ref({ traces_host_path: '', project_path: '', project_name: '' })
const saving = ref(false)
const restarting = ref(false)
const savedOnce = ref(false)
const toast = ref(null)
let toastTimer = null

onMounted(async () => {
  try {
    const { data } = await axios.get('/api/settings')
    form.value = { ...form.value, ...data }
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
    const { data } = await axios.post('/api/settings', form.value)
    savedOnce.value = true
    showToast(data.compose_patched ? 'Saved — docker-compose.yml updated' : 'Saved', 'ok')
  } catch {
    showToast('Failed to save settings', 'err')
  } finally {
    saving.value = false
  }
}

async function restart() {
  restarting.value = true
  try {
    await axios.post('/api/settings/restart')
    showToast('Restart signal sent — page will reload in 8s', 'ok')
    setTimeout(() => window.location.reload(), 8000)
  } catch {
    showToast('Restart failed — run "docker compose restart app" manually', 'err')
  } finally {
    restarting.value = false
  }
}
</script>

<style scoped>
.settings-page {
  padding: 32px 40px;
  max-width: 680px;
  font-family: 'JetBrains Mono', monospace;
  overflow-y: auto;
  height: 100%;
}

.settings-title {
  font-size: 11px;
  font-weight: 600;
  color: #3a3a55;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  margin-bottom: 28px;
}

.settings-section {
  margin-bottom: 28px;
}

.settings-section--info {
  margin-top: 40px;
  padding-top: 28px;
  border-top: 1px solid #141428;
}

.section-label {
  font-size: 12px;
  font-weight: 600;
  color: #8899bb;
  margin-bottom: 6px;
  letter-spacing: 0.02em;
}

.section-desc {
  font-size: 11px;
  color: #3a3a55;
  line-height: 1.7;
  margin-bottom: 10px;
}
.section-desc code {
  color: #5a7aaa;
  background: #0e0e1a;
  padding: 1px 5px;
  border-radius: 3px;
}

.field-row { display: flex; gap: 8px; align-items: center; }

.settings-input {
  background: #0c0c18;
  border: 1px solid #1e1e32;
  border-radius: 7px;
  color: #9ab;
  font-family: monospace;
  font-size: 12px;
  padding: 10px 14px;
  flex: 1;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.settings-input:focus {
  border-color: #3a4a6a;
  box-shadow: 0 0 0 2px rgba(90, 120, 180, 0.12);
}
.settings-input--short { flex: 0 0 200px; }

.settings-actions {
  display: flex;
  gap: 10px;
  margin-top: 8px;
  flex-wrap: wrap;
}

.action-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  border-radius: 7px;
  font-family: monospace;
  font-size: 12px;
  cursor: pointer;
  border: 1px solid;
  transition: opacity 0.15s, background 0.15s;
}
.action-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.action-btn--save {
  background: #0e1e2e;
  color: #7aadff;
  border-color: #2a4a6a;
}
.action-btn--save:not(:disabled):hover { background: #122030; }

.action-btn--restart {
  background: #1a0e28;
  color: #b07aff;
  border-color: #3a2a5a;
}
.action-btn--restart:not(:disabled):hover { background: #1e1030; }

.btn-spinner {
  width: 11px;
  height: 11px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

.settings-toast {
  margin-top: 16px;
  padding: 10px 16px;
  border-radius: 7px;
  font-size: 12px;
  border: 1px solid;
}
.settings-toast--ok { color: #7dcc7d; background: #0a180a; border-color: #1a3a1a; }
.settings-toast--err { color: #cc7070; background: #180a0a; border-color: #3a1a1a; }

.toast-enter-active, .toast-leave-active { transition: opacity 0.25s, transform 0.25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-4px); }

.info-block { display: flex; flex-direction: column; gap: 12px; }

.info-row {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  font-size: 11px;
  color: #3a3a55;
  line-height: 1.65;
}
.info-row strong { color: #5a6a88; }
.info-row code { color: #4a6a8a; background: #0e0e1a; padding: 1px 5px; border-radius: 3px; }

.info-num {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #141428;
  border: 1px solid #1e1e38;
  color: #4a4a6a;
  font-size: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 1px;
}
</style>
