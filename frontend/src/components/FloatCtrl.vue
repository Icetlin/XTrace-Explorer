<template>
  <div ref="ctrlRef" class="float-ctrl" :class="{ 'float-ctrl--open': xdOpen || favOpen || timingsOpen }">

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

    <!-- Combined timings panel: Backend (DB) + Frontend (in-memory) -->
    <transition name="xd-expand">
      <div v-if="timingsOpen" class="timings-panel">
        <div class="timings-panel__header timings-panel__header--row">
          <span>Timings</span>
          <div class="timings-panel__head-actions">
            <button
              class="timings-panel__head-btn"
              :class="{ 'timings-panel__head-btn--ok': copyFlash }"
              :title="copyFlash ? 'Copied!' : 'Copy all timings to clipboard'"
              @click="copyTimings"
            >{{ copyFlash ? '✓' : '⧉' }}</button>
            <button
              class="timings-panel__head-btn"
              :class="{ 'timings-panel__head-btn--off': !perf.enabled }"
              :title="perf.enabled ? 'Pause frontend recording' : 'Resume frontend recording'"
              @click="perf.toggle()"
            >{{ perf.enabled ? '❚❚' : '▶' }}</button>
            <button class="timings-panel__head-btn" title="Clear frontend buffer" @click="perf.clear()">⌫</button>
          </div>
        </div>
        <div class="timings-panel__hint">
          backend = server processing · frontend = client-side (API incl. network, render = mount, tab/toc = interactions)
        </div>
        <div class="timings-panel__list">
          <!-- Backend section -->
          <div v-if="timings.length" class="timings-panel__section">backend <span class="timings-panel__section-count">{{ timings.length }}</span></div>
          <div v-if="!timings.length" class="timings-panel__empty">no backend requests yet</div>
          <div v-for="t in timings" :key="'be-' + t.id" class="timings-panel__row">
            <span class="timings-panel__method" :class="'method--' + t.endpoint_method.toLowerCase()">{{ t.endpoint_method }}</span>
            <span class="timings-panel__url">{{ t.endpoint_url }}</span>
            <span class="timings-panel__duration" :class="durationClass(t.duration_ms)">{{ t.duration_ms }} ms</span>
            <span class="timings-panel__time">{{ t.created }}</span>
          </div>

          <!-- Frontend section -->
          <div v-if="perf.entries.length" class="timings-panel__section timings-panel__section--spaced">frontend <span class="timings-panel__section-count">{{ perf.entries.length }}</span></div>
          <div v-if="!perf.entries.length" class="timings-panel__empty">no frontend events yet</div>
          <div v-for="e in perf.entries" :key="'fe-' + e.id" class="timings-panel__row timings-panel__row--fe">
            <span class="timings-panel__cat" :class="'perf-cat--' + e.category">{{ e.category }}</span>
            <span class="timings-panel__url" :title="e.name">{{ e.name }}</span>
            <span class="timings-panel__duration" :class="durationClass(e.durationMs)">
              {{ formatFeDuration(e.durationMs) }}
            </span>
            <span class="timings-panel__time">{{ feTime(e.ts) }}</span>
          </div>
        </div>
      </div>
    </transition>

    <!-- Buttons row (always horizontal). Four semantic groups, separated by
         thin dividers: View · Trace state · Debug & runtime · Global. -->
    <div class="float-ctrl__row">
      <!-- ── Group 1: View & analyze ── -->
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

      <!-- SQL queries (full-page QbPage) -->
      <button
        v-if="hasTrace"
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--active': qbOpen }"
        title="DB Queries — full-page workspace (Profiler+ supported)"
        @click="toggleQb"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
          <rect x="1.5" y="2" width="12" height="3" rx="1" stroke="currentColor" stroke-width="1.2"/>
          <rect x="1.5" y="6.5" width="12" height="3" rx="1" stroke="currentColor" stroke-width="1.2"/>
          <rect x="1.5" y="11" width="7" height="2.5" rx="1" stroke="currentColor" stroke-width="1.2"/>
          <circle cx="12.5" cy="12.2" r="1.8" fill="currentColor" opacity="0.7"/>
        </svg>
      </button>

      <!-- AI summary (preview + copy) -->
      <button
        v-if="hasTrace"
        class="float-ctrl__item float-ctrl__item--summary"
        :class="{ 'float-ctrl__item--loading': summaryLoading }"
        :disabled="summaryLoading"
        title="Build AI summary — preview &amp; copy to clipboard"
        @click="openSummary"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true">
          <path d="M7.5 0.5L8.4 5.1L12.5 6.5L8.4 7.9L7.5 12.5L6.6 7.9L2.5 6.5L6.6 5.1Z" fill="currentColor"/>
          <path d="M11.5 9.5L11.9 11.1L13.5 11.5L11.9 11.9L11.5 13.5L11.1 11.9L9.5 11.5L11.1 11.1Z" fill="currentColor" opacity="0.7"/>
        </svg>
      </button>

      <!-- Timings (backend + frontend) — always available, frontend metrics
           are useful even before a trace is opened. -->
      <button
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--active': timingsOpen }"
        title="Timings (backend + frontend)"
        @click="toggleTimings"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
          <circle cx="7.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M7.5 8.5V5.5M7.5 8.5L9.5 10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M5.5 1.5h4M7.5 1.5V3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
      </button>

      <div class="float-ctrl__divider" />

      <!-- ── Group 2: Trace state ── -->
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

      <!-- Reparse -->
      <button
        v-if="store.currentFile"
        class="float-ctrl__item float-ctrl__item--reparse"
        :class="{ 'float-ctrl__item--loading': reparsing }"
        :disabled="reparsing"
        title="Reparse (drops parsed data)"
        @click="doReparse"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
          <path d="M2 7.5a5.5 5.5 0 1 0 1.1-3.3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
          <path d="M2 3v4.5h4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>

      <div class="float-ctrl__divider" />

      <!-- ── Group 3: Debug & runtime ── -->
      <!-- Live console (backend errors + frontend console.*) -->
      <button
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--active': liveConsoleOpen, 'float-ctrl__item--log': liveConsoleOpen }"
        title="Live console — backend errors + frontend console.log"
        @click="liveConsoleOpen = !liveConsoleOpen; if (liveConsoleOpen) liveConsoleBadge = 0"
      >
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
          <rect x="1.5" y="3" width="12" height="9" rx="1.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M4 6.5h7M4 9h4.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <circle v-if="liveConsoleBadge > 0" cx="12" cy="3" r="2.5" fill="#e05050"/>
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
          <ellipse cx="7.5" cy="8.5" rx="3" ry="3.8" stroke="currentColor" stroke-width="1.3"/>
          <circle cx="7.5" cy="4.2" r="1.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M6.5 3L5 1.5M8.5 3L10 1.5" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <path d="M4.5 7L2.5 6.5M4.5 8.5L2.5 8.5M4.5 10L2.5 11" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
          <path d="M10.5 7L12.5 6.5M10.5 8.5L12.5 8.5M10.5 10L12.5 11" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
        <span class="xd-dot" />
      </button>

      <div class="float-ctrl__divider" />

      <!-- ── Group 4: Global ── -->
      <!-- Theme toggle -->
      <button
        class="float-ctrl__item float-ctrl__item--theme"
        :title="store.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
        @click="store.toggleTheme()"
      >
        <svg v-if="store.theme === 'dark'" width="15" height="15" viewBox="0 0 15 15" fill="none">
          <path d="M13 9.5A6 6 0 1 1 5.5 2a4.5 4.5 0 0 0 7.5 7.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
        </svg>
        <svg v-else width="15" height="15" viewBox="0 0 15 15" fill="none">
          <circle cx="7.5" cy="7.5" r="2.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M7.5 1v1.5M7.5 12.5V14M1 7.5h1.5M12.5 7.5H14M2.93 2.93l1.06 1.06M11.01 11.01l1.06 1.06M2.93 12.07l1.06-1.06M11.01 3.99l1.06-1.06" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
      </button>

      <!-- Settings gear -->
      <button
        class="float-ctrl__item"
        :class="{ 'float-ctrl__item--active': activeModal === 'settings' }"
        title="Settings"
        @click="openModal('settings')"
      >
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
          <path d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
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

  <!-- AI summary modal (separate overlay so it sits above other modals) -->
  <SummaryModal
    :visible="summaryVisible"
    :loading="summaryLoading"
    :error="summaryError"
    :text="summaryText"
    :stats="summaryStats"
    :truncated="summaryTruncated"
    @cancel="closeSummary"
    @copy="onSummaryCopied"
  />

  <!-- Live console — opened by the console button in the ctrl menu -->
  <LiveConsole
    v-if="liveConsoleOpen"
    :open="liveConsoleOpen"
    @close="liveConsoleOpen = false"
    @badge="liveConsoleBadge = $event"
  />

  <!-- DB Queries full-page workspace -->
  <QbPage
    v-if="qbOpen"
    :file-id="store.activeTabFileId"
    @close="qbOpen = false"
  />
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import SettingsPage from './SettingsPage.vue'
import QbPage from './QbPage.vue'
import LiveConsole from './LiveConsole.vue'
import SummaryModal from './SummaryModal.vue'
import { useTraceStore } from '../stores/trace'
import { usePerfStore } from '../stores/perf'
import { usePerfTrack } from '../perfTrack'
import { favColor } from '../favColor.js'

