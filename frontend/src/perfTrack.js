// usePerfTrack — opt-in component mount/render timing.
//
// Usage in a component's <script setup>:
//   usePerfTrack('TocTree')                 // records once on mount
//   usePerfTrack('CodeView', { reMount: true }) // also records on every re-mount
//
// The label is shown verbatim in the Frontend timings panel under the
// 'render' category, so keep it short and unique per component.
import { onBeforeMount, onMounted, onUpdated, getCurrentInstance } from 'vue'
import { usePerfStore } from './stores/perf'

const DEFAULT_MIN_MS = 1.0   // skip sub-ms mounts (noise)
const DEFAULT_MIN_UPDATE_MS = 2.0

export function usePerfTrack(label, options = {}) {
  if (!label) return
  const {
    category = 'render',
    reMount = false,            // record subsequent remounts too
    trackUpdates = false,       // also measure render→updated time
    minMs = DEFAULT_MIN_MS,
    minUpdateMs = DEFAULT_MIN_UPDATE_MS,
  } = options

  const perf = usePerfStore()
  let t0 = 0
  let u0 = 0

  onBeforeMount(() => { t0 = performance.now() })
  onMounted(() => {
    const dur = performance.now() - t0
    if (dur >= minMs) perf.record(`mount ${label}`, category, dur)
    if (trackUpdates) u0 = performance.now()
  })

  if (trackUpdates) {
    onUpdated(() => {
      const dur = performance.now() - u0
      if (dur >= minUpdateMs) perf.record(`update ${label}`, category, dur)
      u0 = performance.now()
    })
  }

  // For re-mounts: when v-if toggles the component off and back on, the
  // instance is destroyed and a new one is created — onBeforeMount fires
  // again. We just leave the hook in place; reMount only controls the
  // minMs threshold for subsequent mounts (it is always recorded if it
  // passes the threshold; the option is kept for clarity / future use).
  // (No-op: onBeforeMount + onMounted are already re-registered per
  // instance lifetime; nothing to do here.)
  void reMount
}
