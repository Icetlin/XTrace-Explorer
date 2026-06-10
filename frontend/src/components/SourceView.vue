<template>
  <div v-if="lines.length" class="source-view">
    <div class="source-header" @click="collapsed = !collapsed">
      <span class="src-chevron">{{ collapsed ? '▸' : '▾' }}</span>
      <span class="src-filename">{{ shortFile }}</span>
      <span class="src-hint">{{ calledLines.size }} calls · {{ inferredLines.size }} inferred</span>
    </div>
    <div v-if="!collapsed" class="source-body">
      <div
        v-for="[no, code] in lines"
        :key="no"
        class="src-line"
        :class="lineClass(no)"
      >
        <span class="src-ln">{{ no }}</span>
        <span class="src-code">{{ code }}</span>
        <span v-if="calledLines.has(no)" class="src-ann">{{ calledLines.get(no) }}</span>
        <span v-else-if="inferredLines.has(no)" class="src-ann src-ann--inferred">{{ inferredLines.get(no) }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useTraceStore } from '../stores/trace'

const props = defineProps({
  // children array from getChildren response
  children: { type: Array, default: () => [] },
  fileId: Number,
})

const store = useTraceStore()
const collapsed = ref(true)
const sourceData = ref(null)

// Extract absolute file path and line numbers from children
// children[i].file = "src/Security/Foo.php:18" (short), we need the absolute path
// We get it from the first child that has a full path recorded via trace
// Actually children have short file — we need to reconstruct from the first child's sig
// The full path comes from the raw trace line. We store it in child.file_abs if available,
// otherwise we skip source view.

const absFile = computed(() => {
  // Only show source when all children share the same file (= body of one function)
  const files = new Set(props.children.map(c => c.file_abs?.replace(/:\d+$/, '')).filter(Boolean))
  if (files.size !== 1) return null
  return [...files][0]
})

const calledLineNos = computed(() => {
  const nos = new Set()
  for (const c of props.children) {
    const ln = extractLineNo(c.file)
    if (ln) nos.add(ln)
  }
  return nos
})

function extractLineNo(fileStr) {
  if (!fileStr) return null
  const m = fileStr.match(/:(\d+)$/)
  return m ? parseInt(m[1]) : null
}

// Build called lines map: lineNo → annotation text
const calledLines = computed(() => {
  const map = new Map()
  for (const c of props.children) {
    const ln = extractLineNo(c.file)
    if (!ln) continue
    const existing = map.get(ln)
    const short = c.sig ? c.sig.split('\\').pop() : ''
    if (!existing) map.set(ln, short)
  }
  return map
})

// Infer which lines between last two calls are "instanceof / return" branches
const inferredLines = computed(() => {
  const map = new Map()
  if (!sourceData.value) return map
  const linesMap = sourceData.value.lines
  const calledNos = [...calledLines.value.keys()].sort((a, b) => a - b)
  if (calledNos.length < 1) return map

  const lastCalled = calledNos[calledNos.length - 1]
  // Lines after last call until end of function — these were "reached" without calls
  for (const [noStr, code] of Object.entries(linesMap)) {
    const no = parseInt(noStr)
    if (no <= lastCalled) continue
    const trimmed = code.trim()
    if (!trimmed || trimmed === '}' || trimmed === '{') continue
    if (trimmed.startsWith('//')) continue
    // instanceof check or return — mark as inferred
    if (trimmed.includes('instanceof') || trimmed.startsWith('return')) {
      const label = trimmed.includes('instanceof') ? 'no call → entered here' :
                    trimmed.startsWith('return false') ? 'returned false' :
                    trimmed.startsWith('return true') ? 'returned true' : ''
      if (label) map.set(no, label)
    }
  }
  return map
})

const lines = computed(() => {
  if (!sourceData.value) return []
  return Object.entries(sourceData.value.lines).map(([no, code]) => [parseInt(no), code])
})

const shortFile = computed(() => {
  if (!absFile.value) return ''
  const m = absFile.value.match(/\/(src|vendor)\/.+$/)
  if (m) {
    const parts = m[0].split('/')
    return parts.slice(-3).join('/')
  }
  return absFile.value.split('/').slice(-1)[0]
})

function lineClass(no) {
  if (calledLines.value.has(no)) return 'src-line--called'
  if (inferredLines.value.has(no)) return 'src-line--inferred'
  return ''
}

watch(() => props.children, async (children) => {
  sourceData.value = null
  if (!children?.length) return

  // Find file_abs — need children with absolute path
  const fileAbs = children.find(c => c.file_abs)?.file_abs?.replace(/:\d+$/, '')
  if (!fileAbs) return

  // Determine range: from min called line - 5 to max called line + 15
  const nos = children.map(c => extractLineNo(c.file)).filter(Boolean)
  if (!nos.length) return
  const hint = Math.min(...nos)

  sourceData.value = await store.fetchSource(fileAbs, hint, store.extractMethodName(props.children?.[0]?.sig), store.extractClassName(props.children?.[0]?.sig))
}, { immediate: true })
</script>

<style scoped>
.source-view {
  margin: 4px 0 6px 14px;
  border: 1px solid rgba(50, 70, 90, 0.3);
  border-radius: 5px;
  overflow: hidden;
  background: #0b0b13;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
}

.source-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px;
  background: rgba(30, 50, 70, 0.25);
  border-bottom: 1px solid rgba(50, 70, 90, 0.2);
  cursor: pointer;
  user-select: none;
  font-size: 11px;
  color: #3a5a6a;
}
.source-header:hover { color: #5a8aaa; }

.src-chevron { font-size: 9px; }
.src-filename { color: #4a7a8a; }
.src-hint { margin-left: auto; font-size: 10px; color: #2a3a48; font-style: italic; }

.source-body { padding: 4px 0; }

.src-line {
  display: flex;
  align-items: baseline;
  gap: 0;
  font-size: 12px;
  line-height: 1.75;
  padding: 0 12px 0 8px;
  position: relative;
}

.src-ln {
  width: 34px;
  flex-shrink: 0;
  color: #1e2830;
  text-align: right;
  padding-right: 10px;
  user-select: none;
  font-size: 10.5px;
}

.src-code {
  flex: 1;
  color: #252535;
  white-space: pre;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Called lines */
.src-line--called { background: rgba(40, 80, 110, 0.08); }
.src-line--called .src-ln  { color: #2e5060; }
.src-line--called .src-code { color: #5a90b0; }
.src-line--called::before {
  content: '●';
  position: absolute;
  left: 2px;
  font-size: 5px;
  color: #3a6a8a;
  top: 8px;
}

/* Inferred lines (instanceof / return after last call) */
.src-line--inferred { background: rgba(70, 90, 40, 0.07); }
.src-line--inferred .src-ln  { color: #485840; }
.src-line--inferred .src-code { color: #6a8050; }
.src-line--inferred::before {
  content: '◆';
  position: absolute;
  left: 1px;
  font-size: 5px;
  color: #607040;
  top: 8px;
}

.src-ann {
  flex-shrink: 0;
  margin-left: 10px;
  font-size: 10px;
  font-style: italic;
  color: #3a6070;
  background: rgba(40, 80, 100, 0.12);
  border-radius: 3px;
  padding: 0 5px;
  white-space: nowrap;
}
.src-ann--inferred {
  color: #708050;
  background: rgba(70, 90, 40, 0.15);
}
</style>
