<template>
  <div class="fav-page">
    <div class="fav-header">Tracked patterns</div>

    <div class="fav-add">
      <input
        v-model="newPattern"
        placeholder="pattern text (e.g. sio_u, setCookie, kernel.response)"
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
      <span class="fav-pattern">{{ fav.pattern }}</span>
      <span v-if="fav.label" class="fav-label">{{ fav.label }}</span>
      <button class="fav-del" @click="store.deleteFavourite(fav.id)">✕</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useTraceStore } from '../stores/trace'

const store = useTraceStore()
const newPattern = ref('')
const newLabel = ref('')

onMounted(() => store.loadFavourites())

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
  padding: 16px;
  font-family: 'JetBrains Mono', monospace;
  max-width: 700px;
}

.fav-header {
  font-size: 13px;
  font-weight: 600;
  color: #7aadff;
  margin-bottom: 14px;
}

.fav-add {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  align-items: center;
}

.fav-input {
  background: #111;
  border: 1px solid #2a2a3a;
  border-radius: 4px;
  color: #ccc;
  font-family: monospace;
  font-size: 12px;
  padding: 5px 10px;
  flex: 1;
  outline: none;
}
.fav-input:focus { border-color: #5a5a8a; }
.fav-input--label { flex: 0 0 160px; }

.fav-btn {
  background: #1e1e3a;
  color: #ff9e9e;
  border: 1px solid #5a3a3a;
  border-radius: 4px;
  padding: 5px 14px;
  font-family: monospace;
  font-size: 12px;
  cursor: pointer;
  white-space: nowrap;
}
.fav-btn:hover { background: #2a1e2e; }

.fav-empty {
  color: #444;
  font-size: 12px;
  font-style: italic;
  padding: 8px 0;
}

.fav-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px;
  border-radius: 4px;
  border: 1px solid #1e1e2e;
  margin-bottom: 4px;
  background: #111118;
}
.fav-row:hover { background: #16161e; }

.fav-pattern {
  flex: 1;
  font-size: 12px;
  color: #ff9e9e;
}

.fav-label {
  font-size: 11px;
  color: #666;
  background: #1e1e2e;
  border-radius: 3px;
  padding: 1px 6px;
}

.fav-del {
  background: none;
  border: none;
  color: #444;
  font-size: 12px;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 3px;
  line-height: 1;
}
.fav-del:hover { color: #f88; background: #2a1a1a; }
</style>
