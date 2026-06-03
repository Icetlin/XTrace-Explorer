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
          'trace-tab--active': activeSection === 'trace' && store.activeTabFileId === tab.fileId,
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

      <!-- Right-side nav -->
      <button
        class="nav-btn"
        :class="{ 'nav-btn--active': activeSection === 'settings' }"
        @click="activeSection = 'settings'"
      >
        ⚙ Settings
        <span v-if="store.favourites.length" class="nav-badge">{{ store.favourites.length }}</span>
      </button>
    </div>

    <!-- ── File browser dropdown ── -->
    <transition name="slide-down">
      <div v-if="showBrowser" class="file-browser">
        <div class="file-browser__header">
          <span>Select .xt trace file</span>
          <span v-if="loadingBrowse" class="file-browser__loading">
            <span class="spinner-inline" /> scanning…
          </span>
          <span v-else class="file-browser__count">{{ filteredBrowseFiles.length }} / {{ browseFiles.length }} files</span>
          <button class="file-browser__close" @click="showBrowser = false">✕</button>
        </div>
        <div class="file-browser__search">
          <input
            ref="searchInput"
            v-model="browseQuery"
            class="file-browser__search-input"
            placeholder="filter by name…"
            @keydown.escape="showBrowser = false"
          />
        </div>
        <div v-if="!filteredBrowseFiles.length && !loadingBrowse" class="file-browser__empty">
          {{ browseQuery ? 'No files match "' + browseQuery + '"' : 'No .xt files found in traces directory' }}
        </div>
        <div
          v-for="f in filteredBrowseFiles"
          :key="f.rel_path"
          class="file-row"
          @click="openFile(f.rel_path)"
        >
          <span class="file-row__info">
            <span class="file-row__name">{{ f.name }}</span>
            <span v-if="f.dir" class="file-row__dir">{{ f.dir }}</span>
          </span>
          <span class="file-row__size">{{ formatSize(f.size) }}</span>
        </div>
      </div>
    </transition>

    <!-- ── Parsing status bar (for active tab) ── -->
    <transition name="fade">
      <div
        v-if="activeSection === 'trace' && store.currentFile?.status === 'parsing'"
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
        v-else-if="activeSection === 'trace' && store.currentFile?.status === 'pending'"
        class="status-bar status-bar--pending"
      >
        <div class="status-bar__content">
          <span class="status-bar__pulse status-bar__pulse--slow" />
          <span>Queued for parsing…</span>
        </div>
      </div>
      <div
        v-else-if="activeSection === 'trace' && store.currentFile?.status === 'error'"
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
        v-if="activeSection === 'trace' && store.currentTab?.scanning"
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
      v-if="activeSection === 'trace' && store.currentTab?.request"
      :req="store.currentTab.request"
    />

    <!-- ── Response info bar ── -->
    <ResponseInfo
      v-if="activeSection === 'trace' && store.currentTab?.response"
      :res="store.currentTab.response"
    />

    <!-- ── Main content ── -->
    <div class="main">

      <!-- Settings (includes Favourites + Filters tabs) -->
      <SettingsPage v-show="activeSection === 'settings'" />

      <!-- Trace views -->
      <template v-for="tab in store.openTabs" :key="tab.fileId">
        <div
          v-show="activeSection === 'trace' && store.activeTabFileId === tab.fileId && tab.status === 'ready'"
          class="trace-view"
          :style="store.activeCodeNode ? { gridTemplateColumns: splitWidth + 'px 4px 1fr' } : { gridTemplateColumns: '1fr' }"
        >
          <!-- Left: TOC -->
          <TocTree
            v-if="tab.toc.length"
            :ref="el => { if (el) tocTreeRefs[tab.fileId] = el }"
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
        v-if="activeSection === 'trace' && !store.openTabs.length"
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

    <!-- ── Jump toast ── -->
    <transition name="toast">
      <div v-if="jumpToast" class="jump-toast">
        ↱ line {{ jumpToast.toLocaleString() }}
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useTraceStore } from './stores/trace'
import TocTree from './components/TocTree.vue'
import DesertBackground from './components/DesertBackground.vue'
import RequestInfo from './components/RequestInfo.vue'
import ResponseInfo from './components/ResponseInfo.vue'
import ExportPanel from './components/ExportPanel.vue'
import SettingsPage from './components/SettingsPage.vue'
import Breadcrumbs from './components/Breadcrumbs.vue'
import SelectionPreview from './components/SelectionPreview.vue'
import CodeView from './components/CodeView.vue'
import axios from 'axios'

