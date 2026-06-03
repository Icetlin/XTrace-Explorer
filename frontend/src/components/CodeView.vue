<template>
  <div class="code-view" v-if="source || loading || error">
    <!-- Header -->
    <div class="code-view__header">
      <span class="code-view__filename" :title="currentFile">{{ shortFilename }}</span>
      <span class="code-view__range" v-if="source">lines {{ source.fn_from }}–{{ source.fn_to }}</span>
      <button class="code-view__close" @click="store.setCodeNode(null)">✕</button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="code-view__loading">
      <span class="spinner-inline" /> loading…
    </div>

    <!-- Error -->
    <div v-else-if="error" class="code-view__error">{{ error }}</div>

    <!-- Source lines -->
    <div v-else-if="source" ref="scrollEl" class="code-view__body"
      @mouseleave="store.setHoveredCodeLine(null)"
    >
      <div
        v-for="[no, html] in highlightedLines"
        :key="no"
        class="code-line"
        :class="lineClass(no)"
        :data-line="no"
        @mouseenter="store.setHoveredCodeLine(currentFile + ':' + no)"
      >
        <span class="code-line__no">{{ no }}</span>
        <span class="code-line__code" v-html="html" />
        <span v-if="annotations.has(no)" class="code-line__ann">
          <span v-for="(ann, i) in annotations.get(no)" :key="i" class="code-line__ann-item">
            <span class="ann-arrow">⇒</span>
            <span class="ann-val" :class="ann.type">{{ ann.value }}</span>
          </span>
        </span>
      </div>
    </div>
  </div>

  <!-- Empty state when no node selected yet -->
  <div v-else class="code-view code-view--empty">
    <span>Click any node to view source</span>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useTraceStore } from '../stores/trace'
import hljs from 'highlight.js/lib/core'
import phpLang from 'highlight.js/lib/languages/php'

hljs.registerLanguage('php', phpLang)

const store = useTraceStore()

const loading = ref(false)
const error = ref(null)
const source = ref(null)
const currentFile = ref(null)
const currentHint = ref(null)
const scrollEl = ref(null)
const annotations = ref(new Map())

watch(() => store.activeCodeNode, (node) => {
  if (!node?.file_abs) {
    source.value = null
    error.value = null
    currentFile.value = null
    store.setActiveCodeFile(null)
    return
  }

  // If the node's own file_abs points outside /src/ but the sig is an App\ class,
  // prefer the first child's file_abs — it's the first line inside the method body.
  const fileAbs = resolveFileAbs(node)
  if (!fileAbs) return

  const absPath = fileAbs.replace(/:\d+$/, '')
  const hint = extractLineNo(fileAbs)
  if (absPath === currentFile.value && hint === currentHint.value && source.value) {
    scrollToLine(hint)
    return
  }
  currentFile.value = absPath
  currentHint.value = hint
  store.setActiveCodeFile(absPath)
  annotations.value = buildAnnotations(node, absPath)
  fetchSource(absPath, hint)
})

function resolveFileAbs(node) {
  const own = node.file_abs || ''
  // Own file is inside the app src — use it directly
  if (own.includes('/src/')) return own
  // App\ class but file_abs points to vendor/framework — use first child's file_abs
  if (node.sig?.startsWith('App\\') && node.children?.length) {
    const child = node.children.find(c => c.file_abs?.includes('/src/'))
    if (child) return child.file_abs
  }
  // Fallback: use own regardless
  return own
}

const highlightedLines = computed(() => {
  if (!source.value) return []
  const entries = Object.entries(source.value.lines).map(([no, code]) => [parseInt(no), code])
  if (!entries.length) return []
  const fullCode = entries.map(([, code]) => code).join('\n')
  let highlighted
  try {
    highlighted = hljs.highlight(fullCode, { language: 'php' }).value
  } catch {
    highlighted = fullCode.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  }
  const htmlLines = highlighted.split('\n')
  return entries.map(([no], i) => [no, htmlLines[i] ?? ''])
})

const shortFilename = computed(() => {
  if (!currentFile.value) return ''
  const m = currentFile.value.match(/\/(src|vendor)\/.+$/)
  if (m) return m[0].split('/').slice(-3).join('/')
  return currentFile.value.split('/').slice(-2).join('/')
})

function lineClass(no) {
  const classes = []
  if (no === currentHint.value) classes.push('code-line--target')
  if (annotations.value.has(no)) classes.push('code-line--ann')
  if (store.hoveredCodeLine === currentFile.value + ':' + no) classes.push('code-line--tree-hover')
  return classes
}

function buildAnnotations(node, shownFile) {
  const map = new Map()

  // Each child knows the line in the parent where it was called from (child.file_abs).
  // Annotate those lines so the code panel shows what was passed/returned at each call site.
  for (const child of (node.children || [])) {
    if (!child.file_abs) continue
    const childFile = child.file_abs.replace(/:\d+$/, '')
    if (childFile !== shownFile) continue
    const line = extractLineNo(child.file_abs)
    if (!line) continue

    const entries = []
    // method label (short)
    const sig = child.sig || ''
    const sep = Math.max(sig.lastIndexOf('->'), sig.lastIndexOf('::'))
    const label = sep >= 0 ? sig.slice(sep) : sig.split('\\').pop()
    entries.push({ type: 'sig', value: label })

    for (const arg of (child.args || [])) {
      const val = arg.replace(/^\$\w+\s*=\s*/, '')
      if (val && val !== '[…]' && !val.endsWith('{…}')) {
        entries.push({ type: 'arg', value: val.length > 36 ? val.slice(0, 36) + '…' : val })
      }
    }
    if (child.return != null) {
      entries.push({ type: 'ret', value: String(child.return).slice(0, 36) })
    }
    map.set(line, entries)
  }
  return map
}

