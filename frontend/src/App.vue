<template>
  <div class="app">
    <DesertBackground />

    <!-- ── Tab bar (always visible) ── -->
    <div class="tabs-bar">
      <div class="tabs-bar__logo">XTrace</div>

      <!-- Trace tabs -->
      <div
        v-for="tab in store.openTabs"
        :key="tab.fileId"
        class="trace-tab"
        :class="{
          'trace-tab--active': store.activeTabFileId === tab.fileId,
          'trace-tab--parsing': tab.status === 'parsing',
          'trace-tab--pending': tab.status === 'pending',
          'trace-tab--error': tab.status === 'error',
        }"
        @click="switchToTrace(tab.fileId)"
      >
        <span class="trace-tab__dot" :class="'dot--' + tab.status" />
        <span class="trace-tab__name">{{ shortName(tab.name) }}</span>
        <span v-if="tab.status === 'parsing'" class="trace-tab__progress">{{ tab.progress }}%</span>
        <span v-else-if="tab.status === 'pending'" class="trace-tab__progress">queued</span>
        <span class="trace-tab__close" @click.stop="store.closeTab(tab.fileId)">✕</span>
      </div>

      <!-- Add tab -->
      <button class="tab-add" @click="toggleBrowser" :class="{ 'tab-add--open': showBrowser }">
        +
      </button>

      <div class="tabs-bar__spacer" />
    </div>

    <!-- ── File browser modal ── -->
    <transition name="modal-fade">
      <div v-if="showBrowser" class="fb-overlay" @click.self="showBrowser = false">
        <div class="fb-modal">
          <div class="fb-modal__header">
            <span class="fb-modal__title">Open trace</span>
            <input
              ref="searchInput"
              v-model="browseQuery"
              class="fb-modal__search"
              placeholder="filter…"
              @keydown.escape="showBrowser = false"
            />
            <span v-if="loadingBrowse" class="fb-modal__loading">scanning…</span>
            <button class="fb-modal__close" @click="showBrowser = false">✕</button>
          </div>
          <div class="fb-modal__body">
            <!-- Left: folders -->
            <div class="fb-dirs">
              <div
                v-for="dir in browseDirs"
                :key="dir ?? '__root__'"
                class="fb-dir"
                :class="{ 'fb-dir--active': selectedDir === dir }"
                @click="selectedDir = dir"
              >
                <template v-if="dir">
                  <div class="fb-dir__parsed">
                    <span class="fb-dir__label">{{ parseDirName(dir)?.label ?? dir }}</span>
                    <span v-if="parseDirName(dir)?.time" class="fb-dir__time">{{ parseDirName(dir).time }}</span>
                  </div>
                </template>
                <template v-else>
                  <span class="fb-dir__label fb-dir__label--root">root</span>
                </template>
                <span class="fb-dir__count">{{ browseFiles.filter(f => (f.dir || null) === dir).length }}</span>
              </div>
              <div v-if="!browseDirs.length && !loadingBrowse" class="fb-dirs__empty">no dirs</div>
            </div>
            <!-- Right: files -->
            <div class="fb-files">
              <div v-if="!filesInDir.length && !loadingBrowse" class="fb-files__empty">
                {{ browseQuery ? 'no matches' : 'empty' }}
              </div>
              <div
                v-for="f in filesInDir"
                :key="f.rel_path"
                class="fb-file"
                @click="openFile(f.rel_path)"
              >
                <div class="fb-file__parsed">
                  <span class="fb-file__label">{{ parseFileName(f.name).label }}</span>
                  <span v-if="parseFileName(f.name).time" class="fb-file__time">{{ parseFileName(f.name).time }}</span>
                </div>
                <span class="fb-file__size">{{ formatSize(f.size) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <!-- ── Parsing status bar (for active tab) ── -->
    <transition name="fade">
      <div
        v-if="store.currentFile?.status === 'parsing'"
        class="status-bar status-bar--parsing"
      >
        <div class="status-bar__fill" :style="{ width: (store.currentFile.progress || 0) + '%' }" />
        <div class="status-bar__content">
          <span class="status-bar__pulse" />
          <span>Parsing</span>
          <span class="status-bar__pct">{{ store.currentFile.progress }}%</span>
          <span class="status-bar__name">{{ store.currentFile.name }}</span>
        </div>
      </div>
      <div
        v-else-if="store.currentFile?.status === 'pending'"
        class="status-bar status-bar--pending"
      >
        <div class="status-bar__content">
          <span class="status-bar__pulse status-bar__pulse--slow" />
          <span>Queued for parsing…</span>
        </div>
      </div>
      <div
        v-else-if="store.currentFile?.status === 'error'"
        class="status-bar status-bar--error"
      >
        <div class="status-bar__content">
          ✕ Parse error: {{ store.currentFile.errorMessage }}
        </div>
      </div>
    </transition>

    <!-- ── Fav scan indicator ── -->
    <transition name="fade">
      <div
        v-if="store.currentTab?.scanning"
        class="status-bar status-bar--scanning"
      >
        <div class="status-bar__content">
          <span class="status-bar__pulse" />
          <span>Scanning favourites…</span>
        </div>
      </div>
    </transition>

    <!-- ── Request info bar ── -->
    <RequestInfo
      v-if="store.currentTab?.request"
      :req="store.currentTab.request"
    />

    <!-- ── Response info bar ── -->
    <ResponseInfo
      v-if="store.currentTab?.response"
      :res="store.currentTab.response"
    />

    <!-- ── Main content ── -->
    <div class="main">

      <!-- Trace views -->
      <template v-for="tab in store.openTabs" :key="tab.fileId">
        <div
          v-show="store.activeTabFileId === tab.fileId && tab.status === 'ready'"
          class="trace-view"
          :style="store.activeCodeNode ? { gridTemplateColumns: splitWidth + 'px 4px 1fr' } : { gridTemplateColumns: '1fr' }"
        >
          <!-- Left: TOC -->
          <TocTree
            v-if="tab.toc.length"
            :ref="el => { if (el) { tocTreeRefs[tab.fileId] = el; if (tab.fileId === store.activeTabFileId) activeTocRef.value = el } }"
            :toc="tab.toc"
            :file-id="tab.fileId"
            @jump="onJump"
            @breadcrumb="onBreadcrumb"
          />

          <!-- Resize handle (only when code panel is open) -->
          <div
            v-if="store.activeCodeNode"
            class="split-resizer"
            @mousedown.prevent="startSplitResize"
          />

          <!-- Right: Code view (only when node selected) -->
          <CodeView v-if="store.activeCodeNode" />
        </div>
      </template>

      <!-- Empty state -->
      <div
        v-if="!store.openTabs.length"
        class="empty-state"
      >
        <div class="empty-state__icon">⟁</div>
        <div class="empty-state__text">Click <span class="empty-state__plus">+</span> to open a trace</div>
      </div>
    </div>

    <!-- ── Breadcrumbs (bottom) ── -->
    <Breadcrumbs :path="breadcrumbPath" :last-line="breadcrumbLine" />

    <!-- ── Export panel (floating) ── -->
    <ExportPanel />

    <!-- ── Selection preview (floating, right side) ── -->
    <SelectionPreview />

    <!-- ── Float control bar (bottom-right) ── -->
    <FloatCtrl :toc-ref="activeTocRef" />

    <!-- ── Jump toast ── -->
    <transition name="toast">
      <div v-if="jumpToast" class="jump-toast">
        ↱ line {{ jumpToast.toLocaleString() }}
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick, shallowRef } from 'vue'
import { useTraceStore } from './stores/trace'
import { usePerfStore } from './stores/perf'

// apply saved theme immediately (before mount) to avoid flash
const _savedTheme = localStorage.getItem('xtrace-theme') || 'dark'
document.documentElement.setAttribute('data-theme', _savedTheme)
import TocTree from './components/TocTree.vue'
import DesertBackground from './components/DesertBackground.vue'
import RequestInfo from './components/RequestInfo.vue'
import ResponseInfo from './components/ResponseInfo.vue'
import ExportPanel from './components/ExportPanel.vue'
import Breadcrumbs from './components/Breadcrumbs.vue'
import SelectionPreview from './components/SelectionPreview.vue'
import CodeView from './components/CodeView.vue'
import FloatCtrl from './components/FloatCtrl.vue'
import axios from 'axios'

const store = useTraceStore()
const splitWidth = ref(560)

let splitResizeStart = null
function startSplitResize(e) {
  splitResizeStart = { x: e.clientX, width: splitWidth.value }
  document.addEventListener('mousemove', onSplitResize)
  document.addEventListener('mouseup', stopSplitResize)
}
function onSplitResize(e) {
  if (!splitResizeStart) return
  splitWidth.value = Math.max(260, Math.min(window.innerWidth - 320, splitResizeStart.width + e.clientX - splitResizeStart.x))
}
function stopSplitResize() {
  splitResizeStart = null
  document.removeEventListener('mousemove', onSplitResize)
  document.removeEventListener('mouseup', stopSplitResize)
}
const showBrowser = ref(false)
const browseFiles = ref([])
const browseQuery = ref('')
const loadingBrowse = ref(false)
const selectedDir = ref(null)
const jumpToast = ref(null)
const breadcrumbPath = ref([])
const breadcrumbLine = ref(null)
const searchInput = ref(null)
let jumpToastTimer = null
const tocTreeRefs = {}
const activeTocRef = shallowRef(null)

// All unique dirs (null = root)
const browseDirs = computed(() => {
  const dirs = new Set()
  browseFiles.value.forEach(f => dirs.add(f.dir || null))
  const sorted = [...dirs].sort((a, b) => {
    if (a === null) return -1
    if (b === null) return 1
    return a.localeCompare(b)
  })
  return sorted
})

const filesInDir = computed(() => {
  const q = browseQuery.value.trim().toLowerCase()
  return browseFiles.value.filter(f => {
    const dirMatch = f.dir === selectedDir.value || (selectedDir.value === null && !f.dir)
    const qMatch = !q || f.name.toLowerCase().includes(q) || (f.dir && f.dir.toLowerCase().includes(q))
    return dirMatch && qMatch
  })
})

const filteredBrowseFiles = computed(() => {
  const q = browseQuery.value.trim().toLowerCase()
  if (!q) return browseFiles.value
  return browseFiles.value.filter(f =>
    f.name.toLowerCase().includes(q) || (f.dir && f.dir.toLowerCase().includes(q))
  )
})

store.loadFiles()
store.loadFavourites()
store.loadSettings()

watch(() => store.theme, (t) => {
  document.documentElement.setAttribute('data-theme', t)
}, { immediate: true })

let restoringSession = false
onMounted(async () => {
  restoringSession = true
  await store.restoreSession()
  restoringSession = false
})

watch(
  () => [store.openTabs.map(t => t.fileId), store.activeTabFileId],
  () => { if (!restoringSession) store.persistSession() },
  { deep: true }
)

watch(
  () => {
    const tab = store.openTabs.find(t => t.fileId === store.activeTabFileId)
    return tab?.toc?.length ?? 0
  },
  () => nextTick(() => {
    activeTocRef.value = tocTreeRefs[store.activeTabFileId] ?? null
  })
)

function toggleBrowser() {
  showBrowser.value = !showBrowser.value
  if (showBrowser.value) {
    browseQuery.value = ''
    loadBrowse().then(() => {
      selectedDir.value = browseDirs.value[0] ?? null
    })
  }
}

async function loadBrowse() {
  loadingBrowse.value = true
  const { data } = await axios.get('/api/browse')
  browseFiles.value = data
  loadingBrowse.value = false
}

async function openFile(relPath) {
  showBrowser.value = false
  const { data } = await axios.post('/api/open', { rel_path: relPath })
  // Use the full relPath (dir + file) as display name — the directory encodes the
  // correct wall-clock time (e.g. 2026-06-11_17-42-54_…) which gets lost if we
  // just take the filename. The tab shortName() truncates to 26 chars anyway.
  await store.selectFile(data.file_id, relPath)
  await store.loadFiles()
  if (store.currentFile?.status !== 'ready') store.startPolling(data.file_id)
}

const perf = usePerfStore()

function switchToTrace(fileId) {
  const tab = store.openTabs.find(t => t.fileId === fileId)
  const name = shortName(tab?.name || fileId)
  perf.time(`switch → ${name}`, 'tab', () => {
    store.switchToTab(fileId)
    showBrowser.value = false
    // nextTick is sync, so time() will measure the DOM update latency too.
    return nextTick().then(() => { activeTocRef.value = tocTreeRefs[fileId] ?? null })
  })
}

function onJump(lineNo) {
  jumpToast.value = lineNo
  clearTimeout(jumpToastTimer)
  jumpToastTimer = setTimeout(() => { jumpToast.value = null }, 2000)
  const treeRef = tocTreeRefs[store.activeTabFileId]
  if (treeRef?.jumpToLine) treeRef.jumpToLine(lineNo)
}

function onBreadcrumb({ crumbs, line }) {
  breadcrumbPath.value = crumbs
  breadcrumbLine.value = line
}

function shortName(name) {
  if (!name) return '?'
  const base = name.replace(/\.xt$/, '')
  return base.length > 26 ? '…' + base.slice(-24) : base
}

function formatSize(bytes) {
  if (bytes > 1e9) return (bytes / 1e9).toFixed(1) + ' GB'
  if (bytes > 1e6) return (bytes / 1e6).toFixed(0) + ' MB'
  return (bytes / 1e3).toFixed(0) + ' KB'
}

// "2026-06-05_11-02-14_inner-api_user_user-data_7094798_..." → { time: "2026-06-05 11:02:14", label: "inner-api / user / user-data" }
function parseDirName(dir) {
  if (!dir) return null
  // Match leading datetime: YYYY-MM-DD_HH-MM-SS
  const m = dir.match(/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})_(.+)$/)
  if (!m) return { time: null, label: dir }
  const time = `${m[1]} ${m[2]}:${m[3]}:${m[4]}`
  // Remaining: strip trailing timestamp (long number at end)
  const rest = m[5].replace(/_\d{7,}$/, '').replace(/_/g, ' / ')
  return { time, label: rest }
}

