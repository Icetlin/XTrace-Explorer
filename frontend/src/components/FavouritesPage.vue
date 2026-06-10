<template>
  <div class="fav-page">
    <div class="fav-header">Tracked patterns</div>

    <div class="fav-add">
      <input
        v-model="newPattern"
        placeholder="pattern (e.g. sio_u, setCookie, kernel.response)"
        class="fav-input"
        @keydown.enter="addManual"
      />
      <input
        v-model="newLabel"
        placeholder="label (optional)"
        class="fav-input fav-input--label"
        @keydown.enter="addManual"
      />
      <button class="fav-btn" @click="addManual">+ Add</button>
    </div>

    <div v-if="!store.favourites.length" class="fav-empty">
      No tracked patterns yet. Right-click any call node to add one.
    </div>

    <div v-for="fav in store.favourites" :key="fav.id" class="fav-row">
      <span class="fav-dot" :style="{ background: color(fav.pattern).borderLeft }"></span>
      <span class="fav-pattern" :style="{ color: color(fav.pattern).text }">{{ fav.pattern }}</span>
      <span v-if="fav.label" class="fav-label" :style="{ color: color(fav.pattern).textDim, background: color(fav.pattern).bg, borderColor: color(fav.pattern).border }">{{ fav.label }}</span>
      <button class="fav-del" @click="store.deleteFavourite(fav.id)">✕</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useTraceStore } from '../stores/trace'
import { usePerfTrack } from '../perfTrack'
import { favColor } from '../favColor.js'

const store = useTraceStore()
usePerfTrack('FavouritesPage', { category: 'render' })
const newPattern = ref('')
const newLabel = ref('')

onMounted(() => store.loadFavourites())

const color = favColor

async function addManual() {
  const p = newPattern.value.trim()
  if (!p) return
  await store.addFavourite(p, newLabel.value.trim() || null)
  newPattern.value = ''
  newLabel.value = ''
}
</script>

<style scoped>
.fav-page {
  padding: 24px 20px;
  font-family: 'JetBrains Mono', monospace;
  max-width: 720px;
}

.fav-header {
  font-size: 11px;
  font-weight: 600;
  color: #444;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 20px;
}

.fav-add {
  display: flex;
  gap: 8px;
  margin-bottom: 24px;
  align-items: center;
}

.fav-input {
  background: #0e0e18;
  border: 1px solid #1e1e32;
  border-radius: 6px;
  color: #bbb;
  font-family: monospace;
  font-size: 12px;
  padding: 8px 12px;
  flex: 1;
  outline: none;
  transition: border-color 0.15s;
}
.fav-input:focus { border-color: #3a3a60; }
.fav-input--label { flex: 0 0 160px; }

.fav-btn {
  background: #141428;
  color: #666;
  border: 1px solid #2a2a42;
  border-radius: 6px;
  padding: 8px 16px;
  font-family: monospace;
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  transition: color 0.1s, border-color 0.1s;
}
.fav-btn:hover { color: #9ecbff; border-color: #3a5a8a; }

.fav-empty {
  color: #333;
  font-size: 12px;
  font-style: italic;
  padding: 12px 0;
}

.fav-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border-radius: 8px;
  border: 1px solid #141422;
  margin-bottom: 6px;
  background: #0c0c18;
  transition: background 0.1s, border-color 0.1s;
}
.fav-row:hover { background: #111120; border-color: #1e1e30; }

.fav-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.fav-pattern {
  flex: 1;
  font-size: 13px;
  font-weight: 500;
}

.fav-label {
  font-size: 10px;
  border: 1px solid;
  border-radius: 4px;
  padding: 2px 8px;
  flex-shrink: 0;
}

.fav-del {
  background: none;
  border: none;
  color: #2a2a3a;
  font-size: 11px;
  cursor: pointer;
  padding: 3px 7px;
  border-radius: 4px;
  line-height: 1;
  transition: color 0.1s, background 0.1s;
}
.fav-del:hover { color: #cc6060; background: #1e1018; }
</style>
