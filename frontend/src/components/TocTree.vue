<template>
  <div class="toc-tree" ref="tocTreeEl" @contextmenu.prevent>
    <ContextMenu ref="ctxMenu" />

    <div v-if="!toc.length" class="empty">No trace loaded</div>

    <template v-for="(group, gi) in tocGroups" :key="gi">

      <!-- ── Collapsed repeated-event group ── -->
      <div v-if="group.count > 1 && !expandedGroups.has(gi)" class="event-block event-group">
        <!-- Source group header for the group -->
        <div
          v-if="group.src && (gi === 0 || tocGroups[gi-1].src !== group.src)"
          class="source-group-header"
          :data-src="group.src"
        >{{ group.src }}</div>
        <div class="event-row event-row--group" @click="expandedGroups = new Set([...expandedGroups, gi])">
          <span class="chevron">▸</span>
          <span class="event-name">
            <span :class="group.voteAttr ? 'event-name--dimmed' : ''">{{ group.eventName }}</span>
            <span v-if="group.voteAttr" class="event-name--attr"> · {{ group.voteAttr }}</span>
          </span>
          <span v-if="group.caller" class="vote-caller-badge">{{ group.caller }}</span>
          <span class="event-group-count">× {{ group.count }}</span>
          <span
            v-for="m in groupScanMatches(group)"
            :key="m.pattern"
            class="fav-badge-ev"
            :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
          >{{ m.label || m.pattern }}</span>
        </div>
      </div>

      <!-- ── Expanded or singleton events ── -->
      <template v-else>
        <!-- Source group header (singleton only — groups show it in collapsed state) -->
        <div
          v-if="group.count === 1 && group.src && (gi === 0 || tocGroups[gi-1].src !== group.src)"
          class="source-group-header"
          :data-src="group.src"
        >{{ group.src }}</div>

        <!-- Collapse button for expanded group -->
        <div v-if="group.count > 1" class="event-group-bar" @click="expandedGroups = new Set([...expandedGroups].filter(x => x !== gi))">
          <span class="event-group-collapse">▾ {{ group.eventName }}<span v-if="group.voteAttr" class="event-group-collapse--attr"> · {{ group.voteAttr }}</span><span v-if="group.caller" class="event-group-collapse--caller"> [{{ group.caller }}]</span> × {{ group.count }} — collapse</span>
        </div>

        <!-- ── FLATTEN MODE: homogeneous group → skip event level, show listeners directly ── -->
        <template v-if="group.count > 1 && group.homogeneous">
          <div
            v-for="ei in sortedGroupIndices(group)"
            :key="ei"
            class="event-block"
          >
            <div
              v-for="(listener, li) in toc[ei].listeners"
              v-show="!store.isListenerFiltered(listener.sig)"
              :key="li"
              class="listener-block"
            >
              <div
                class="listener-row"
                :class="{ 'listener-row--fav': listenerScanMatches(ei, li).length, 'listener-row--selected': store.isSelected(listener.line_no), 'listener-row--code-active': store.isCodeActive(listener.line_no), 'listener-row--abstain': listener.vote_result === 0, 'listener-row--granted': listener.vote_result === 1, 'listener-row--denied': listener.vote_result === -1 }"
                :data-listener-line="listener.line_no"
                @click="onListenerClick($event, ei, li, listener, toc[ei])"
                @contextmenu.prevent="onListenerCtx($event, listener)"
              >
                <span class="event-idx">#{{ ei - group.startEi + 1 }}</span>
                <span class="chevron-sm">{{ expandedListeners.has(`${ei}-${li}`) ? '▾' : '▸' }}</span>
                <span class="listener-class"><span v-for="(p,pi) in camelParts(listenerClass(listener.sig))" :key="pi" :style="classPartStyle(pi)">{{ p }}</span></span>
                <span class="listener-method"><span v-for="(p,pi) in camelParts(listenerMethod(listener.sig))" :key="pi" :style="methodPartStyle(pi)">{{ p }}</span></span>
                <span v-if="listener.voter_class" class="voter-badge">{{ listener.voter_class }}</span>
                <span v-for="attr in (listener.vote_attrs ?? [])" :key="attr" class="vote-attr-badge">{{ attr }}</span>
                <span v-if="listener.vote_result === 1" class="vote-result vote-result--granted">GRANTED</span>
                <span v-else-if="listener.vote_result === -1" class="vote-result vote-result--denied">DENIED</span>
                <template v-for="m in listenerScanMatches(ei, li)" :key="m.pattern">
                  <span class="fav-badge-li" :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }">{{ m.label || m.pattern }}</span>
                </template>
              </div>
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
                    :ancestor-crumbs="[{ sig: toc[ei].event, line_no: toc[ei].line_no }, { sig: listener.sig, line_no: listener.line_no }]"
                    @jump="$emit('jump', $event)"
                    @ctx-menu="onCallNodeCtx"
                    @breadcrumb="$emit('breadcrumb', $event)"
                  />
                </template>
              </div>
            </div>
          </div>
        </template>

        <!-- ── NORMAL MODE: singleton or heterogeneous group ── -->
        <template v-else>
          <div
            v-for="ei in group.indices"
            :key="ei"
            class="event-block"
          >
            <!-- controller_execution synthetic entry -->
            <template v-if="toc[ei].type === 'controller_execution'">
              <div
                class="event-row event-row--controller"
                :class="{ 'has-listeners': toc[ei].children?.length || toc[ei].app_calls_count > 0, 'event-row--selected': store.isSelected(toc[ei].line_no), 'event-row--code-active': store.isCodeActive(toc[ei].line_no) }"
                @click="onEventRowClick($event, ei, toc[ei])"
              >
                <span class="chevron" @click.stop="toggleEvent(ei)">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
                <span class="event-name event-name--controller">{{ controllerShortSig(toc[ei].event) }}</span>
              </div>
              <template v-if="expandedEvents.has(ei)">
                <div v-if="appCallsLoading[ei]" class="app-calls-loading">loading…</div>
                <AppCallTree
                  v-else-if="dedupeAppCalls(toc[ei].event, getAppCalls(ei)).length"
                  :calls="dedupeAppCalls(toc[ei].event, getAppCalls(ei))"
                  :expanded="expandedAppCalls"
                  @toggle="toggleAppCall"
                />
                <NestedEventList
                  v-if="toc[ei].children?.length"
                  :events="toc[ei].children"
                />
              </template>
            </template>

            <!-- normal event entry -->
            <template v-else>
            <div
              class="event-row"
              :class="{ 'has-listeners': toc[ei].listeners?.length, 'event-row--selected': store.isSelected(toc[ei].line_no) }"
              @click="onEventRowClick($event, ei, toc[ei])"
              @contextmenu.prevent="onEventCtx($event, toc[ei])"
            >
              <span class="chevron" @click.stop="toggleEvent(ei)">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
              <span class="event-name">
                <span :class="voteAttr(toc[ei]) ? 'event-name--dimmed' : ''">{{ toc[ei].event }}</span>
                <span v-if="voteAttr(toc[ei])" class="event-name--attr"> · {{ voteAttr(toc[ei]) }}</span>
              </span>
              <span
                v-for="m in eventScanMatches(ei)"
                :key="m.pattern"
                class="fav-badge-ev"
                :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
              >{{ m.label || m.pattern }}</span>
            </div>

            <div v-if="expandedEvents.has(ei) && toc[ei].listeners?.length" class="listeners">
              <div
                v-for="({ li, listener }, sortIdx) in sortedListenersWithIdx(toc[ei].listeners)"
                v-show="!store.isListenerFiltered(listener.sig)"
                :key="li"
                class="listener-block"
                :class="{ 'listener-block--sep': sortIdx > 0 && listenerSource(listener.sig) !== listenerSource(sortedListenersWithIdx(toc[ei].listeners)[sortIdx-1]?.listener.sig) }"
              >
                <div
                  v-if="listenerSource(listener.sig) && listenerSource(listener.sig) !== listenerSource(sortedListenersWithIdx(toc[ei].listeners)[sortIdx-1]?.listener.sig)"
                  class="listener-source-header"
                  :data-src="listenerSource(listener.sig)"
                >{{ listenerSource(listener.sig) }}</div>
                <div
                  class="listener-row"
                  :class="{ 'listener-row--fav': listenerScanMatches(ei, li).length, 'listener-row--selected': store.isSelected(listener.line_no), 'listener-row--code-active': store.isCodeActive(listener.line_no), 'listener-row--abstain': listener.vote_result === 0, 'listener-row--granted': listener.vote_result === 1, 'listener-row--denied': listener.vote_result === -1 }"
                  :data-listener-line="listener.line_no"
                  @mouseenter="prefetchListener(listener)"
                  @click="onListenerClick($event, ei, li, listener, toc[ei])"
                  @contextmenu.prevent="onListenerCtx($event, listener)"
                >
                  <span class="connector">└</span>
                  <span class="listener-idx">#{{ li + 1 }}</span>
                  <span class="chevron-sm">{{ expandedListeners.has(`${ei}-${li}`) ? '▾' : '▸' }}</span>
                  <span class="listener-class"><span v-for="(p,pi) in camelParts(listenerClass(listener.sig))" :key="pi" :style="classPartStyle(pi)">{{ p }}</span></span>
                  <span class="listener-method"><span v-for="(p,pi) in camelParts(listenerMethod(listener.sig))" :key="pi" :style="methodPartStyle(pi)">{{ p }}</span></span>
                  <span v-if="listener.voter_class" class="voter-badge">{{ listener.voter_class }}</span>
                  <span v-for="attr in (listener.vote_attrs ?? [])" :key="attr" class="vote-attr-badge">{{ attr }}</span>
                  <span v-if="listener.vote_result === 1" class="vote-result vote-result--granted">GRANTED</span>
                  <span v-else-if="listener.vote_result === -1" class="vote-result vote-result--denied">DENIED</span>
                  <template v-for="m in listenerScanMatches(ei, li)" :key="m.pattern">
                    <span class="fav-badge-li" :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }">{{ m.label || m.pattern }}</span>
                  </template>
                </div>
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
                      :ancestor-crumbs="[{ sig: toc[ei].event, line_no: toc[ei].line_no }, { sig: listener.sig, line_no: listener.line_no }]"
                      @jump="$emit('jump', $event)"
                      @ctx-menu="onCallNodeCtx"
                      @breadcrumb="$emit('breadcrumb', $event)"
                    />
                  </template>
                </div>
              </div>
            </div>

            <div v-if="expandedEvents.has(ei) && !toc[ei].listeners?.length" class="no-listeners">
              no listeners recorded
            </div>

            <div v-if="expandedEvents.has(ei) && appCallsLoading[ei]" class="app-calls-loading">loading…</div>
            <AppCallTree
              v-else-if="expandedEvents.has(ei) && dedupeAppCalls(toc[ei].event, getAppCalls(ei)).length"
              :calls="dedupeAppCalls(toc[ei].event, getAppCalls(ei))"
              :expanded="expandedAppCalls"
              @toggle="toggleAppCall"
            />

            <NestedEventList
              v-if="expandedEvents.has(ei) && toc[ei].children?.length"
              :events="toc[ei].children"
            />
            </template><!-- end normal event -->
          </div>
        </template>
      </template>

    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, watch } from 'vue'
