<template>
  <div ref="ctrlRef" class="float-ctrl" :class="{ 'float-ctrl--open': xdOpen || favOpen }">

    <!-- Xdebug mode options (shown above buttons when open) -->
    <transition name="xd-expand">
      <div v-if="xdOpen" class="xd-options">
        <button
          v-for="m in XD_MODES"
          :key="m"
          class="xd-opt"
          :class="{ 'xd-opt--active': xdStatus === m }"
          @click="selectXdMode(m)"
        >
          <span class="xd-opt__dot" :class="'xd-opt__dot--' + m.replace('+', '-')" />
          {{ m }}
        </button>
        <div class="xd-options__divider" />
        <button class="xd-opt xd-opt--organize" :disabled="xdLoading" @click="organizeTraces" title="Move session .xt files into a dated folder">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M1 3h10M1 6h7M1 9h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
          </svg>
          organize
        </button>
        <div class="xd-options__divider" />
      </div>
    </transition>

    <!-- Favourites panel -->
    <transition name="xd-expand">
      <div v-if="favOpen" class="fav-panel">
        <div class="fav-panel__list">
          <div v-if="!store.favourites.length" class="fav-panel__empty">no patterns yet</div>
          <div v-for="fav in store.favourites" :key="fav.id" class="fav-panel__row">
            <span class="fav-panel__dot" :style="{ background: favColor(fav.pattern).borderLeft }" />
            <span class="fav-panel__pattern" :style="{ color: favColor(fav.pattern).text }">{{ fav.pattern }}</span>
            <span v-if="fav.label" class="fav-panel__label" :style="{ color: favColor(fav.pattern).textDim, background: favColor(fav.pattern).bg }">{{ fav.label }}</span>
            <button class="fav-panel__del" @click="store.deleteFavourite(fav.id)">✕</button>
          </div>
        </div>
        <div class="fav-panel__divider" />
        <div class="fav-panel__add">
          <input v-model="favPattern" class="fav-panel__input" placeholder="pattern" spellcheck="false" @keydown.enter="addFav" />
          <input v-model="favLabel" class="fav-panel__input fav-panel__input--sm" placeholder="label" spellcheck="false" @keydown.enter="addFav" />
          <button class="fav-panel__btn" @click="addFav">+</button>
        </div>
        <div class="fav-panel__divider" />
      </div>
    </transition>

    <!-- Buttons row (always horizontal) -->
    <div class="float-ctrl__row">
      <!-- Collapse / expand TOC -->
      <button
        v-if="hasTrace"
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--dim': !tocRef }"
        :title="collapsed ? 'Expand all' : 'Collapse all'"
        @click="toggleCollapse"
      >
        <svg v-if="collapsed" width="15" height="15" viewBox="0 0 15 15" fill="none">
          <path d="M2 5.5L5.5 2M5.5 2H2M5.5 2V5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M13 9.5L9.5 13M9.5 13H13M9.5 13V9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <svg v-else width="15" height="15" viewBox="0 0 15 15" fill="none">
          <path d="M5.5 2L2 5.5M2 5.5H5.5M2 5.5V2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M9.5 13L13 9.5M13 9.5H9.5M13 9.5V13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>

      <!-- Favourites toggle -->
      <button
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--active': favOpen }"
        title="Favourites"
        @click="favOpen = !favOpen; xdOpen = false"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
          <ellipse cx="7.5" cy="7.5" rx="6" ry="4" stroke="currentColor" stroke-width="1.3"/>
          <circle cx="7.5" cy="7.5" r="2" stroke="currentColor" stroke-width="1.3"/>
        </svg>
      </button>

      <!-- Xdebug toggle button -->
      <button
        class="float-ctrl__item float-ctrl__item--xd"
        :class="[xdColorClass, { 'float-ctrl__item--loading': xdLoading, 'float-ctrl__item--active': xdOpen }]"
        :title="xdTitle"
        :disabled="xdLoading"
        @click="xdOpen = !xdOpen; favOpen = false"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
          <!-- body -->
          <ellipse cx="7.5" cy="8.5" rx="3" ry="3.8" stroke="currentColor" stroke-width="1.3"/>
          <!-- head -->
          <circle cx="7.5" cy="4.2" r="1.5" stroke="currentColor" stroke-width="1.3"/>
          <!-- antennae -->
          <path d="M6.5 3L5 1.5M8.5 3L10 1.5" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <!-- legs left -->
          <path d="M4.5 7L2.5 6.5M4.5 8.5L2.5 8.5M4.5 10L2.5 11" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <!-- legs right -->
          <path d="M10.5 7L12.5 6.5M10.5 8.5L12.5 8.5M10.5 10L12.5 11" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
        <span class="xd-dot" />
      </button>

      <!-- Settings gear -->
      <button
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--active': activeModal === 'settings' }"
        title="Settings"
        @click="openModal('settings')"
      >
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="2.5" stroke="currentColor" stroke-width="1.4"/>
          <path d="M8 1v1.5M8 13.5V15M1 8h1.5M13.5 8H15M2.93 2.93l1.06 1.06M12.01 12.01l1.06 1.06M2.93 13.07l1.06-1.06M12.01 3.99l1.06-1.06" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- Modal overlay -->
  <transition name="float-modal">
    <div v-if="activeModal" class="float-modal-wrap" @click.self="closeModal">
      <div class="float-modal">
        <div class="float-modal__header">
          <span class="float-modal__title">{{ modalTitle }}</span>
          <button class="float-modal__close" @click="closeModal">✕</button>
        </div>
        <div class="float-modal__body">
          <SettingsPage v-if="activeModal === 'settings'" />
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import SettingsPage from './SettingsPage.vue'
import { useTraceStore } from '../stores/trace'
import { favColor } from '../favColor.js'

