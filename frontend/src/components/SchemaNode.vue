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
      <span class="schema-sig" :title="node.sig">
        <span v-for="(p, pi) in sigClassParts(node.sig)" :key="'c'+pi" :style="classPartStyle(pi)">{{ p }}</span>
        <span v-for="(p, pi) in sigMethodParts(node.sig)" :key="'m'+pi" :style="methodPartStyle(pi)">{{ p }}</span>
      </span>

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

const CLASS_COLORS  = ['#e8eef4', '#c0cfe0', '#98afc8', '#7898b8', '#5880a0']
const METHOD_COLORS = ['#8aaac8', '#6888a8', '#507090', '#3a5878', '#2a4060']
function classPartStyle(i)  { return { color: CLASS_COLORS[Math.min(i, CLASS_COLORS.length - 1)] } }
function methodPartStyle(i) { return { color: METHOD_COLORS[Math.min(i, METHOD_COLORS.length - 1)] } }

function camelParts(str) {
  if (!str) return []
  const m = str.match(/^(->|::)(.*)$/)
  const sep = m ? m[1] : ''
  const word = m ? m[2] : str
  const parts = word.split(/(?=[A-Z])/).filter(Boolean)
  if (!parts.length) return [str]
  parts[0] = sep + parts[0]
  return parts
}

function sigClassParts(sig) {
  if (!sig) return []
  const arrow = sig.lastIndexOf('->')
  const dc    = sig.lastIndexOf('::')
  const sep   = Math.max(arrow, dc)
  const cls   = sep >= 0 ? sig.slice(0, sep).split('\\').pop() : sig.split('\\').pop()
  return camelParts(cls)
}

function sigMethodParts(sig) {
  if (!sig) return []
  const arrow = sig.lastIndexOf('->')
  const dc    = sig.lastIndexOf('::')
  const sep   = Math.max(arrow, dc)
  if (sep < 0) return []
  return camelParts(sig.slice(sep))
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