import { useTraceStore } from '../stores/trace'
import { usePerfStore } from '../stores/perf'
import { usePerfTrack } from '../perfTrack'
import CallNode from './CallNode.vue'
import ContextMenu from './ContextMenu.vue'
import NestedEventList from './NestedEventList.vue'
import AppCallTree from './AppCallTree.vue'
import { favColor } from '../favColor.js'

const props = defineProps({
  toc: { type: Array, default: () => [] },
  fileId: { type: Number, default: null },
})
const emit = defineEmits(['jump', 'breadcrumb'])

const store = useTraceStore()
const perf = usePerfStore()
// Record mount time so the Frontend timings panel can show component-load cost.
usePerfTrack('TocTree', { category: 'render', trackUpdates: true })
const ctxMenu = ref(null)

const expandedEvents = ref(new Set())
const expandedListeners = ref(new Set())
const expandedGroups = ref(new Set())
const expandedAppCalls = ref(new Set())
// Cache of lazy-loaded app_calls per event index (ei → array)
const appCallsCache = reactive({})
const appCallsLoading = reactive({})

function toggleAppCall(lineNo) {
  const s = new Set(expandedAppCalls.value)
  s.has(lineNo) ? s.delete(lineNo) : s.add(lineNo)
  expandedAppCalls.value = s
}

