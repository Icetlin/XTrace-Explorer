<template>
  <div class="nested-event-list">
    <template v-for="(group, gi) in groups" :key="gi">
      <!-- Collapsed group -->
      <div
        v-if="group.count > 1 && !expandedGroups.has(gi)"
        class="nested-event-row nested-event-row--group"
        @click="expandedGroups = new Set([...expandedGroups, gi])"
      >
        <span class="chevron-sm">▸</span>
        <span class="nested-event-name">
          <span class="nested-event-name--dimmed">{{ group.eventName }}</span>
          <span v-if="group.voteAttr" class="nested-event-name--attr"> · {{ group.voteAttr }}</span>
        </span>
        <span v-if="group.caller" class="vote-caller-badge">{{ group.caller }}</span>
        <span class="nested-group-count">× {{ group.count }}</span>
      </div>

      <!-- Expanded or singleton -->
      <template v-else>
        <div
          v-if="group.count > 1"
          class="nested-group-bar"
          @click="expandedGroups = new Set([...expandedGroups].filter(x => x !== gi))"
        >
          <span class="nested-group-collapse">▾ {{ group.eventName }}<span v-if="group.voteAttr" class="nested-group-collapse--attr"> · {{ group.voteAttr }}</span><span v-if="group.caller" class="nested-group-collapse--caller"> [{{ group.caller }}]</span> × {{ group.count }} — collapse</span>
        </div>

        <div
          v-for="ei in group.indices"
          :key="ei"
          class="nested-event-block"
        >
          <div
            class="nested-event-row"
            :class="{ 'nested-event-row--selected': store.isSelected(events[ei].line_no) }"
            @click="toggleEvent(ei)"
          >
            <span class="chevron-sm">{{ expandedEvents.has(ei) ? '▾' : '▸' }}</span>
            <span class="nested-event-name">
              <span :class="voteAttr(events[ei]) ? 'nested-event-name--dimmed' : ''">{{ events[ei].event }}</span>
              <span v-if="voteAttr(events[ei])" class="nested-event-name--attr"> · {{ voteAttr(events[ei]) }}</span>
            </span>
            <span v-if="events[ei].caller && group.count === 1" class="vote-caller-badge">{{ callerLabel(events[ei].caller) }}</span>
          </div>

          <div v-if="expandedEvents.has(ei) && events[ei].listeners?.length" class="nested-listeners">
            <div
              v-for="(listener, li) in events[ei].listeners"
              :key="li"
              class="listener-row"
              :class="{ 'listener-row--abstain': listener.vote_result === 0, 'listener-row--granted': listener.vote_result === 1, 'listener-row--denied': listener.vote_result === -1 }"
            >
              <span class="connector">└</span>
              <span class="listener-class">{{ listenerClass(listener.sig) }}</span>
              <span class="listener-method">{{ listenerMethod(listener.sig) }}</span>
              <span v-if="listener.voter_class" class="voter-badge">{{ listener.voter_class }}</span>
              <span v-for="attr in (listener.vote_attrs ?? [])" :key="attr" class="vote-attr-badge">{{ attr }}</span>
              <span v-if="listener.vote_result === 1" class="vote-result vote-result--granted">GRANTED</span>
              <span v-else-if="listener.vote_result === -1" class="vote-result vote-result--denied">DENIED</span>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useTraceStore } from '../stores/trace'

const props = defineProps({
  events: { type: Array, default: () => [] },
})

const store = useTraceStore()
const expandedEvents = ref(new Set())
const expandedGroups = ref(new Set())

function voteAttr(ev) {
  if (!ev.event?.includes('authorization.vote')) return null
  return ev.listeners?.[0]?.vote_attrs?.[0] ?? null
}

function groupKey(ev) {
  const attr = voteAttr(ev)
  if (!attr) return ev.event
  return `${ev.event}::${attr}::${ev.caller ?? ''}`
}

function callerLabel(caller) {
  if (!caller) return null
  if (caller.includes('TwoFactor')) return '2FA check'
  if (caller.includes('AccessListener')) return 'access_control'
  return caller.split('->')[0]
}

const groups = computed(() => {
  const result = []
  let i = 0
  while (i < props.events.length) {
    const key = groupKey(props.events[i])
    const name = props.events[i].event
    if (store.isEventFiltered(name)) { i++; while (i < props.events.length && groupKey(props.events[i]) === key) i++; continue }
    let j = i + 1
    while (j < props.events.length && groupKey(props.events[j]) === key) j++
    const indices = []
    for (let k = i; k < j; k++) indices.push(k)
    const attr = voteAttr(props.events[i])
    const caller = props.events[i].caller ?? null
    result.push({
      eventName: props.events[i].event,
      voteAttr: attr,
      caller: caller ? callerLabel(caller) : null,
      count: j - i,
      indices,
    })
    i = j
  }
  return result
})

