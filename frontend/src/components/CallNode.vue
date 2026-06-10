<template>
  <div class="call-node" :data-indent="indent % 12">
    <div
      class="call-row"
      :class="{
        'call-row--fav': directMatches.length,
        'call-row--fav-bubble': !directMatches.length && bubbleMatches.length,
        'call-row--selected': store.isSelected(node.line_no),
        'call-row--code-active': store.isCodeActive(node.line_no),
        'call-row--leaf': isLeaf,
        'call-row--noisy': isNoisy,
      }"
      :style="{
        paddingLeft: (indent * 16 + 14) + 'px',
        ...(directMatches.length ? {
          background: favColor(directMatches[0].pattern).bg,
          borderLeftColor: favColor(directMatches[0].pattern).borderLeft,
        } : {})
      }"
      :data-line-no="node.line_no"
      @click="onRowClick"
      @contextmenu.prevent="onContextMenu"
    >
      <span class="chevron-sm" :class="{ 'chevron-sm--leaf': isLeaf || isNoisy }" @click.stop="toggle">{{ isLeaf || isNoisy ? '·' : (expanded ? '▾' : '▸') }}</span>
      <span class="call-sig" :class="[sigClass, { 'call-sig--dim': isLeaf || isNoisy }]" :title="node.sig" v-html="renderSig(node.sig)"></span>
      <template v-if="node.args?.length">
        <span
          v-for="(a, i) in node.args"
          :key="i"
          class="call-arg"
          :class="{ 'call-arg--obj': isObjectArg(a), 'call-arg--expanded': expandedArgs[i], 'call-arg--fav': argMatches(a) }"
          :style="argMatches(a) ? { borderColor: argFavColor(a).borderLeft, color: argFavColor(a).text } : {}"
          @click.stop="isObjectArg(a) && toggleArg(i)"
        >
          <span v-if="argName(a)" class="arg-name">{{ argName(a) }}</span>
          <span v-if="argName(a)" class="arg-sep">=</span>
          <span
            class="arg-val"
            :style="argMatches(a) ? { color: argFavColor(a).text } : {}"
          >{{ argVal(a) }}</span>
        </span>
        <div v-if="Object.keys(expandedArgs).length > 0" class="arg-fields">
          <template v-for="(obj, idx) in expandedArgData" :key="idx">
            <div v-if="obj" class="arg-obj-header">{{ obj.class }} {</div>
            <div v-if="obj" v-for="f in obj.fields" :key="f.name" class="arg-obj-field">
              <span class="arg-field-name">${{ f.name }}</span>
              <span class="arg-field-eq">=</span>
              <span class="arg-field-val">{{ f.value }}</span>
            </div>
            <div v-if="obj" class="arg-obj-close">}</div>
          </template>
        </div>
      </template>
      <span v-if="node.return != null" class="call-return" :class="{ 'call-return--fav': returnMatches }">⇒ {{ node.return }}</span>
      <span v-if="expanded && parentReturn != null" class="call-return call-return--parent">⇒ {{ parentReturn }}</span>
      <span v-if="node.file" class="call-file" :class="{ 'call-file--dim': isLeaf || isNoisy }">{{ node.file }}</span>
      <!-- Fav match badges -->
      <span
        v-for="m in directMatches"
        :key="m.pattern"
        class="fav-badge"
        :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
      >{{ m.label || m.pattern }}</span>
      <!-- Bubble hint: descendant has a match -->
      <span
        v-for="m in bubbleMatches.filter(bm => !directMatches.some(d => d.pattern === bm.pattern))"
        :key="'b-' + m.pattern"
        class="fav-badge fav-badge--bubble"
        :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
      >{{ m.label || m.pattern }}</span>
    </div>

    <div v-if="expanded" class="call-children">
      <div v-if="loading" class="loading">loading…</div>
      <template v-else>
        <template v-for="(child, i) in children" :key="i">
          <div
            v-if="childSource(child.sig) && (i === 0 || childSource(child.sig) !== childSource(children[i-1].sig))"
            class="child-source-header"
            :data-src="childSource(child.sig)"
          >{{ childSource(child.sig) }}</div>
          <CallNode
            :node="child"
            :file-id="fileId"
            :indent="indent + 1"
            :expand-path="expandPath"
            :ancestor-crumbs="myCrumbs"
            @jump="$emit('jump', $event)"
            @fav-match="onChildFavMatch"
            @ctx-menu="$emit('ctx-menu', $event)"
            @breadcrumb="$emit('breadcrumb', $event)"
          />
        </template>
        <div v-if="!children.length && raw" class="leaf">no calls</div>
        <SourceView v-if="children.length" :children="children" :file-id="fileId" />
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useTraceStore } from '../stores/trace'
import { favColor } from '../favColor.js'
import SourceView from './SourceView.vue'