// Lazy-load app_calls when an event is expanded and app_calls is null (stripped on server)
watch(expandedEvents, async (expanded) => {
  for (const ei of expanded) {
    const entry = props.toc[ei]
    if (!entry) continue
    // app_calls === null means server stripped it; app_calls_count > 0 means there's data
    if (entry.app_calls === null && entry.app_calls_count > 0 && !(ei in appCallsCache) && !appCallsLoading[ei]) {
      appCallsLoading[ei] = true
      try {
        appCallsCache[ei] = await store.fetchAppCalls(props.fileId, ei)
      } finally {
        appCallsLoading[ei] = false
      }
    }
  }
}, { deep: false })

function getAppCalls(ei) {
  const entry = props.toc[ei]
  if (!entry) return []
  // If app_calls is present (not stripped), use it directly
  if (Array.isArray(entry.app_calls)) return entry.app_calls
  // Otherwise use lazy-loaded cache
  return appCallsCache[ei] ?? []
}

// For debug.security.authorization.vote: extract the voted attribute from listeners
function voteAttr(tocEvent) {
  if (!tocEvent.event?.includes('authorization.vote')) return null
  return tocEvent.listeners?.[0]?.vote_attrs?.[0] ?? null
}

// Group key: for vote events, include attribute AND caller so each security layer is a separate group
function groupKey(tocEvent) {
  const attr = voteAttr(tocEvent)
  if (!attr) return tocEvent.event
  const caller = tocEvent.caller ?? ''
  return `${tocEvent.event}::${attr}::${caller}`
}

