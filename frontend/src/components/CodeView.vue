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
        <div class="code-line__main">
          <span class="code-line__no">{{ no }}</span>
          <span class="code-line__code" v-html="html" />
        </div>
        <div v-if="annotations.has(no)" class="code-line__ann">
          <span class="code-line__ann-no" />
          <span v-for="(ann, i) in annotations.get(no)" :key="i" class="code-line__ann-item">
            <span class="ann-arrow">⇒</span>
            <span class="ann-val" :class="ann.type">{{ ann.value }}</span>
          </span>
        </div>
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
  loadNodeSource(node)
}, { immediate: true })

// Load source for a node. First fetches children (to know the body file),
// then fetches source, then builds annotations.
async function loadNodeSource(node) {
  loading.value = true
  error.value = null
  source.value = null
  annotations.value = new Map()

  try {
    // Step 1: fetch direct children — their file_abs tells us which file is the method body
    let allCalls = node.children || []
    const fileId = store.currentFile?.file_id
    if (fileId && node.line_no != null) {
      try {
        const result = await store.fetchChildren(fileId, node.line_no, node.depth ?? 1)
        allCalls = result.children || []
      } catch (e) { console.warn('[CodeView] fetchChildren failed', e) }
    }

    // Step 2: determine which file to show
    // node.file_abs = call site in parent; children's file_abs = call sites inside the body.
    // The body file is the base of children's file_abs.
    const bodyFileAbs = allCalls.find(c => c.file_abs)?.file_abs
    const fileAbs = bodyFileAbs || node.file_abs
    const absPath = fileAbs.replace(/:\d+$/, '')
    // Hint: prefer the node's own line in the body file (first child's line) for scrolling
    const hint = extractLineNo(fileAbs)

    if (absPath === currentFile.value && hint === currentHint.value && source.value) {
      scrollToLine(hint)
      loading.value = false
      return
    }
    currentFile.value = absPath
    currentHint.value = hint
    store.setActiveCodeFile(absPath)

    // Step 3: fetch source
    const data = await store.fetchSource(absPath, hint)
    if (!data) { error.value = 'File not found'; return }
    source.value = data

    // Step 4: fetch var context (object fields + child return types from trace)
    let varCtx = null
    if (fileId && node.line_no != null) {
      varCtx = await store.fetchVarContext(fileId, node.line_no, node.depth ?? 1)
    }

    annotations.value = buildAnnotations(node, absPath, allCalls, data, varCtx)
    await nextTick()
    scrollToLine(hint)
  } catch (e) {
    error.value = 'Failed to load: ' + (e?.message || 'unknown error')
  } finally {
    loading.value = false
  }
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

// Build annotations for shownFile from allCalls + varCtx.
// varCtx = { vars: { "$name": {class, fields} | {scalar} }, child_types: { lineNo: "ClassName" } }
function buildAnnotations(node, shownFile, allCalls, sourceData, varCtx) {
  const map = new Map()
  const lines = sourceData?.lines || {}

  // ── varMap: variable name → display string ────────────────────────────────
  // Priority: varCtx.vars (full object fields from raw trace) > node.args (simplified)
  const varMap = new Map()       // $name → display string like "User {…}"
  const varFields = new Map()    // $name → Map(fieldName → value) for field-level lookup

  // Seed from node.args (simplified, e.g. "User {…}")
  for (const arg of (node.args || [])) {
    const m = arg.match(/^\$(\w+)\s*=\s*(.+)$/)
    if (m) varMap.set(m[1], m[2].trim())
  }

  // Override with varCtx.vars which has full parsed fields
  const ctxVars = varCtx?.vars || {}
  for (const [varKey, info] of Object.entries(ctxVars)) {
    const name = varKey.replace(/^\$/, '')
    if (info.scalar) {
      varMap.set(name, info.scalar)
    } else if (info.class) {
      varMap.set(name, info.class + ' {…}')
      const fm = new Map()
      for (const f of (info.fields || [])) fm.set(f.name, f.value)
      varFields.set(name, fm)
    }
  }

  // child_types: child line_no (string) → inferred return class name
  const childTypes = varCtx?.child_types || {}

  // ── callsByLine: lineNo → array of calls made on that line ────────────────
  const callsByLine = new Map()
  for (const call of (allCalls || [])) {
    if (!call.file_abs) continue
    const callFile = call.file_abs.replace(/:\d+$/, '')
    if (callFile !== shownFile) continue
    const line = extractLineNo(call.file_abs)
    if (!line) continue
    if (!callsByLine.has(line)) callsByLine.set(line, [])
    callsByLine.get(line).push(call)
  }

  // Infer $var assignments from children with known return values
  const sortedLines = [...callsByLine.keys()].sort((a, b) => a - b)
  for (const line of sortedLines) {
    const srcLine = lines[line] || ''
    const assignMatch = srcLine.match(/^\s*\$(\w+)\s*=\s*[^=]/)
    if (!assignMatch) continue
    const varName = assignMatch[1]
    const callsHere = callsByLine.get(line)
    // 1. Explicit return from trace
    const withReturn = callsHere.filter(c => c.return != null)
    if (withReturn.length) {
      varMap.set(varName, String(withReturn[withReturn.length - 1].return))
      continue
    }
    // 2. Inferred type from child_types (grandchild receiver heuristic)
    for (const call of callsHere) {
      const ct = childTypes[String(call.line_no)]
      if (ct) { varMap.set(varName, ct + ' {…}'); break }
    }
  }

  // ── Per-line annotation entries ───────────────────────────────────────────
  for (const [lineNoStr, lineCode] of Object.entries(lines)) {
    const lineNo = parseInt(lineNoStr)
    const trimmed = lineCode.trim()
    const entries = []

    const callsHere = callsByLine.get(lineNo) || []

    // 1. Calls with explicit return value → show the return
    for (const call of callsHere) {
      if (call.return != null) {
        const retStr = String(call.return)
        entries.push({ type: 'ret', value: retStr.length > 48 ? retStr.slice(0, 48) + '…' : retStr })
      }
    }

    // 2. Calls without return → try to show field value from varCtx, else method name
    for (const call of callsHere) {
      if (call.return != null) continue
      const sig = call.sig || ''
      const arrowPos = sig.lastIndexOf('->')
      if (arrowPos >= 0) {
        const methodName = sig.slice(arrowPos + 2)
        // Try to find which $var this is called on from source line: $var->method()
        for (const sm of trimmed.matchAll(/\$(\w+)->/g)) {
          const varName = sm[1]
          const fields = varFields.get(varName)
          if (!fields) continue
          const fieldName = methodName
            .replace(/^(?:is|get|has)([A-Z])/, (_, c) => c.toLowerCase())
            .replace(/^([A-Z])/, c => c.toLowerCase())
          const val = fields.get(fieldName) ?? fields.get(methodName)
          if (val != null) {
            entries.push({ type: 'field', value: `$${varName}.${fieldName} = ${val}` })
            break
          }
        }
      }
      if (!entries.length) {
        // Fallback: show method name
        const sep = Math.max(sig.lastIndexOf('->'), sig.lastIndexOf('::'))
        const label = sep >= 0 ? sig.slice(sep) : sig.split('\\').pop()
        entries.push({ type: 'sig', value: label })
      }
    }

    // 3. Lines without direct calls: instanceof, null check, field access
    if (!callsHere.length) {
      for (const m of trimmed.matchAll(/\$(\w+)\s+instanceof\s+([\w\\]+)/g)) {
        const [, varName, iface] = m
        if (!varMap.has(varName)) continue
        const val = varMap.get(varName)
        const typeName = val.replace(/\s*\{…\}$/, '').split('\\').pop()
        const ifaceName = iface.split('\\').pop().replace(/Interface$/i, '')
        const matches = typeName.toLowerCase().includes(ifaceName.toLowerCase())
        entries.push({ type: 'instanceof', value: `${matches ? '✓' : '✗'} $${varName}: ${typeName}` })
      }

      for (const m of trimmed.matchAll(/(?:null\s*===?\s*\$(\w+)|\$(\w+)\s*===?\s*null)/g)) {
        const varName = m[1] || m[2]
        if (!varMap.has(varName)) continue
        const val = varMap.get(varName)
        entries.push({ type: 'nullcheck', value: `$${varName} = ${val.length > 44 ? val.slice(0, 44) + '…' : val}` })
      }
    }

    if (entries.length) map.set(lineNo, entries)
  }

  return map
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
  flex-direction: column;
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

.code-line__main {
  display: flex;
  align-items: baseline;
  line-height: 1.7;
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
  flex-wrap: wrap;
  gap: 4px;
  padding: 1px 0 3px 50px;
}

.code-line__ann-no {
  display: none;
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
.ann-val.var {
  color: #a0c8a0;
  background: rgba(40, 100, 40, 0.12);
  border: 1px solid rgba(60, 140, 60, 0.25);
  font-style: italic;
}
.ann-val.instanceof {
  color: #b0d0a0;
  background: rgba(40, 90, 30, 0.15);
  border: 1px solid rgba(70, 130, 50, 0.3);
  font-style: italic;
}
.ann-val.nullcheck {
  color: #90a8c8;
  background: rgba(40, 60, 110, 0.15);
  border: 1px solid rgba(60, 90, 160, 0.3);
  font-style: italic;
}
.ann-val.field {
  color: #c8a860;
  background: rgba(110, 80, 20, 0.15);
  border: 1px solid rgba(150, 110, 30, 0.3);
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