const props = defineProps({
  tocRef: { type: Object, default: null },
})

const store = useTraceStore()
const perf = usePerfStore()
usePerfTrack('FloatCtrl', { category: 'render' })
const activeModal = ref(null)
const collapsed = ref(false)
const ctrlRef = ref(null)

function onDocClick(e) {
  if (ctrlRef.value && !ctrlRef.value.contains(e.target)) {
    xdOpen.value = false
    favOpen.value = false
    timingsOpen.value = false
  }
}
onMounted(() => document.addEventListener('mousedown', onDocClick))
onUnmounted(() => document.removeEventListener('mousedown', onDocClick))

// ── Live console panel ──
// The "console" button in the ctrl menu opens this. It shows both
// backend errors (from /api/errors, polled) and frontend console.* calls
// (intercepted on demand via a toggle inside the panel).
const liveConsoleOpen = ref(false)
const liveConsoleBadge = ref(0)   // bumps when new entries arrive while closed
watch(liveConsoleBadge, (n) => {
  // no-op — kept reactive for the badge dot
})

const hasTrace = computed(() => store.openTabs.some(t => t.status === 'ready'))

const reparsing = ref(false)
async function doReparse() {
  const f = store.currentFile
  if (!f) return
  if (!confirm(`Reparse "${f.name}"?\nAll parsed data will be dropped and re-built from scratch.`)) return
  reparsing.value = true
  try {
    await store.reparse(f.file_id)
  } finally {
    reparsing.value = false
  }
}

