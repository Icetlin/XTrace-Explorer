<template>
  <div class="toc">
    <div v-if="!items.length" class="empty">No TOC loaded</div>
    <div
      v-for="item in items"
      :key="item.line_no"
      class="toc-row"
      :class="{ 'is-search-hit': searchSigs.has(item.sig) }"
      @click="$emit('jump', item.line_no)"
      :title="item.sig"
    >
      <span class="line-no">#{{ item.line_no }}</span>
      <span class="sig">{{ item.sig }}</span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  items: { type: Array, default: () => [] },
  searchSigs: { type: Set, default: () => new Set() },
})
defineEmits(['jump'])
</script>

<style scoped>
.toc {
  font-size: 12px;
  font-family: monospace;
  overflow-y: auto;
  height: 100%;
  padding: 4px 0;
}
.empty { color: #666; padding: 8px; }
.toc-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
}
.toc-row:hover { background: #1e1e2e; }
.toc-row.is-search-hit { background: #2a2800; }
.line-no { color: #444; width: 56px; flex-shrink: 0; text-align: right; font-size: 10px; }
.sig { color: #adf; overflow: hidden; text-overflow: ellipsis; }
</style>