const props = defineProps({
  tocRef: { type: Object, default: null },
})

const store = useTraceStore()
const activeModal = ref(null)
const collapsed = ref(false)
const ctrlRef = ref(null)

function onDocClick(e) {
  if (ctrlRef.value && !ctrlRef.value.contains(e.target)) {
    xdOpen.value = false
    favOpen.value = false
  }
}
onMounted(() => document.addEventListener('mousedown', onDocClick))
onUnmounted(() => document.removeEventListener('mousedown', onDocClick))

const hasTrace = computed(() => store.openTabs.some(t => t.status === 'ready'))

function openModal(id) {
  activeModal.value = activeModal.value === id ? null : id
}

function closeModal() {
  activeModal.value = null
}

function toggleCollapse() {
  if (!props.tocRef) return
  if (collapsed.value) {
    props.tocRef.expandAll()
    collapsed.value = false
  } else {
    props.tocRef.collapseAll()
    collapsed.value = true
  }
}

const modalTitle = computed(() => {
  if (activeModal.value === 'settings') return 'Settings'
  return ''
})

// ── Xdebug toggle ──────────────────────────────────────────────────────────
const XD_MODES  = ['off', 'debug', 'debug+trace']
const xdStatus  = ref(null)   // null | 'off' | 'debug' | 'debug+trace'
const xdLoading = ref(false)
const xdOpen    = ref(false)

const xdTitle = computed(() => {
  if (xdLoading.value) return 'Xdebug: switching…'
  if (xdStatus.value === null) return 'Xdebug: unknown'
  return `Xdebug: ${xdStatus.value}`
})

const xdColorClass = computed(() => {
  if (xdStatus.value === 'debug+trace') return 'xd-trace'
  if (xdStatus.value === 'debug')       return 'xd-debug'
  return 'xd-off'
})

onMounted(async () => {
  try {
    const { data } = await axios.get('/api/xdebug/status')
    xdStatus.value = data.running ? (data.mode || 'off') : null
  } catch {}
  await store.loadFavourites()
})

// ── Favourites panel ───────────────────────────────────────────────────────
const favOpen      = ref(false)
const favPattern   = ref('')
const favLabel     = ref('')

async function addFav() {
  const p = favPattern.value.trim()
  if (!p) return
  await store.addFavourite(p, favLabel.value.trim() || null)
  favPattern.value = ''
  favLabel.value   = ''
}

async function organizeTraces() {
  xdOpen.value = false
  xdLoading.value = true
  try {
    const { data } = await axios.post('/api/xdebug/organize')
    console.log('organize:', data.ok ? (data.message + (data.folder ? ` → ${data.folder}` : '')) : data.error)
  } catch {}
  xdLoading.value = false
}

