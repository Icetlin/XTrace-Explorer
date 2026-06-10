<template>
  <div class="app-call-tree">
    <div
      v-for="(node, i) in calls"
      :key="i"
      class="app-call-node"
    >
      <div
        class="app-call-row"
        :class="{
          'app-call-row--selected': store.isSelected(node.line_no),
          'app-call-row--expandable': node.children?.length,
          'app-call-row--has-source': !!node.file_abs,
          'app-call-row--code-active': store.isCodeActive(node.line_no),
          'app-call-row--code-hover': node.file_abs && store.hoveredCodeLine === node.file_abs
            && node.file_abs.replace(/:\d+$/, '') === store.activeCodeFile,
          'app-call-row--inherited': store.activeCodeNode && isInherited(node, store.activeCodeNode),
        }"
        @click="onNodeClick(node)"
        @mouseenter="node.file_abs && store.setHoveredCodeLine(node.file_abs)"
        @mouseleave="store.setHoveredCodeLine(null)"
      >
        <span class="app-call-chevron">
          <template v-if="node.children?.length">{{ expanded.has(node.line_no) ? '▾' : '▸' }}</template>
          <template v-else>·</template>
        </span>
        <span class="app-call-class">
          <span
            v-for="(part, pi) in camelParts(callClass(node.sig))"
            :key="pi"
            :style="classPartStyle(pi)"
          >{{ part }}</span>
        </span>
        <span class="app-call-method">
          <span
            v-for="(part, pi) in camelParts(callMethod(node.sig))"
            :key="pi"
            :style="methodPartStyle(pi)"
          >{{ part }}</span>
        </span>
        <span v-if="node.args?.length" class="app-call-args">
          <span v-for="(a, ai) in node.args.slice(0, 2)" :key="ai" class="app-call-arg">{{ simplifyArg(a) }}</span>
          <span v-if="node.args.length > 2" class="app-call-arg app-call-arg--more">+{{ node.args.length - 2 }}</span>
        </span>
        <span v-if="node.return != null" class="app-call-return">⇒ {{ node.return }}</span>
        <span v-if="node.duration_ms != null" class="app-call-duration" :class="durationClass(node.duration_ms)">{{ formatDuration(node.duration_ms) }}</span>
        <span v-if="node.mem_delta_kb != null && node.mem_delta_kb > 0" class="app-call-mem">+{{ formatMem(node.mem_delta_kb) }}</span>
        <span v-if="node.file && isAppFile(node.file_abs)" class="app-call-file">{{ shortFile(node.file) }}</span>
      </div>
      <AppCallTree
        v-if="node.children?.length && expanded.has(node.line_no)"
        :calls="node.children"
        :expanded="expanded"
        :parent-crumbs="[...parentCrumbs, { sig: node.sig, line_no: node.line_no }]"
        @toggle="$emit('toggle', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useTraceStore } from '../stores/trace'
import { usePerfTrack } from '../perfTrack'

const props = defineProps({
  calls:        { type: Array,  default: () => [] },
  expanded:     { type: Object, required: true },
  parentCrumbs: { type: Array,  default: () => [] },
})

const emit = defineEmits(['toggle'])
const store = useTraceStore()
usePerfTrack('AppCallTree', { category: 'render' })

function onNodeClick(node) {
  if (node.children?.length) {
    emit('toggle', node.line_no)
  }
  if (node.file_abs) {
    store.setCodeNode(node, props.parentCrumbs)
  }
}

function toggle(lineNo) {
  emit('toggle', lineNo)
}

function callClass(sig) {
  const arrow  = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  if (sep === -1) return sig.split('\\').pop()
  return sig.slice(0, sep).split('\\').pop()
}

function callMethod(sig) {
  const arrow  = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  if (sep === -1) return ''
  return sig.slice(sep)
}

function simplifyArg(arg) {
  const val = arg.replace(/^\$\w+\s*=\s*/, '')
  if (val.length > 28) return val.slice(0, 28) + '…'
  return val
}

const CLASS_COLORS_DARK  = ['#e8eef4', '#c0cfe0', '#98afc8', '#7898b8', '#5880a0']
const METHOD_COLORS_DARK  = ['#8aaac8', '#6888a8', '#507090', '#3a5878', '#2a4060']
const CLASS_COLORS_LIGHT = ['#0d1e3a', '#1a3460', '#2a4e80', '#3a6090', '#4a70a0']
const METHOD_COLORS_LIGHT = ['#0d3060', '#1a4878', '#2a5888', '#185098', '#103080']

const classColors  = computed(() => store.theme === 'light' ? CLASS_COLORS_LIGHT  : CLASS_COLORS_DARK)
const methodColors = computed(() => store.theme === 'light' ? METHOD_COLORS_LIGHT : METHOD_COLORS_DARK)

function classPartStyle(i) {
  return { color: classColors.value[Math.min(i, classColors.value.length - 1)] }
}
function methodPartStyle(i) {
  return { color: methodColors.value[Math.min(i, methodColors.value.length - 1)] }
}

// Split camelCase/PascalCase into parts for gradient coloring.
// Keeps the separator (->/::) attached to the first part of the method.
function camelParts(str) {
  if (!str) return []
  // Separate leading separator from method name
  const m = str.match(/^(-?>|::)(.*)$/)
  const sep = m ? m[1] : ''
  const word = m ? m[2] : str
  // Split on uppercase boundaries: 'getActiveByUser' → ['get','Active','By','User']
  const parts = word.split(/(?=[A-Z])/).filter(Boolean)
  if (!parts.length) return [str]
  parts[0] = sep + parts[0]
  return parts
}

