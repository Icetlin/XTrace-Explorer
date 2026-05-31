<template>
  <div class="toc-tree" ref="tocTreeEl" @contextmenu.prevent>
    <ContextMenu ref="ctxMenu" />

    <div v-if="!toc.length" class="empty">No trace loaded</div>

    <div
      v-for="(event, ei) in toc"
      :key="ei"
      class="event-block"
      :class="{ 'event-block--sep': ei > 0 && eventSource(event.event, event.event_class) !== eventSource(toc[ei-1].event, toc[ei-1].event_class) }"
    >
      <!-- Source group header — only on first event of a new source group -->
      <div
        v-if="eventSource(event.event, event.event_class) && (ei === 0 || eventSource(event.event, event.event_class) !== eventSource(toc[ei-1].event, toc[ei-1].event_class))"
        class="source-group-header"
        :data-src="eventSource(event.event, event.event_class)"
      >{{ eventSource(event.event, event.event_class) }}</div>

      <!-- Event header -->
      <div
        class="event-row"
        :class="{ 'has-listeners': event.listeners?.length }"
        @click="toggleEvent(ei)"
        @contextmenu.prevent="onEventCtx($event, event)"
      >
        <span class="chevron">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
        <span class="event-name">{{ event.event }}</span>
        <span
          v-for="m in eventScanMatches(ei)"
          :key="m.pattern"
          class="fav-badge-ev"
          :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
        >{{ m.label || m.pattern }}</span>
        <span class="line-badge" @click.stop="$emit('jump', event.line_no)">
          line {{ event.line_no.toLocaleString() }}
        </span>
      </div>

      <!-- Listeners -->
      <div v-if="expandedEvents.has(ei) && event.listeners?.length" class="listeners">
        <div
          v-for="(listener, li) in event.listeners"
          v-show="!store.isListenerFiltered(listener.sig)"
          :key="li"
          class="listener-block"
          :class="{ 'listener-block--sep': li > 0 && listenerSource(listener.sig) !== listenerSource(event.listeners[li-1].sig) }"
        >
          <!-- Listener source group header -->
          <div
            v-if="listenerSource(listener.sig) && listenerSource(listener.sig) !== listenerSource(prevVisibleListener(event.listeners, li)?.sig)"
            class="listener-source-header"
            :data-src="listenerSource(listener.sig)"
          >{{ listenerSource(listener.sig) }}</div>

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
              <span
                class="fav-badge-li"
                :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
              >{{ m.label || m.pattern }}</span>
              <span
                v-for="hit in listenerHits(ei, li, m.pattern)"
                :key="hit.line_no"
                class="fav-hit-line"
                :style="{ color: favColor(m.pattern).bubble }"
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
                :ancestor-crumbs="[{ sig: event.event, line_no: event.line_no }, { sig: listener.sig, line_no: listener.line_no }]"
                @jump="$emit('jump', $event)"
                @ctx-menu="onCallNodeCtx"
                @breadcrumb="$emit('breadcrumb', $event)"
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
import { favColor } from '../favColor.js'

const props = defineProps({
  toc: { type: Array, default: () => [] },
  fileId: { type: Number, default: null },
})
const emit = defineEmits(['jump', 'breadcrumb'])

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

function prevVisibleListener(listeners, li) {
  for (let i = li - 1; i >= 0; i--) {
    if (!store.isListenerFiltered(listeners[i].sig)) return listeners[i]
  }
  return null
}

function listenerSource(sig) {
  if (!sig) return null
  for (const { namespace, label } of store.appNamespaces) {
    if (sig.startsWith(namespace)) return label || namespace.replace(/\\+$/, '').split('\\').pop()
  }
  if (sig.startsWith('Symfony\\')) return 'sf'
  const parts = sig.split('\\')
  if (parts.length < 2) return null  // no namespace — don't label single-word sigs
  const bundle = parts.find(p => p.endsWith('Bundle'))
  if (bundle) return bundle.replace(/Bundle$/, '')
  return parts[0] || null
}

function eventSource(name, eventClass) {
  if (!name) return null

  // Use full FQCN when available — most reliable
  if (eventClass) {
    for (const { namespace, label } of store.appNamespaces) {
      if (eventClass.startsWith(namespace)) return label || namespace.replace(/\\+$/, '').split('\\').pop()
    }
    if (eventClass.startsWith('Symfony\\')) return 'sf'
    const parts = eventClass.split('\\')
    const bundle = parts.find(p => p.endsWith('Bundle'))
    if (bundle) return bundle.replace(/Bundle$/, '')
    return parts[0] || null
  }

  // Fallback: parse dotted event name
  if (name.startsWith('kernel.') || name.startsWith('security.') ||
      name.startsWith('console.') || name.startsWith('messenger.') ||
      name.startsWith('workflow.')) return 'sf'
  if (name.startsWith('lexik_jwt')) return 'jwt'
  if (name.startsWith('scheb_')) return '2fa'
  if (name.includes('.')) return 'sf'
  return null
}

function toggleEvent(ei) {
  const s = new Set(expandedEvents.value)
  s.has(ei) ? s.delete(ei) : s.add(ei)
  expandedEvents.value = s
}

