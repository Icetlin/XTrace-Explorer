<template>
  <div ref="containerRef" class="timeline" @scroll="onScroll">
    <div :style="{ height: totalHeight + 'px', position: 'relative' }">
      <div :style="{ transform: `translateY(${offsetY}px)`, position: 'absolute', width: '100%' }">
        <div
          v-for="item in visibleItems"
          :key="item.line_no"
          class="row"
          :class="{
            'is-call': item.sig,
            'is-annotated': annotatedLines.has(item.line_no),
            'is-search-hit': searchHitLines.has(item.line_no),
          }"
          :style="{ paddingLeft: (item.depth * 12 + 8) + 'px' }"
          @click="selectLine(item)"
        >
          <span class="line-no">{{ item.line_no }}</span>
          <span class="sig">{{ item.sig }}</span>
          <span v-if="annotatedLines.has(item.line_no)" class="annot-marker" title="Annotated">●</span>
        </div>
      </div>
    </div>

    <!-- Annotation popup -->
    <div v-if="selectedLine" class="annot-popup" @click.stop>
      <div class="annot-popup-header">
        Line {{ selectedLine.line_no }}: {{ selectedLine.sig || selectedLine.raw?.slice(0, 60) }}
      </div>
      <textarea v-model="annotText" placeholder="Add annotation..." rows="3" />
      <div class="annot-popup-actions">
        <button @click="saveAnnotation">Save</button>
        <button @click="selectedLine = null">Cancel</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useTraceStore } from '../stores/trace'

const ROW_HEIGHT = 20
const CHUNK_SIZE = 200
const OVERSCAN = 5

const store = useTraceStore()
const containerRef = ref(null)
const scrollTop = ref(0)
const containerHeight = ref(600)

const props = defineProps({
  fileId: Number,
  estimatedTotal: { type: Number, default: 100000 },
  annotations: { type: Array, default: () => [] },
  searchHits: { type: Array, default: () => [] },
})

const annotatedLines = computed(() => new Set(props.annotations.map(a => a.line_no)))
const searchHitLines = computed(() => new Set(props.searchHits.map(h => h.line_no)))

const totalHeight = computed(() => props.estimatedTotal * ROW_HEIGHT)

const startIdx = computed(() => Math.max(0, Math.floor(scrollTop.value / ROW_HEIGHT) - OVERSCAN))
const endIdx = computed(() => Math.min(props.estimatedTotal - 1, Math.ceil((scrollTop.value + containerHeight.value) / ROW_HEIGHT) + OVERSCAN))

const offsetY = computed(() => startIdx.value * ROW_HEIGHT)

const visibleItems = ref([])

const selectedLine = ref(null)
const annotText = ref('')

watch([startIdx, endIdx], async () => {
  if (!props.fileId) return
  const from = startIdx.value + 1
  const to = endIdx.value + 1
  const chunkFrom = Math.floor((from - 1) / CHUNK_SIZE) * CHUNK_SIZE + 1

  const lines = await store.fetchChunk(props.fileId, chunkFrom, chunkFrom + CHUNK_SIZE - 1)

  // Only show lines with a parsed call signature (lines with "->")
  visibleItems.value = lines.filter(l => l.line_no >= from && l.line_no <= to && l.sig)
}, { immediate: true })

function onScroll() {
  scrollTop.value = containerRef.value?.scrollTop ?? 0
}

watch(() => store.scrollToLine, async (lineNo) => {
  if (!lineNo || !containerRef.value) return
  await nextTick()
  const targetScrollTop = (lineNo - 1) * ROW_HEIGHT - containerHeight.value / 2
  containerRef.value.scrollTop = Math.max(0, targetScrollTop)
  store.scrollToLine = null
})

onMounted(() => {
  const ro = new ResizeObserver(entries => {
    containerHeight.value = entries[0].contentRect.height
  })
  ro.observe(containerRef.value)
})

function selectLine(item) {
  selectedLine.value = item
  const existing = props.annotations.find(a => a.line_no === item.line_no)
  annotText.value = existing?.text ?? ''
}

async function saveAnnotation() {
  if (!annotText.value.trim()) return
  await store.addAnnotation(props.fileId, selectedLine.value.line_no, annotText.value.trim())
  selectedLine.value = null
  annotText.value = ''
}
</script>

<style scoped>
.timeline {
  flex: 1;
  overflow-y: auto;
  font-family: monospace;
  font-size: 12px;
  background: #111;
  position: relative;
}
.row {
  height: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
}
.row:hover { background: #1e1e1e; }
.row.is-search-hit { background: #2a2800; }
.row.is-annotated { border-left: 2px solid #f80; }
.line-no { color: #444; width: 60px; flex-shrink: 0; text-align: right; font-size: 10px; }
.sig { color: #adf; }
.raw { color: #666; }
.annot-marker { color: #f80; margin-left: auto; padding-right: 8px; }

.annot-popup {
  position: fixed;
  bottom: 20px;
  right: 20px;
  background: #1e1e2e;
  border: 1px solid #444;
  border-radius: 6px;
  padding: 12px;
  width: 360px;
  z-index: 100;
  box-shadow: 0 4px 20px rgba(0,0,0,0.5);
}
.annot-popup-header {
  color: #adf;
  font-size: 11px;
  margin-bottom: 8px;
  word-break: break-all;
}
textarea {
  width: 100%;
  background: #111;
  color: #ccc;
  border: 1px solid #333;
  border-radius: 4px;
  padding: 6px;
  resize: vertical;
  font-family: monospace;
  font-size: 12px;
  box-sizing: border-box;
}
.annot-popup-actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}
button {
  background: #2a2a4a;
  color: #adf;
  border: 1px solid #44f8;
  border-radius: 4px;
  padding: 4px 12px;
  cursor: pointer;
  font-size: 12px;
}
button:hover { background: #3a3a6a; }
</style>