// ── AI summary modal ────────────────────────────────────────────────────────
const summaryVisible   = ref(false)
const summaryLoading   = ref(false)
const summaryError     = ref(null)
const summaryText      = ref('')
const summaryStats     = ref(null)
const summaryTruncated = ref(false)

async function openSummary() {
  const f = store.currentFile
  if (!f) return
  summaryVisible.value = true
  summaryError.value   = null
  summaryText.value    = ''
  summaryStats.value   = null
  summaryTruncated.value = false
  summaryLoading.value = true
  try {
    const t0 = performance.now()
    const result = await store.buildSummary(f.file_id)
    const elapsed = Math.round(performance.now() - t0)
    summaryText.value      = result.text      ?? ''
    summaryStats.value     = result.stats     ?? null
    summaryTruncated.value = !!result.truncated
    console.log(`[summary] built in ${elapsed}ms · ${result.stats?.chars ?? 0} chars · sections=${result.stats?.sections_included ?? '?'}`)
  } catch (e) {
    console.error('[summary] build failed:', e)
    summaryError.value = e?.response?.data?.error || e?.message || 'Failed to build summary'
  } finally {
    summaryLoading.value = false
  }
}

function closeSummary() {
  if (summaryLoading.value) return
  summaryVisible.value = false
}

function onSummaryCopied(text) {
  console.log(`[summary] copied ${text.length} chars to clipboard`)
}

function openModal(id) {
  activeModal.value = activeModal.value === id ? null : id
}

