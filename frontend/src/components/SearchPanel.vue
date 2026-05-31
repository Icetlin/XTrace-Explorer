<template>
  <div class="search-panel">
    <input
      v-model="query"
      placeholder="Search (min 2 chars)..."
      @input="onInput"
    />
    <div v-if="results.length" class="results">
      <div
        v-for="r in results"
        :key="r.line_no"
        class="result-row"
        @click="$emit('jump', r.line_no)"
      >
        <span class="line-no">#{{ r.line_no }}</span>
        <span class="sig">{{ shortSig(r.sig) }}</span>
      </div>
      <div class="count">{{ results.length }} results{{ results.length >= 200 ? ' (capped at 200)' : '' }}</div>
    </div>
    <div v-else-if="query.length >= 2" class="empty">No results</div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useTraceStore } from '../stores/trace'

const props = defineProps({ fileId: Number })
defineEmits(['jump'])

const store = useTraceStore()
const query = ref('')
const results = store.searchResults

let debounceTimer = null
function onInput() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    if (props.fileId) store.search(props.fileId, query.value)
  }, 300)
}

function shortSig(sig) {
  if (!sig) return ''
  const parts = sig.split('\\')
  return parts[parts.length - 1]
}
</script>

<style scoped>
.search-panel {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px;
  background: #161620;
  border-bottom: 1px solid #222;
}
input {
  background: #111;
  color: #ccc;
  border: 1px solid #333;
  border-radius: 4px;
  padding: 6px 10px;
  font-family: monospace;
  font-size: 12px;
  outline: none;
}
input:focus { border-color: #44f; }
.results { max-height: 200px; overflow-y: auto; }
.result-row {
  display: flex;
  gap: 8px;
  padding: 2px 4px;
  cursor: pointer;
  font-family: monospace;
  font-size: 11px;
}
.result-row:hover { background: #1e1e2e; }
.line-no { color: #555; width: 60px; flex-shrink: 0; text-align: right; }
.sig { color: #adf; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.count { color: #555; font-size: 10px; padding: 2px 4px; }
.empty { color: #555; font-size: 11px; padding: 2px 4px; }
</style>