// Build groups of consecutive identical events for collapsing
const tocGroups = computed(() => {
  const groups = []
  let i = 0
  while (i < props.toc.length) {
    const key = groupKey(props.toc[i])
    const name = props.toc[i].event
    if (store.isEventFiltered(name)) { i++; while (i < props.toc.length && groupKey(props.toc[i]) === key) i++; continue }
    let j = i + 1
    while (j < props.toc.length && groupKey(props.toc[j]) === key) j++
    const indices = []
    for (let k = i; k < j; k++) indices.push(k)
    // Homogeneous = all events in this run have identical listener sig lists
    const firstSigs = (props.toc[i].listeners ?? []).map(l => l.sig).join('|')
    const homogeneous = j - i > 1 && indices.every(k =>
      (props.toc[k].listeners ?? []).map(l => l.sig).join('|') === firstSigs
    )
    const attr = voteAttr(props.toc[i])
    const caller = attr ? (props.toc[i].caller ?? null) : null
    // Shorten caller: "TwoFactorAccessListener->authenticate" → "2FA", "AccessListener->authenticate" → "access_control"
    let callerLabel = null
    if (caller) {
      if (caller.includes('TwoFactor')) callerLabel = '2FA check'
      else if (caller.includes('AccessListener')) callerLabel = 'access_control'
      else callerLabel = caller.split('->')[0]
    }
    groups.push({
      eventName: name,
      voteAttr: attr,
      caller: callerLabel,
      displayName: attr ? `${name} · ${attr}` : name,
      count: j - i,
      startEi: i,
      indices,
      src: eventSource(name, props.toc[i].event_class),
      homogeneous,
    })
    i = j
  }
  return groups
})
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

// Collect unique fav matches across all events in a group
function groupScanMatches(group) {
  const seen = new Set()
  const result = []
  for (const ei of group.indices) {
    for (const h of eventScanMatches(ei)) {
      if (!seen.has(h.pattern)) { seen.add(h.pattern); result.push(h) }
    }
  }
  return result
}

// Returns [{li, listener}, ...] sorted: non-ABSTAIN first (original order), then ABSTAIN (original order)
function sortedListenersWithIdx(listeners) {
  const entries = listeners.map((listener, li) => ({ li, listener }))
  const nonAbstain = entries.filter(e => e.listener.vote_result !== 0 && e.listener.vote_result !== undefined)
  const abstain = entries.filter(e => e.listener.vote_result === 0 || e.listener.vote_result === undefined)
  return [...nonAbstain, ...abstain]
}