// ── QbPage (full-page DB queries workspace) ──────────────────────────────
const qbOpen = ref(false)
function toggleQb() {
  qbOpen.value = !qbOpen.value
  if (qbOpen.value) activeModal.value = null   // close any modal
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

// ── Backend timings panel ──────────────────────────────────────────────────
const timingsOpen = ref(false)
const timings = ref([])
let timingsTimer = null

function toggleTimings() {
  xdOpen.value = false
  favOpen.value = false
  timingsOpen.value = !timingsOpen.value
}

// Render helpers for frontend timings.
function formatFeDuration(ms) {
  if (ms < 1)    return ms.toFixed(2) + ' ms'
  if (ms < 10)   return ms.toFixed(1) + ' ms'
  if (ms < 1000) return Math.round(ms) + ' ms'
  return (ms / 1000).toFixed(2) + ' s'
}
function feTime(ts) {
  const d = new Date(ts)
  return d.toTimeString().slice(0, 8)
}

// ── Copy all timings to clipboard ──
const copyFlash = ref(false)
let copyFlashTimer = null
function pad(s, n) { return String(s).padEnd(n).slice(0, n) }

function buildTimingsText() {
  const lines = []
  const now = new Date()
  const stamp = now.toISOString().replace('T', ' ').slice(0, 19)
  lines.push(`# XTrace timings — ${stamp}`)
  if (store.currentFile) lines.push(`# file: ${store.currentFile.name}`)
  lines.push('')

  lines.push(`## Backend (${timings.value.length})`)
  if (!timings.value.length) {
    lines.push('(no backend requests)')
  } else {
    // method(6)  url(padded)  duration(right-aligned in 10)  time
    for (const t of timings.value) {
      lines.push(`${pad(t.endpoint_method, 6)} ${pad(t.endpoint_url, 50)} ${pad(t.duration_ms + ' ms', 12)} ${t.created}`)
    }
  }
  lines.push('')

  lines.push(`## Frontend (${perf.entries.length})`)
  if (!perf.entries.length) {
    lines.push('(no frontend events)')
  } else {
    for (const e of perf.entries) {
      const cat = pad(e.category, 7)
      const dur = pad(formatFeDuration(e.durationMs), 10)
      lines.push(`${cat} ${pad(e.name, 50)} ${dur} ${feTime(e.ts)}`)
    }
  }
  return lines.join('\n')
}

async function copyTimings() {
  const text = buildTimingsText()
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback for non-secure contexts: temporary textarea + execCommand.
      const ta = document.createElement('textarea')
      ta.value = text
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
    }
    copyFlash.value = true
    clearTimeout(copyFlashTimer)
    copyFlashTimer = setTimeout(() => { copyFlash.value = false }, 1200)
  } catch (e) {
    console.error('copy failed:', e)
  }
}
onUnmounted(() => clearTimeout(copyFlashTimer))

async function loadTimings() {
  const f = store.currentFile
  if (!f) return
  try {
    timings.value = await store.fetchTimings(f.file_id)
  } catch {}
}

watch(timingsOpen, (open) => {
  if (open) {
    loadTimings()
    timingsTimer = setInterval(loadTimings, 3000)
  } else {
    clearInterval(timingsTimer)
    timingsTimer = null
  }
})

watch(() => store.currentFile?.file_id, () => {
  if (timingsOpen.value) loadTimings()
})

function durationClass(ms) {
  if (ms >= 500) return 'timing--slow'
  if (ms >= 100) return 'timing--mid'
  return 'timing--fast'
}

