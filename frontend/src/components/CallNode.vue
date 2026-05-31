<template>
  <div class="call-node">
    <div
      class="call-row"
      :class="{ 'call-row--fav': directMatches.length, 'call-row--fav-bubble': !directMatches.length && bubbleMatches.length }"
      :style="{ paddingLeft: (indent * 16 + 4) + 'px' }"
      :data-line-no="node.line_no"
      @click="toggle"
      @contextmenu.prevent="onContextMenu"
    >
      <span class="chevron-sm">{{ expanded ? '▾' : '▸' }}</span>
      <span class="call-sig" :class="sigClass" :title="node.sig">{{ shortSig(node.sig) }}</span>
      <template v-if="node.args?.length">
        <span
          v-for="(a, i) in node.args"
          :key="i"
          class="call-arg"
          :class="{ 'call-arg--obj': isObjectArg(a), 'call-arg--expanded': expandedArgs[i], 'call-arg--fav': argMatches(a) }"
          @click.stop="isObjectArg(a) ? toggleArg(i) : null"
        >{{ a }}</span>
        <div v-if="Object.keys(expandedArgs).length" class="arg-fields">
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
      <span v-if="node.file" class="call-file">{{ node.file }}</span>
      <!-- Fav match badges -->
      <span v-for="m in directMatches" :key="m.pattern" class="fav-badge">{{ m.label || m.pattern }}</span>
      <!-- Bubble hint: descendant has a match -->
      <span v-if="!directMatches.length && bubbleMatches.length" class="fav-bubble">
        ↓ {{ bubbleMatches.map(m => m.label || m.pattern).join(', ') }}
      </span>
      <span class="call-line" @click.stop="$emit('jump', node.line_no)">
        {{ node.line_no.toLocaleString() }}
      </span>
    </div>

    <div v-if="expanded" class="call-children">
      <div v-if="loading" class="loading">loading…</div>
      <template v-else>
        <CallNode
          v-for="(child, i) in children"
          :key="i"
          :node="child"
          :file-id="fileId"
          :indent="indent + 1"
          :expand-path="expandPath"
          @jump="$emit('jump', $event)"
          @fav-match="onChildFavMatch"
          @ctx-menu="$emit('ctx-menu', $event)"
        />
        <div v-if="!children.length && raw" class="leaf">no calls</div>
        <button v-if="!raw" class="raw-toggle" @click.stop="loadRaw">
          show all calls
        </button>
        <button v-else class="raw-toggle raw-toggle--active" @click.stop="loadFiltered">
          hide noise
        </button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useTraceStore } from '../stores/trace'

const props = defineProps({
  node: Object,
  fileId: Number,
  indent: { type: Number, default: 0 },
  expandPath: { type: Array, default: null }, // line_nos to auto-expand toward
})
const emit = defineEmits(['jump', 'fav-match', 'ctx-menu'])

const store = useTraceStore()
const expanded = ref(false)
const children = ref([])
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

const directMatches = computed(() => store.matchFavourites(nodeText.value))

const returnMatches = computed(() =>
  props.node.return != null && store.matchFavourites(String(props.node.return)).length > 0
)

function argMatches(arg) {
  return store.matchFavourites(arg).length > 0
}

// When this node has direct matches, bubble up to parent
watch(directMatches, (matches) => {
  if (matches.length) emit('fav-match', matches)
}, { immediate: true })

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

async function toggle() {
  expanded.value = !expanded.value
  if (expanded.value && !children.value.length) {
    await load(false)
  }
}

async function load(isRaw) {
  loading.value = true
  raw.value = isRaw
  children.value = await store.fetchChildren(props.fileId, props.node.line_no, props.node.depth, isRaw)
  loading.value = false
}

async function loadRaw() {
  loading.value = true
  children.value = await store.fetchChildren(props.fileId, props.node.line_no, props.node.depth, true)
  raw.value = true
  loading.value = false
}

