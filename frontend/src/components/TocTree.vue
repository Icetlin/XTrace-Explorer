<template>
  <div class="toc-tree" ref="tocTreeEl" @contextmenu.prevent>
    <ContextMenu ref="ctxMenu" />

    <div v-if="!toc.length" class="empty">No trace loaded</div>

    <div v-for="(event, ei) in toc" :key="ei" class="event-block">
      <!-- Event header -->
      <div
        class="event-row"
        :class="{
          'has-listeners': event.listeners?.length,
          'event-row--fav': eventScanMatches(ei).length
        }"
        @click="toggleEvent(ei)"
        @contextmenu.prevent="onEventCtx($event, event)"
      >
        <span class="chevron">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
        <span class="event-name">{{ event.event }}</span>
        <span v-for="m in eventScanMatches(ei)" :key="m.pattern" class="fav-badge-ev">{{ m.label || m.pattern }}</span>
        <span class="line-badge" @click.stop="$emit('jump', event.line_no)">
          line {{ event.line_no.toLocaleString() }}
        </span>
      </div>

      <!-- Listeners -->
      <div v-if="expandedEvents.has(ei) && event.listeners?.length" class="listeners">
        <div
          v-for="(listener, li) in event.listeners"
          :key="li"
          class="listener-block"
        >
          <!-- Listener row -->
          <div
            class="listener-row"
            :class="{ 'listener-row--fav': listenerScanMatches(ei, li).length }"
            :data-listener-line="listener.line_no"
            @click="toggleListener(ei, li, listener)"
            @contextmenu.prevent="onListenerCtx($event, listener)"
          >
            <span class="connector">└</span>
            <span class="chevron-sm">{{ expandedListeners.has(`${ei}-${li}`) ? '▾' : '▸' }}</span>
            <span class="listener-class">{{ listenerClass(listener.sig) }}</span>
            <span class="listener-method">{{ listenerMethod(listener.sig) }}</span>
            <template v-for="m in listenerScanMatches(ei, li)" :key="m.pattern">
              <span class="fav-badge-li">{{ m.label || m.pattern }}</span>
              <span
                v-for="hit in listenerHits(ei, li, m.pattern)"
                :key="hit.line_no"
                class="fav-hit-line"
                @click.stop="$emit('jump', hit.line_no)"
              >↱{{ hit.line_no.toLocaleString() }}</span>
            </template>
            <span class="line-badge-sm" @click.stop="$emit('jump', listener.line_no)">
              {{ listener.line_no.toLocaleString() }}
            </span>
          </div>

          <!-- Lazy children -->
          <div v-if="expandedListeners.has(`${ei}-${li}`)" class="children">
            <div v-if="loadingKey === `${ei}-${li}`" class="loading">loading…</div>
            <template v-else>
              <CallNode
                v-for="(child, ci) in getChildren(ei, li)"
                :key="ci"
                :node="child"
                :file-id="fileId"
                :indent="0"
                :expand-path="expandPaths[`${ei}-${li}`]"
                @jump="$emit('jump', $event)"
                @ctx-menu="onCallNodeCtx"
              />
            </template>
          </div>
        </div>
      </div>

      <!-- Event has no listeners — show line-only -->
      <div v-if="expandedEvents.has(ei) && !event.listeners?.length" class="no-listeners">
        no listeners recorded
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick } from 'vue'
import { useTraceStore } from '../stores/trace'
import CallNode from './CallNode.vue'
import ContextMenu from './ContextMenu.vue'

const props = defineProps({
  toc: { type: Array, default: () => [] },
  fileId: { type: Number, default: null },
})
defineEmits(['jump'])

const store = useTraceStore()
const ctxMenu = ref(null)

const expandedEvents = ref(new Set())
const expandedListeners = ref(new Set())
const childrenCache = reactive({})
const loadingKey = ref(null)

// favScan result from backend: { ei: { li: [{ pattern, label, line_no }] } }
const favScan = computed(() => {
  const tab = store.openTabs.find(t => t.fileId === props.fileId)
  return tab?.favScan ?? {}
})

