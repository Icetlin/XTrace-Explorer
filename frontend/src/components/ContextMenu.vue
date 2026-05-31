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
      <!-- Favourites section -->
      <div class="ctx-title">Add to favourites</div>
      <div
        v-for="(item, i) in favItems"
        :key="'f' + i"
        class="ctx-item"
        @click="pickFav(item)"
      >
        <span class="ctx-kind">{{ item.kind }}</span>
        <span class="ctx-val">{{ item.value }}</span>
      </div>
      <div v-if="!favItems.length" class="ctx-empty">nothing to track here</div>

      <!-- Filter section (only shown when there are filter items) -->
      <template v-if="filterItems.length">
        <div class="ctx-title ctx-title--filter">Hide listener (add to filters)</div>
        <div
          v-for="(item, i) in filterItems"
          :key="'r' + i"
          class="ctx-item ctx-item--filter"
          @click="pickFilter(item)"
        >
          <span class="ctx-kind ctx-kind--filter">⊘ filter</span>
          <span class="ctx-val">{{ item.value }}</span>
        </div>
      </template>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useTraceStore } from '../stores/trace'

const store = useTraceStore()

const visible = ref(false)
const x = ref(0)
const y = ref(0)
const items = ref([])

const favItems = computed(() => items.value.filter(i => i.action !== 'filter'))
const filterItems = computed(() => items.value.filter(i => i.action === 'filter'))

function open(event, nodeItems) {
  event.preventDefault()
  items.value = nodeItems
  const vw = window.innerWidth
  const vh = window.innerHeight
  let nx = event.clientX + 4
  let ny = event.clientY + 4
  const estimatedH = 32 + nodeItems.length * 32 + (filterItems.value.length ? 28 : 0)
  if (nx + 260 > vw) nx = vw - 264
  if (ny + estimatedH > vh) ny = vh - estimatedH - 8
  x.value = nx
  y.value = ny
  visible.value = true
}

function close() {
  visible.value = false
}

async function pickFav(item) {
  close()
  await store.addFavourite(item.value, item.label ?? null)
}

async function pickFilter(item) {
  close()
  if (store.listenerFilters.includes(item.value)) return
  await store.addListenerFilter(item.value)
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
.ctx-title--filter {
  color: #5a4a6a;
  border-top: 1px solid #2a2a3a;
  margin-top: 2px;
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
.ctx-item--filter:hover { background: #1e1828; }
.ctx-kind--filter { color: #9a7acc; min-width: 56px; }

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
