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
        <span v-if="node.file && isAppFile(node.file_abs)" class="app-call-file">{{ shortFile(node.file) }}</span>
      </div>
      <AppCallTree
        v-if="node.children?.length && expanded.has(node.line_no)"
        :calls="node.children"
        :expanded="expanded"
        @toggle="$emit('toggle', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { useTraceStore } from '../stores/trace'

const props = defineProps({
  calls:    { type: Array,  default: () => [] },
  expanded: { type: Object, required: true },
})

const emit = defineEmits(['toggle'])
const store = useTraceStore()

function onNodeClick(node) {
  if (node.children?.length) {
    emit('toggle', node.line_no)
  }
  if (node.file_abs) {
    store.setCodeNode(node)
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

// Class: first word bright white, fades to steel blue
const CLASS_COLORS = ['#e8eef4', '#c0cfe0', '#98afc8', '#7898b8', '#5880a0']
// Method: starts at muted blue, fades darker
const METHOD_COLORS = ['#8aaac8', '#6888a8', '#507090', '#3a5878', '#2a4060']

function classPartStyle(i) {
  return { color: CLASS_COLORS[Math.min(i, CLASS_COLORS.length - 1)] }
}
function methodPartStyle(i) {
  return { color: METHOD_COLORS[Math.min(i, METHOD_COLORS.length - 1)] }
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
  align-items: center;
  gap: 5px;
  padding: 2px 6px;
  border-radius: 2px;
  cursor: pointer;
  transition: background 0.1s;
}
.app-call-row:hover { background: rgba(100, 140, 200, 0.06); }
.app-call-row--selected { background: rgba(100, 140, 200, 0.1); }

.app-call-chevron {
  color: rgba(140, 160, 180, 0.4);
  font-size: 9px;
  width: 10px;
  flex-shrink: 0;
}

.app-call-class {
  font-size: 12.5px;
  font-weight: 500;
}

.app-call-method {
  font-size: 12px;
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
</style>