// Unique patterns/labels that hit a listener (from scan)
function listenerScanMatches(ei, li) {
  const hits = favScan.value?.[ei]?.[li]
  if (!hits?.length) return []
  const seen = new Set()
  return hits.filter(h => seen.has(h.pattern) ? false : seen.add(h.pattern))
}

function listenerHits(ei, li, pattern) {
  const hits = favScan.value?.[ei]?.[li]
  if (!hits) return []
  return hits.filter(h => h.pattern === pattern)
}

// Unique patterns across all listeners of an event
function eventScanMatches(ei) {
  const eventHits = favScan.value?.[ei]
  if (!eventHits) return []
  const seen = new Set()
  const result = []
  for (const hits of Object.values(eventHits)) {
    for (const h of hits) {
      if (!seen.has(h.pattern)) { seen.add(h.pattern); result.push(h) }
    }
  }
  return result
}

function toggleEvent(ei) {
  const s = new Set(expandedEvents.value)
  s.has(ei) ? s.delete(ei) : s.add(ei)
  expandedEvents.value = s
}

async function toggleListener(ei, li, listener) {
  const key = `${ei}-${li}`
  const s = new Set(expandedListeners.value)
  if (s.has(key)) {
    s.delete(key)
    expandedListeners.value = s
    return
  }
  s.add(key)
  expandedListeners.value = s

  if (!childrenCache[key]) {
    loadingKey.value = key
    childrenCache[key] = await store.fetchChildren(props.fileId, listener.line_no, listener.depth ?? 0)
    loadingKey.value = null
  }
}

function getChildren(ei, li) {
  return childrenCache[`${ei}-${li}`] || []
}

function listenerClass(sig) {
  const arrow = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  if (sep === -1) return sig
  const cls = sig.slice(0, sep)
  return cls.split('\\').pop()
}

function listenerMethod(sig) {
  const arrow = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  return sep === -1 ? '' : sig.slice(sep)
}

function onEventCtx(event, tocEvent) {
  ctxMenu.value.open(event, [{ kind: 'event', value: tocEvent.event }])
}

function onListenerCtx(event, listener) {
  const short = listenerClass(listener.sig) + listenerMethod(listener.sig)
  ctxMenu.value.open(event, [
    { kind: 'sig', value: short },
    { kind: 'full sig', value: listener.sig },
  ])
}

function onCallNodeCtx({ event, items }) {
  ctxMenu.value.open(event, items)
}

const tocTreeEl = ref(null)

// expandPaths: map of listener key "ei-li" → array of line_nos to expand
const expandPaths = reactive({})

async function jumpToLine(lineNo) {
  console.log('[jump] jumpToLine called', lineNo)
  // Find which listener in TOC contains lineNo by range (before fetching path)
  let targetEi = -1, targetLi = -1, targetListener = null
  for (let ei = 0; ei < props.toc.length; ei++) {
    const event = props.toc[ei]
    if (!event.listeners?.length) continue
    for (let li = 0; li < event.listeners.length; li++) {
      const listener = event.listeners[li]
      const nextLine = event.listeners[li + 1]?.line_no ?? Infinity
      if (listener.line_no <= lineNo && lineNo < nextLine) {
        targetEi = ei; targetLi = li; targetListener = listener
        break
      }
    }
    if (targetListener) break
  }
  console.log('[jump] targetListener', targetListener?.line_no, 'ei', targetEi, 'li', targetLi)
  if (!targetListener) { console.log('[jump] no listener found'); return }

  // Get ancestor path starting from listener.line_no so we capture full call chain
  const path = await store.fetchPath(props.fileId, lineNo, targetListener.line_no)
  console.log('[jump] path', path.length, path.map(p => p.line_no))
  if (!path.length) { console.log('[jump] empty path'); return }

  // Expand event
  const es = new Set(expandedEvents.value)
  es.add(targetEi)
  expandedEvents.value = es

  // Expand listener
  const key = `${targetEi}-${targetLi}`
  const ls = new Set(expandedListeners.value)
  ls.add(key)
  expandedListeners.value = ls
  if (!childrenCache[key]) {
    loadingKey.value = key
    childrenCache[key] = await store.fetchChildren(props.fileId, targetListener.line_no, targetListener.depth ?? 0)
    loadingKey.value = null
  }

  // expandPath = non-noise line_nos; CallNode auto-expands if its line_no is in the list
  const pathLineNos = path.filter(p => !p.noise).map(p => p.line_no)
  console.log('[jump] setting expandPaths key', key, pathLineNos)
  expandPaths[key] = pathLineNos

  await nextTick()
  // Try to scroll to the target line_no; retry after async auto-expand
  const tryScroll = () => {
    const el = tocTreeEl.value?.querySelector(`[data-line-no="${lineNo}"]`)
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); return true }
    return false
  }
  if (!tryScroll()) {
    const listenerEl = tocTreeEl.value?.querySelector(`[data-listener-line="${targetListener.line_no}"]`)
    if (listenerEl) listenerEl.scrollIntoView({ behavior: 'smooth', block: 'start' })
    setTimeout(tryScroll, 400)
    setTimeout(tryScroll, 900)
  }
}