// For flatten mode: sort group.indices so non-ABSTAIN voters come first
function sortedGroupIndices(group) {
  const indices = group.indices
  const nonAbstain = indices.filter(ei => {
    const r = props.toc[ei].listeners?.[0]?.vote_result
    return r !== 0 && r !== undefined
  })
  const abstain = indices.filter(ei => {
    const r = props.toc[ei].listeners?.[0]?.vote_result
    return r === 0 || r === undefined
  })
  return [...nonAbstain, ...abstain]
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

function controllerSrcNode(event, ei) {
  // Root app_call file_abs points to the HttpKernel caller, not the controller file.
  // First child's file_abs = controller file:line (the line that makes the first call inside the method).
  const calls = getAppCalls(ei)
  const root = calls[0]
  if (!root) return null
  const firstChild = root.children?.find(c => c.file_abs?.includes('/src/'))
  if (!firstChild) return null
  return { ...root, file_abs: firstChild.file_abs, file: firstChild.file }
}

function onEventRowClick(e, ei, event) {
  if (e.ctrlKey || e.metaKey) {
    store.toggleSelection({ type: 'event', sig: event.event, line_no: event.line_no, breadcrumb: [] })
    return
  }
  // expand on click, but never collapse — chevron handles collapse
  if (!expandedEvents.value.has(ei)) toggleEvent(ei)
  if (event.type === 'controller_execution') {
    const srcNode = controllerSrcNode(event, ei)
    if (srcNode) store.setCodeNode(srcNode, [{ sig: event.event, line_no: event.line_no }])
    else store.setCodeNode({ line_no: event.line_no, sig: event.event }, [])
  } else {
    store.setCodeNode({ line_no: event.line_no, sig: event.event, file_abs: null }, [])
  }
}

function onListenerClick(e, ei, li, listener, event) {
  if (e.ctrlKey || e.metaKey) {
    store.toggleSelection({
      type: 'listener',
      sig: listener.sig,
      line_no: listener.line_no,
      depth: listener.depth ?? 0,
      breadcrumb: [{ sig: event.event, line_no: event.line_no }, { sig: listener.sig, line_no: listener.line_no }],
    })
    return
  }
  const cachedFileAbs = store.getListenerFileAbs(props.fileId, listener.line_no, listener.depth ?? 0)
  store.setCodeNode({ line_no: listener.line_no, sig: listener.sig, file_abs: cachedFileAbs ?? null },
    [{ sig: event.event, line_no: event.line_no }])
  toggleListener(ei, li, listener)
}

function prefetchListener(listener) {
  if (!props.fileId || listener.line_no == null) return
  // Fire-and-forget: warm the children + source caches on hover so click is instant
  store.fetchChildren(props.fileId, listener.line_no, listener.depth ?? 0).then(result => {
    const firstChild = (result?.children || []).find(c => c.file_abs)
    if (firstChild) {
      const absPath = firstChild.file_abs.replace(/:\d+$/, '')
      const hint = firstChild.file_abs.match(/:(\d+)$/)?.[1]
      store.fetchSource(absPath, hint ? parseInt(hint) : 0)
    }
  }).catch(() => {})
}

async function toggleListener(ei, li, listener) {
  const event = props.toc[ei]
  console.log('[toggleListener]', { ei, li, sig: listener.sig, line_no: listener.line_no, depth: listener.depth })
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
    console.log('[toggleListener] fetching children for line_no', listener.line_no, 'depth', listener.depth ?? 0)
    const shortSig = (listener.sig || '').split(/[\\>\(]/).pop() || listener.sig
    const result = await perf.time(
      `expand ${shortSig}`,
      'toc',
      () => store.fetchChildren(props.fileId, listener.line_no, listener.depth ?? 0),
      { line_no: listener.line_no }
    )
    console.log('[toggleListener] result', result)
    childrenCache[key] = result
    loadingKey.value = null
  }
}

function getChildren(ei, li) {
  const cached = childrenCache[`${ei}-${li}`]
  if (!cached) return []
  return cached.children ?? cached
}

// Method suffixes that are pure infrastructure noise — never business logic.
const NOISE_METHOD_RE = /->(?:getUserIdentifier|getPassword|getSalt|getRoles|eraseCredentials|__serialize|__unserialize|__wakeup|__clone|getContext|setContext|setHost|getHost|getUrlMatcher|withSecurity|withRestrictionProvider|withAuthorizationChecker|withAccessProvider|setSoftRemovableFilterDisabler|setSoftDeleteableFilter|addFilterConstraint)$/

// Namespaces that are always infrastructure — filter entire subtree regardless of children.
const NOISE_NS_RE = /^App\\Routing\\/

const CLASS_COLORS_DARK  = ['#e8eef4', '#c0cfe0', '#98afc8', '#7898b8', '#5880a0']
const CLASS_COLORS_LIGHT = ['#0d1e3a', '#1a3460', '#2a4e80', '#3a6090', '#4a70a0']
const METHOD_COLORS_DARK  = ['#8aaac8', '#6888a8', '#507090', '#3a5878', '#2a4060']
const METHOD_COLORS_LIGHT = ['#0d3060', '#1a4878', '#2a5888', '#185098', '#103080']
const classColors  = computed(() => store.theme === 'light' ? CLASS_COLORS_LIGHT  : CLASS_COLORS_DARK)
const methodColors = computed(() => store.theme === 'light' ? METHOD_COLORS_LIGHT : METHOD_COLORS_DARK)
function classPartStyle(i)  { return { color: classColors.value[Math.min(i,  classColors.value.length  - 1)] } }
function methodPartStyle(i) { return { color: methodColors.value[Math.min(i, methodColors.value.length - 1)] } }
function camelParts(str) {
  if (!str) return []
  const m = str.match(/^(->|::)(.*)$/)
  const sep = m ? m[1] : ''
  const word = m ? m[2] : str
  const parts = word.split(/(?=[A-Z])/).filter(Boolean)
  if (!parts.length) return [str]
  parts[0] = sep + parts[0]
  return parts
}

// Remove DI-container noise: calls originating from var/cache (lazy service init),
// routing infrastructure namespace, method-level noise, and __construct with no children.
function filterAppCalls(calls) {
  if (!calls?.length) return []
  return calls
    .filter(n => {
      if (n.file_abs?.includes('/var/cache/')) return false
      const sig = n.sig ?? ''
      // Skip non-App\ classes entirely — vendor/framework code
      if (!sig.startsWith('App\\')) return false
      if (NOISE_NS_RE.test(sig)) return false
      const isConstructor = sig.endsWith('->__construct') || sig.endsWith('::__construct')
      if (isConstructor && !n.children?.length) return false
      if (NOISE_METHOD_RE.test(sig) && !n.children?.length) return false
      return true
    })
    .map(n => ({ ...n, children: filterAppCalls(n.children) }))
}

// If the sole root app_call duplicates the event name (controller-as-event),
// skip it and render its children directly to avoid repeating the header.
function dedupeAppCalls(event, appCalls) {
  const filtered = filterAppCalls(appCalls)
  if (filtered.length === 1 && filtered[0].sig === event) {
    return filterAppCalls(filtered[0].children)
  }
  return filtered
}

function controllerShortSig(sig) {
  const arrow = sig.lastIndexOf('->')
  if (arrow === -1) return sig.split('\\').pop()
  const cls = sig.slice(0, arrow).split('\\').pop()
  const method = sig.slice(arrow)
  return cls + method
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
  ctxMenu.value.open(event, [
    { kind: 'event', value: tocEvent.event },
    { action: 'filter-event', value: tocEvent.event },
  ])
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

const allCollapsed = ref(false)

function collapseAll() {
  expandedEvents.value = new Set()
  expandedListeners.value = new Set()
  expandedGroups.value = new Set()
  expandedAppCalls.value = new Set()
  allCollapsed.value = true
}

function expandAll() {
  const allEi = new Set(props.toc.map((_, i) => i))
  expandedEvents.value = allEi
  expandedGroups.value = new Set(tocGroups.value.map((_, i) => i))
  allCollapsed.value = false
}

defineExpose({ jumpToLine, collapseAll, expandAll, allCollapsed })
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
  margin-bottom: 2px;
}

/* ── Source group header ── */
.source-group-header {
  font-size: 9.5px;
  font-weight: 600;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  padding: 10px 18px 4px;
  color: #4a6080;
  display: flex;
  align-items: center;
  gap: 8px;
}
.source-group-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(50, 70, 110, 0.45);
}
.source-group-header[data-src="sf"] { color: #3a7898; }
.source-group-header:not([data-src="sf"]) { color: #7a7040; }

/* ── Event row ── */
.event-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px 8px 14px;
  cursor: pointer;
  border-left: 3px solid rgba(60, 100, 160, 0.2);
  transition: background 0.12s, border-left-color 0.12s;
  border-radius: 0 4px 4px 0;
}
.event-row:hover {
  background: rgba(80, 130, 200, 0.07);
  border-left-color: rgba(80, 140, 220, 0.5);
}
.event-row.has-listeners {
  border-left-color: rgba(70, 120, 190, 0.35);
}
.event-row--controller {
  border-left-color: rgba(80, 180, 80, 0.35);
}
.event-row--controller:hover {
  border-left-color: rgba(80, 200, 80, 0.6);
  background: rgba(60, 140, 60, 0.06);
}
.event-name--controller { color: #78c058; font-size: 14px; }
.event-row--selected {
  background: rgba(80, 130, 220, 0.12) !important;
  border-left-color: rgba(90, 150, 240, 0.8) !important;
}
.event-row--code-active {
  background: rgba(180, 140, 40, 0.10) !important;
  border-left-color: rgba(210, 170, 50, 0.85) !important;
}

.fav-badge-ev {
  font-size: 10px;
  border: 1px solid;
  border-radius: 3px;
  padding: 0px 6px;
  flex-shrink: 0;
  letter-spacing: 0.02em;
}

.chevron { color: #5878a0; font-size: 11px; width: 12px; flex-shrink: 0; transition: color 0.1s; }
.event-row:hover .chevron { color: #80a8d8; }

.event-name {
  font-size: 14.5px;
  font-weight: 500;
  color: #90b8d8;
  flex: 1;
  letter-spacing: 0.01em;
}
.event-name--dimmed { color: #5070a0; }
.event-name--attr { color: #b8d890; font-weight: 600; }
.event-group-collapse--attr { color: #90b860; }
.event-group-collapse--caller { color: #5868a0; font-size: 10px; }
.vote-caller-badge {
  font-size: 10px;
  color: #6878b0;
  background: rgba(50, 60, 110, 0.2);
  border: 1px solid rgba(70, 80, 140, 0.3);
  border-radius: 8px;
  padding: 1px 7px;
  flex-shrink: 0;
}

.line-badge {
  font-size: 10px;
  color: #5060a0;
  background: #0e1020;
  border: 1px solid #222840;
  border-radius: 4px;
  padding: 2px 7px;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.1s, border-color 0.1s;
}
.line-badge:hover { color: #90c8ff; border-color: #5a80df; }

/* ── Listeners container — vertical line connects to event ── */
.listeners {
  position: relative;
  margin-left: 28px;
  margin-top: 1px;
  margin-bottom: 4px;
  padding-left: 16px;
}
.listeners::before {
  content: '';
  position: absolute;
  left: 4px;
  top: 0;
  bottom: 6px;
  width: 1px;
  background: rgba(60, 100, 180, 0.3);
  border-radius: 1px;
}

.listener-block {
  margin-bottom: 1px;
}

.listener-source-header {
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 5px 10px 3px 0;
  color: #404860;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 3px;
}
.listener-source-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(40, 60, 90, 0.5);
}
.listener-source-header[data-src="sf"] { color: #3a6878; }
.listener-source-header:not([data-src="sf"]) { color: #5e5630; }

.listener-row {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 5px 12px 5px 0;
  border-left: 2px solid transparent;
  cursor: pointer;
  transition: background 0.1s, border-left-color 0.1s;
  border-radius: 0 3px 3px 0;
}
.listener-row:hover {
  background: rgba(255, 255, 255, 0.03);
  border-left-color: rgba(80, 130, 200, 0.4);
}
.listener-row--fav { }
.listener-row--selected {
  background: rgba(80, 130, 220, 0.10) !important;
  border-left-color: rgba(90, 150, 240, 0.7) !important;
}
.listener-row--code-active {
  background: rgba(180, 140, 40, 0.10) !important;
  border-left-color: rgba(210, 170, 50, 0.85) !important;
}
.listener-row--abstain { opacity: 0.32; }
.listener-row--abstain:hover { opacity: 0.6; }
.listener-row--granted {
  border-left-color: rgba(70, 190, 90, 0.65) !important;
  background: rgba(40, 110, 55, 0.08) !important;
}
.listener-row--denied {
  border-left-color: rgba(210, 70, 70, 0.65) !important;
  background: rgba(110, 30, 30, 0.08) !important;
}

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
  border-radius: 3px;
  padding: 0px 5px;
  flex-shrink: 0;
}

.connector { display: none; }
.chevron-sm { color: #5070a0; font-size: 10px; width: 11px; flex-shrink: 0; }
.listener-row:hover .chevron-sm { color: #80a8d8; }

.listener-class {
  font-size: 13.5px;
  font-weight: 500;
}
.listener-method {
  font-size: 13px;
}
.voter-badge {
  font-size: 11px;
  color: #b09050;
  background: rgba(110, 75, 15, 0.22);
  border: 1px solid rgba(160, 110, 30, 0.3);
  border-radius: 3px;
  padding: 0px 5px;
  flex-shrink: 0;
  font-style: italic;
}
.vote-attr-badge {
  font-size: 10px;
  color: #70a8c8;
  background: rgba(25, 65, 95, 0.28);
  border: 1px solid rgba(55, 100, 140, 0.3);
  border-radius: 3px;
  padding: 0px 5px;
  flex-shrink: 0;
}
.vote-result {
  font-size: 10px;
  font-weight: 700;
  border-radius: 3px;
  padding: 1px 6px;
  flex-shrink: 0;
  letter-spacing: 0.04em;
  margin-left: auto;
}
.vote-result--granted { color: #68d880; background: rgba(35, 110, 55, 0.22); border: 1px solid rgba(55, 160, 80, 0.35); }
.vote-result--denied  { color: #d86868; background: rgba(110, 28, 28, 0.22); border: 1px solid rgba(190, 55, 55, 0.35); }
.line-badge-sm {
  font-size: 10px;
  color: #485880;
  margin-left: auto;
  cursor: pointer;
  padding: 1px 5px;
  border-radius: 3px;
  flex-shrink: 0;
  transition: color 0.1s, background 0.1s;
}
.line-badge-sm:hover { color: #90c8ff; background: #0e1020; }

/* ── Children (CallNode inside listener) ── */
.children {
  margin-left: 2px;
  padding-bottom: 4px;
}

.loading { color: #5070a0; font-size: 12px; padding: 5px 0; }

.no-listeners {
  margin-left: 28px;
  color: #3a5070;
  font-size: 12px;
  padding: 3px 0 6px;
  font-style: italic;
}

/* ── Flatten mode: event index badge ── */
.event-idx {
  font-size: 10px;
  color: #3a5878;
  min-width: 26px;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.listener-idx {
  font-size: 10px;
  color: #3a5878;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  min-width: 24px;
}

/* ── Repeated-event group ── */
.event-row--group { opacity: 0.7; }
.event-group-count {
  font-size: 11px;
  color: #506888;
  background: rgba(45, 65, 100, 0.22);
  border: 1px solid rgba(55, 80, 130, 0.28);
  border-radius: 8px;
  padding: 0px 7px;
  margin-left: 2px;
  flex-shrink: 0;
  font-weight: 600;
}
.event-row--group:hover .event-group-count {
  color: #78a0c8;
  background: rgba(55, 85, 140, 0.32);
}

.event-group-bar {
  display: flex;
  align-items: center;
  padding: 3px 16px 3px 14px;
  cursor: pointer;
  border-bottom: 1px solid rgba(35, 50, 85, 0.35);
  margin-bottom: 3px;
}
.event-group-collapse {
  font-size: 10px;
  color: #506080;
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: 0.02em;
  transition: color 0.1s;
}
.event-group-bar:hover .event-group-collapse { color: #88b0d8; }

</style>

<style>
/* ── TocTree light theme overrides ── */
html[data-theme="light"] .toc-tree {
  color: #0d1828 !important;
  background: rgba(220, 230, 248, 0.45) !important;
}
html[data-theme="light"] .toc-tree .empty { color: #4a5878 !important; }

html[data-theme="light"] .toc-tree .source-group-header { color: #2a4870 !important; }
html[data-theme="light"] .toc-tree .source-group-header::after { background: rgba(80, 110, 180, 0.3) !important; }
html[data-theme="light"] .toc-tree .source-group-header[data-src="sf"] { color: #1a5880 !important; }
html[data-theme="light"] .toc-tree .source-group-header:not([data-src="sf"]) { color: #5a4820 !important; }

html[data-theme="light"] .toc-tree .event-row { border-left-color: rgba(80, 120, 200, 0.2) !important; }
html[data-theme="light"] .toc-tree .event-row:hover { background: rgba(70, 110, 210, 0.07) !important; border-left-color: rgba(60, 120, 220, 0.55) !important; }
html[data-theme="light"] .toc-tree .event-row.has-listeners { border-left-color: rgba(60, 110, 200, 0.35) !important; }
html[data-theme="light"] .toc-tree .event-row--controller { border-left-color: rgba(40, 160, 60, 0.4) !important; }
html[data-theme="light"] .toc-tree .event-row--controller:hover { border-left-color: rgba(30, 170, 50, 0.65) !important; background: rgba(30, 120, 40, 0.06) !important; }
html[data-theme="light"] .toc-tree .event-row--selected { background: rgba(50, 100, 220, 0.10) !important; border-left-color: rgba(40, 110, 230, 0.8) !important; }
html[data-theme="light"] .toc-tree .event-row--code-active { background: rgba(160, 120, 10, 0.09) !important; border-left-color: rgba(180, 140, 10, 0.75) !important; }
html[data-theme="light"] .toc-tree .event-name { color: #0d2c58 !important; }
html[data-theme="light"] .toc-tree .event-name--dimmed { color: #3a5878 !important; }
html[data-theme="light"] .toc-tree .event-name--attr { color: #3a6010 !important; }
html[data-theme="light"] .toc-tree .event-name--controller { color: #1a6808 !important; }
html[data-theme="light"] .toc-tree .chevron { color: #3a5888 !important; }
html[data-theme="light"] .toc-tree .event-row:hover .chevron { color: #0d3060 !important; }
html[data-theme="light"] .toc-tree .chevron-sm { color: #3a5888 !important; }

html[data-theme="light"] .toc-tree .vote-caller-badge { color: #2a4878 !important; background: rgba(60, 90, 180, 0.10) !important; border-color: rgba(60, 90, 180, 0.25) !important; }
html[data-theme="light"] .toc-tree .line-badge { color: #2a4070 !important; background: #e8eef8 !important; border-color: #9ab0d8 !important; }
html[data-theme="light"] .toc-tree .line-badge:hover { color: #0d2060 !important; border-color: #3a6abf !important; }
html[data-theme="light"] .toc-tree .line-badge-sm { color: #3a5070 !important; }
html[data-theme="light"] .toc-tree .line-badge-sm:hover { color: #0d2060 !important; background: #e0e8f8 !important; }

html[data-theme="light"] .toc-tree .listeners::before { background: rgba(70, 110, 200, 0.25) !important; }

html[data-theme="light"] .toc-tree .listener-row:hover { background: rgba(60, 100, 200, 0.05) !important; border-left-color: rgba(60, 120, 210, 0.45) !important; }
html[data-theme="light"] .toc-tree .listener-row--selected { background: rgba(50, 100, 220, 0.09) !important; border-left-color: rgba(40, 110, 230, 0.7) !important; }
html[data-theme="light"] .toc-tree .listener-row--code-active { background: rgba(160, 120, 10, 0.09) !important; border-left-color: rgba(180, 140, 10, 0.75) !important; }
html[data-theme="light"] .toc-tree .listener-row--granted { border-left-color: rgba(20, 150, 55, 0.65) !important; background: rgba(15, 100, 35, 0.06) !important; }
html[data-theme="light"] .toc-tree .listener-row--denied  { border-left-color: rgba(200, 35, 35, 0.65) !important; background: rgba(170, 15, 15, 0.06) !important; }

html[data-theme="light"] .toc-tree .listener-source-header { color: #2a4060 !important; }
html[data-theme="light"] .toc-tree .listener-source-header::after { background: rgba(60, 90, 150, 0.25) !important; }
html[data-theme="light"] .toc-tree .listener-source-header[data-src="sf"] { color: #1a5070 !important; }
html[data-theme="light"] .toc-tree .listener-source-header:not([data-src="sf"]) { color: #50400a !important; }

html[data-theme="light"] .toc-tree .voter-badge { color: #7a5010 !important; background: rgba(160, 100, 10, 0.10) !important; border-color: rgba(180, 120, 20, 0.3) !important; }
html[data-theme="light"] .toc-tree .vote-attr-badge { color: #1a4878 !important; background: rgba(30, 80, 150, 0.09) !important; border-color: rgba(40, 90, 170, 0.28) !important; }
html[data-theme="light"] .toc-tree .vote-result--granted { color: #0d6025 !important; background: rgba(15, 110, 45, 0.10) !important; border-color: rgba(15, 145, 55, 0.35) !important; }
html[data-theme="light"] .toc-tree .vote-result--denied  { color: #7a0808 !important; background: rgba(150, 15, 15, 0.09) !important; border-color: rgba(190, 25, 25, 0.35) !important; }

html[data-theme="light"] .toc-tree .loading { color: #3a5080 !important; }
html[data-theme="light"] .toc-tree .no-listeners { color: #3a5070 !important; }
html[data-theme="light"] .toc-tree .event-idx { color: #2a4868 !important; }
html[data-theme="light"] .toc-tree .listener-idx { color: #2a4868 !important; }

html[data-theme="light"] .toc-tree .event-group-count { color: #2a4878 !important; background: rgba(50, 90, 180, 0.10) !important; border-color: rgba(60, 100, 200, 0.22) !important; }
html[data-theme="light"] .toc-tree .event-group-bar { border-bottom-color: rgba(80, 110, 200, 0.18) !important; }
html[data-theme="light"] .toc-tree .event-group-collapse { color: #2a4878 !important; }
html[data-theme="light"] .toc-tree .event-group-bar:hover .event-group-collapse { color: #0d2050 !important; }
html[data-theme="light"] .toc-tree .event-group-collapse--attr { color: #3a6010 !important; }
html[data-theme="light"] .toc-tree .event-group-collapse--caller { color: #3a5080 !important; }
</style>
