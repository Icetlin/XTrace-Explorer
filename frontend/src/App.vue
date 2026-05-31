<template>
  <div class="app">
    <!-- Header -->
    <div class="header">
      <span class="title">XTrace Explorer</span>
      <button class="btn" @click="showBrowser = !showBrowser">
        {{ showBrowser ? 'Close' : '+ Open trace' }}
      </button>
      <span v-if="store.currentFile?.status === 'ready'" class="file-info">
        {{ store.totalLines.toLocaleString() }} lines
      </span>
      <div class="spacer" />
      <button
        class="tab-btn"
        :class="{ active: activeSection === 'favourites' }"
        @click="activeSection = 'favourites'"
      >
        Favourites
        <span v-if="store.favourites.length" class="tab-count">{{ store.favourites.length }}</span>
      </button>
    </div>

    <!-- Trace tabs bar -->
    <div v-if="store.openTabs.length || showBrowser" class="tabs-bar">
      <div
        v-for="tab in store.openTabs"
        :key="tab.fileId"
        class="trace-tab"
        :class="{ active: activeSection === 'trace' && store.activeTabFileId === tab.fileId }"
        @click="switchToTrace(tab.fileId)"
      >
        <span class="trace-tab-name">{{ shortName(tab.name) }}</span>
        <span v-if="tab.status === 'parsing'" class="trace-tab-status">{{ tab.progress }}%</span>
        <span v-else-if="tab.status !== 'ready'" class="trace-tab-status">{{ tab.status }}</span>
        <span class="trace-tab-close" @click.stop="store.closeTab(tab.fileId)">✕</span>
      </div>
      <button class="trace-tab trace-tab--add" @click="showBrowser = !showBrowser">+</button>
    </div>

    <!-- File browser -->
    <div v-if="showBrowser" class="file-browser">
      <div class="file-browser-title">
        Select .xt file from <code>/traces</code>
        <span v-if="loadingBrowse" class="muted">Loading…</span>
      </div>
      <div v-if="!browseFiles.length && !loadingBrowse" class="muted" style="padding: 8px 16px">
        No .xt files found
      </div>
      <div
        v-for="f in browseFiles"
        :key="f.name"
        class="file-row"
        @click="openFile(f.name)"
      >
        <span class="file-name">{{ f.name }}</span>
        <span class="file-size">{{ formatSize(f.size) }}</span>
      </div>
    </div>

    <!-- Parsing progress (for active tab) -->
    <div v-if="activeSection === 'trace' && store.currentFile?.status === 'parsing'" class="progress-bar-wrap">
      <div class="progress-bar" :style="{ width: (store.currentFile.progress || 0) + '%' }"></div>
      <span>Parsing {{ store.currentFile.progress }}%…</span>
    </div>
    <div v-if="activeSection === 'trace' && store.currentFile?.status === 'pending'" class="progress-bar-wrap">
      <span>Queued for parsing…</span>
    </div>
    <div v-if="activeSection === 'trace' && store.currentFile?.status === 'error'" class="error-bar">
      Parse error: {{ store.currentFile.errorMessage }}
    </div>

    <!-- Favourites page -->
    <div class="main" v-show="activeSection === 'favourites'">
      <FavouritesPage />
    </div>

    <!-- Trace views — one per open tab, v-show preserves expand/scroll state -->
    <template v-for="tab in store.openTabs" :key="tab.fileId">
      <div
        class="main"
        v-show="activeSection === 'trace' && store.activeTabFileId === tab.fileId && tab.status === 'ready'"
      >
        <TocTree
          v-if="tab.toc.length"
          :ref="el => { if (el) tocTreeRefs[tab.fileId] = el }"
          :toc="tab.toc"
          :file-id="tab.fileId"
          @jump="onJump"
        />
      </div>
    </template>

    <!-- Empty state -->
    <div v-if="activeSection === 'trace' && !store.openTabs.length" class="empty-state">
      Click "+ Open trace" to select an .xt file
    </div>

    <!-- Jump toast -->
    <div v-if="jumpToast" class="jump-toast">
      line {{ jumpToast.toLocaleString() }}
    </div>

  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useTraceStore } from './stores/trace'
import TocTree from './components/TocTree.vue'
import FavouritesPage from './components/FavouritesPage.vue'
import axios from 'axios'

const store = useTraceStore()
const activeSection = ref('trace')
const showBrowser = ref(false)
const browseFiles = ref([])
const loadingBrowse = ref(false)
const jumpToast = ref(null)
let jumpToastTimer = null
const tocTreeRefs = {}

store.loadFiles()
store.loadFavourites()

watch(showBrowser, (v) => { if (v) loadBrowse() })

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

function shortName(name) {
  if (!name) return '?'
  const base = name.replace(/\.xt$/, '')
  return base.length > 28 ? '…' + base.slice(-26) : base
}