onUnmounted(() => clearInterval(timingsTimer))
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
}
html[data-theme="light"] .float-ctrl {
  background: rgba(232, 238, 252, 0.90);
  border-color: rgba(140, 165, 220, 0.5);
  filter: drop-shadow(0 4px 24px rgba(80, 100, 180, 0.18));
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

/* Thin vertical separator between semantic button groups.
   Width 1px, height matches the icon row, slightly muted so it groups
   without competing visually with the icons themselves. */
.float-ctrl__divider {
  width: 1px;
  height: 20px;
  background: rgba(55, 65, 110, 0.4);
  margin: 0 4px;
  flex-shrink: 0;
}
html[data-theme="light"] .float-ctrl__divider {
  background: rgba(140, 160, 220, 0.5);
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
html[data-theme="light"] .float-ctrl__item {
  color: rgba(50, 80, 160, 0.6);
}
html[data-theme="light"] .float-ctrl__item:hover {
  color: rgba(20, 50, 140, 0.95);
  background: rgba(80, 110, 200, 0.12);
}
html[data-theme="light"] .float-ctrl__item--active {
  color: rgba(20, 60, 180, 1);
  background: rgba(80, 120, 220, 0.18);
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
.float-ctrl__item--reparse {
  color: rgba(180, 120, 80, 0.65);
}
.float-ctrl__item--reparse:hover {
  color: rgba(240, 160, 80, 0.95);
  background: rgba(120, 60, 20, 0.18);
}
html[data-theme="light"] .float-ctrl__item--reparse {
  color: rgba(160, 90, 30, 0.65);
}
html[data-theme="light"] .float-ctrl__item--reparse:hover {
  color: rgba(180, 80, 10, 0.95);
}
.float-ctrl__item--summary {
  color: rgba(190, 165, 255, 0.75);
}
.float-ctrl__item--summary:hover {
  color: rgba(220, 195, 255, 1);
  background: rgba(120, 80, 220, 0.18);
}
html[data-theme="light"] .float-ctrl__item--summary {
  color: rgba(110, 80, 200, 0.7);
}
html[data-theme="light"] .float-ctrl__item--summary:hover {
  color: rgba(90, 50, 200, 0.95);
  background: rgba(120, 80, 220, 0.10);
}
.float-ctrl__item--theme {
  color: rgba(100, 140, 200, 0.6);
}
html[data-theme="light"] .float-ctrl__item--theme {
  color: rgba(180, 130, 30, 0.75);
}
html[data-theme="light"] .float-ctrl__item--theme:hover {
  color: rgba(160, 100, 10, 0.95);
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

/* ── Backend timings panel ── */
.timings-panel {
  display: flex;
  flex-direction: column;
  min-width: 360px;
  max-width: 440px;
}
.timings-panel__header {
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(140, 165, 215, 0.75);
  padding: 6px 10px 4px;
}
html[data-theme="light"] .timings-panel__header {
  color: rgba(30, 60, 140, 0.7);
}
.timings-panel__list {
  display: flex;
  flex-direction: column;
  gap: 1px;
  max-height: 240px;
  overflow-y: auto;
  padding: 0 0 4px;
}
.timings-panel__list::-webkit-scrollbar { width: 3px; }
.timings-panel__list::-webkit-scrollbar-thumb { background: rgba(80,100,160,0.3); border-radius: 2px; }
.timings-panel__empty {
  font-size: 10px;
  color: rgba(80,90,140,0.5);
  padding: 6px 10px;
  font-family: 'JetBrains Mono', monospace;
}
.timings-panel__row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
  border-radius: 5px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  transition: background 0.1s;
}
.timings-panel__row:hover { background: rgba(255,255,255,0.05); }
.timings-panel__method {
  flex-shrink: 0;
  width: 42px;
  text-align: center;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.04em;
  padding: 2px 0;
  border-radius: 4px;
  color: rgba(140, 160, 210, 0.85);
  background: rgba(255,255,255,0.06);
}
.timings-panel__method.method--get    { color: #6cb8ff; background: rgba(60, 130, 255, 0.16); }
.timings-panel__method.method--post   { color: #6fd98e; background: rgba(70, 200, 120, 0.16); }
.timings-panel__method.method--put,
.timings-panel__method.method--patch  { color: #ffb766; background: rgba(255, 160, 60, 0.16); }
.timings-panel__method.method--delete { color: #ff8a8a; background: rgba(230, 70, 70, 0.16); }
.timings-panel__url {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgba(180, 195, 230, 0.9);
}
html[data-theme="light"] .timings-panel__url {
  color: rgba(20, 40, 90, 0.85);
}
.timings-panel__duration {
  flex-shrink: 0;
  width: 56px;
  text-align: right;
  font-weight: 600;
}
.timings-panel__duration.timing--fast { color: #6fd98e; }
.timings-panel__duration.timing--mid  { color: #ffc966; }
.timings-panel__duration.timing--slow { color: #ff8a8a; }
.timings-panel__time {
  flex-shrink: 0;
  width: 52px;
  text-align: right;
  color: rgba(100, 115, 160, 0.7);
  font-size: 10px;
}

/* Frontend-timings header row (title + actions) */
.timings-panel__header--row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-right: 6px;
}
.timings-panel__hint {
  font-family: 'JetBrains Mono', monospace;
  font-size: 9px;
  line-height: 1.45;
  color: rgba(110, 125, 170, 0.6);
  padding: 0 10px 6px;
}
html[data-theme="light"] .timings-panel__hint {
  color: rgba(50, 70, 130, 0.55);
}
.timings-panel__head-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}
.timings-panel__head-count {
  font-size: 9px;
  color: rgba(120, 140, 190, 0.7);
  font-family: 'JetBrains Mono', monospace;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  padding: 1px 6px;
  min-width: 22px;
  text-align: center;
}
html[data-theme="light"] .timings-panel__head-count {
  color: rgba(40, 60, 130, 0.6);
  background: rgba(80, 100, 200, 0.08);
}
.timings-panel__head-btn {
  background: none;
  border: 1px solid rgba(80, 100, 160, 0.25);
  color: rgba(140, 160, 210, 0.75);
  font-size: 9px;
  line-height: 1;
  padding: 2px 5px;
  border-radius: 4px;
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  transition: background 0.1s, color 0.1s;
}
.timings-panel__head-btn:hover {
  background: rgba(255, 255, 255, 0.06);
  color: rgba(200, 220, 255, 0.95);
}
.timings-panel__head-btn--off {
  color: rgba(120, 130, 160, 0.5);
  border-color: rgba(80, 100, 160, 0.18);
}
.timings-panel__head-btn--off:hover {
  color: rgba(100, 200, 140, 0.9);
}
.timings-panel__head-btn--ok {
  color: #6fd98e;
  border-color: rgba(70, 200, 120, 0.45);
  background: rgba(70, 200, 120, 0.12);
}
.timings-panel__head-btn--ok:hover {
  color: #6fd98e;
  background: rgba(70, 200, 120, 0.16);
}

/* In-panel section labels (backend / frontend) */
.timings-panel__section {
  font-family: 'JetBrains Mono', monospace;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(140, 165, 215, 0.6);
  padding: 6px 10px 2px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.timings-panel__section--spaced {
  margin-top: 4px;
  border-top: 1px solid rgba(55, 65, 110, 0.3);
  padding-top: 8px;
}
html[data-theme="light"] .timings-panel__section {
  color: rgba(30, 60, 140, 0.55);
}
html[data-theme="light"] .timings-panel__section--spaced {
  border-top-color: rgba(160, 180, 220, 0.4);
}
.timings-panel__section-count {
  font-size: 9px;
  font-weight: 600;
  color: rgba(120, 140, 190, 0.6);
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  padding: 0 6px;
}
html[data-theme="light"] .timings-panel__section-count {
  color: rgba(40, 60, 130, 0.55);
  background: rgba(80, 100, 200, 0.08);
}

/* Frontend row variant — name + category badge + duration */
.timings-panel__row--fe { gap: 6px; }
.timings-panel__cat {
  flex-shrink: 0;
  width: 44px;
  text-align: center;
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.04em;
  padding: 2px 0;
  border-radius: 4px;
  text-transform: lowercase;
  color: rgba(200, 215, 245, 0.9);
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(120, 140, 190, 0.18);
}
.perf-cat--api     { color: #6cb8ff; background: rgba(60, 130, 255, 0.14);  border-color: rgba(60, 130, 255, 0.32); }
.perf-cat--tab     { color: #c9a8ff; background: rgba(150,  90, 220, 0.14); border-color: rgba(150,  90, 220, 0.30); }
.perf-cat--render  { color: #6fd98e; background: rgba(70,  200, 120, 0.14); border-color: rgba(70,  200, 120, 0.30); }
.perf-cat--toc     { color: #ffb766; background: rgba(255, 160,  60, 0.14); border-color: rgba(255, 160,  60, 0.30); }
.perf-cat--search  { color: #ff8ac6; background: rgba(220,  80, 170, 0.14); border-color: rgba(220,  80, 170, 0.30); }
.perf-cat--boot    { color: #9ec0ff; background: rgba(100, 160, 255, 0.14); border-color: rgba(100, 160, 255, 0.28); }
.perf-cat--custom  { color: #aaa;    background: rgba(160, 160, 160, 0.10); border-color: rgba(160, 160, 160, 0.24); }
html[data-theme="light"] .timings-panel__cat { color: rgba(30, 50, 100, 0.85); background: rgba(80, 110, 200, 0.06); border-color: rgba(80, 110, 200, 0.18); }

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
html[data-theme="light"] .float-modal {
  background: rgba(240, 244, 255, 0.96);
  border-color: rgba(140, 160, 220, 0.5);
  box-shadow: 0 8px 60px rgba(80, 100, 200, 0.18), 0 0 0 1px rgba(80,100,200,0.06);
}

.float-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px 12px;
  border-bottom: 1px solid rgba(40, 50, 90, 0.4);
  flex-shrink: 0;
}
html[data-theme="light"] .float-modal__header {
  border-bottom-color: rgba(140, 160, 210, 0.35);
}

.float-modal__title {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  color: rgba(140, 165, 215, 0.75);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}
html[data-theme="light"] .float-modal__title {
  color: rgba(30, 60, 140, 0.7);
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
html[data-theme="light"] .float-modal__close {
  color: rgba(60, 80, 150, 0.65);
}
html[data-theme="light"] .float-modal__close:hover {
  color: rgba(200, 40, 40, 0.9);
  background: rgba(200, 40, 40, 0.1);
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
