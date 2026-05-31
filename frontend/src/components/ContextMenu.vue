<template>
  <teleport to="body">
    <div
      v-if="visible"
      class="ctx-backdrop"
      @mousedown.self="close"
      @contextmenu.prevent="close"
    />
    <div
      v-if="visible"
      class="ctx-menu"
      :style="{ left: x + 'px', top: y + 'px' }"
    >
      <div class="ctx-title">Add to favourites</div>
      <div
        v-for="(item, i) in items"
        :key="i"
        class="ctx-item"
        @click="pick(item)"
      >
        <span class="ctx-kind">{{ item.kind }}</span>
        <span class="ctx-val">{{ item.value }}</span>
      </div>
      <div v-if="!items.length" class="ctx-empty">nothing to track here</div>
    </div>
  </teleport>
</template>

<script setup>
import { ref } from 'vue'
import { useTraceStore } from '../stores/trace'

const store = useTraceStore()

const visible = ref(false)
const x = ref(0)
const y = ref(0)
const items = ref([])

function open(event, nodeItems) {
  event.preventDefault()
  items.value = nodeItems
  // Position — clamp to viewport
  const vw = window.innerWidth
  const vh = window.innerHeight
  let nx = event.clientX + 4
  let ny = event.clientY + 4
  if (nx + 260 > vw) nx = vw - 264
  if (ny + 40 + nodeItems.length * 32 > vh) ny = vh - (40 + nodeItems.length * 32 + 8)
  x.value = nx
  y.value = ny
  visible.value = true
}

function close() {
  visible.value = false
}

async function pick(item) {
  close()
  await store.addFavourite(item.value, item.kind)
}

defineExpose({ open, close })
</script>

<style scoped>
.ctx-backdrop {
  position: fixed;
  inset: 0;
  z-index: 999;
}

.ctx-menu {
  position: fixed;
  z-index: 1000;
  background: #1a1a28;
  border: 1px solid #3a3a5a;
  border-radius: 6px;
  min-width: 240px;
  max-width: 380px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.6);
  font-family: 'JetBrains Mono', monospace;
  overflow: hidden;
}

.ctx-title {
  font-size: 10px;
  color: #555;
  padding: 6px 12px 4px;
  border-bottom: 1px solid #2a2a3a;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.ctx-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  cursor: pointer;
  font-size: 11px;
  border-bottom: 1px solid #1e1e2e;
}
.ctx-item:last-child { border-bottom: none; }
.ctx-item:hover { background: #252535; }

.ctx-kind {
  color: #ff9e9e;
  font-size: 10px;
  min-width: 52px;
  flex-shrink: 0;
}

.ctx-val {
  color: #ccc;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ctx-empty {
  padding: 8px 12px;
  font-size: 11px;
  color: #444;
  font-style: italic;
}
</style>
