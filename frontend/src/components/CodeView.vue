<template>
  <Transition name="code-panel">
    <!-- No backdrop — TOC stays fully visible behind the panel. The panel
         itself is frosted glass (semi-transparent + backdrop-filter blur)
         so the user sees both simultaneously. -->
    <div v-if="source || loading || error" class="code-panel" :style="{ width: width + 'px' }">
      <div
        class="code-panel__resizer"
        @mousedown.prevent="$emit('resize-start', $event)"
      />

      <div class="code-view">
        <!-- Header -->
        <div class="code-view__header">
          <span class="code-view__filename" :title="currentFile">{{ shortFilename }}</span>
          <span class="code-view__range" v-if="source">lines {{ source.fn_from }}–{{ source.fn_to }}</span>
          <button class="code-view__close" @click="close" title="Close (Esc)">✕</button>
        </div>

        <!-- Loading bar — visible so user knows the click was registered while
             fetchChildren (1.5s+ for deep listener) or fetchSource is in flight. -->
        <div v-if="loading" class="code-view__loadbar">loading source…</div>

        <!-- Error -->
        <div v-if="error" class="code-view__error">{{ error }}</div>

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
              <span class="code-line__no" @click.stop="openInStorm(no)" title="Open in PhpStorm">{{ no }}</span>
              <span class="code-line__code" v-html="html" />
            </div>
            <div v-if="annotations.has(no)" class="code-line__ann">
              <span class="code-line__ann-no" />
              <span
                v-for="(ann, i) in annotations.get(no)"
                :key="i"
                class="code-line__ann-item"
                :class="{ 'code-line__ann-item--clickable': ann.objCall || ann.arrCall || ann.inferredClass || ann.call }"
                @click.stop="(ann.objCall || ann.arrCall || ann.inferredClass) ? openObjPopup($event, ann) : (ann.call && store.setCodeNode(ann.call))"
              >
                <span class="ann-arrow">⇒</span>
                <span class="ann-val" :class="ann.type">{{ ann.value }}</span>
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Transition>

  <!-- Object/array inspector popup -->
  <teleport to="body">
    <div
      v-if="popup"
      ref="popupEl"
      class="obj-popup"
      :style="{ left: popup.x + 'px', top: popup.y + 'px' }"
      @click.stop
    >
      <div class="obj-popup__header">{{ popup.title }}</div>
      <div v-if="popup.rows.length === 0" class="obj-popup__empty">empty</div>
      <div v-else class="obj-popup__fields">
        <template v-for="(row, ri) in popup.rows" :key="ri">
          <div
            class="obj-popup__field"
            :class="{ 'obj-popup__field--expandable': row.expandable, 'obj-popup__field--open': popupExpanded.has(ri) }"
            @click="row.expandable && expandPopupRow(ri, row)"
          >
            <span class="obj-popup__chevron">{{ row.expandable ? (popupExpanded.has(ri) ? '▾' : '▸') : '' }}</span>
            <span class="obj-popup__fname">{{ row.label }}</span>
            <span class="obj-popup__fval">{{ row.value }}</span>
          </div>
          <div v-if="popupExpanded.has(ri)" class="obj-popup__nested">
            <div class="obj-popup__nested-header">{{ popupExpanded.get(ri).title }}</div>
            <div v-if="popupExpanded.get(ri).rows.length === 0" class="obj-popup__empty obj-popup__empty--nested">empty</div>
            <div v-for="(nr, nri) in popupExpanded.get(ri).rows" :key="nri" class="obj-popup__field obj-popup__field--nested">
              <span class="obj-popup__chevron" />
              <span class="obj-popup__fname">{{ nr.label }}</span>
              <span class="obj-popup__fval">{{ nr.value }}</span>
            </div>
          </div>
        </template>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useTraceStore } from '../stores/trace'
import { usePerfTrack } from '../perfTrack'
import hljs from 'highlight.js/lib/core'
import phpLang from 'highlight.js/lib/languages/php'

hljs.registerLanguage('php', phpLang)

