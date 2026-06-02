<template>
  <div v-if="!node.noise" class="schema-node">
    <div
      class="schema-row"
      :class="{
        'schema-row--selected': node.selected,
      }"
      :style="{ paddingLeft: (indent * 14 + 10) + 'px' }"
    >
      <!-- connector -->
      <span class="schema-chevron">{{ node.children?.length ? '▸' : '·' }}</span>

      <!-- sig -->
      <span class="schema-sig" :class="sigClass" :title="node.sig">{{ shortSig(node.sig) }}</span>

      <!-- args (only on selected nodes) -->
      <template v-if="node.selected && node.args?.length">
        <span v-for="(a, i) in node.args" :key="i" class="schema-arg">{{ argVal(a) }}</span>
      </template>

      <!-- return -->
      <span v-if="node.return != null" class="schema-return">⇒ {{ node.return }}</span>

      <!-- selected marker -->
      <span v-if="node.selected" class="schema-sel-dot" title="selected" />
    </div>

    <!-- children -->
    <div v-if="node.children?.length" class="schema-children">
      <SchemaNode
        v-for="(child, i) in node.children"
        :key="child.line_no ?? i"
        :node="child"
        :indent="indent + 1"
      />
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  node:   Object,
  indent: { type: Number, default: 0 },
})

const sigClass = (() => {
  const s = props.node.sig || ''
  if (s.startsWith('App\\')) return 'sig-app'
  if (s.match(/Controller/)) return 'sig-ctrl'
  if (s.match(/Subscriber|Listener/)) return 'sig-listener'
  if (s.match(/^Symfony|^Doctrine|^Lexik|^Scheb/)) return 'sig-vendor'
  return 'sig-other'
})()

function shortSig(sig) {
  if (!sig) return '?'
  const arrow  = sig.lastIndexOf('->')
  const dcolon = sig.lastIndexOf('::')
  const sep    = Math.max(arrow, dcolon)
  if (sep === -1) return sig
  return sig.slice(0, sep).split('\\').pop() + sig.slice(sep)
}

function argVal(arg) {
  const m = arg.match(/^\$\w+\s*=\s*(.+)$/)
  return m ? m[1] : arg
}
</script>

<style scoped>
.schema-node {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
}

.schema-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  padding: 3px 10px;
  border-left: 2px solid transparent;
  font-size: 11.5px;
  line-height: 1.55;
}

.schema-row--selected {
  border-left-color: rgba(70, 120, 200, 0.5);
  background: rgba(60, 100, 180, 0.06);
}


.schema-chevron {
  color: #2a3040;
  font-size: 9px;
  width: 10px;
  flex-shrink: 0;
}

.schema-sig {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 240px;
}

/* same palette as CallNode */
.sig-app      { color: #5ab0cc; }
.sig-ctrl     { color: #b08050; }
.sig-listener { color: #a09048; }
.sig-vendor   { color: #585868; }
.sig-other    { color: #6a7a8a; }

.schema-arg {
  font-size: 10.5px;
  color: #4a6070;
  background: rgba(255, 255, 255, 0.025);
  border: 1px solid rgba(60, 90, 120, 0.14);
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.schema-return {
  font-size: 10.5px;
  color: #7a5040;
  background: rgba(120, 60, 20, 0.07);
  border: 1px solid rgba(100, 50, 20, 0.18);
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}

.schema-sel-dot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: rgba(70, 130, 210, 0.6);
  flex-shrink: 0;
  margin-left: 2px;
}

.schema-children {
  border-left: 1px solid rgba(255, 255, 255, 0.03);
  margin-left: 16px;
}
</style>