function formatSize(bytes) {
  if (bytes > 1e9) return (bytes / 1e9).toFixed(1) + ' GB'
  if (bytes > 1e6) return (bytes / 1e6).toFixed(0) + ' MB'
  return (bytes / 1e3).toFixed(0) + ' KB'
}
</script>

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body, #app { height: 100%; background: #0e0e14; color: #ccc; }

.app { display: flex; flex-direction: column; height: 100vh; }

/* Header */
.header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 16px;
  background: #161622;
  border-bottom: 1px solid #1e1e30;
  flex-shrink: 0;
}
.title { font-weight: 700; color: #7aadff; font-family: monospace; font-size: 14px; }
.spacer { flex: 1; }
.btn {
  background: #1e1e3a;
  color: #7aadff;
  border: 1px solid #3a3a6a;
  border-radius: 5px;
  padding: 4px 12px;
  cursor: pointer;
  font-size: 12px;
  font-family: monospace;
}
.btn:hover { background: #2a2a4a; }
.btn-green { color: #7ddb7d; border-color: #3a6a3a; background: #1a2a1a; }
.btn-green:hover { background: #1e341e; }
.file-info { color: #444; font-size: 11px; font-family: monospace; }
.muted { color: #444; font-size: 11px; }

.tab-btn {
  background: none;
  border: 1px solid #2a2a3a;
  border-radius: 4px;
  color: #555;
  font-family: monospace;
  font-size: 11px;
  padding: 3px 10px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 5px;
}
.tab-btn:hover { color: #999; border-color: #3a3a5a; }
.tab-btn.active { color: #ff9e9e; border-color: #5a3a4a; background: #1e1218; }
.tab-count {
  background: #5a2a3a;
  color: #ff9e9e;
  border-radius: 8px;
  font-size: 10px;
  padding: 0 5px;
  min-width: 16px;
  text-align: center;
}

/* Tabs bar */
.tabs-bar {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 8px 0;
  background: #111118;
  border-bottom: 1px solid #1e1e2e;
  flex-shrink: 0;
  overflow-x: auto;
}

.trace-tab {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px 5px;
  border-radius: 4px 4px 0 0;
  border: 1px solid #1e1e2e;
  border-bottom: none;
  background: #0e0e14;
  color: #555;
  font-family: monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  margin-bottom: -1px;
}
.trace-tab:hover { background: #161622; color: #888; }
.trace-tab.active { background: #161622; color: #ccc; border-color: #2a2a3a; }

.trace-tab-name { max-width: 200px; overflow: hidden; text-overflow: ellipsis; }
.trace-tab-status { color: #7aadff; font-size: 10px; }
.trace-tab-close {
  color: #444;
  font-size: 10px;
  padding: 1px 3px;
  border-radius: 2px;
  line-height: 1;
}
.trace-tab-close:hover { color: #f88; background: #2a1a1a; }
.trace-tab--add {
  background: none;
  border: 1px dashed #2a2a3a;
  color: #444;
  font-size: 14px;
  padding: 2px 8px 4px;
}
.trace-tab--add:hover { color: #7aadff; border-color: #5a5a8a; }

/* File browser */
.file-browser {
  background: #111118;
  border-bottom: 1px solid #1e1e30;
  max-height: 260px;
  overflow-y: auto;
  flex-shrink: 0;
}
.file-browser-title {
  padding: 6px 16px;
  font-size: 11px;
  color: #444;
  border-bottom: 1px solid #1a1a24;
  display: flex;
  gap: 12px;
  align-items: center;
  font-family: monospace;
}
.file-browser-title code { color: #7aadff; }
.file-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 16px;
  cursor: pointer;
  font-family: monospace;
  font-size: 12px;
  border-bottom: 1px solid #111118;
}
.file-row:hover { background: #1a1a24; }
.file-name { color: #bbb; }
.file-size { color: #444; font-size: 11px; }

/* Progress */
.progress-bar-wrap {
  height: 28px;
  background: #161622;
  border-bottom: 1px solid #1e1e30;
  display: flex;
  align-items: center;
  padding: 0 16px;
  gap: 12px;
  font-size: 12px;
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
  font-family: monospace;
}
.progress-bar {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  background: #1a2e1a;
  transition: width 0.5s;
}
.error-bar {
  background: #2e1a1a;
  color: #f88;
  padding: 6px 16px;
  font-size: 12px;
  font-family: monospace;
  flex-shrink: 0;
}

/* Main */
.main {
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.empty-state {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #333;
  font-family: monospace;
  font-size: 14px;
}

/* Jump toast */
.jump-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: #1e1e3a;
  color: #7aadff;
  border: 1px solid #3a3a6a;
  border-radius: 6px;
  padding: 8px 16px;
  font-family: monospace;
  font-size: 13px;
  pointer-events: none;
  animation: fadeIn 0.15s ease;
}
@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; } }
</style>
