<template>
  <transition name="crumbs">
    <div v-if="crumbs.length" class="breadcrumbs">
      <template v-for="(c, i) in crumbs" :key="i">
        <span class="crumb-sep" v-if="i > 0">›</span>
        <span
          class="crumb"
          :class="{ 'crumb--last': i === crumbs.length - 1 }"
          :title="c.full"
        >{{ c.short }}</span>
      </template>
      <span class="crumb-line">line {{ lastLine?.toLocaleString() }}</span>
    </div>
  </transition>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  // Array of { sig, line_no, depth } from root to current node
  path: { type: Array, default: () => [] },
  // The line_no of the last clicked node
  lastLine: { type: Number, default: null },
})

function shortSig(sig) {
  if (!sig) return '?'
  const arrow = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep = Math.max(arrow, dcolon)
  if (sep === -1) return sig.split('\\').pop()
  const cls = sig.slice(0, sep).split('\\').pop()
  const method = sig.slice(sep)
  return cls + method
}

const crumbs = computed(() =>
  props.path.map(p => ({
    short: shortSig(p.sig),
    full: p.sig,
  }))
)
</script>

<style scoped>
.breadcrumbs {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 5px 28px;
  background: rgba(6, 8, 18, 0.75);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-top: 1px solid rgba(30, 35, 65, 0.5);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: #5a6a88;
  overflow-x: auto;
  white-space: nowrap;
  flex-shrink: 0;
  min-height: 26px;
}

.crumb {
  color: #6878a0;
  cursor: default;
  padding: 1px 4px;
  border-radius: 3px;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.crumb--last {
  color: #90a8c8;
}

.crumb-sep {
  color: #4a5070;
  font-size: 12px;
  flex-shrink: 0;
}

.crumb-line {
  margin-left: auto;
  color: #5a6888;
  flex-shrink: 0;
  padding-left: 12px;
}

.crumbs-enter-active, .crumbs-leave-active { transition: opacity 0.2s; }
.crumbs-enter-from, .crumbs-leave-to { opacity: 0; }
</style>
