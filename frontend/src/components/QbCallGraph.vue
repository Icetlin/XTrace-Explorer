<template>
  <div class="qcg">
    <div v-if="!roots.length" class="qcg__empty">
      No app-code backtraces to build a graph from.
    </div>
    <QbCallGraphNode
      v-for="rootKey in roots"
      :key="rootKey"
      :node-key="rootKey"
      :nodes="nodes"
      :depth="0"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useQbStore } from '../stores/qb'
import QbCallGraphNode from './QbCallGraphNode.vue'

const qb = useQbStore()
const nodes = computed(() => qb.callGraph.nodes)
const roots = computed(() => qb.callGraph.roots)
</script>

<style scoped>
.qcg { font-size: 12px; }
.qcg__empty {
  text-align: center;
  padding: 40px;
  color: #888;
  font-size: 13px;
}
</style>