const props = defineProps({
  node: Object,
  fileId: Number,
  indent: { type: Number, default: 0 },
  expandPath: { type: Array, default: null },
  ancestorCrumbs: { type: Array, default: () => [] },
})
const emit = defineEmits(['jump', 'fav-match', 'ctx-menu', 'breadcrumb'])

const store = useTraceStore()

const isLeaf = computed(() => {
  if (props.node.noise_only) return false
  if (rawCount.value === 0) return true
  if (rawCount.value === null && props.node.subtree_end != null && props.node.subtree_end <= props.node.line_no + 1) return true
  return false
})
const isNoisy = computed(() => !!props.node.noise_only)

const expanded = ref(false)
const children = ref([])
const parentReturn = ref(null)
const loading = ref(false)
const raw = ref(false)
const rawCount = ref(null)  // null = unknown, 0 = true leaf, >0 = has noise children

// Auto-expand when this node is in the expandPath
watch(() => props.expandPath, async (path) => {
  if (!path?.includes(props.node.line_no)) return
  if (!expanded.value) {
    expanded.value = true
    if (!children.value.length) await load(false)
  }
}, { immediate: true })
const expandedArgs = ref({})
const expandedArgData = ref({})

// Favourites matching
const bubbleMatches = ref([])  // matches propagated up from children

// Full text of this node for matching
const nodeText = computed(() => {
  const parts = [props.node.sig || '']
  if (props.node.args) parts.push(...props.node.args)
  if (props.node.return != null) parts.push(String(props.node.return))
  return parts.join(' ')
})

const allMatches = computed(() => {
  const runtime = store.matchFavourites(nodeText.value)
  const fromServer = props.node.fav_matches || []
  const seen = new Set(runtime.map(m => m.pattern))
  const extra = fromServer.filter(m => !seen.has(m.pattern))
  return [...runtime, ...extra]
})

const directMatches = computed(() => {
  // Show badge only if match is in sig — not already visible in an arg or return
  const argText = (props.node.args || []).join(' ')
  const retText = props.node.return != null ? String(props.node.return) : ''
  return allMatches.value.filter(m => {
    const inArg = store.matchFavourites(argText).some(x => x.pattern === m.pattern)
    const inRet = retText && store.matchFavourites(retText).some(x => x.pattern === m.pattern)
    return !inArg && !inRet
  })
})

const returnMatches = computed(() =>
  props.node.return != null && store.matchFavourites(String(props.node.return)).length > 0
)

function argMatches(arg) {
  return store.matchFavourites(arg).length > 0
}

function argFavColor(arg) {
  const matches = store.matchFavourites(arg)
  return matches.length ? favColor(matches[0].pattern) : favColor('')
}

// Bubble all matches (including arg matches) up to parent for propagation
watch(allMatches, (matches) => {
  if (matches.length) emit('fav-match', matches)
}, { immediate: true })

// On mount: bubble favScan hits from subtree immediately (works even before children are expanded)
onMounted(() => {
  if (!props.node.subtree_end || props.node.subtree_end <= props.node.line_no) return
  const fromLine = props.node.line_no + 1
  const toLine = props.node.subtree_end
  const tab = store.openTabs.find(t => t.fileId === props.fileId)
  if (tab?.favScan && Object.keys(tab.favScan).length) {
    const subtreeMatches = store.favMatchesInRange(props.fileId, fromLine, toLine)
    if (subtreeMatches.length) {
      const seen = new Set(bubbleMatches.value.map(m => m.pattern))
      for (const m of subtreeMatches) {
        if (!seen.has(m.pattern)) { bubbleMatches.value = [...bubbleMatches.value, m]; seen.add(m.pattern) }
      }
      emit('fav-match', subtreeMatches)
    }
  } else {
    const stop = watch(() => {
      const t = store.openTabs.find(t => t.fileId === props.fileId)
      return t?.favScan
    }, (scan) => {
      if (!scan || !Object.keys(scan).length) return
      const subtreeMatches = store.favMatchesInRange(props.fileId, fromLine, toLine)
      if (subtreeMatches.length) {
        const seen = new Set(bubbleMatches.value.map(m => m.pattern))
        for (const m of subtreeMatches) {
          if (!seen.has(m.pattern)) { bubbleMatches.value = [...bubbleMatches.value, m]; seen.add(m.pattern) }
        }
        emit('fav-match', subtreeMatches)
      }
      stop()
    })
  }
})