// "trace__inner-api_user-user-data_161_1780642982.xt" → { label: "inner-api / user-user-data / 161", time: "2026-06-05 11:02" }
function parseFileName(name) {
  // Strip .xt
  let base = name.replace(/\.xt$/, '')
  // Strip leading "trace__" or "trace_"
  base = base.replace(/^trace__?/, '')
  // Extract trailing unix timestamp (_XXXXXXXXXX at end, 9-11 digits)
  const tsMatch = base.match(/_(\d{9,11})$/)
  let timeStr = null
  if (tsMatch) {
    const ts = parseInt(tsMatch[1])
    // Sanity: unix timestamps from ~2020 to ~2040
    if (ts > 1580000000 && ts < 2200000000) {
      const d = new Date(ts * 1000)
      timeStr = d.toLocaleString('sv').replace('T', ' ').slice(0, 16) // "YYYY-MM-DD HH:MM"
    }
    base = base.slice(0, -tsMatch[0].length)
  }
  // Convert underscores to " / ", collapse multiple
  const label = base.replace(/_+/g, ' / ').replace(/\s*\/\s*\/\s*/g, ' / ').replace(/^\s*\/\s*/, '').replace(/\s*\/\s*$/, '')
  return { label, time: timeStr }
}
</script>

<style>
/* ── CSS theme variables ── */
:root,
html[data-theme="dark"] {
  --bg-root:         #0a0c14;
  --bg-bar:          rgba(8, 8, 18, 0.72);
  --bg-panel:        rgba(8, 8, 18, 0.80);
  --bg-panel-solid:  #0d1020;
  --bg-row-hover:    rgba(30, 40, 70, 0.4);
  --bg-input:        rgba(20, 24, 44, 0.95);
  --bg-fill-parse:   linear-gradient(90deg, #0d2040, #0a1428);
  --bg-status-parse: #080e18;
  --bg-status-pend:  #0a0a18;
  --bg-status-err:   #120808;
  --bg-status-scan:  #080e14;
  --bg-toast:        #0e1a2e;
  --bg-resizer:      rgba(30, 50, 90, 0.4);
  --bg-resizer-act:  rgba(60, 100, 180, 0.5);

  --border-bar:      rgba(30, 30, 60, 0.5);
  --border-logo:     #2a2a48;
  --border-panel:    rgba(20, 20, 40, 0.6);
  --border-panel-hd: rgba(40, 50, 90, 0.6);
  --border-input:    rgba(60, 75, 120, 0.6);
  --border-input-fo: rgba(80, 120, 200, 0.8);
  --border-row:      rgba(25, 28, 50, 0.6);
  --border-toast:    #1e3a5a;
  --border-s-parse:  #0e1e30;
  --border-s-pend:   #14142a;
  --border-s-err:    #2a1010;
  --border-s-scan:   #0e1e2a;

  --text-base:       #c0c8d8;
  --text-logo:       #7a9ac0;
  --text-tab:        #6878a0;
  --text-tab-hover:  #a0b8d0;
  --text-tab-active: #c0d0e8;
  --text-tab-parse:  #5a8ab8;
  --text-tab-err:    #b05050;
  --text-tab-prog:   #3a6a9a;
  --text-tab-close:  #3a3a58;
  --text-add:        #6878a0;
  --text-add-hover:  #90b8e0;
  --text-add-open:   #6a9ac8;
  --text-nav:        #6878a0;
  --text-nav-hover:  #a0b8d0;
  --text-nav-active: #b0d0f0;
  --text-hd:         #b0c8e8;
  --text-count:      #8898c0;
  --text-load:       #6a90c8;
  --text-close-btn:  #8898b8;
  --text-input:      #a0b8d8;
  --text-ph:         #6070a0;
  --text-empty-file: #506878;
  --text-row-name:   #a0c0e0;
  --text-row-dir:    #6878a0;
  --text-s-parse:    #3a6a9a;
  --text-s-pend:     #3a3a5a;
  --text-s-err:      #7a4040;
  --text-s-scan:     #3a6a5a;
  --text-pct:        #4a8acc;
  --text-name-dim:   #2a3a55;
  --text-pulse:      #3a7acc;
  --text-pulse-slow: #3a3a7a;
  --text-toast:      #4a8acc;
  --text-empty:      rgba(160, 150, 130, 0.5);
  --text-plus:       rgba(180, 160, 120, 0.75);

  --dot-ready:       #2a5a2a;
  --dot-parsing:     #3a6a9a;
  --dot-pending:     #3a3a5a;
  --dot-error:       #7a2a2a;

  --nav-badge-bg:    rgba(50, 70, 120, 0.7);
  --nav-badge-text:  #90b8e8;
  --border-nav:      #4a7aa8;
  --tab-close-hov-c: #cc6060;
  --tab-close-hov-b: #1a0e0e;
  --close-hover:     #e06060;
}

html[data-theme="light"] {
  --bg-root:         #f0f2f8;
  --bg-bar:          rgba(240, 242, 250, 0.88);
  --bg-panel:        rgba(240, 242, 250, 0.92);
  --bg-panel-solid:  #e8eaf4;
  --bg-row-hover:    rgba(180, 200, 240, 0.3);
  --bg-input:        rgba(255, 255, 255, 0.90);
  --bg-fill-parse:   linear-gradient(90deg, #bdd4f0, #d0dff4);
  --bg-status-parse: #e0eaf8;
  --bg-status-pend:  #eaebf5;
  --bg-status-err:   #f8e0e0;
  --bg-status-scan:  #e0f0ea;
  --bg-toast:        #e8f0fc;
  --bg-resizer:      rgba(150, 180, 230, 0.35);
  --bg-resizer-act:  rgba(80, 130, 220, 0.45);

  --border-bar:      rgba(160, 170, 210, 0.45);
  --border-logo:     #b0bcd8;
  --border-panel:    rgba(160, 170, 210, 0.5);
  --border-panel-hd: rgba(140, 160, 210, 0.5);
  --border-input:    rgba(120, 145, 210, 0.55);
  --border-input-fo: rgba(60, 110, 210, 0.8);
  --border-row:      rgba(180, 192, 225, 0.6);
  --border-toast:    #90b0dc;
  --border-s-parse:  #a0c0e0;
  --border-s-pend:   #b8b8d8;
  --border-s-err:    #e0a0a0;
  --border-s-scan:   #90c8b0;

  --text-base:       #121828;
  --text-logo:       #1a4a80;
  --text-tab:        #2a3860;
  --text-tab-hover:  #0d1e48;
  --text-tab-active: #060f30;
  --text-tab-parse:  #1a4878;
  --text-tab-err:    #7a1010;
  --text-tab-prog:   #1a4880;
  --text-tab-close:  #4a5878;
  --text-add:        #2a3860;
  --text-add-hover:  #0e2860;
  --text-add-open:   #1a4880;
  --text-nav:        #2a3860;
  --text-nav-hover:  #0d1e48;
  --text-nav-active: #060f30;
  --text-hd:         #0d1830;
  --text-count:      #283060;
  --text-load:       #1a4888;
  --text-close-btn:  #283068;
  --text-input:      #101828;
  --text-ph:         #5a6890;
  --text-empty-file: #3a4870;
  --text-row-name:   #0d1e48;
  --text-row-dir:    #2a3860;
  --text-s-parse:    #1a4878;
  --text-s-pend:     #303070;
  --text-s-err:      #6a1010;
  --text-s-scan:     #1a5038;
  --text-pct:        #0e4898;
  --text-name-dim:   #4a5878;
  --text-pulse:      #1a58a0;
  --text-pulse-slow: #3a3888;
  --text-toast:      #0e4898;
  --text-empty:      rgba(40, 50, 100, 0.55);
  --text-plus:       rgba(20, 60, 150, 0.75);

  --dot-ready:       #2a742a;
  --dot-parsing:     #2a5a9a;
  --dot-pending:     #6868a0;
  --dot-error:       #9a2828;

  --nav-badge-bg:    rgba(100, 130, 210, 0.2);
  --nav-badge-text:  #2a5a9a;
  --border-nav:      #2a6aa8;
  --tab-close-hov-c: #cc3030;
  --tab-close-hov-b: #fce8e8;
  --close-hover:     #cc3030;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html, body, #app {
  height: 100%;
  background: var(--bg-root);
  color: var(--text-base);
}
.app { display: flex; flex-direction: column; height: 100vh; position: relative; }

/* ── Tab bar ── */
.tabs-bar {
  display: flex;
  align-items: stretch;
  gap: 2px;
  padding: 0 20px;
  background: var(--bg-bar);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid var(--border-bar);
  flex-shrink: 0;
  min-height: 40px;
  overflow-x: auto;
}

.tabs-bar__logo {
  display: flex;
  align-items: center;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  color: var(--text-logo);
  letter-spacing: 0.08em;
  padding: 0 12px 0 4px;
  border-right: 1px solid var(--border-logo);
  margin-right: 8px;
  flex-shrink: 0;
}

.tabs-bar__spacer { flex: 1; }

/* Trace tabs */
.trace-tab {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 0 12px;
  border-bottom: 2px solid transparent;
  color: var(--text-tab);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.12s, border-color 0.12s;
  position: relative;
}
.trace-tab:hover { color: var(--text-tab-hover); }
.trace-tab--active { color: var(--text-tab-active); border-bottom-color: var(--border-nav); }
.trace-tab--parsing { color: var(--text-tab-parse); }
.trace-tab--error { color: var(--text-tab-err); }

.trace-tab__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot--ready    { background: var(--dot-ready); }
.dot--parsing  { background: var(--dot-parsing); animation: pulse-dot 1.2s ease-in-out infinite; }
.dot--pending  { background: var(--dot-pending); animation: pulse-dot 2s ease-in-out infinite; }
.dot--error    { background: var(--dot-error); }

@keyframes pulse-dot {
  0%, 100% { opacity: 0.4; }
  50%       { opacity: 1; }
}

.trace-tab__name {
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.trace-tab__progress {
  font-size: 10px;
  color: var(--text-tab-prog);
  flex-shrink: 0;
}
.trace-tab__close {
  font-size: 10px;
  color: var(--text-tab-close);
  padding: 2px 3px;
  border-radius: 3px;
  line-height: 1;
  transition: color 0.1s, background 0.1s;
}
.trace-tab__close:hover { color: var(--tab-close-hov-c); background: var(--tab-close-hov-b); }

/* Add tab button */
.tab-add {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  background: none;
  border: none;
  color: var(--text-add);
  font-size: 18px;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.12s;
  padding-bottom: 2px;
}
.tab-add:hover { color: var(--text-add-hover); }
.tab-add--open { color: var(--text-add-open); }

/* Nav buttons */
.nav-btn {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 0 14px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--text-nav);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.12s, border-color 0.12s;
}
.nav-btn:hover { color: var(--text-nav-hover); }
.nav-btn--active { color: var(--text-nav-active); border-bottom-color: var(--border-nav); }

.nav-badge {
  background: var(--nav-badge-bg);
  color: var(--nav-badge-text);
  border-radius: 8px;
  font-size: 10px;
  padding: 0 5px;
  min-width: 16px;
  text-align: center;
}

/* ── File browser modal ── */
.fb-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
}

.fb-modal {
  background: var(--bg-panel-solid);
  border: 1px solid var(--border-panel);
  border-radius: 10px;
  width: min(820px, 90vw);
  height: min(520px, 80vh);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 24px 64px rgba(0,0,0,0.55);
}

.fb-modal__header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border-panel-hd);
  flex-shrink: 0;
}