async function loadFiltered() {
  loading.value = true
  children.value = await store.fetchChildren(props.fileId, props.node.line_no, props.node.depth, false)
  raw.value = false
  loading.value = false
}

function isObjectArg(arg) {
  return /\{…\}$/.test(arg)
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
</script>

<style scoped>
.call-node { font-family: 'JetBrains Mono', 'Fira Code', monospace; }

.call-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  padding: 3px 4px;
  border-radius: 3px;
  cursor: pointer;
  font-size: 12px;
  line-height: 1.5;
}
.call-row:hover { background: #1a1a2a; }

.chevron-sm { color: #333; font-size: 10px; width: 10px; flex-shrink: 0; }

.call-sig { color: #999; }
.sig-app      { color: #58c8ff; }
.sig-ctrl     { color: #ff9e64; }
.sig-listener { color: #e8c46a; }
.sig-vendor   { color: #555; }

.call-arg {
  font-size: 11px;
  color: #9ecbff;
  background: #0d1117;
  border: 1px solid #1e2a3a;
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}

.call-arg--obj { cursor: pointer; border-color: #2a3a4a; }
.call-arg--obj:hover { border-color: #58c8ff; color: #b8e8ff; }
.call-arg--expanded { border-color: #3a5a7a; color: #b8e8ff; }

.arg-fields {
  width: 100%;
  flex-basis: 100%;
  margin: 2px 0 2px 24px;
  font-size: 11px;
  font-family: 'JetBrains Mono', monospace;
}
.arg-obj-header, .arg-obj-close { color: #4a6a8a; }
.arg-obj-field {
  display: flex;
  gap: 5px;
  padding: 1px 8px;
}
.arg-field-name { color: #7daacc; }
.arg-field-eq   { color: #555; }
.arg-field-val  { color: #9ecbff; }

/* ── Favourites highlighting ── */
.call-row--fav {
  background: #1e0e18 !important;
  border-left: 2px solid #ff6eb4;
  padding-left: calc(var(--pl, 4px) - 2px);
}
.call-row--fav:hover { background: #281020 !important; }
.call-row--fav-bubble { border-left: 2px solid #3a1e2e; }

.fav-badge {
  font-size: 10px;
  color: #ff6eb4;
  background: #2a0e1e;
  border: 1px solid #5a2a3a;
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}

.fav-bubble {
  font-size: 10px;
  color: #5a2a3a;
  flex-shrink: 0;
  margin-left: 4px;
  font-style: italic;
}

.call-arg--fav {
  border-color: #ff6eb4 !important;
  color: #ffb8d8 !important;
}

.call-return--fav {
  border-color: #ff6eb4 !important;
  color: #ffb8d8 !important;
}

.call-return {
  font-size: 11px;
  color: #f78c6c;
  background: #1a120a;
  border: 1px solid #3a2010;
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}

.call-file {
  font-size: 10px;
  color: #2a2a3a;
  margin-left: 2px;
  flex-shrink: 0;
}

.call-line {
  font-size: 10px;
  color: #2a2a3a;
  margin-left: auto;
  flex-shrink: 0;
  padding: 1px 4px;
  border-radius: 3px;
  cursor: pointer;
}
.call-line:hover { color: #7aadff; background: #111; }

.call-children {
  border-left: 1px dashed #1a1a2a;
  margin-left: 14px;
}

.loading, .leaf { color: #333; font-size: 11px; padding: 2px 8px; font-style: italic; }

.raw-toggle {
  display: block;
  margin: 2px 8px 4px;
  background: none;
  border: 1px dashed #2a2a3a;
  color: #3a3a5a;
  font-size: 10px;
  font-family: monospace;
  cursor: pointer;
  border-radius: 3px;
  padding: 1px 8px;
}
.raw-toggle:hover { color: #7aadff; border-color: #5a7adf; }
.raw-toggle--active { color: #444; border-color: #2a2a2a; }
.raw-toggle--active:hover { color: #f78c6c; border-color: #5a2010; }
</style>