function isAppFile(fileAbs) {
  return fileAbs?.includes('/src/')
}

function sigClass(sig) {
  if (!sig) return ''
  const arrow = sig.lastIndexOf('->')
  const dc = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dc)
  return sep >= 0 ? sig.slice(0, sep).split('\\').pop() : sig.split('\\').pop()
}

// "Inherited" = method from a parent Controller class called as $this->method().
// Recognised by: class ends with Controller AND differs from the active node's class.
// Repository/Service/Model calls are NOT inherited — they are dependency calls.
function isInherited(node, activeNode) {
  if (!activeNode?.sig) return false
  const nodeClass = sigClass(node.sig)
  if (!nodeClass.endsWith('Controller')) return false
  return nodeClass !== sigClass(activeNode.sig)
}

function formatDuration(ms) {
  if (ms >= 1000) return (ms / 1000).toFixed(2) + 's'
  if (ms >= 1)    return ms + 'ms'
  return '<1ms'
}

function durationClass(ms) {
  if (ms >= 500) return 'app-call-duration--critical'
  if (ms >= 100) return 'app-call-duration--slow'
  if (ms >= 20)  return 'app-call-duration--warn'
  return 'app-call-duration--ok'
}

function formatMem(kb) {
  if (kb >= 1024) return (kb / 1024).toFixed(1) + 'MB'
  return kb + 'KB'
}

function shortFile(file) {
  if (!file) return ''
  const m = file.match(/\/(src|vendor)\/.+$/)
  if (m) {
    const parts = m[0].split('/')
    return parts.slice(-2).join('/') + (file.includes(':') ? ':' + file.split(':').pop() : '')
  }
  return file.split('/').pop()
}
</script>

<style scoped>
.app-call-tree {
  margin-left: 16px;
  border-left: 1px dashed rgba(80, 110, 150, 0.2);
  padding-left: 8px;
  padding-top: 1px;
  padding-bottom: 1px;
}

.app-call-node {
  margin-bottom: 1px;
}

.app-call-row {
  display: flex;
  align-items: baseline;
  gap: 5px;
  padding: 2px 6px;
  border-radius: 2px;
  cursor: pointer;
  transition: background 0.1s;
  overflow: hidden;
}
.app-call-row:hover { background: rgba(100, 140, 200, 0.06); }
.app-call-row--selected { background: rgba(100, 140, 200, 0.1); }
.app-call-row--code-active {
  background: rgba(180, 140, 40, 0.12) !important;
  outline: 1px solid rgba(210, 170, 50, 0.4);
  border-radius: 2px;
}

.app-call-chevron {
  color: rgba(140, 160, 180, 0.4);
  font-size: 9px;
  width: 10px;
  flex-shrink: 0;
}

.app-call-class {
  font-size: 12.5px;
  font-weight: 500;
  white-space: nowrap;
  line-height: 1;
}

.app-call-method {
  font-size: 12.5px;
  white-space: nowrap;
  line-height: 1;
}

.app-call-row--has-source {
  cursor: pointer;
}
.app-call-row--has-source:hover .app-call-class {
  color: #e0eeff;
}

.app-call-row--code-hover {
  background: rgba(80, 130, 200, 0.12);
  outline: 1px solid rgba(80, 130, 200, 0.3);
  border-radius: 2px;
}

.app-call-row--inherited .app-call-class {
  color: #7a90a8;
  font-style: italic;
}
.app-call-row--inherited .app-call-method {
  color: #485870;
}
.app-call-row--inherited .app-call-args,
.app-call-row--inherited .app-call-return,
.app-call-row--inherited .app-call-file {
  opacity: 0.4;
}

.app-call-args {
  display: flex;
  gap: 4px;
  flex-wrap: nowrap;
  overflow: hidden;
}

.app-call-arg {
  font-size: 11px;
  color: #8aaac8;
  background: rgba(50, 80, 120, 0.18);
  border: 1px solid rgba(70, 100, 150, 0.3);
  border-radius: 3px;
  padding: 0 5px;
  white-space: nowrap;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  flex-shrink: 0;
}

.app-call-arg--more {
  color: #506070;
  background: none;
  border-color: rgba(60, 80, 100, 0.3);
}

.app-call-return {
  font-size: 11px;
  color: #c89060;
  background: rgba(120, 70, 20, 0.15);
  border: 1px solid rgba(150, 90, 30, 0.3);
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
  white-space: nowrap;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.app-call-file {
  font-size: 10px;
  color: #506878;
  margin-left: auto;
  flex-shrink: 0;
  white-space: nowrap;
}

.app-call-duration {
  font-size: 10.5px;
  font-variant-numeric: tabular-nums;
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
  white-space: nowrap;
  border: 1px solid transparent;
}
.app-call-duration--ok       { color: #5a8a60; background: rgba(50,100,60,0.12); border-color: rgba(60,120,70,0.2); }
.app-call-duration--warn     { color: #a09040; background: rgba(120,100,20,0.15); border-color: rgba(150,120,30,0.3); }
.app-call-duration--slow     { color: #c07030; background: rgba(140,70,20,0.18); border-color: rgba(170,90,30,0.35); }
.app-call-duration--critical { color: #d04030; background: rgba(160,40,20,0.2); border-color: rgba(200,50,30,0.4); }

.app-call-mem {
  font-size: 10px;
  color: #7888b0;
  background: rgba(60,70,120,0.12);
  border: 1px solid rgba(80,90,150,0.2);
  border-radius: 3px;
  padding: 0 4px;
  flex-shrink: 0;
  white-space: nowrap;
}
</style>