function toggleEvent(ei) {
  const s = new Set(expandedEvents.value)
  s.has(ei) ? s.delete(ei) : s.add(ei)
  expandedEvents.value = s
}

function listenerClass(sig) {
  const sep = Math.max(sig.lastIndexOf('->'), sig.lastIndexOf('::'))
  return sep === -1 ? sig : sig.slice(0, sep).split('\\').pop()
}

function listenerMethod(sig) {
  const sep = Math.max(sig.lastIndexOf('->'), sig.lastIndexOf('::'))
  return sep === -1 ? '' : sig.slice(sep)
}
</script>

<style scoped>
.nested-event-list {
  margin-left: 24px;
  margin-top: 4px;
  border-left: 1px dashed rgba(60, 80, 130, 0.35);
  padding-left: 10px;
  padding-bottom: 4px;
}

.nested-event-block {
  margin-bottom: 1px;
}

.nested-event-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
  cursor: pointer;
  border-radius: 3px;
  transition: background 0.12s;
}
.nested-event-row:hover { background: rgba(255,255,255,0.03); }
.nested-event-row--group { opacity: 0.8; }
.nested-event-row--selected { background: rgba(80, 120, 180, 0.07); }

.nested-event-name {
  font-size: 13px;
  color: #7090b8;
  flex: 1;
}
.nested-event-name--dimmed { color: #506888; }
.nested-event-name--attr { color: #a8c878; font-weight: 600; font-size: 12.5px; }

.nested-group-count {
  font-size: 10px;
  color: #4a6080;
  background: rgba(40, 60, 90, 0.25);
  border: 1px solid rgba(50, 75, 120, 0.3);
  border-radius: 8px;
  padding: 1px 7px;
  flex-shrink: 0;
  font-weight: 600;
}

.nested-group-bar {
  padding: 2px 10px;
  cursor: pointer;
}
.nested-group-collapse {
  font-size: 10px;
  color: #4a6080;
  font-family: 'JetBrains Mono', monospace;
  transition: color 0.1s;
}
.nested-group-bar:hover .nested-group-collapse { color: #7090c0; }
.nested-group-collapse--attr { color: #80a050; }
.nested-group-collapse--caller { color: #506070; font-size: 9.5px; }

.nested-listeners {
  margin-left: 16px;
  margin-top: 1px;
  margin-bottom: 2px;
}

/* reuse parent styles for listener rows */
.listener-row {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 3px 10px;
  border-left: 2px solid transparent;
  font-size: 13px;
}
.listener-row--abstain { opacity: 0.38; }
.listener-row--granted { border-left-color: rgba(80, 200, 100, 0.6); background: rgba(40, 100, 50, 0.08); }
.listener-row--denied  { border-left-color: rgba(220, 80, 80, 0.6);  background: rgba(100, 30, 30, 0.08); }

.connector { color: #6878a0; font-size: 12px; flex-shrink: 0; }
.chevron-sm { color: #6878a8; font-size: 10px; width: 10px; flex-shrink: 0; }

.listener-class { color: #b09060; font-weight: 500; }
.listener-method { color: #707080; font-size: 12.5px; }

.voter-badge {
  font-size: 10.5px;
  color: #c0a060;
  background: rgba(120, 80, 20, 0.2);
  border: 1px solid rgba(180, 120, 40, 0.25);
  border-radius: 3px;
  padding: 1px 4px;
  flex-shrink: 0;
  font-style: italic;
}
.vote-attr-badge {
  font-size: 9.5px;
  color: #6898b8;
  background: rgba(25, 60, 90, 0.3);
  border: 1px solid rgba(50, 100, 140, 0.3);
  border-radius: 3px;
  padding: 1px 4px;
  flex-shrink: 0;
}
.vote-result {
  font-size: 9.5px;
  font-weight: 700;
  border-radius: 3px;
  padding: 1px 5px;
  flex-shrink: 0;
  margin-left: auto;
}
.vote-result--granted { color: #70e090; background: rgba(40, 120, 60, 0.2); border: 1px solid rgba(60, 180, 90, 0.3); }
.vote-result--denied  { color: #e07070; background: rgba(120, 30, 30, 0.2); border: 1px solid rgba(200, 60, 60, 0.3); }

.vote-caller-badge {
  font-size: 10px;
  color: #7888b8;
  background: rgba(60, 70, 120, 0.2);
  border: 1px solid rgba(80, 90, 150, 0.3);
  border-radius: 10px;
  padding: 1px 8px;
  flex-shrink: 0;
}
</style>
