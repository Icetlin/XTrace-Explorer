<template>
  <div class="call-node">
    <div
      class="call-row"
      :class="{
        'call-row--fav': directMatches.length,
        'call-row--fav-bubble': !directMatches.length && bubbleMatches.length,
        'call-row--selected': store.isSelected(node.line_no),
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
      <span class="chevron-sm">{{ expanded ? '▾' : '▸' }}</span>
      <span class="call-sig" :class="sigClass" :title="node.sig" v-html="renderSig(node.sig)"></span>
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
      <span v-if="node.file" class="call-file">{{ node.file }}</span>
      <!-- Fav match badges -->
      <span
        v-for="m in directMatches"
        :key="m.pattern"
        class="fav-badge"
        :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
      >{{ m.label || m.pattern }}</span>
      <!-- Bubble hint: descendant has a match -->
      <span
        v-for="m in bubbleMatches.filter(m => !directMatches.some(d => d.pattern === m.pattern))"
        :key="'b-' + m.pattern"
        class="fav-badge fav-badge--bubble"
        :style="{ color: favColor(m.pattern).text, background: favColor(m.pattern).bg, borderColor: favColor(m.pattern).border }"
      >{{ m.label || m.pattern }}</span>
      <span class="call-line" @click.stop="$emit('jump', node.line_no)">
        {{ node.line_no.toLocaleString() }}
      </span>
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
  // Ancestor crumbs passed down so each node can prepend its own sig
  ancestorCrumbs: { type: Array, default: () => [] },
})
const emit = defineEmits(['jump', 'fav-match', 'ctx-menu', 'breadcrumb'])

const store = useTraceStore()
const expanded = ref(false)
const children = ref([])
const parentReturn = ref(null)
const loading = ref(false)
const raw = ref(false)

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
  const sig = props.node.sig
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
      args: props.node.args,
      breadcrumb: props.ancestorCrumbs,
    })
    return
  }
  toggle()
}

async function toggle() {
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
  return /\{…\}$/.test(arg)
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
  const store_ = store
  for (const { namespace, label } of store_.appNamespaces) {
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
.call-node {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
}

.call-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  padding: 5px 18px;
  border-left: 2px solid transparent;
  cursor: pointer;
  font-size: 14px;
  line-height: 1.6;
  transition: background 0.1s;
}
.call-row:hover { background: rgba(255, 255, 255, 0.035); }

.chevron-sm { color: #343448; font-size: 10px; width: 10px; flex-shrink: 0; }

.call-sig { color: #8a8a9a; }
.sig-op   { opacity: 0.4; }
.sig-sep  { opacity: 0.25; }
.cc-sp    { font-size: 0.35em; }
.sig-app      { color: #5ab0cc; }
.sig-ctrl     { color: #b08050; }
.sig-listener { color: #a09048; }
.sig-vendor   { color: #686878; }

.call-arg {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: #5a7088;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(80, 110, 140, 0.18);
  border-radius: 4px;
  padding: 1px 7px;
  flex-shrink: 0;
}

.call-arg--obj { cursor: pointer; border-color: rgba(80, 120, 150, 0.25); }
.call-arg--obj:hover { border-color: rgba(80, 150, 180, 0.4); color: #6a8899; }
.call-arg--expanded { border-color: rgba(60, 130, 160, 0.45); color: #6a8899; }

.arg-name { color: #506878; font-size: 10.5px; }
.arg-sep  { color: #384450; font-size: 10px; }
.arg-val  { color: #7090a8; }

.arg-op {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.04em;
  border-radius: 3px;
  padding: 0 4px;
  flex-shrink: 0;
  background: rgba(255,255,255,0.04);
}
.arg-op--write  { color: #b09050; }
.arg-op--delete { color: #b06848; }
.arg-op--read   { color: #4a90a0; }
.arg-op--check  { color: #5a9060; }
.arg-op--create { color: #8068a8; }

.arg-fields {
  width: 100%;
  flex-basis: 100%;
  margin: 4px 0 4px 24px;
  font-size: 13.5px;
  background: rgba(255, 255, 255, 0.02);
  border-left: 1px solid rgba(80, 110, 150, 0.15);
  border-radius: 0 4px 4px 0;
  padding: 4px 0;
}
.arg-obj-header, .arg-obj-close {
  color: #3a5a6a;
  padding: 1px 12px;
  font-size: 12.5px;
}
.arg-obj-field {
  display: flex;
  gap: 6px;
  padding: 2px 12px;
}
.arg-field-name { color: #5a88a0; }
.arg-field-eq   { color: #2a3840; }
.arg-field-val  { color: #6a8898; }

/* ── Favourites ── */
.call-row--fav { border-left-width: 2px; }
.call-row--fav-bubble { border-left: 2px solid rgba(80, 60, 100, 0.3); }
.call-row--selected { background: rgba(80, 120, 180, 0.07) !important; border-left: 2px solid rgba(80, 130, 200, 0.45) !important; }

.fav-badge {
  font-size: 10px;
  border: 1px solid;
  border-radius: 4px;
  padding: 1px 6px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.fav-badge--bubble { opacity: 0.45; }

.fav-badge-op {
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.05em;
  opacity: 0.7;
}

.fav-bubble {
  font-size: 10px;
  color: #383048;
  flex-shrink: 0;
  margin-left: 4px;
  font-style: italic;
}

.call-arg--fav { }
.call-return--fav { }

.call-return {
  font-size: 13px;
  color: #7a5040;
  background: rgba(120, 60, 20, 0.08);
  border: 1px solid rgba(100, 50, 20, 0.2);
  border-radius: 4px;
  padding: 1px 7px;
  flex-shrink: 0;
}
.call-return--parent {
  color: #c87040;
  background: rgba(160, 80, 20, 0.12);
  border-color: rgba(140, 70, 20, 0.35);
}

.call-file {
  font-size: 10px;
  color: #2e2e40;
  margin-left: 2px;
  flex-shrink: 0;
}

.call-line {
  font-size: 10px;
  color: #4a4a62;
  margin-left: auto;
  flex-shrink: 0;
  padding: 2px 6px;
  border-radius: 3px;
  cursor: pointer;
  transition: color 0.1s, background 0.1s;
}
.call-line:hover { color: #5a8aaa; background: rgba(255,255,255,0.04); }

.call-children {
  border-left: 1px solid rgba(255,255,255,0.04);
  margin-left: 20px;
  padding-top: 1px;
}

.child-source-header {
  font-size: 9.5px;
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  padding: 4px 14px 3px;
  color: #2e3a42;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.child-source-header::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(40, 55, 70, 0.5);
}
.child-source-header[data-src="sf"] { color: #2a4a58; }
.child-source-header:not([data-src="sf"]) { color: #484830; }

.loading, .leaf { color: #303048; font-size: 12.5px; padding: 4px 10px; font-style: italic; }

.raw-toggle {
  display: block;
  margin: 3px 8px 4px;
  background: none;
  border: 1px dashed rgba(255,255,255,0.06);
  color: #333348;
  font-size: 10px;
  font-family: monospace;
  cursor: pointer;
  border-radius: 3px;
  padding: 2px 10px;
  transition: color 0.1s, border-color 0.1s;
}
.raw-toggle:hover { color: #5a8aaa; border-color: rgba(90,138,170,0.3); }
.raw-toggle--active { color: #363636; border-color: rgba(255,255,255,0.04); }
.raw-toggle--active:hover { color: #8a5040; border-color: rgba(140,80,60,0.3); }
</style>
