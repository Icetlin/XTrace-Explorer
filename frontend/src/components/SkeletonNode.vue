<template>
  <div>
    <div
      class="node"
      :class="{ highlighted: searchSigs.has(node.sig) }"
      @click="toggle"
    >
      <span class="arrow">{{ node.children?.length ? (open ? '▼' : '►') : '·' }}</span>
      <span class="sig" :title="node.sig" @click.stop="$emit('jump', node.first_line)">{{ shortSig(node.sig) }}</span>
      <span class="line-no" @click.stop="$emit('jump', node.first_line)">#{{ node.first_line }}</span>
    </div>
    <div v-if="open && node.children?.length" class="children">
      <SkeletonNode
        v-for="childIdx in node.children"
        :key="childIdx"
        :idx="childIdx"
        :nodes="nodes"
        :search-sigs="searchSigs"
        @jump="$emit('jump', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  idx: Number,
  nodes: Array,
  searchSigs: { type: Set, default: () => new Set() },
})
defineEmits(['jump'])

const node = computed(() => props.nodes[props.idx])
const open = ref(false)
function toggle() { open.value = !open.value }

function shortSig(sig) {
  if (!sig) return ''
  const parts = sig.split('\\')
  const last = parts[parts.length - 1]
  return last.length > 38 ? last.slice(0, 38) + '…' : last
}
</script>

<style scoped>
.node {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 2px 4px;
  cursor: pointer;
  border-radius: 3px;
  white-space: nowrap;
}
.node:hover { background: #2a2a2a; }
.node.highlighted { background: #3a3000; }
.arrow { color: #666; width: 12px; flex-shrink: 0; }
.sig { color: #cdd; flex: 1; overflow: hidden; text-overflow: ellipsis; }
.sig:hover { color: #7cf; text-decoration: underline; }
.line-no { color: #555; font-size: 10px; flex-shrink: 0; }
.children { padding-left: 12px; }
</style>