async function selectXdMode(mode) {
  xdOpen.value = false
  if (xdLoading.value || mode === xdStatus.value) return
  xdLoading.value = true
  try {
    const { data } = await axios.post('/api/xdebug/set', { mode }, { timeout: 40000 })
    if (data.ok) {
      const { data: s } = await axios.get('/api/xdebug/status')
      xdStatus.value = s.running ? (s.mode || 'off') : null
    }
  } catch {}
  xdLoading.value = false
}
</script>

<style scoped>
/* ── Float control bar ── */
.float-ctrl {
  position: fixed;
  right: 20px;
  bottom: 48px;
  z-index: 200;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0;
  background: rgba(12, 16, 32, 0.82);
  border: 1px solid rgba(55, 65, 110, 0.45);
  border-radius: 10px;
  padding: 4px;
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  filter: drop-shadow(0 4px 24px rgba(0, 0, 0, 0.55));
  animation: float-bob 4s ease-in-out infinite;
}
.float-ctrl:hover,
.float-ctrl--open {
  animation: none;
}
.float-ctrl__row {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 2px;
}

@keyframes float-bob {
  0%, 100% { transform: translateY(0); }
  50%       { transform: translateY(-4px); }
}

/* Item buttons */
.float-ctrl__item {
  background: none;
  border: none;
  color: rgba(80, 100, 155, 0.65);
  cursor: pointer;
  width: 34px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  transition: color 0.14s, background 0.14s;
}
.float-ctrl__item:hover {
  color: rgba(140, 180, 240, 0.9);
  background: rgba(255, 255, 255, 0.07);
}
.float-ctrl__item--active {
  color: rgba(120, 185, 255, 1);
  background: rgba(40, 80, 160, 0.22);
}
.float-ctrl__item--dim {
  opacity: 0.3;
  pointer-events: none;
}
.float-ctrl__item--xd {
  position: relative;
}
.float-ctrl__item--xd .xd-dot {
  position: absolute;
  bottom: 5px;
  right: 5px;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: rgba(80, 100, 155, 0.5);
  transition: background 0.2s;
}
.float-ctrl__item--xd.xd-debug .xd-dot {
  background: rgba(100, 200, 140, 0.9);
}
.float-ctrl__item--xd.xd-trace .xd-dot {
  background: rgba(255, 160, 80, 0.95);
  box-shadow: 0 0 4px rgba(255, 160, 80, 0.6);
}
.float-ctrl__item--xd.xd-debug {
  color: rgba(100, 200, 140, 0.8);
}
.float-ctrl__item--xd.xd-trace {
  color: rgba(255, 160, 80, 0.9);
}
.float-ctrl__item--loading {
  opacity: 0.5;
  cursor: wait;
}

/* ── Xdebug inline options ── */
.xd-options {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.xd-options__divider {
  height: 1px;
  background: rgba(55, 65, 110, 0.35);
  margin: 2px 2px 0;
}
.xd-opt {
  display: flex;
  align-items: center;
  gap: 8px;
  background: none;
  border: none;
  color: rgba(140, 160, 210, 0.65);
  cursor: pointer;
  padding: 5px 8px;
  border-radius: 6px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  text-align: left;
  transition: background 0.12s, color 0.12s;
  white-space: nowrap;
}
.xd-opt:hover {
  background: rgba(255,255,255,0.07);
  color: rgba(200, 220, 255, 0.95);
}
.xd-opt--active {
  color: rgba(200, 220, 255, 0.92);
  background: rgba(40, 60, 130, 0.22);
}
.xd-opt__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: rgba(80, 100, 155, 0.4);
  flex-shrink: 0;
}
.xd-opt--organize { color: rgba(120, 150, 200, 0.6); gap: 6px; }
.xd-opt--organize:hover { color: rgba(160, 190, 240, 0.9); }
.xd-opt__dot--debug        { background: rgba(100, 200, 140, 0.9); }
.xd-opt__dot--debug-trace  { background: rgba(255, 160, 80, 0.95); box-shadow: 0 0 4px rgba(255,160,80,0.5); }

