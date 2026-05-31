<template>
  <div class="skeleton-tree">
    <div v-if="!nodes.length" class="empty">No skeleton loaded</div>
    <SkeletonNode
      v-for="idx in roots"
      :key="idx"
      :idx="idx"
      :nodes="nodes"
      :search-sigs="searchSigs"
      @jump="$emit('jump', $event)"
    />
  </div>
</template>

<script setup>
import SkeletonNode from './SkeletonNode.vue'

defineProps({
  nodes: { type: Array, default: () => [] },
  roots: { type: Array, default: () => [] },
  searchSigs: { type: Set, default: () => new Set() },
})
defineEmits(['jump'])
</script>

<style scoped>
.skeleton-tree {
  font-size: 12px;
  font-family: monospace;
  overflow-y: auto;
  height: 100%;
  padding: 4px;
}
.empty { color: #666; padding: 8px; }
</style>
