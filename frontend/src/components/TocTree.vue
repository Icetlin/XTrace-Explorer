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
                :class="{ 'listener-row--fav': listenerScanMatches(ei, li).length, 'listener-row--selected': store.isSelected(listener.line_no), 'listener-row--abstain': listener.vote_result === 0, 'listener-row--granted': listener.vote_result === 1, 'listener-row--denied': listener.vote_result === -1 }"
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
                :class="{ 'has-listeners': toc[ei].children?.length || toc[ei].app_calls?.length, 'event-row--selected': store.isSelected(toc[ei].line_no) }"
                @click="onEventClick($event, ei, toc[ei])"
              >
                <span class="chevron">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
                <span class="event-name event-name--controller">{{ controllerShortSig(toc[ei].event) }}</span>
              </div>
              <template v-if="expandedEvents.has(ei)">
                <AppCallTree
                  v-if="dedupeAppCalls(toc[ei].event, toc[ei].app_calls).length"
                  :calls="dedupeAppCalls(toc[ei].event, toc[ei].app_calls)"
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
              @click="onEventClick($event, ei, toc[ei])"
              @contextmenu.prevent="onEventCtx($event, toc[ei])"
            >
              <span class="chevron">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
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
                  :class="{ 'listener-row--fav': listenerScanMatches(ei, li).length, 'listener-row--selected': store.isSelected(listener.line_no), 'listener-row--abstain': listener.vote_result === 0, 'listener-row--granted': listener.vote_result === 1, 'listener-row--denied': listener.vote_result === -1 }"
                  :data-listener-line="listener.line_no"
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

            <AppCallTree
              v-if="expandedEvents.has(ei) && dedupeAppCalls(toc[ei].event, toc[ei].app_calls).length"
              :calls="dedupeAppCalls(toc[ei].event, toc[ei].app_calls)"
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
import { ref, reactive, computed, nextTick } from 'vue'
import { useTraceStore } from '../stores/trace'
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
const ctxMenu = ref(null)

const expandedEvents = ref(new Set())
const expandedListeners = ref(new Set())
const expandedGroups = ref(new Set())
const expandedAppCalls = ref(new Set())