/* ── Favourites panel ── */
.fav-panel { display: flex; flex-direction: column; gap: 0; }
.fav-panel__list { display: flex; flex-direction: column; gap: 1px; max-height: 180px; overflow-y: auto; padding: 2px 0; }
.fav-panel__list::-webkit-scrollbar { width: 3px; }
.fav-panel__list::-webkit-scrollbar-thumb { background: rgba(80,100,160,0.3); border-radius: 2px; }
.fav-panel__empty { font-size: 10px; color: rgba(80,90,140,0.5); padding: 6px 10px; font-family: 'JetBrains Mono', monospace; }
.fav-panel__row {
  display: flex; align-items: center; gap: 6px;
  padding: 4px 8px; border-radius: 5px;
  transition: background 0.1s;
}
.fav-panel__row:hover { background: rgba(255,255,255,0.05); }
.fav-panel__dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.fav-panel__pattern { flex: 1; font-size: 11px; font-family: 'JetBrains Mono', monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fav-panel__label { font-size: 9px; padding: 1px 5px; border-radius: 3px; flex-shrink: 0; opacity: 0.85; }
.fav-panel__del {
  background: none; border: none; cursor: pointer; color: rgba(80,90,140,0.4);
  font-size: 10px; padding: 1px 4px; border-radius: 3px; line-height: 1; flex-shrink: 0;
  transition: color 0.1s, background 0.1s;
}
.fav-panel__del:hover { color: rgba(210,80,80,0.85); background: rgba(120,20,20,0.2); }
.fav-panel__divider { height: 1px; background: rgba(55,65,110,0.35); margin: 2px 2px; }
.fav-panel__add { display: flex; gap: 4px; padding: 4px 2px; }
.fav-panel__input {
  flex: 1; background: rgba(255,255,255,0.05); border: 1px solid rgba(55,65,110,0.4);
  border-radius: 5px; color: rgba(180,195,230,0.9); font-size: 10px;
  font-family: 'JetBrains Mono', monospace; padding: 4px 7px; outline: none; min-width: 0;
}
.fav-panel__input--sm { max-width: 60px; }
.fav-panel__input:focus { border-color: rgba(80,120,200,0.6); }
.fav-panel__btn {
  background: rgba(40,60,130,0.3); border: 1px solid rgba(60,90,180,0.35);
  border-radius: 5px; color: rgba(140,180,240,0.85); cursor: pointer;
  font-size: 14px; line-height: 1; padding: 3px 8px; flex-shrink: 0;
  transition: background 0.12s;
}
.fav-panel__btn:hover { background: rgba(50,80,160,0.45); }

.xd-expand-enter-active { transition: opacity 0.18s ease, transform 0.18s cubic-bezier(0.34,1.1,0.64,1); }
.xd-expand-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.xd-expand-enter-from   { opacity: 0; transform: translateY(8px); }
.xd-expand-leave-to     { opacity: 0; transform: translateY(4px); }

/* ── Modal ── */
.float-modal-wrap {
  position: fixed;
  inset: 0;
  z-index: 300;
  display: flex;
  align-items: flex-end;
  justify-content: flex-end;
  padding: 0 20px 100px 20px;
  pointer-events: all;
}

.float-modal {
  background: rgba(8, 10, 22, 0.92);
  backdrop-filter: blur(28px);
  -webkit-backdrop-filter: blur(28px);
  border: 1px solid rgba(50, 65, 110, 0.5);
  border-radius: 14px;
  box-shadow: 0 8px 60px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255,255,255,0.03);
  width: min(860px, calc(100vw - 40px));
  height: min(640px, calc(100vh - 120px));
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.float-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px 12px;
  border-bottom: 1px solid rgba(40, 50, 90, 0.4);
  flex-shrink: 0;
}

.float-modal__title {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  color: rgba(140, 165, 215, 0.75);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

.float-modal__close {
  background: none;
  border: none;
  color: rgba(80, 90, 135, 0.65);
  cursor: pointer;
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 5px;
  line-height: 1;
  transition: color 0.12s, background 0.12s;
}
.float-modal__close:hover {
  color: rgba(210, 90, 90, 0.9);
  background: rgba(120, 20, 20, 0.2);
}

.float-modal__body {
  flex: 1;
  overflow: hidden;
  display: flex;
  min-height: 0;
}

/* ── Transition ── */
.float-modal-enter-active {
  transition: opacity 0.22s ease, transform 0.22s cubic-bezier(0.34, 1.2, 0.64, 1);
}
.float-modal-leave-active {
  transition: opacity 0.16s ease, transform 0.16s cubic-bezier(0.4, 0, 1, 1);
}
.float-modal-enter-from {
  opacity: 0;
  transform: translateY(24px) scale(0.97);
}
.float-modal-leave-to {
  opacity: 0;
  transform: translateY(16px) scale(0.98);
}
</style>