const props = defineProps({
  width: { type: Number, default: 560 },
})
const emit = defineEmits(['resize-start', 'close'])

const store = useTraceStore()
// CodeView re-mounts every time a new code node is selected — perfect
// signal to record render time per node activation.
usePerfTrack('CodeView', { category: 'render' })

function close() {
  emit('close')
}

// Global Esc-to-close. The @keydown.esc on the overlay only fires when the
// overlay has focus, which is unreliable (clicking on TOC steals focus).
// Listening on document catches Esc regardless of where focus is.
function onKeydown(e) {
  if (e.key === 'Escape') close()
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

const loading = ref(false)
const error = ref(null)
const source = ref(null)
const currentFile = ref(null)
const currentHint = ref(null)
const scrollEl = ref(null)
const annotations = ref(new Map())
// Highlighted lines cache — populated lazily via requestIdleCallback
const _highlightedCache = ref(null)
let _highlightPending = false
// Track inflight load so stale completions don't overwrite newer ones
let _loadSeq = 0

// Object inspector popup
// popup: { x, y, title, type: 'object'|'array', rows: [{label, value, expandable, raw}] }
const popup = ref(null)
const popupEl = ref(null)
const popupExpanded = ref(new Map()) // rowIdx → { title, type, rows }

function makeObjRows(fields) {
  return fields.map(f => ({ label: f.name, value: f.value, expandable: f.expandable, raw: f.raw ?? null }))
}
function makeArrRows(items) {
  return items.map(it => ({ label: it.key, value: it.value, expandable: it.expandable, raw: it.raw ?? null }))
}

async function openObjPopup(event, ann) {
  if (!ann.objCall && !ann.arrCall && !ann.inferredClass) return
  const fileId = store.currentFile?.file_id
  if (!fileId) return
  const rect = event.currentTarget.getBoundingClientRect()
  try {
    popupExpanded.value = new Map()
    if (ann.arrCall) {
      const data = await store.fetchArray(fileId, ann.arrCall.line_no, ann.arrArgIdx ?? 0)
      if (!data) return
      popup.value = { x: rect.left, y: rect.bottom + 4, title: `[${data.count}]`, rows: makeArrRows(data.items) }
    } else if (ann.inferredClass) {
      const data = await store.fetchFindObject(fileId, ann.call.line_no, ann.inferredClass)
      if (!data?.fields) return
      popup.value = { x: rect.left, y: rect.bottom + 4, title: data.class, rows: makeObjRows(data.fields) }
    } else {
      const data = await store.fetchObject(fileId, ann.objCall.line_no, ann.objArgIdx ?? 0)
      if (!data?.fields) return
      popup.value = { x: rect.left, y: rect.bottom + 4, title: data.class, rows: makeObjRows(data.fields) }
    }
    await nextTick()
    clampPopup(rect)
  } catch (e) { /* not inspectable */ }
}

function clampPopup(anchorRect) {
  const el = popupEl.value
  if (!el) return
  const pw = el.offsetWidth || 300
  const ph = el.offsetHeight || 200
  const vw = window.innerWidth
  const vh = window.innerHeight
  const margin = 8
  let x = anchorRect ? anchorRect.left : popup.value.x
  let y = anchorRect ? anchorRect.bottom + 4 : popup.value.y
  // Prefer below anchor; if not enough room flip above
  if (y + ph + margin > vh) y = (anchorRect ? anchorRect.top - ph - 4 : y - ph - 8)
  // Clamp horizontal
  x = Math.max(margin, Math.min(x, vw - pw - margin))
  // Clamp vertical
  y = Math.max(margin, Math.min(y, vh - ph - margin))
  popup.value = { ...popup.value, x, y }
}

async function expandPopupRow(rowIdx, row) {
  if (!row.expandable || !row.raw) return
  if (popupExpanded.value.has(rowIdx)) {
    const m = new Map(popupExpanded.value)
    m.delete(rowIdx)
    popupExpanded.value = m
    return
  }
  const fileId = store.currentFile?.file_id
  if (!fileId) return
  try {
    const data = await store.expandItem(fileId, row.raw)
    if (!data) return
    let expanded
    if (data.type === 'object' && data.data?.fields) {
      expanded = { title: data.data.class, rows: makeObjRows(data.data.fields) }
    } else if (data.type === 'array' && data.data) {
      expanded = { title: `[${data.data.length}]`, rows: makeArrRows(data.data) }
    }
    if (expanded) {
      const m = new Map(popupExpanded.value)
      m.set(rowIdx, expanded)
      popupExpanded.value = m
    }
  } catch (e) { /* ignore */ }
}

function closePopup() { popup.value = null }

function onDocClick(e) {
  if (popupEl.value && !popupEl.value.contains(e.target)) closePopup()
}
onMounted(() => document.addEventListener('click', onDocClick, true))
onUnmounted(() => document.removeEventListener('click', onDocClick, true))

watch(() => store.activeCodeNode, (node) => {
  _highlightedCache.value = null
  _highlightPending = false
  if (!node) {
    source.value = null
    error.value = null
    loading.value = false
    currentFile.value = null
    store.setActiveCodeFile(null)
    return
  }
  loadNodeSource(node)
}, { immediate: true })

// Load source for a node.
// If node.file_abs is known — fetchSource is the only blocking call (~5ms, often cached).
// If file_abs is null (listener without cached children) — fetch children first to resolve file.
// fetchVarContext always runs in background for annotations.
async function loadNodeSource(node) {
  const seq = ++_loadSeq
  error.value = null
  annotations.value = new Map()
  _highlightedCache.value = null
  _highlightPending = false

  const fileId = store.currentFile?.file_id

  // Resolve file_abs — may require fetching children first
  // trimEnd() removes trailing \n that old toc.json files may contain (fgets artifact)
  let fileAbs = node.file_abs?.trimEnd() ?? null
  let prefetchedChildren = null

  if (!fileAbs && fileId && node.line_no != null) {
    // No file_abs yet — need children to know which file to show.
    // Show loading bar while we resolve.
    loading.value = true
    const result = await store.fetchChildren(fileId, node.line_no, node.depth ?? 0).catch(() => null)
    if (seq !== _loadSeq) return
    prefetchedChildren = result
    const firstChild = (result?.children || []).find(c => c.file_abs)
    fileAbs = firstChild?.file_abs ?? null
  }

  if (!fileAbs) {
    loading.value = false
    return // nothing to show
  }

  const absPath = fileAbs.replace(/:\d+$/, '')
  const hint = extractLineNo(fileAbs)
  // xdebug puts the call-site line into file_abs, not the function declaration.
  // Pass the target class+method so the server can use PHP Reflection to find
  // the actual definition (which may be in a different file from file_abs).
  const sigParts = store.extractSigParts(node.sig)
  const method = sigParts.method
  const klass = sigParts.class

  // Set currentFile + currentHint IMMEDIATELY (before any await) so the CodeView
  // header shows the file path right away. Without this, a 1.5s fetchChildren for
  // a listener with no cached children leaves the user staring at an empty
  // "Click any node to view source" placeholder — looks unresponsive.
  if (absPath !== currentFile.value) {
    currentFile.value = absPath
    source.value = null  // clear stale source from a different file
  }
  currentHint.value = hint
  store.setActiveCodeFile(absPath)

  // Same file already shown — just scroll + refresh annotations, no need to refetch source
  if (absPath === currentFile.value && source.value) {
    loading.value = false
    await nextTick()
    scrollToLine(hint)
    loadAnnotationsBackground(seq, node, absPath, fileId, source.value, prefetchedChildren)
    return
  }

  loading.value = true

  const data = await store.fetchSource(absPath, hint, method, klass).catch(() => null)
  if (seq !== _loadSeq) return
  if (!data) { error.value = 'File not found'; loading.value = false; return }
  if (data.error) { error.value = data.error; loading.value = false; return }

  source.value = data
  loading.value = false
  await nextTick()
  scrollToLine(hint)

  loadAnnotationsBackground(seq, node, absPath, fileId, data, prefetchedChildren)
}

async function loadAnnotationsBackground(seq, node, absPath, fileId, data, prefetchedChildren = null) {
  if (!fileId || node.line_no == null) {
    if (seq === _loadSeq) annotations.value = buildAnnotations(node, absPath, [], data, null)
    return
  }
  // Use already-fetched children if available; otherwise fetch in background
  const childrenPromise = prefetchedChildren
    ? Promise.resolve(prefetchedChildren)
    : store.fetchChildren(fileId, node.line_no, node.depth ?? 1).catch(() => null)

  const [childrenResult, varCtx] = await Promise.all([
    childrenPromise,
    store.fetchVarContext(fileId, node.line_no, node.depth ?? 1).catch(() => null),
  ])
  if (seq !== _loadSeq) return
  const allCalls = childrenResult?.children || []
  annotations.value = buildAnnotations(node, absPath, allCalls, data, varCtx)
}

const highlightedLines = computed(() => {
  // Return cached highlighted lines if available
  if (_highlightedCache.value) return _highlightedCache.value
  if (!source.value) return []
  const entries = Object.entries(source.value.lines).map(([no, code]) => [parseInt(no), code])
  if (!entries.length) return []
  // Show plain (escaped) lines immediately, schedule highlight in idle time
  const plainLines = entries.map(([no, code]) => [no, code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')])
  if (!_highlightPending) {
    _highlightPending = true
    const run = () => {
      if (!source.value) { _highlightPending = false; return }
      const fullCode = entries.map(([, code]) => code).join('\n')
      let highlighted
      try {
        highlighted = hljs.highlight(fullCode, { language: 'php' }).value
      } catch {
        highlighted = fullCode.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      }
      const htmlLines = highlighted.split('\n')
      _highlightedCache.value = entries.map(([no], i) => [no, htmlLines[i] ?? ''])
      _highlightPending = false
    }
    if (typeof requestIdleCallback !== 'undefined') {
      requestIdleCallback(run, { timeout: 500 })
    } else {
      setTimeout(run, 0)
    }
  }
  return plainLines
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

  // Sorted siblings for next-sibling lookup (to find obj arg for inferred returns)
  const sortedCalls = [...(allCalls || [])].sort((a, b) => a.line_no - b.line_no)

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

    // 1. Calls with explicit return value (or inferred via child_types) → show the return
    for (const call of callsHere) {
      const inferredType = childTypes[String(call.line_no)]
      const isInferred = call.return == null && inferredType != null
      const retRaw = call.return != null ? String(call.return) : (inferredType ? inferredType + ' {…}' : null)
      if (retRaw == null) continue
      const retStr = retRaw.length > 48 ? retRaw.slice(0, 48) + '…' : retRaw
      const callIdx = sortedCalls.findIndex(c => c.line_no === call.line_no)
      const nextSibling = callIdx >= 0 ? sortedCalls[callIdx + 1] : null
      const isObj = retStr.includes('{…}') && !retStr.startsWith('[')
      const isArr = !isObj && (retStr === '[…]' || retStr.startsWith('['))
      const objCall = (isObj && !isInferred && nextSibling) ? nextSibling : null
      const arrCall = (isArr && !isInferred && nextSibling) ? nextSibling : null
      // For inferred types: store class name so popup can use /api/find-object
      const inferredClass = isInferred && isObj ? inferredType : null
      entries.push({ type: 'ret', value: retStr, call, objCall, objArgIdx: 0, arrCall, arrArgIdx: 0, inferredClass })
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
            entries.push({ type: 'field', value: `$${varName}.${fieldName} = ${val}`, call })
            break
          }
        }
      }
      if (!entries.length) {
        // Fallback: show method name
        const sep = Math.max(sig.lastIndexOf('->'), sig.lastIndexOf('::'))
        const label = sep >= 0 ? sig.slice(sep) : sig.split('\\').pop()
        entries.push({ type: 'sig', value: label, call })
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




function openInStorm(lineNo) {
  if (!currentFile.value) return
  fetch('http://localhost:63343', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ path: currentFile.value, line: lineNo }),
  }).catch(() => {})
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
/* ── Floating overlay layout ─────────────────────────────────────────────
   CodeView is no longer a grid column — it slides in as a fixed-position
   panel on the right with a matte backdrop. The TOC behind it keeps 100%
   width and remains fully scrollable/interactable in the visible left area. */

/* The panel is a fixed-position frosted-glass sheet on the right edge of the
   viewport. No backdrop — the TOC behind stays fully visible (and fully
   interactive) while the panel sits on top with blur. */
.code-panel {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 150; /* below .float-ctrl (200) so the floating bar stays clickable */
  background: rgba(8, 10, 22, 0.62);
  backdrop-filter: blur(28px) saturate(160%);
  -webkit-backdrop-filter: blur(28px) saturate(160%);
  border-left: 1px solid rgba(70, 90, 140, 0.55);
  box-shadow: -10px 0 50px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.03);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  color: rgba(220, 230, 245, 0.9); /* base fg — overridden per-element below */
}
html[data-theme="light"] .code-panel {
  background: rgba(248, 250, 255, 0.62);
  border-left-color: rgba(140, 160, 220, 0.6);
  box-shadow: -10px 0 50px rgba(80, 100, 200, 0.16), 0 0 0 1px rgba(80, 100, 200, 0.04);
  color: rgba(15, 30, 60, 0.92);
}

/* Floating resize handle on the left edge of the panel — 4px wide hit area. */
.code-panel__resizer {
  position: absolute;
  left: -2px;
  top: 0;
  bottom: 0;
  width: 4px;
  cursor: col-resize;
  z-index: 1;
  background: linear-gradient(to right, transparent 50%, rgba(140, 165, 215, 0.18) 50%);
  transition: background 0.15s;
}
.code-panel__resizer:hover,
.code-panel__resizer:active {
  background: linear-gradient(to right, transparent 40%, rgba(140, 165, 215, 0.6) 50%, transparent 60%);
}
html[data-theme="light"] .code-panel__resizer {
  background: linear-gradient(to right, transparent 50%, rgba(60, 100, 200, 0.22) 50%);
}
html[data-theme="light"] .code-panel__resizer:hover,
html[data-theme="light"] .code-panel__resizer:active {
  background: linear-gradient(to right, transparent 40%, rgba(40, 80, 200, 0.65) 50%, transparent 60%);
}

/* Inner .code-view — fills the panel, no border (panel has its own). */
.code-view {
  display: flex;
  flex-direction: column;
  background: transparent;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  flex: 1;
  min-width: 0;
  height: 100%;
  overflow: hidden;
  position: relative;
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

.code-view__loadbar {
  padding: 4px 12px;
  font-size: 11px;
  color: rgba(140, 180, 220, 0.85);
  background: linear-gradient(90deg, transparent 0%, rgba(60, 130, 200, 0.25) 40%, rgba(80, 160, 240, 0.4) 60%, transparent 100%);
  background-size: 200% 100%;
  animation: loadbar-slide 1.2s linear infinite;
  border-bottom: 1px solid rgba(60, 130, 200, 0.2);
  text-align: center;
}
@keyframes loadbar-slide {
  0%   { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}

.code-view__error {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #7a3030;
  padding: 20px;
  font-style: italic;
}

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
  cursor: pointer;
}
.code-line__no:hover {
  color: #4a90c0;
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
.code-line__ann-item--clickable {
  cursor: pointer;
  border-radius: 3px;
  transition: background 0.1s;
}
.code-line__ann-item--clickable:hover {
  background: rgba(80, 130, 200, 0.12);
}
.code-line__ann-item--clickable:hover .ann-arrow { color: #6090b8; }

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


.obj-popup {
  position: fixed;
  z-index: 9999;
  background: #0f1825;
  border: 1px solid rgba(50, 80, 130, 0.55);
  border-radius: 7px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.65), 0 2px 8px rgba(0,0,0,0.4);
  min-width: 260px;
  max-width: 460px;
  max-height: 400px;
  overflow-y: auto;
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  scrollbar-width: thin;
  scrollbar-color: rgba(50,80,130,0.4) transparent;
}
.obj-popup__header {
  position: sticky;
  top: 0;
  padding: 5px 10px 5px 12px;
  color: #90b8e0;
  font-weight: 700;
  font-size: 11.5px;
  background: #131e30;
  border-bottom: 1px solid rgba(50, 80, 130, 0.4);
  border-radius: 7px 7px 0 0;
  letter-spacing: 0.02em;
}
.obj-popup__fields { padding: 3px 0 4px; }
.obj-popup__field {
  display: flex;
  align-items: baseline;
  gap: 0;
  padding: 2px 10px 2px 4px;
  border-radius: 3px;
  transition: background 0.08s;
}
.obj-popup__field:hover { background: rgba(50, 80, 140, 0.1); }
.obj-popup__field--expandable { cursor: pointer; }
.obj-popup__field--expandable:hover { background: rgba(60, 100, 180, 0.15); }
.obj-popup__field--open { background: rgba(40, 70, 130, 0.12); }
.obj-popup__chevron {
  width: 14px;
  flex-shrink: 0;
  color: #4a6898;
  font-size: 9px;
  text-align: center;
}
.obj-popup__fname {
  color: #6888b0;
  flex-shrink: 0;
  min-width: 90px;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11.5px;
}
.obj-popup__fval {
  color: #c0d4ec;
  word-break: break-all;
  font-size: 11.5px;
  padding-left: 6px;
}
.obj-popup__empty {
  padding: 5px 14px;
  color: #3a5070;
  font-style: italic;
  font-size: 11px;
}
.obj-popup__empty--nested { padding: 2px 8px; }
.obj-popup__nested {
  margin: 1px 8px 4px 18px;
  border-radius: 4px;
  background: rgba(20, 35, 60, 0.5);
  border: 1px solid rgba(40, 65, 110, 0.35);
  padding: 2px 0;
}
.obj-popup__nested-header {
  font-size: 10px;
  font-weight: 600;
  color: #4a6888;
  padding: 2px 8px 2px 10px;
  border-bottom: 1px solid rgba(40, 65, 110, 0.25);
  margin-bottom: 1px;
  letter-spacing: 0.02em;
}
.obj-popup__field--nested { padding: 1px 8px 1px 4px; }
.obj-popup__field--nested .obj-popup__fname { min-width: 70px; color: #5878a0; font-size: 11px; }
.obj-popup__field--nested .obj-popup__fval { font-size: 11px; }

/* ── Slide-in transition (panel only — no backdrop to fade anymore) ── */
.code-panel-enter-active,
.code-panel-leave-active {
  transition: transform 0.22s cubic-bezier(0.34, 1.05, 0.64, 1), opacity 0.18s ease;
}
.code-panel-enter-from,
.code-panel-leave-to {
  transform: translateX(40px);
  opacity: 0;
}

/* ── Light-theme overrides ─────────────────────────────────────────────
   The CodeView was originally dark-only. Every text colour gets a dark
   counterpart for html[data-theme="light"] so the frosted-glass panel is
   actually readable on a white-ish backdrop. The .code-panel base color
   (set near the top) is the only thing scoped via theme; everything below
   is force-overridden. */
html[data-theme="light"] .code-line__no         { color: #2a4060; }
html[data-theme="light"] .code-view__header    { border-bottom-color: rgba(140, 160, 220, 0.4); }
html[data-theme="light"] .code-view__filename  { color: #1a4878; }
html[data-theme="light"] .code-view__range     { color: #5a6a88; }
html[data-theme="light"] .code-view__close     { color: #2a3a58; }
html[data-theme="light"] .code-view__close:hover { color: #c03030; background: rgba(200, 40, 40, 0.1); }
html[data-theme="light"] .code-view__loadbar   { background: rgba(220, 235, 250, 0.9); color: #2a4878; }
html[data-theme="light"] .code-view__error     { color: #8a1f1f; background: rgba(200, 40, 40, 0.08); }
html[data-theme="light"] .code-line            { color: rgba(15, 30, 60, 0.92); }
html[data-theme="light"] .code-line:hover      { background: rgba(60, 100, 180, 0.06); }
html[data-theme="light"] .code-line--target    { background: rgba(60, 120, 200, 0.13); border-left-color: rgba(40, 100, 200, 0.7); }
html[data-theme="light"] .code-line--target .code-line__no { color: #1a4878; }
html[data-theme="light"] .code-line--ann       { background: rgba(40, 120, 60, 0.08); }
html[data-theme="light"] .code-line--ann.code-line--target { background: rgba(40, 120, 60, 0.14); border-left-color: rgba(30, 120, 60, 0.6); }
html[data-theme="light"] .code-line__ann-no    { color: #3a8050; }
html[data-theme="light"] .code-line__ann-item  { color: #1a3a20; }
html[data-theme="light"] .code-line__ann-item--clickable { background: rgba(60, 120, 200, 0.12); }
html[data-theme="light"] .code-line__ann-item--clickable:hover .ann-arrow { color: #1a4878; }
html[data-theme="light"] .ann-arrow            { color: #4a6a90; }
html[data-theme="light"] .ann-val              { color: #1a3060; }
html[data-theme="light"] .ann-val--str         { color: #1a5040; }
html[data-theme="light"] .ann-val--num         { color: #8a3010; }
html[data-theme="light"] .ann-val--null        { color: #6a6a6a; }
html[data-theme="light"] .ann-val--bool        { color: #7a3a8a; }

/* highlight.js token colours — light palette. Mapped to the same slots as
   the dark palette so syntax highlighting reads correctly on light bg. */
html[data-theme="light"] .code-line__code .hljs-keyword  { color: #7c3aed; }
html[data-theme="light"] .code-line__code .hljs-string   { color: #2a7a3a; }
html[data-theme="light"] .code-line__code .hljs-comment  { color: #6a8090; font-style: italic; }
html[data-theme="light"] .code-line__code .hljs-number   { color: #b8420a; }
html[data-theme="light"] .code-line__code .hljs-variable { color: #1a5fb8; }
html[data-theme="light"] .code-line__code .hljs-built_in { color: #1a8078; }
html[data-theme="light"] .code-line__code .hljs-title,
html[data-theme="light"] .code-line__code .hljs-function { color: #1a3aa0; }
html[data-theme="light"] .code-line__code .hljs-attr     { color: #a06008; }
html[data-theme="light"] .code-line__code .hljs-literal  { color: #b81f50; }
html[data-theme="light"] .code-line__code .hljs-type,
html[data-theme="light"] .code-line__code .hljs-class    { color: #8a5a08; }
html[data-theme="light"] .code-line__code .hljs-params,
html[data-theme="light"] .code-line__code .hljs-subst    { color: #3a3a3a; }

/* Object/array inspector popup — dark by default, override for light */
html[data-theme="light"] .obj-popup              { background: rgba(248, 250, 255, 0.96); border-color: rgba(140, 160, 220, 0.6); box-shadow: 0 4px 24px rgba(80, 100, 200, 0.18); }
html[data-theme="light"] .obj-popup__header     { color: #1a3060; border-bottom-color: rgba(140, 160, 220, 0.4); }
html[data-theme="light"] .obj-popup__field      { color: #1a3060; }
html[data-theme="light"] .obj-popup__field:hover { background: rgba(60, 100, 180, 0.08); }
html[data-theme="light"] .obj-popup__fname      { color: #2a4878; }
html[data-theme="light"] .obj-popup__fval       { color: #1a3060; }
html[data-theme="light"] .obj-popup__empty      { color: #6a7a90; }
html[data-theme="light"] .obj-popup__nested-header { color: #1a3060; border-bottom-color: rgba(140, 160, 220, 0.3); }
html[data-theme="light"] .obj-popup__field--nested { color: #1a3060; }
html[data-theme="light"] .obj-popup__field--nested .obj-popup__fname { color: #2a4060; }
</style>
