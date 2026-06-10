<template>
  <transition name="sel-preview">
    <div v-if="store.selection.length" class="sel-preview">
      <div class="sel-preview__header">
        <span class="sel-preview__title">Schema</span>
        <span class="sel-preview__count">{{ store.selection.length }} selected</span>
        <button class="sel-preview__clear" @click="store.clearSelection()" title="Clear selection">✕</button>
      </div>

      <div class="sel-preview__body" ref="bodyEl">
        <div v-if="loading" class="sel-loading">building…</div>
        <template v-else-if="tree.length">
          <SchemaNode
            v-for="(node, i) in tree"
            :key="node.line_no ?? i"
            :node="node"
            :indent="0"
          />
        </template>
        <div v-else class="sel-empty">nothing to show</div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useTraceStore } from '../stores/trace'
import { usePerfTrack } from '../perfTrack'
import axios from 'axios'
import SchemaNode from './SchemaNode.vue'

const store = useTraceStore()
usePerfTrack('SelectionPreview', { category: 'render' })
const tree    = ref([])
const loading = ref(false)
const bodyEl  = ref(null)

watch(
  () => store.selection.map(s => s.line_no).join(',') + '|' + store.activeTabFileId,
  async () => {
    const fileId = store.activeTabFileId
    const sel    = store.selection
    if (!sel.length || !fileId) { tree.value = []; return }

    loading.value = true
    try {
      const items = sel.map(s => ({
        line_no:   s.line_no,
        depth:     s.depth ?? 0,
        sig:       s.sig,
        args:      s.args ?? [],
        // breadcrumb = [{event}, {listener}, ...deeper]. from_line should be the listener (index 1),
        // because getAncestorPath needs to start at the real call root, not the dispatch event line.
        // Fallback to index 0 if there's only one crumb (e.g. direct listener selection), or line_no itself.
        from_line: s.breadcrumb?.[1]?.line_no ?? s.breadcrumb?.[0]?.line_no ?? s.line_no,
      }))

      const { data } = await axios.post(`/api/schema/${fileId}`, { items })
      tree.value = data
    } catch {
      tree.value = []
    } finally {
      loading.value = false
    }
  },
  { immediate: true }
)
</script>

<style scoped>
.sel-preview {
  position: fixed;
  right: 24px;
  top: 50%;
  transform: translateY(-50%);
  width: 340px;
  max-height: 65vh;
  display: flex;
  flex-direction: column;

  background: rgba(9, 11, 20, 0.75);
  backdrop-filter: blur(24px) saturate(150%);
  -webkit-backdrop-filter: blur(24px) saturate(150%);
  border: 1px solid rgba(55, 75, 130, 0.2);
  border-radius: 12px;
  box-shadow:
    0 8px 48px rgba(0, 0, 0, 0.6),
    0 0 0 1px rgba(255, 255, 255, 0.035) inset,
    0 1px 0 rgba(255, 255, 255, 0.055) inset;

  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 12px;
  z-index: 200;
  overflow: hidden;
}

.sel-preview__header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px 9px;
  border-bottom: 1px solid rgba(40, 55, 100, 0.22);
  flex-shrink: 0;
}

.sel-preview__title {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: #3a5a80;
}

.sel-preview__count {
  font-size: 10px;
  color: #263650;
}

.sel-preview__clear {
  margin-left: auto;
  background: none;
  border: none;
  color: #2a3050;
  font-size: 11px;
  cursor: pointer;
  padding: 2px 5px;
  border-radius: 4px;
  transition: color 0.1s, background 0.1s;
  line-height: 1;
}
.sel-preview__clear:hover { color: #cc5050; background: rgba(160, 30, 30, 0.08); }

.sel-preview__body {
  overflow-y: auto;
  flex: 1;
  padding: 6px 0 8px;
  scrollbar-width: thin;
  scrollbar-color: rgba(40, 55, 90, 0.35) transparent;
}

.sel-loading, .sel-empty {
  padding: 12px 16px;
  font-size: 11.5px;
  color: #2a3550;
  font-style: italic;
}

/* ── Transition ── */
.sel-preview-enter-active {
  transition: opacity 0.18s ease, transform 0.18s cubic-bezier(0.34, 1.35, 0.64, 1);
}
.sel-preview-leave-active {
  transition: opacity 0.13s ease, transform 0.13s ease-in;
}
.sel-preview-enter-from {
  opacity: 0;
  transform: translateY(-50%) translateX(16px);
}
.sel-preview-leave-to {
  opacity: 0;
  transform: translateY(-50%) translateX(16px);
}
</style>