async function toggleListener(ei, li, listener) {
  const event = props.toc[ei]
  emit('breadcrumb', {
    crumbs: [
      { sig: event?.event, line_no: event?.line_no },
      { sig: listener.sig, line_no: listener.line_no },
    ],
    line: listener.line_no,
  })

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
  // For filter: use the class part (before ->) so it matches all methods of the class
  const sep = listener.sig.lastIndexOf('->')
  const classPart = sep !== -1 ? listener.sig.slice(0, sep) : listener.sig
  ctxMenu.value.open(event, [
    { kind: 'sig', value: short },
    { kind: 'full sig', value: listener.sig },
    { action: 'filter', value: classPart },
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
  font-size: 14.5px;
  color: #ccc;
  padding: 16px 20px 16px 28px;
  overflow-y: auto;
  height: 100%;
  background: rgba(255, 255, 255, 0.04);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}

.empty {
  color: #444;
  padding: 40px 24px;
  text-align: center;
  font-size: 14px;
}

/* ── Event block ── */
.event-block {
  margin-bottom: 6px;
}
.event-block--sep {
  margin-top: 0;
}

/* ── Source group header ── */
.source-group-header {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 3px 18px 4px;
  color: #3a4a5a;
  display: flex;
  align-items: center;
  gap: 8px;
}
.source-group-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(50, 60, 90, 0.35);
}
.source-group-header[data-src="sf"] { color: #3a6070; }
.source-group-header:not([data-src="sf"]) { color: #5a5a40; }

.event-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 18px;
  cursor: pointer;
  border-left: none;
  transition: background 0.15s;
}
.event-row:hover {
  background: rgba(255, 255, 255, 0.04);
}
.event-row.has-listeners { }
.event-row--fav { border-left-width: 3px; }

.fav-badge-ev {
  font-size: 10px;
  border: 1px solid;
  border-radius: 4px;
  padding: 1px 7px;
  flex-shrink: 0;
  letter-spacing: 0.02em;
}

.chevron { color: #555; font-size: 12.5px; width: 12px; flex-shrink: 0; }

.event-source, .listener-source {
  font-size: 9px;
  font-weight: 500;
  letter-spacing: 0.03em;
  flex-shrink: 0;
  text-align: right;
  white-space: nowrap;
}
.event-source    { opacity: 0.45; }
.listener-source { opacity: 0.35; }
.event-source[data-src="sf"],  .listener-source[data-src="sf"] { color: #6a9aaa; }
.event-source:not([data-src="sf"]), .listener-source:not([data-src="sf"]) { color: #7a7a60; }

.event-name {
  font-size: 15px;
  font-weight: 500;
  color: #7a9abb;
  flex: 1;
  letter-spacing: 0.01em;
}

.line-badge {
  font-size: 10px;
  color: #3a3a55;
  background: #0e0e1a;
  border: 1px solid #252535;
  border-radius: 4px;
  padding: 2px 8px;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.1s, border-color 0.1s;
}
.line-badge:hover { color: #7aadff; border-color: #4a6acf; }

/* ── Listeners ── */
.listeners {
  margin-left: 24px;
  margin-top: 2px;
  margin-bottom: 6px;
}

.listener-block {
  margin-bottom: 2px;
}
.listener-block--sep {
  margin-top: 0;
}

.listener-source-header {
  font-size: 9.5px;
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  padding: 4px 14px 3px 36px;
  color: #2e3a42;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.listener-source-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(40, 55, 70, 0.4);
}
.listener-source-header[data-src="sf"] { color: #2a4a58; }
.listener-source-header:not([data-src="sf"]) { color: #484830; }

.listener-row {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 6px 14px;
  border-left: 2px solid transparent;
  cursor: pointer;
  transition: background 0.12s;
}
.listener-row:hover { background: rgba(255, 255, 255, 0.03); }
.listener-row--fav { border-left-width: 2px; }

.fav-hit-line {
  font-size: 10px;
  cursor: pointer;
  padding: 1px 5px;
  border-radius: 3px;
  flex-shrink: 0;
  opacity: 0.7;
  transition: opacity 0.1s;
}
.fav-hit-line:hover { opacity: 1; }

.fav-badge-li {
  font-size: 10px;
  border: 1px solid;
  border-radius: 4px;
  padding: 1px 6px;
  flex-shrink: 0;
}

.connector { color: #2a2a3a; font-size: 13.5px; flex-shrink: 0; }
.chevron-sm { color: #383850; font-size: 10px; width: 10px; flex-shrink: 0; }

.listener-class {
  font-size: 14px;
  color: #b0a070;
  font-weight: 500;
}
.listener-method {
  font-size: 13.5px;
  color: #4a4a5a;
}
.line-badge-sm {
  font-size: 10px;
  color: #3a3a55;
  margin-left: auto;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 3px;
  flex-shrink: 0;
  transition: color 0.1s, background 0.1s;
}
.line-badge-sm:hover { color: #7aadff; background: #0e0e1a; }

/* ── Children ── */
.children {
  margin-left: 26px;
  border-left: 1px dashed #181828;
  padding-left: 8px;
  padding-bottom: 4px;
}

.loading { color: #3a3a55; font-size: 12.5px; padding: 6px 0; }

.no-listeners {
  margin-left: 24px;
  color: #2e2e44;
  font-size: 12.5px;
  padding: 4px 0 8px;
  font-style: italic;
}
</style>
