import { defineStore } from 'pinia'
import { ref } from 'vue'

// Module-level counter so ids stay unique even if store is reset.
let _id = 0

// CATEGORIES — keep in sync with FloatCtrl.vue CSS .perf-cat--<cat>
export const PERF_CATEGORIES = {
  api:     { label: 'api',     color: '#6cb8ff' },
  tab:     { label: 'tab',     color: '#c9a8ff' },
  render:  { label: 'render',  color: '#6fd98e' },
  toc:     { label: 'toc',     color: '#ffb766' },
  search:  { label: 'search',  color: '#ff8ac6' },
  boot:    { label: 'boot',    color: '#9ec0ff' },
  custom:  { label: 'custom',  color: '#aaa' },
}

export const usePerfStore = defineStore('perf', () => {
  // In-memory ring buffer of timings. Capped to avoid unbounded growth.
  const entries = ref([])
  const enabled = ref(true)
  const MAX = 400

  function record(name, category, durationMs, meta = {}) {
    if (!enabled.value) return null
    const entry = {
      id: ++_id,
      name: String(name).slice(0, 200),
      category: PERF_CATEGORIES[category] ? category : 'custom',
      durationMs: Math.round(durationMs * 100) / 100,
      meta,
      ts: Date.now(),
    }
    // Prepend — newest first. Keep buffer bounded.
    entries.value = [entry, ...entries.value].slice(0, MAX)
    return entry
  }

  // Measure an async function (preserves return + throws).
  async function time(name, category, fn, meta = {}) {
    const start = performance.now()
    try {
      return await fn()
    } finally {
      record(name, category, performance.now() - start, meta)
    }
  }

  // Measure a sync function.
  function timeSync(name, category, fn, meta = {}) {
    const start = performance.now()
    try {
      return fn()
    } finally {
      record(name, category, performance.now() - start, meta)
    }
  }

  // Manual mark/measure pair (for code paths that aren't functions).
  //   const stop = perf.start('toc expand')
  //   ... do work ...
  //   stop()  // records the entry
  function start(name, category = 'custom', meta = {}) {
    const t0 = performance.now()
    return (extraMeta = {}) =>
      record(name, category, performance.now() - t0, { ...meta, ...extraMeta })
  }

  function clear() {
    entries.value = []
  }

  function toggle() {
    enabled.value = !enabled.value
    if (enabled.value) record('perf tracking enabled', 'custom', 0)
  }

  return { entries, enabled, record, time, timeSync, start, clear, toggle }
})