function toggleAppCall(lineNo) {
  const s = new Set(expandedAppCalls.value)
  s.has(lineNo) ? s.delete(lineNo) : s.add(lineNo)
  expandedAppCalls.value = s
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

function onEventClick(e, ei, event) {
  if (e.ctrlKey || e.metaKey) {
    store.toggleSelection({ type: 'event', sig: event.event, line_no: event.line_no, breadcrumb: [] })
    return
  }
  toggleEvent(ei)
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
  toggleListener(ei, li, listener)
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
    const result = await store.fetchChildren(props.fileId, listener.line_no, listener.depth ?? 0)
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

const CLASS_COLORS = ['#e8eef4', '#c0cfe0', '#98afc8', '#7898b8', '#5880a0']
const METHOD_COLORS = ['#8aaac8', '#6888a8', '#507090', '#3a5878', '#2a4060']
function classPartStyle(i) { return { color: CLASS_COLORS[Math.min(i, CLASS_COLORS.length - 1)] } }
function methodPartStyle(i) { return { color: METHOD_COLORS[Math.min(i, METHOD_COLORS.length - 1)] } }
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
  color: #5a7090;
  display: flex;
  align-items: center;
  gap: 8px;
}
.source-group-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(60, 80, 120, 0.4);
}
.source-group-header[data-src="sf"] { color: #5090a8; }
.source-group-header:not([data-src="sf"]) { color: #8a8050; }

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
.event-row--controller { border-left: 2px solid rgba(100, 160, 80, 0.3); }
.event-name--controller { color: #80c060; font-size: 14px; }
.event-row--selected { background: rgba(80, 120, 180, 0.08); border-left: 2px solid rgba(80, 130, 200, 0.5); }

.fav-badge-ev {
  font-size: 10px;
  border: 1px solid;
  border-radius: 4px;
  padding: 1px 7px;
  flex-shrink: 0;
  letter-spacing: 0.02em;
}

.chevron { color: #7080a0; font-size: 12.5px; width: 12px; flex-shrink: 0; }


.event-name {
  font-size: 15px;
  font-weight: 500;
  color: #a0c0e0;
  flex: 1;
  letter-spacing: 0.01em;
}
.event-name--dimmed { color: #6080a0; }
.event-name--attr { color: #c8e8a0; font-weight: 600; }
.event-group-collapse--attr { color: #a0c070; }
.event-group-collapse--caller { color: #6878a8; font-size: 10px; }
.vote-caller-badge {
  font-size: 10px;
  color: #7888b8;
  background: rgba(60, 70, 120, 0.2);
  border: 1px solid rgba(80, 90, 150, 0.3);
  border-radius: 10px;
  padding: 1px 8px;
  flex-shrink: 0;
  letter-spacing: 0.02em;
}

.line-badge {
  font-size: 10px;
  color: #6070a0;
  background: #0e1020;
  border: 1px solid #2a3050;
  border-radius: 4px;
  padding: 2px 8px;
  cursor: pointer;
  flex-shrink: 0;
  transition: color 0.1s, border-color 0.1s;
}
.line-badge:hover { color: #90c8ff; border-color: #5a80df; }

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
  color: #506070;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.listener-source-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(50, 70, 90, 0.4);
}
.listener-source-header[data-src="sf"] { color: #4a7890; }
.listener-source-header:not([data-src="sf"]) { color: #707050; }

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
.listener-row--selected { background: rgba(80, 120, 180, 0.07); border-left: 2px solid rgba(80, 130, 200, 0.4); }
/* Vote result states */
.listener-row--abstain { opacity: 0.38; }
.listener-row--abstain:hover { opacity: 0.65; }
.listener-row--granted { border-left: 2px solid rgba(80, 200, 100, 0.6); background: rgba(40, 100, 50, 0.08); }
.listener-row--denied { border-left: 2px solid rgba(220, 80, 80, 0.6); background: rgba(100, 30, 30, 0.08); }

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

.connector { color: #6878a0; font-size: 13.5px; flex-shrink: 0; }
.chevron-sm { color: #6878a8; font-size: 10px; width: 10px; flex-shrink: 0; }

.listener-class {
  font-size: 14px;
  font-weight: 500;
}
.listener-method {
  font-size: 13.5px;
}
.voter-badge {
  font-size: 11px;
  color: #c0a060;
  background: rgba(120, 80, 20, 0.25);
  border: 1px solid rgba(180, 120, 40, 0.3);
  border-radius: 3px;
  padding: 1px 5px;
  flex-shrink: 0;
  font-style: italic;
}
.vote-attr-badge {
  font-size: 10px;
  color: #7ab0d0;
  background: rgba(30, 70, 100, 0.3);
  border: 1px solid rgba(60, 110, 150, 0.3);
  border-radius: 3px;
  padding: 1px 5px;
  flex-shrink: 0;
  font-family: monospace;
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
.vote-result--granted { color: #70e090; background: rgba(40, 120, 60, 0.25); border: 1px solid rgba(60, 180, 90, 0.35); }
.vote-result--denied  { color: #e07070; background: rgba(120, 30, 30, 0.25); border: 1px solid rgba(200, 60, 60, 0.35); }
.line-badge-sm {
  font-size: 10px;
  color: #5a6888;
  margin-left: auto;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 3px;
  flex-shrink: 0;
  transition: color 0.1s, background 0.1s;
}
.line-badge-sm:hover { color: #90c8ff; background: #0e1020; }

/* ── Children ── */
.children {
  margin-left: 26px;
  border-left: 1px dashed #2a3050;
  padding-left: 8px;
  padding-bottom: 4px;
}

.loading { color: #6070a0; font-size: 12.5px; padding: 6px 0; }

.no-listeners {
  margin-left: 24px;
  color: #506080;
  font-size: 12.5px;
  padding: 4px 0 8px;
  font-style: italic;
}

/* ── Flatten mode: event index badge ── */
.event-idx {
  font-size: 10px;
  color: #4a6080;
  min-width: 28px;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.02em;
}
/* Normal mode: original listener index (shown when voters are sorted) */
.listener-idx {
  font-size: 10px;
  color: #4a6080;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  min-width: 26px;
}

/* ── Repeated-event group ── */
.event-row--group {
  opacity: 0.75;
}
.event-group-count {
  font-size: 11px;
  color: #5a7090;
  background: rgba(50, 70, 100, 0.25);
  border: 1px solid rgba(60, 85, 130, 0.3);
  border-radius: 10px;
  padding: 1px 8px;
  margin-left: 2px;
  flex-shrink: 0;
  font-weight: 600;
  letter-spacing: 0.03em;
}
.event-row--group:hover .event-group-count {
  color: #7a9cc0;
  background: rgba(60, 90, 140, 0.35);
}

.event-group-bar {
  display: flex;
  align-items: center;
  padding: 3px 18px 3px 14px;
  cursor: pointer;
  border-bottom: 1px solid rgba(40, 55, 90, 0.3);
  margin-bottom: 4px;
}
.event-group-collapse {
  font-size: 10.5px;
  color: #5a7090;
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: 0.02em;
  transition: color 0.1s;
}
.event-group-bar:hover .event-group-collapse {
  color: #90b8e0;
}
</style>