.fb-modal__title {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  color: var(--text-hd);
  white-space: nowrap;
}

.fb-modal__search {
  flex: 1;
  background: var(--bg-input);
  border: 1px solid var(--border-input);
  border-radius: 4px;
  color: var(--text-input);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  padding: 4px 10px;
  outline: none;
  max-width: 260px;
}
.fb-modal__search::placeholder { color: var(--text-ph); }
.fb-modal__search:focus { border-color: var(--border-input-fo); }

.fb-modal__loading {
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  color: var(--text-load);
}

.fb-modal__close {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--text-close-btn);
  cursor: pointer;
  font-size: 13px;
  padding: 2px 6px;
  border-radius: 3px;
}
.fb-modal__close:hover { color: var(--close-hover); }

.fb-modal__body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* Left column: dirs */
.fb-dirs {
  width: 200px;
  flex-shrink: 0;
  border-right: 1px solid var(--border-panel-hd);
  overflow-y: auto;
  padding: 6px 0;
}

.fb-dir {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  padding: 7px 14px;
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--text-row-name);
  transition: background 0.1s;
  border-radius: 0;
}
.fb-dir:hover { background: var(--bg-row-hover); }
.fb-dir--active {
  background: rgba(60, 100, 200, 0.15);
  color: var(--text-nav-active);
  border-left: 2px solid var(--border-nav);
  padding-left: 12px;
}