function onChildFavMatch(matches) {
  // Merge unique patterns from children
  const existing = new Set(bubbleMatches.value.map(m => m.pattern))
  for (const m of matches) {
    if (!existing.has(m.pattern)) {
      bubbleMatches.value = [...bubbleMatches.value, m]
      existing.add(m.pattern)
    }
  }
  emit('fav-match', bubbleMatches.value)
}

// Context menu items for this node
function buildContextItems() {
  const items = []
  // Sig
  if (props.node.sig) {
    const short = shortSig(props.node.sig)
    items.push({ kind: 'sig', value: short })
    if (props.node.sig !== short) items.push({ kind: 'full sig', value: props.node.sig })
  }
  // Args — extract actual values (strip "$name = " prefix)
  for (const arg of (props.node.args || [])) {
    const m = arg.match(/^\$\w+\s*=\s*(.+)$/)
    const val = m ? m[1] : arg
    if (val && val !== '[…]' && !val.endsWith('{…}')) {
      items.push({ kind: 'arg', value: val.replace(/^'|'$/g, '') })
    }
    // For objects like Cookie('sio_u') extract inner value too
    const inner = val.match(/\((['"]?)(.+?)\1\)$/)
    if (inner) items.push({ kind: 'arg val', value: inner[2] })
  }
  // Return
  if (props.node.return != null) {
    const ret = String(props.node.return).replace(/^'|'$/g, '')
    if (ret && ret !== '[…]' && !ret.endsWith('{…}')) {
      items.push({ kind: 'return', value: ret })
    }
  }
  return items
}

function onContextMenu(event) {
  emit('ctx-menu', { event, items: buildContextItems() })
}

const myCrumbs = computed(() => [
  ...props.ancestorCrumbs,
  { sig: props.node.sig, line_no: props.node.line_no },
])

function onRowClick(e) {
  if (e.ctrlKey || e.metaKey) {
    store.toggleSelection({
      type: 'call',
      sig: props.node.sig,
      line_no: props.node.line_no,
      depth: props.node.depth,
      args: props.node.args,
      breadcrumb: props.ancestorCrumbs,
    })
    return
  }
  if (props.node.file_abs) {
    store.setCodeNode(props.node, props.ancestorCrumbs)
  }
  // Don't auto-toggle here. The chevron (▸/▾) handles expand/collapse via its
  // own click handler — separating the two avoids the row double-acting
  // (CodeView + children fetch firing on every click).
}

async function toggle() {
  if (isLeaf.value || isNoisy.value) return
  expanded.value = !expanded.value
  emit('breadcrumb', { crumbs: myCrumbs.value, line: props.node.line_no })
  if (expanded.value && !children.value.length) {
    await load(false)
  }
}

async function load(isRaw) {
  loading.value = true
  raw.value = isRaw
  const result = await store.fetchChildren(props.fileId, props.node.line_no, props.node.depth, isRaw)
  children.value = result.children ?? result
  parentReturn.value = result.parent_return ?? null
  if (!isRaw && result.raw_count != null) rawCount.value = result.raw_count
  loading.value = false
  // Bubble fav matches from entire subtree using favScan — covers any depth
  if (children.value.length) {
    const fromLine = props.node.line_no + 1
    const toLine = props.node.subtree_end ?? (children.value[children.value.length - 1].subtree_end ?? children.value[children.value.length - 1].line_no)
    const subtreeMatches = store.favMatchesInRange(props.fileId, fromLine, toLine)
    const seen = new Set(bubbleMatches.value.map(m => m.pattern))
    for (const m of subtreeMatches) {
      if (!seen.has(m.pattern)) { bubbleMatches.value = [...bubbleMatches.value, m]; seen.add(m.pattern) }
    }
    if (bubbleMatches.value.length) emit('fav-match', bubbleMatches.value)
  }
}

async function loadRaw() {
  loading.value = true
  const result = await store.fetchChildren(props.fileId, props.node.line_no, props.node.depth, true)
  children.value = result.children ?? result
  parentReturn.value = result.parent_return ?? null
  raw.value = true
  loading.value = false
}

async function loadFiltered() {
  loading.value = true
  const result = await store.fetchChildren(props.fileId, props.node.line_no, props.node.depth, false)
  children.value = result.children ?? result
  parentReturn.value = result.parent_return ?? null
  raw.value = false
  loading.value = false
}

function isObjectArg(arg) {
  return /[{]…[}]$/.test(arg)
}

// Parse "$name = value" → name or null
function argName(arg) {
  const m = arg.match(/^\$(\w+)\s*=/)
  return m ? '$' + m[1] : null
}

// Parse "$name = value" → value only (or the whole arg if no name)
function argVal(arg) {
  const m = arg.match(/^\$\w+\s*=\s*(.+)$/)
  return m ? m[1] : arg
}

// Determine operation type from method name in sig
function callOp(sig) {
  if (!sig) return null
  const arrow = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  const method = sep !== -1 ? sig.slice(sep + 2) : sig
  const m = method.toLowerCase()
  if (m === '__construct' || m === 'create') return 'create'
  if (/^(set|add|push|append|put|assign|write|save|store|insert|update|register|attach|bind|inject|configure)/.test(m)) return 'write'
  if (/^(remove|delete|unset|detach|deregister|clear|flush|drop|discard|reset)/.test(m)) return 'delete'
  if (/^(get|fetch|find|load|read|retrieve|lookup|query|resolve|extract|parse|build|make|generate|produce|compute)/.test(m)) return 'read'
  if (/^(is|has|can|check|validate|assert|verify|test|match|compare|equal|contains|exists)/.test(m)) return 'check'
  return null
}

const OP_WORDS = { write: 'edit', delete: 'del', read: 'get', check: 'check', create: 'new' }

function callOpWord(sig) {
  const op = callOp(sig)
  return op ? OP_WORDS[op] : ''
}

async function toggleArg(idx) {
  if (expandedArgs.value[idx]) {
    delete expandedArgs.value[idx]
    delete expandedArgData.value[idx]
    expandedArgs.value = { ...expandedArgs.value }
    expandedArgData.value = { ...expandedArgData.value }
    return
  }
  expandedArgs.value = { ...expandedArgs.value, [idx]: true }
  expandedArgData.value = { ...expandedArgData.value, [idx]: null }
  try {
    const obj = await store.fetchObject(props.fileId, props.node.line_no, idx)
    expandedArgData.value = { ...expandedArgData.value, [idx]: obj }
  } catch {
    delete expandedArgs.value[idx]
    delete expandedArgData.value[idx]
    expandedArgs.value = { ...expandedArgs.value }
    expandedArgData.value = { ...expandedArgData.value }
  }
}

const sigClass = computed(() => {
  const s = props.node.sig || ''
  if (s.startsWith('App\\')) return 'sig-app'
  if (s.match(/Controller/)) return 'sig-ctrl'
  if (s.match(/Subscriber|Listener/)) return 'sig-listener'
  if (s.match(/^Symfony|^Doctrine|^Lexik|^Scheb/)) return 'sig-vendor'
  return 'sig-other'
})

function shortSig(sig) {
  if (!sig) return '?'
  const arrow = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  if (sep === -1) return sig
  const cls = sig.slice(0, sep)
  const method = sig.slice(sep)
  const shortCls = cls.split('\\').pop()
  return shortCls + method
}

const OP_PREFIXES = [
  'set','add','push','append','put','assign','write','save','store','insert','update','register','attach','bind','inject','configure',
  'remove','delete','unset','detach','deregister','clear','flush','drop','discard','reset',
  'get','fetch','find','load','read','retrieve','lookup','query','resolve','extract','parse','build','make','generate','produce','compute',
  'is','has','can','check','validate','assert','verify','test','match','compare','equal','contains','exists',
  'create',
]

function camelSpaced(s) {
  // Insert thin space before each uppercase letter that follows a lowercase letter
  return s.replace(/([a-z])([A-Z])/g, '$1<span class="cc-sp"> </span>$2')
}

function childSource(sig) {
  if (!sig) return null
  for (const { namespace, label } of store.appNamespaces) {
    if (sig.startsWith(namespace)) return label || namespace.replace(/\\+$/, '').split('\\').pop()
  }
  if (sig.startsWith('Symfony\\')) return 'sf'
  const parts = sig.split('\\')
  if (parts.length < 2) return null  // no namespace — don't group builtins
  const bundle = parts.find(p => p.endsWith('Bundle'))
  if (bundle) return bundle.replace(/Bundle$/, '')
  return parts[0] || null
}

function renderSig(sig) {
  if (!sig) return '?'
  const arrow = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  if (sep === -1) return sig
  const cls = sig.slice(0, sep)
  const sepStr = sig.slice(sep, sep + 2)
  const method = sig.slice(sep + 2)
  const shortCls = cls.split('\\').pop()

  // Find matching op prefix in method name
  const methodLower = method.toLowerCase()
  let opPrefix = null
  let rest = method
  if (methodLower === '__construct') {
    opPrefix = '__construct'
    rest = ''
  } else {
    for (const prefix of OP_PREFIXES) {
      if (methodLower.startsWith(prefix) && (method.length === prefix.length || /[A-Z_]/.test(method[prefix.length]))) {
        opPrefix = method.slice(0, prefix.length)
        rest = method.slice(prefix.length)
        break
      }
    }
  }

  const clsHtml = shortCls
  const sepHtml = sepStr

  let methodHtml
  if (opPrefix) {
    methodHtml = `<span class="sig-op">${opPrefix}</span>${rest}`
  } else {
    methodHtml = method
  }

  return `${clsHtml}<span class="sig-sep">${sepHtml}</span>${methodHtml}`
}
</script>

<style scoped>
/* Indent-level colors — cycle every 6 levels */
.call-node { --indent-color: #2a3a5a; }
.call-node[data-indent="0"] { --indent-color: #3a7aaa; }
.call-node[data-indent="1"] { --indent-color: #4a9a7a; }
.call-node[data-indent="2"] { --indent-color: #8a7ac8; }
.call-node[data-indent="3"] { --indent-color: #c89040; }
.call-node[data-indent="4"] { --indent-color: #c86070; }
.call-node[data-indent="5"] { --indent-color: #4aa8c0; }
.call-node[data-indent="6"] { --indent-color: #3a7aaa; }
.call-node[data-indent="7"] { --indent-color: #4a9a7a; }
.call-node[data-indent="8"] { --indent-color: #8a7ac8; }
.call-node[data-indent="9"] { --indent-color: #c89040; }
.call-node[data-indent="10"] { --indent-color: #c86070; }
.call-node[data-indent="11"] { --indent-color: #4aa8c0; }

.call-node {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  position: relative;
}

.call-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  padding: 4px 12px 4px 8px;
  border-left: 2px solid transparent;
  cursor: pointer;
  font-size: 13.5px;
  line-height: 1.55;
  transition: background 0.1s;
  border-radius: 0 4px 4px 0;
}
.call-row:hover {
  background: rgba(255, 255, 255, 0.04);
  border-left-color: color-mix(in srgb, var(--indent-color) 50%, transparent) !important;
}
.call-row--selected {
  background: rgba(80, 140, 220, 0.12) !important;
  border-left-color: rgba(80, 150, 230, 0.8) !important;
}
.call-row--code-active {
  background: rgba(180, 140, 40, 0.10) !important;
  border-left-color: rgba(210, 170, 50, 0.85) !important;
}
.call-row--code-active:hover {
  background: rgba(180, 140, 40, 0.15) !important;
}
.call-row--fav-bubble { border-left-color: rgba(80, 60, 100, 0.25) !important; }

.chevron-sm {
  color: color-mix(in srgb, var(--indent-color) 70%, #6878a8);
  font-size: 10px;
  width: 12px;
  flex-shrink: 0;
  transition: color 0.1s;
}
.chevron-sm--leaf { color: #353a50; font-size: 13px; }
.call-row--leaf { cursor: default; }
.call-row--leaf:hover { background: transparent; border-left-color: transparent !important; }
.call-row--noisy { cursor: default; }
.call-row--noisy:hover { background: transparent; border-left-color: transparent !important; }
.call-sig--dim { opacity: 0.35; }
.call-file--dim { opacity: 0.35; }

.call-sig { color: #9898b0; }
.sig-op   { opacity: 0.5; }
.sig-sep  { opacity: 0.3; }
.cc-sp    { font-size: 0.35em; }
.sig-app      { color: #60c0e0; }
.sig-ctrl     { color: #d09860; }
.sig-listener { color: #c8b860; }
.sig-vendor   { color: #7878a0; }

.call-arg {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 12px;
  color: #7090b0;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(70, 110, 150, 0.2);
  border-radius: 3px;
  padding: 0px 6px;
  flex-shrink: 0;
}
.call-arg--obj { cursor: pointer; border-color: rgba(80, 120, 160, 0.28); }
.call-arg--obj:hover { border-color: rgba(80, 160, 200, 0.5); color: #80a8c0; background: rgba(80,140,180,0.06); }
.call-arg--expanded { border-color: rgba(60, 150, 180, 0.55); color: #80a8c0; background: rgba(60,140,170,0.08); }

.arg-name { color: #5a7890; font-size: 10px; }
.arg-sep  { color: #3a4a60; font-size: 10px; }
.arg-val  { color: #80a0c0; }

.arg-fields {
  width: 100%;
  flex-basis: 100%;
  margin: 4px 0 4px 20px;
  font-size: 13px;
  background: rgba(255, 255, 255, 0.015);
  border-left: 2px solid rgba(60, 100, 140, 0.2);
  border-radius: 0 4px 4px 0;
  padding: 4px 0;
}
.arg-obj-header, .arg-obj-close {
  color: #4a6878;
  padding: 1px 12px;
  font-size: 12px;
}
.arg-obj-field {
  display: flex;
  gap: 6px;
  padding: 2px 12px;
}
.arg-field-name { color: #6898b8; }
.arg-field-eq   { color: #3a4860; }
.arg-field-val  { color: #7898b0; }

/* ── Favourites ── */
.call-row--fav { border-left-width: 2px; }

.fav-badge {
  font-size: 10px;
  border: 1px solid;
  border-radius: 3px;
  padding: 0px 5px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.fav-badge--bubble { opacity: 0.4; }

.call-return {
  font-size: 12px;
  color: #c07840;
  background: rgba(140, 70, 20, 0.10);
  border: 1px solid rgba(140, 80, 30, 0.25);
  border-radius: 3px;
  padding: 0px 6px;
  flex-shrink: 0;
}
.call-return--parent {
  color: #e08840;
  background: rgba(180, 90, 20, 0.14);
  border-color: rgba(180, 90, 30, 0.4);
}

.call-file {
  font-size: 10.5px;
  color: #485878;
  margin-left: auto;
  flex-shrink: 0;
  padding: 0 4px;
}

.call-line {
  font-size: 10px;
  color: #5060a0;
  flex-shrink: 0;
  padding: 1px 5px;
  border-radius: 3px;
  cursor: pointer;
  transition: color 0.1s, background 0.1s;
}
.call-line:hover { color: #80b0d8; background: rgba(255,255,255,0.05); }

/* The indent line — uses the level color */
.call-children {
  position: relative;
  padding-left: 14px;
  padding-top: 1px;
}
.call-children::before {
  content: '';
  position: absolute;
  left: 5px;
  top: 0;
  bottom: 4px;
  width: 1px;
  background: color-mix(in srgb, var(--indent-color) 35%, transparent);
  border-radius: 1px;
  transition: background 0.2s;
}
.call-node:hover > .call-children::before {
  background: color-mix(in srgb, var(--indent-color) 55%, transparent);
}

.child-source-header {
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
.child-source-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(40, 60, 90, 0.6);
}
.child-source-header[data-src="sf"] { color: #3a6880; }
.child-source-header:not([data-src="sf"]) { color: #605840; }

.loading, .leaf { color: #4a5880; font-size: 12px; padding: 3px 8px; font-style: italic; }

</style>

<style>
/* ── CallNode light theme overrides ── */
html[data-theme="light"] .call-node[data-indent="0"] { --indent-color: #1a6aaa; }
html[data-theme="light"] .call-node[data-indent="1"] { --indent-color: #1a8060; }
html[data-theme="light"] .call-node[data-indent="2"] { --indent-color: #6050c0; }
html[data-theme="light"] .call-node[data-indent="3"] { --indent-color: #9a6010; }
html[data-theme="light"] .call-node[data-indent="4"] { --indent-color: #b03050; }
html[data-theme="light"] .call-node[data-indent="5"] { --indent-color: #1a8898; }
html[data-theme="light"] .call-node[data-indent="6"] { --indent-color: #1a6aaa; }
html[data-theme="light"] .call-node[data-indent="7"] { --indent-color: #1a8060; }
html[data-theme="light"] .call-node[data-indent="8"] { --indent-color: #6050c0; }
html[data-theme="light"] .call-node[data-indent="9"] { --indent-color: #9a6010; }
html[data-theme="light"] .call-node[data-indent="10"] { --indent-color: #b03050; }
html[data-theme="light"] .call-node[data-indent="11"] { --indent-color: #1a8898; }

html[data-theme="light"] .call-node .call-row:hover { background: rgba(60, 100, 200, 0.05); }
html[data-theme="light"] .call-node .call-row--selected { background: rgba(40, 100, 220, 0.10) !important; border-left-color: rgba(40, 110, 220, 0.8) !important; }
html[data-theme="light"] .call-node .call-row--code-active { background: rgba(160, 120, 10, 0.09) !important; border-left-color: rgba(180, 140, 10, 0.75) !important; }

html[data-theme="light"] .call-node .chevron-sm { color: #3a5888; }
html[data-theme="light"] .call-node .chevron-sm--leaf { color: #8090b0; }

html[data-theme="light"] .call-node .call-sig { color: #2a3858 !important; }
html[data-theme="light"] .call-node .sig-app      { color: #0a6888 !important; }
html[data-theme="light"] .call-node .sig-ctrl     { color: #7a4800 !important; }
html[data-theme="light"] .call-node .sig-listener { color: #6a5800 !important; }
html[data-theme="light"] .call-node .sig-vendor   { color: #384068 !important; }

html[data-theme="light"] .call-node .call-arg {
  color: #1a3870 !important;
  background: rgba(60, 100, 180, 0.06) !important;
  border-color: rgba(60, 100, 180, 0.22) !important;
}
html[data-theme="light"] .call-node .call-arg--obj:hover { border-color: rgba(40, 120, 180, 0.5) !important; }
html[data-theme="light"] .call-node .arg-name { color: #2a5070 !important; }
html[data-theme="light"] .call-node .arg-sep  { color: #4a6070 !important; }
html[data-theme="light"] .call-node .arg-val  { color: #0d2c58 !important; }

html[data-theme="light"] .call-node .arg-fields { background: rgba(60, 100, 180, 0.04) !important; border-left-color: rgba(60, 100, 180, 0.2) !important; }
html[data-theme="light"] .call-node .arg-obj-header,
html[data-theme="light"] .call-node .arg-obj-close  { color: #2a4870 !important; }
html[data-theme="light"] .call-node .arg-field-name { color: #1a5070 !important; }
html[data-theme="light"] .call-node .arg-field-eq   { color: #4a5870 !important; }
html[data-theme="light"] .call-node .arg-field-val  { color: #0d2848 !important; }

html[data-theme="light"] .call-node .call-return {
  color: #7a3000 !important;
  background: rgba(180, 80, 10, 0.08) !important;
  border-color: rgba(160, 70, 10, 0.3) !important;
}
html[data-theme="light"] .call-node .call-return--parent {
  color: #9a4800 !important;
  background: rgba(200, 100, 10, 0.10) !important;
  border-color: rgba(200, 90, 10, 0.4) !important;
}

html[data-theme="light"] .call-node .call-file { color: #3a5070 !important; }
html[data-theme="light"] .call-node .call-line:hover { color: #0d2050 !important; background: rgba(60, 100, 180, 0.08) !important; }

html[data-theme="light"] .call-node .child-source-header { color: #2a4060 !important; }
html[data-theme="light"] .call-node .child-source-header::after { background: rgba(60, 90, 150, 0.3) !important; }
html[data-theme="light"] .call-node .child-source-header[data-src="sf"] { color: #1a5070 !important; }
html[data-theme="light"] .call-node .child-source-header:not([data-src="sf"]) { color: #504010 !important; }

html[data-theme="light"] .call-node .loading,
html[data-theme="light"] .call-node .leaf { color: #3a5070 !important; }
</style>
