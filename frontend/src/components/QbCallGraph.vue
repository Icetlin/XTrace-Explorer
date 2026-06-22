<template>
  <div class="qcg">
    <div v-if="!roots.length" class="qcg__empty">
      No app-code backtraces to build a graph from.
    </div>
    <template v-else>
      <!-- Mini toolbar — only shown when there's something to expand.
           Lets users collapse the whole tree after exploring, or jump
           straight to "everything open" without having to click each
           chevron (the way PhpStorm's debugger does it). -->
      <div class="qcg__toolbar">
        <span class="qcg__toolbar-label">tree</span>
        <button
          class="qcg__toolbar-btn"
          title="Expand all nodes (PhpStorm-style)"
          @click="qb.expandAllCallGraph()"
        >⊞ expand all</button>
        <button
          class="qcg__toolbar-btn"
          title="Collapse every node back to roots"
          @click="qb.collapseAllCallGraph()"
        >⊟ collapse all</button>
      </div>
      <QbCallGraphNode
        v-for="rootKey in roots"
        :key="rootKey"
        :node-key="rootKey"
        :nodes="nodes"
        :depth="0"
      />
    </template>
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

/* Mini toolbar above the call-graph tree. Same look as the QbPage
 * toolbar's ghost buttons so it doesn't feel like a different UI. */
.qcg__toolbar {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  padding: 4px 6px;
  background: rgba(255,255,255,0.02);
  border: 1px solid rgba(255,255,255,0.04);
  border-radius: 4px;
}
.qcg__toolbar-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #888;
  margin-right: 4px;
}
.qcg__toolbar-btn {
  background: transparent;
  border: 1px solid rgba(255,255,255,0.10);
  border-radius: 3px;
  color: #ccc;
  font-size: 11px;
  padding: 2px 8px;
  cursor: pointer;
  font-family: inherit;
}
.qcg__toolbar-btn:hover {
  background: rgba(255,255,255,0.05);
  border-color: rgba(92, 217, 122, 0.5);
  color: #5cd97a;
}
</style>