async function fetchSource(absPath, hint) {
  loading.value = true
  error.value = null
  source.value = null
  try {
    const data = await store.fetchSource(absPath, hint)
    if (!data) { error.value = 'File not found'; return }
    source.value = data
    await nextTick()
    scrollToLine(hint)
  } catch (e) {
    error.value = 'Failed to load: ' + (e?.message || 'unknown error')
  } finally {
    loading.value = false
  }
}

function scrollToLine(lineNo) {
  if (!lineNo || !scrollEl.value) return
  nextTick(() => {
    const el = scrollEl.value?.querySelector(`[data-line="${lineNo}"]`)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  })
}

function extractLineNo(fileStr) {
  if (!fileStr) return null
  const m = fileStr.match(/:(\d+)$/)
  return m ? parseInt(m[1]) : null
}
</script>

<style>
.code-line__code .hljs-keyword    { color: #c792ea; }
.code-line__code .hljs-string     { color: #c3e88d; }
.code-line__code .hljs-comment    { color: #546e7a; font-style: italic; }
.code-line__code .hljs-number     { color: #f78c6c; }
.code-line__code .hljs-variable   { color: #82aaff; }
.code-line__code .hljs-built_in   { color: #80cbc4; }
.code-line__code .hljs-title      { color: #82aaff; }
.code-line__code .hljs-function   { color: #82aaff; }
.code-line__code .hljs-attr       { color: #ffcb6b; }
.code-line__code .hljs-literal    { color: #ff5370; }
.code-line__code .hljs-type       { color: #ffcb6b; }
.code-line__code .hljs-class      { color: #ffcb6b; }
.code-line__code .hljs-params     { color: #a6accd; }
.code-line__code .hljs-subst      { color: #a6accd; }
</style>

<style scoped>
.code-view {
  display: flex;
  flex-direction: column;
  background: #090b14;
  border-left: 1px solid rgba(40, 60, 100, 0.4);
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  min-width: 0;
  height: 100%;
  overflow: hidden;
}

.code-view--empty {
  align-items: center;
  justify-content: center;
  color: #1e2830;
  font-size: 12px;
  font-style: italic;
}

.code-view__header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px 6px 14px;
  background: rgba(10, 14, 26, 0.9);
  border-bottom: 1px solid rgba(30, 50, 90, 0.5);
  flex-shrink: 0;
  min-height: 36px;
}

.code-view__filename {
  font-size: 11px;
  color: #6090c0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.code-view__range {
  font-size: 10px;
  color: #3a5070;
  flex-shrink: 0;
}

.code-view__close {
  background: none;
  border: none;
  color: #4a6080;
  cursor: pointer;
  font-size: 11px;
  padding: 2px 4px;
  border-radius: 3px;
  flex-shrink: 0;
  transition: color 0.1s, background 0.1s;
}
.code-view__close:hover { color: #e06060; background: rgba(120, 30, 30, 0.15); }

.code-view__body {
  flex: 1;
  overflow-y: auto;
  overflow-x: auto;
  padding: 8px 0;
}

.code-view__loading,
.code-view__error {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 12px;
  color: #3a5070;
  padding: 20px;
  font-style: italic;
}
.code-view__error { color: #7a3030; }

.code-line {
  display: flex;
  align-items: baseline;
  line-height: 1.7;
  padding: 0 16px 0 0;
  border-left: 2px solid transparent;
  transition: background 0.1s, border-color 0.1s;
}
.code-line:hover { background: rgba(255, 255, 255, 0.02); }

.code-line--target {
  background: rgba(80, 140, 200, 0.08);
  border-left-color: rgba(80, 150, 220, 0.6);
}
.code-line--target .code-line__no { color: #3a70a0; }
.code-line--ann { background: rgba(60, 100, 50, 0.06); }
.code-line--tree-hover {
  background: rgba(80, 160, 80, 0.1);
  border-left-color: rgba(80, 160, 80, 0.5);
}

.code-line__no {
  width: 38px;
  flex-shrink: 0;
  color: #1e2830;
  text-align: right;
  padding-right: 12px;
  user-select: none;
  font-size: 11px;
  line-height: inherit;
}

.code-line__code {
  flex: 1;
  font-size: 12.5px;
  color: #8090a8;
  white-space: pre;
  min-width: 0;
}

.code-line__ann {
  display: flex;
  align-items: center;
  gap: 6px;
  padding-left: 12px;
  flex-shrink: 0;
}

.code-line__ann-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
}

.ann-arrow { color: #3a5060; font-size: 10px; }

.ann-val {
  border-radius: 3px;
  padding: 0 5px;
}
.ann-val.arg {
  color: #7898b8;
  background: rgba(50, 80, 120, 0.15);
  border: 1px solid rgba(60, 90, 140, 0.25);
}
.ann-val.ret {
  color: #c87040;
  background: rgba(120, 60, 20, 0.12);
  border: 1px solid rgba(140, 70, 25, 0.3);
}
.ann-val.sig {
  color: #5a7890;
  background: none;
  border: none;
  padding: 0;
  font-style: italic;
}

.spinner-inline {
  width: 10px;
  height: 10px;
  border: 1.5px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  display: inline-block;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