.fb-dir__parsed {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  overflow: hidden;
}
.fb-dir__label {
  font-size: 11px;
  color: var(--text-row-name);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fb-dir__label--root {
  color: var(--text-row-dir);
  font-style: italic;
}
.fb-dir__time {
  font-size: 10px;
  color: var(--text-count);
}
.fb-dir__count {
  font-size: 10px;
  color: var(--text-count);
  background: var(--nav-badge-bg);
  border-radius: 8px;
  padding: 0 5px;
  flex-shrink: 0;
  align-self: flex-start;
  margin-top: 1px;
}

.fb-dirs__empty {
  padding: 16px;
  font-size: 11px;
  color: var(--text-empty-file);
  font-family: monospace;
  font-style: italic;
}

/* Right column: files */
.fb-files {
  flex: 1;
  overflow-y: auto;
  padding: 6px 0;
}

.fb-file {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 8px 20px;
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  border-bottom: 1px solid var(--border-row);
  transition: background 0.1s;
}
.fb-file:hover { background: var(--bg-row-hover); }
.fb-file__parsed {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  overflow: hidden;
}
.fb-file__label {
  color: var(--text-row-name);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fb-file__time {
  font-size: 10px;
  color: var(--text-count);
}
.fb-file__size { color: var(--text-row-dir); font-size: 10px; flex-shrink: 0; margin-left: 12px; align-self: flex-start; margin-top: 2px; }

.fb-files__empty {
  padding: 24px;
  font-size: 11px;
  color: var(--text-empty-file);
  font-family: monospace;
  font-style: italic;
}

/* ── Status bar ── */
.status-bar {
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
  height: 30px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
}
.status-bar--parsing {
  background: var(--bg-status-parse);
  border-bottom: 1px solid var(--border-s-parse);
  color: var(--text-s-parse);
}
.status-bar--pending {
  background: var(--bg-status-pend);
  border-bottom: 1px solid var(--border-s-pend);
  color: var(--text-s-pend);
}
.status-bar--error {
  background: var(--bg-status-err);
  border-bottom: 1px solid var(--border-s-err);
  color: var(--text-s-err);
}
.status-bar--scanning {
  background: var(--bg-status-scan);
  border-bottom: 1px solid var(--border-s-scan);
  color: var(--text-s-scan);
}

.status-bar__fill {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  background: var(--bg-fill-parse);
  transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}
.status-bar__content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 24px;
  height: 100%;
}
.status-bar__pct {
  font-weight: 600;
  color: var(--text-pct);
}
.status-bar__name {
  color: var(--text-name-dim);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status-bar__pulse {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--text-pulse);
  animation: pulse-dot 1s ease-in-out infinite;
  flex-shrink: 0;
}
.status-bar__pulse--slow {
  background: var(--text-pulse-slow);
  animation-duration: 2s;
}

/* ── Spinner (inline) ── */
.spinner-inline {
  width: 10px;
  height: 10px;
  border: 1.5px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  display: inline-block;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Main area ── */
.main {
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-height: 0;
  position: relative;
}

.trace-view {
  flex: 1;
  overflow: hidden;
  display: grid;
  grid-template-rows: 1fr;
  min-height: 0;
  min-width: 0;
}

.split-resizer {
  background: var(--bg-resizer);
  cursor: col-resize;
  transition: background 0.15s;
  position: relative;
  z-index: 10;
}
.split-resizer:hover,
.split-resizer:active {
  background: var(--bg-resizer-act);
}

/* ── Empty state ── */
.empty-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--text-empty);
  font-family: 'JetBrains Mono', monospace;
}
.empty-state__icon {
  font-size: 40px;
  opacity: 0.6;
}
.empty-state__text { font-size: 13px; }
.empty-state__plus {
  color: var(--text-plus);
  font-weight: bold;
  font-size: 16px;
}

/* ── Jump toast ── */
.jump-toast {
  position: fixed;
  bottom: 36px;
  right: 28px;
  background: var(--bg-toast);
  color: var(--text-toast);
  border: 1px solid var(--border-toast);
  border-radius: 8px;
  padding: 8px 16px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  pointer-events: none;
  box-shadow: 0 4px 20px rgba(0,0,0,0.5);
}

/* ── Transitions ── */
.slide-down-enter-active { transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1); }
.slide-down-leave-active { transition: all 0.14s cubic-bezier(0.4, 0, 1, 1); }
.slide-down-enter-from, .slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.modal-fade-enter-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.modal-fade-leave-active { transition: opacity 0.1s ease; }
.modal-fade-enter-from { opacity: 0; transform: scale(0.97); }
.modal-fade-leave-to { opacity: 0; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.toast-enter-active { transition: all 0.15s ease-out; }
.toast-leave-active { transition: all 0.2s ease-in; }
.toast-enter-from { opacity: 0; transform: translateY(8px); }
.toast-leave-to { opacity: 0; transform: translateY(8px); }
</style>
