<template>
  <div class="app">

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
        :class="{ 'nav-btn--active': activeSection === 'favourites' }"
        @click="activeSection = 'favourites'"
      >
        ★ Favourites
        <span v-if="store.favourites.length" class="nav-badge">{{ store.favourites.length }}</span>
      </button>
      <button
        class="nav-btn"
        :class="{ 'nav-btn--active': activeSection === 'settings' }"
        @click="activeSection = 'settings'"
      >
        ⚙ Settings
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
          <span v-else class="file-browser__count">{{ browseFiles.length }} files</span>
          <button class="file-browser__close" @click="showBrowser = false">✕</button>
        </div>
        <div v-if="!browseFiles.length && !loadingBrowse" class="file-browser__empty">
          No .xt files found in traces directory
        </div>
        <div
          v-for="f in browseFiles"
          :key="f.name"
          class="file-row"
          @click="openFile(f.name)"
        >
          <span class="file-row__name">{{ f.name }}</span>
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

    <!-- ── Main content ── -->
    <div class="main">

      <!-- Favourites -->
      <FavouritesPage v-show="activeSection === 'favourites'" />

      <!-- Settings -->
      <SettingsPage v-show="activeSection === 'settings'" />

      <!-- Trace views -->
      <template v-for="tab in store.openTabs" :key="tab.fileId">
        <div
          v-show="activeSection === 'trace' && store.activeTabFileId === tab.fileId && tab.status === 'ready'"
          class="trace-view"
        >
          <TocTree
            v-if="tab.toc.length"
            :ref="el => { if (el) tocTreeRefs[tab.fileId] = el }"
            :toc="tab.toc"
            :file-id="tab.fileId"
            @jump="onJump"
            @breadcrumb="onBreadcrumb"
          />
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

    <!-- ── Jump toast ── -->
    <transition name="toast">
      <div v-if="jumpToast" class="jump-toast">
        ↱ line {{ jumpToast.toLocaleString() }}
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useTraceStore } from './stores/trace'
import TocTree from './components/TocTree.vue'
import FavouritesPage from './components/FavouritesPage.vue'
import SettingsPage from './components/SettingsPage.vue'
import Breadcrumbs from './components/Breadcrumbs.vue'
import axios from 'axios'

const store = useTraceStore()
const activeSection = ref('trace')
const showBrowser = ref(false)
const browseFiles = ref([])
const loadingBrowse = ref(false)
const jumpToast = ref(null)
const breadcrumbPath = ref([])
const breadcrumbLine = ref(null)
let jumpToastTimer = null
const tocTreeRefs = {}

store.loadFiles()
store.loadFavourites()

function toggleBrowser() {
  showBrowser.value = !showBrowser.value
  if (showBrowser.value) loadBrowse()
}

async function loadBrowse() {
  loadingBrowse.value = true
  const { data } = await axios.get('/api/browse')
  browseFiles.value = data
  loadingBrowse.value = false
}

async function openFile(filename) {
  showBrowser.value = false
  const { data } = await axios.post('/api/open', { filename })
  await store.selectFile(data.file_id, filename)
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
  background: #0a0a14;
  color: #ccc;
}
.app { display: flex; flex-direction: column; height: 100vh; }

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
  color: #2e3a5a;
  letter-spacing: 0.08em;
  padding: 0 12px 0 4px;
  border-right: 1px solid #131326;
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
  color: #333;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.12s, border-color 0.12s;
  position: relative;
}
.trace-tab:hover { color: #555; }
.trace-tab--active { color: #7a8899; border-bottom-color: #2e4a6a; }
.trace-tab--parsing { color: #2e4a62; }
.trace-tab--error { color: #5a2a2a; }

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
  color: #222;
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
  color: #2a2a44;
  font-size: 18px;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.12s;
  padding-bottom: 2px;
}
.tab-add:hover { color: #4a6a8a; }
.tab-add--open { color: #2e4a6a; }

/* Nav buttons */
.nav-btn {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 0 14px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: #2a2a44;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: color 0.12s, border-color 0.12s;
}
.nav-btn:hover { color: #555; }
.nav-btn--active { color: #5a6a7a; border-bottom-color: #2a3848; }

.nav-badge {
  background: #1e2438;
  color: #5a7aaa;
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
  color: #2a2a3a;
  border-bottom: 1px solid rgba(16, 16, 32, 0.8);
  position: sticky;
  top: 0;
  background: rgba(8, 8, 18, 0.9);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  z-index: 1;
}
.file-browser__count { color: #252540; }
.file-browser__loading { display: flex; align-items: center; gap: 6px; color: #3a4a6a; }
.file-browser__close {
  margin-left: auto;
  background: none;
  border: none;
  color: #2a2a40;
  cursor: pointer;
  font-size: 12px;
  padding: 2px 6px;
  border-radius: 3px;
}
.file-browser__close:hover { color: #cc6060; }
.file-browser__empty {
  padding: 16px 24px;
  font-size: 12px;
  color: #2a2a44;
  font-family: monospace;
  font-style: italic;
}

.file-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 28px;
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  border-bottom: 1px solid rgba(14, 14, 26, 0.6);
  transition: background 0.1s;
}
.file-row:hover { background: rgba(16, 16, 28, 0.6); }
.file-row__name { color: #4a5a6a; }
.file-row__size { color: #202030; font-size: 10px; }

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
  display: flex;
  flex-direction: column;
  min-height: 0;
}

/* ── Empty state ── */
.empty-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: #1e1e30;
  font-family: 'JetBrains Mono', monospace;
}
.empty-state__icon {
  font-size: 40px;
  opacity: 0.3;
}
.empty-state__text { font-size: 13px; }
.empty-state__plus {
  color: #3a4a6a;
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