const store = useTraceStore()
const activeSection = ref('trace')
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
const jumpToast = ref(null)
const breadcrumbPath = ref([])
const breadcrumbLine = ref(null)
const searchInput = ref(null)
let jumpToastTimer = null
const tocTreeRefs = {}


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

function toggleBrowser() {
  showBrowser.value = !showBrowser.value
  if (showBrowser.value) {
    browseQuery.value = ''
    loadBrowse()
    nextTick(() => searchInput.value?.focus())
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
  const displayName = relPath.includes('/') ? relPath.split('/').pop() : relPath
  await store.selectFile(data.file_id, displayName)
  await store.loadFiles()
  activeSection.value = 'trace'
  if (store.currentFile?.status !== 'ready') startPolling(data.file_id)
}

function switchToTrace(fileId) {
  store.switchToTab(fileId)
  activeSection.value = 'trace'
  showBrowser.value = false
}

function startPolling(fileId) {
  const interval = setInterval(async () => {
    const done = await store.pollStatus(fileId)
    if (done) clearInterval(interval)
  }, 2000)
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
</script>

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body, #app {
  height: 100%;
  background: #0a0c14;
  color: #c0c8d8;
}
.app { display: flex; flex-direction: column; height: 100vh; position: relative; }

/* ── Tab bar ── */
.tabs-bar {
  display: flex;
  align-items: stretch;
  gap: 2px;
  padding: 0 20px;
  background: rgba(8, 8, 18, 0.72);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid rgba(30, 30, 60, 0.5);
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
  color: #7a9ac0;
  letter-spacing: 0.08em;
  padding: 0 12px 0 4px;
  border-right: 1px solid #2a2a48;
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
  color: #6878a0;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.12s, border-color 0.12s;
  position: relative;
}
.trace-tab:hover { color: #a0b8d0; }
.trace-tab--active { color: #c0d0e8; border-bottom-color: #4a7ab0; }
.trace-tab--parsing { color: #5a8ab8; }
.trace-tab--error { color: #b05050; }

.trace-tab__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot--ready    { background: #2a5a2a; }
.dot--parsing  { background: #3a6a9a; animation: pulse-dot 1.2s ease-in-out infinite; }
.dot--pending  { background: #3a3a5a; animation: pulse-dot 2s ease-in-out infinite; }
.dot--error    { background: #7a2a2a; }

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
  color: #3a6a9a;
  flex-shrink: 0;
}
.trace-tab__close {
  font-size: 10px;
  color: #3a3a58;
  padding: 2px 3px;
  border-radius: 3px;
  line-height: 1;
  transition: color 0.1s, background 0.1s;
}
.trace-tab__close:hover { color: #cc6060; background: #1a0e0e; }

/* Add tab button */
.tab-add {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  background: none;
  border: none;
  color: #6878a0;
  font-size: 18px;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.12s;
  padding-bottom: 2px;
}
.tab-add:hover { color: #90b8e0; }
.tab-add--open { color: #6a9ac8; }

/* Nav buttons */
.nav-btn {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 0 14px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: #6878a0;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.12s, border-color 0.12s;
}
.nav-btn:hover { color: #a0b8d0; }
.nav-btn--active { color: #b0d0f0; border-bottom-color: #4a7aa8; }

.nav-badge {
  background: rgba(50, 70, 120, 0.7);
  color: #90b8e8;
  border-radius: 8px;
  font-size: 10px;
  padding: 0 5px;
  min-width: 16px;
  text-align: center;
}

/* ── File browser ── */
.file-browser {
  background: rgba(8, 8, 18, 0.8);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(20, 20, 40, 0.6);
  max-height: 280px;
  overflow-y: auto;
  flex-shrink: 0;
}

.file-browser__header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 28px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: #b0c8e8;
  border-bottom: 1px solid rgba(40, 50, 90, 0.6);
  position: sticky;
  top: 0;
  background: #0d1020;
  z-index: 1;
}
.file-browser__count { color: #8898c0; }
.file-browser__loading { display: flex; align-items: center; gap: 6px; color: #6a90c8; }
.file-browser__close {
  margin-left: auto;
  background: none;
  border: none;
  color: #8898b8;
  cursor: pointer;
  font-size: 12px;
  padding: 2px 6px;
  border-radius: 3px;
}
.file-browser__close:hover { color: #e06060; }
.file-browser__search {
  padding: 6px 14px;
  border-bottom: 1px solid rgba(40, 50, 90, 0.5);
  background: #0d1020;
}
.file-browser__search-input {
  width: 100%;
  background: rgba(20, 24, 44, 0.95);
  border: 1px solid rgba(60, 75, 120, 0.6);
  border-radius: 4px;
  color: #a0b8d8;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  padding: 5px 10px;
  outline: none;
}
.file-browser__search-input::placeholder { color: #6070a0; }
.file-browser__search-input:focus { border-color: rgba(80, 120, 200, 0.8); }

.file-browser__empty {
  padding: 16px 24px;
  font-size: 12px;
  color: #506878;
  font-family: monospace;
  font-style: italic;
}

.file-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 28px;
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  border-bottom: 1px solid rgba(25, 28, 50, 0.6);
  transition: background 0.1s;
}
.file-row:hover { background: rgba(30, 40, 70, 0.4); }
.file-row__info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.file-row__name { color: #a0c0e0; }
.file-row__dir  { color: #6878a0; font-size: 10px; }
.file-row__size { color: #6878a0; font-size: 10px; flex-shrink: 0; margin-left: 12px; }

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
  background: #080e18;
  border-bottom: 1px solid #0e1e30;
  color: #3a6a9a;
}
.status-bar--pending {
  background: #0a0a18;
  border-bottom: 1px solid #14142a;
  color: #3a3a5a;
}
.status-bar--error {
  background: #120808;
  border-bottom: 1px solid #2a1010;
  color: #7a4040;
}
.status-bar--scanning {
  background: #080e14;
  border-bottom: 1px solid #0e1e2a;
  color: #3a6a5a;
}

.status-bar__fill {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  background: linear-gradient(90deg, #0d2040, #0a1428);
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
  color: #4a8acc;
}
.status-bar__name {
  color: #2a3a55;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status-bar__pulse {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #3a7acc;
  animation: pulse-dot 1s ease-in-out infinite;
  flex-shrink: 0;
}
.status-bar__pulse--slow {
  background: #3a3a7a;
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
  background: rgba(30, 50, 90, 0.4);
  cursor: col-resize;
  transition: background 0.15s;
  position: relative;
  z-index: 10;
}
.split-resizer:hover,
.split-resizer:active {
  background: rgba(60, 100, 180, 0.5);
}

/* ── Empty state ── */
.empty-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: rgba(160, 150, 130, 0.5);
  font-family: 'JetBrains Mono', monospace;
}
.empty-state__icon {
  font-size: 40px;
  opacity: 0.6;
}
.empty-state__text { font-size: 13px; }
.empty-state__plus {
  color: rgba(180, 160, 120, 0.75);
  font-weight: bold;
  font-size: 16px;
}

/* ── Jump toast ── */
.jump-toast {
  position: fixed;
  bottom: 36px;
  right: 28px;
  background: #0e1a2e;
  color: #4a8acc;
  border: 1px solid #1e3a5a;
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

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.toast-enter-active { transition: all 0.15s ease-out; }
.toast-leave-active { transition: all 0.2s ease-in; }
.toast-enter-from { opacity: 0; transform: translateY(8px); }
.toast-leave-to { opacity: 0; transform: translateY(8px); }
</style>