defineExpose({ jumpToLine })
</script>

<style scoped>
.toc-tree {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
  color: #ccc;
  padding: 12px 8px;
  overflow-y: auto;
  height: 100%;
}

.empty {
  color: #444;
  padding: 24px;
  text-align: center;
  font-size: 14px;
}

/* ── Event block ── */
.event-block {
  margin-bottom: 2px;
}

.event-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border-radius: 6px;
  cursor: pointer;
  background: #1a1a2e;
  border-left: 3px solid #3a3a6a;
  transition: background 0.1s;
}
.event-row:hover { background: #22223a; }
.event-row.has-listeners { border-left-color: #5a7adf; }
.event-row--fav { background: #1e0e18 !important; border-left-color: #ff6eb4 !important; }
.event-row--fav:hover { background: #281020 !important; }

.fav-badge-ev {
  font-size: 10px;
  color: #ff6eb4;
  background: #2a0e1e;
  border: 1px solid #5a2a3a;
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}

.chevron { color: #555; font-size: 11px; width: 12px; }

.event-name {
  font-size: 14px;
  font-weight: 600;
  color: #7aadff;
  flex: 1;
}

.line-badge {
  font-size: 10px;
  color: #444;
  background: #111;
  border: 1px solid #333;
  border-radius: 4px;
  padding: 1px 6px;
  cursor: pointer;
  flex-shrink: 0;
}
.line-badge:hover { color: #7aadff; border-color: #5a7adf; }

/* ── Listeners ── */
.listeners {
  margin-left: 20px;
  border-left: 1px solid #222;
  padding-left: 8px;
  margin-bottom: 4px;
}

.listener-block {
  margin-bottom: 1px;
}

.listener-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 6px;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.1s;
}
.listener-row:hover { background: #1e1e2e; }
.listener-row--fav { background: #1a0e14 !important; }
.listener-row--fav:hover { background: #22101a !important; }

.fav-hit-line {
  font-size: 10px;
  color: #7a3a50;
  cursor: pointer;
  padding: 1px 4px;
  border-radius: 3px;
  flex-shrink: 0;
}
.fav-hit-line:hover { color: #ff6eb4; background: #1e0e18; }

.fav-badge-li {
  font-size: 10px;
  color: #ff6eb4;
  background: #2a0e1e;
  border: 1px solid #5a2a3a;
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}

.connector { color: #333; font-size: 12px; }
.chevron-sm { color: #444; font-size: 10px; width: 10px; }

.listener-class {
  font-size: 13px;
  color: #e8c46a;
  font-weight: 500;
}
.listener-method {
  font-size: 12px;
  color: #888;
}
.line-badge-sm {
  font-size: 10px;
  color: #333;
  margin-left: auto;
  cursor: pointer;
  padding: 1px 5px;
  border-radius: 3px;
  flex-shrink: 0;
}
.line-badge-sm:hover { color: #7aadff; background: #111; }

/* ── Children ── */
.children {
  margin-left: 22px;
  border-left: 1px dashed #1e1e2e;
  padding-left: 8px;
  padding-bottom: 2px;
}

.loading { color: #444; font-size: 11px; padding: 4px 0; }

.no-listeners {
  margin-left: 20px;
  color: #333;
  font-size: 11px;
  padding: 2px 0 6px;
  font-style: italic;
}
</style>
