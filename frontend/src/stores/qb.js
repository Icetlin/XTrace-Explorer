import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

/**
 * State for the QbPage — the new full-page "DB queries" workspace.
 *
 * The page has two data sources:
 *   1. Trace (always available) — sql.json produced by TraceParser, with
 *      heuristic QB chains. This is what the legacy modal SqlPage uses.
 *   2. Profiler (only when profiler+ is enabled and a snapshot is linked) —
 *      structured per-query data with full backtraces from the Symfony
 *      WebProfilerBundle, persisted in DB by ProfilerController.
 *
 * `mode` decides which source is rendered:
 *   - "trace"      : trace only (no profiler config, or no linked snapshot)
 *   - "profiler"   : snapshot is linked, render its analysis
 *   - "loading"    : mid-fetch
 *   - "error"      : last fetch failed
 */
export const useQbStore = defineStore('qb', () => {
  // ── Backend snapshot state ──
  const snapshot = ref(null)        // {id, token, base_url, status, total_queries, total_ms, ...}
  const analysis = ref(null)        // {total, total_ms, n_plus_one, callers, slowest, backtrace, ...}
  const queries = ref([])           // [{n, sql, time, time_ms, params, caller, backtrace}, ...]
  const profilerConfig = ref(null)  // {enabled, usable, base_url, ...}
  const settingsOpen = ref(false)
  const loading = ref(false)
  const error = ref(null)

  // View options
  const viewMode = ref('grouped')   // 'flat' | 'grouped' | 'tree'
  const filter = ref('')
  const groupDupes = ref(true)

  const mode = computed(() => {
    if (loading.value) return 'loading'
    if (error.value) return 'error'
    // Profiler+ data is only relevant when the user has the feature enabled.
    // When disabled we always fall back to trace-based heuristics.
    const enabled = !!profilerConfig.value?.enabled
    if (enabled && snapshot.value) return 'profiler'
    return 'trace'
  })

  const totalQueries = computed(() => {
    if (mode.value === 'profiler') return snapshot.value?.total_queries ?? 0
    return traceQueries.value.length
  })

  const totalMs = computed(() => {
    if (mode.value === 'profiler') return snapshot.value?.total_ms ?? 0
    return traceQueries.value.reduce((acc, q) => acc + (q.time_ms || 0), 0)
  })

  /**
   * Symfony's DB profiler only attaches full backtraces when the target
   * app has `profiling_collect_backtrace: '%kernel.debug%'` uncommented in
   * `config/packages/doctrine.yaml`. When this flag is true, the panel
   * parsed fine — totals, groups, N+1 are real — but the per-query
   * backtrace arrays are empty, so we can't attribute queries to call
   * sites or build the call-graph tree.
   *
   * The QbPage shows a one-line banner explaining how to fix it on the
   * target app, instead of silently rendering an empty backtrace panel.
   */
  const backtraceMissing = computed(() => {
    return mode.value === 'profiler' && analysis.value?.backtrace?.missing === true
  })
  const backtraceMissingReason = computed(() => {
    return analysis.value?.backtrace?.missing_reason ?? null
  })

  /**
   * Build a call-graph from profiler backtraces.
   *
   * Each query has a full backtrace. From it we extract only `is_src` frames
   * (our project's code, not vendor). These app-frames form a chain:
   *   deepest (next to SQL, e.g. UserRepository->findByAccountOwner)
   *   ...
   *   topmost  (entry point, e.g. WorkspaceController->index)
   *
   * We collapse identical chains into a DAG:
   *   - node = "Class->method"
   *   - edge parent -> child means "parent called child"
   *   - SQL hangs off the deepest node as a leaf
   *
   * Roots are nodes with no callers (entry points).
   */
  const callGraph = computed(() => {
    if (mode.value !== 'profiler') return { nodes: new Map(), roots: [] }
    return buildCallGraph(queries.value)
  })

  // ── Trace fallback (loaded once, cached) ──
  const traceFileId = ref(null)
  const traceQueries = ref([])

  async function loadTraceQueries(fileId) {
    // Always reload — cache-by-fileId is dangerous because toggling
    // Profiler+ can wipe the array, and we don't want a stale empty cache
    // to keep us stuck on "No SQL queries" until the next page reload.
    traceFileId.value = fileId
    try {
      const { data } = await axios.get(`/api/sql/${fileId}`)
      traceQueries.value = Array.isArray(data) ? data : (data?.queries ?? [])
    } catch (e) {
      traceQueries.value = []
    }
  }

  // ── Profiler+ actions ──
  async function loadProfilerConfig() {
    try {
      const { data } = await axios.get('/api/profiler/status')
      profilerConfig.value = data
    } catch {
      profilerConfig.value = null
    }
  }
  // Alias used in QbPage — same call, clearer name.
  const loadProfilerStatus = loadProfilerConfig

  async function loadSnapshot(fileId) {
    // If Profiler+ is off, never touch the snapshot — QbPage falls back to
    // trace-based heuristics on its own. This is the key fix for the bug
    // where toggling off didn't actually restore the old behaviour.
    if (!fileId) {
      snapshot.value = null
      analysis.value = null
      queries.value = []
      return
    }
    const enabled = !!profilerConfig.value?.enabled
    if (!enabled) {
      snapshot.value = null
      analysis.value = null
      queries.value = []
      return
    }
    loading.value = true
    error.value = null
    try {
      const { data } = await axios.get(`/api/profiler/analysis/${fileId}`)
      if (data.snapshot) {
        snapshot.value = data.snapshot
        analysis.value = data.analysis
        queries.value = data.queries ?? []
      } else {
        snapshot.value = null
        analysis.value = null
        queries.value = []
      }
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function findAuto(fileId) {
    loading.value = true
    error.value = null
    try {
      const { data } = await axios.post(`/api/profiler/find/${fileId}`, {})
      if (!data.ok) {
        error.value = data.error || 'auto-find failed'
        return false
      }
      await loadSnapshot(fileId)
      return true
    } catch (e) {
      error.value = e.message
      return false
    } finally {
      loading.value = false
    }
  }

  async function linkManual(fileId, tokenOrUrl) {
    loading.value = true
    error.value = null
    try {
      const { data } = await axios.post(`/api/profiler/link/${fileId}`, { token: tokenOrUrl })
      if (!data.ok) {
        error.value = data.error || 'manual link failed'
        return false
      }
      await loadSnapshot(fileId)
      return true
    } catch (e) {
      error.value = e.message
      return false
    } finally {
      loading.value = false
    }
  }

  async function unlink(fileId) {
    try {
      await axios.delete(`/api/profiler/link/${fileId}`)
      snapshot.value = null
      analysis.value = null
      queries.value = []
    } catch (e) {
      error.value = e.message
    }
  }

  async function refresh(fileId) {
    loading.value = true
    error.value = null
    try {
      const { data } = await axios.post(`/api/profiler/refresh/${fileId}`)
      if (!data.ok) {
        error.value = data.error || 'refresh failed'
      }
      await loadSnapshot(fileId)
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  function openSettings() { settingsOpen.value = true }
  function closeSettings() { settingsOpen.value = false }
  function setViewMode(m) {
    if (['flat', 'grouped', 'tree'].includes(m)) viewMode.value = m
  }

  return {
    // state
    snapshot, analysis, queries, profilerConfig, settingsOpen, loading, error,
    viewMode, filter, groupDupes,
    // computed
    mode, totalQueries, totalMs, callGraph,
    // trace fallback
    traceQueries, loadTraceQueries,
    // profiler actions
    loadProfilerConfig, loadProfilerStatus, loadSnapshot,
    findAuto, linkManual, unlink, refresh,
    openSettings, closeSettings,
    setViewMode,
  }
})

/**
 * Build a DAG of "Class->method" nodes with SQL queries hanging off the
 * deepest node of each backtrace chain.
 *
 * Algorithm:
 *   1. For each query, take only the app-code (is_src) frames from its
 *      backtrace, in order from deepest to topmost.
 *   2. The deepest frame becomes the leaf node; the SQL hangs off it.
 *   3. Each consecutive pair (parent, child) creates an edge parent→child.
 *   4. Roots are nodes with no callers.
 *   5. Sub-totals (query count, total ms) are aggregated up the tree at
 *      render time (see QbCallGraph.vue).
 *
 * Returned shape:
 *   {
 *     nodes: Map<callKey, {
 *       call, class, method, file, hostPath, line,
 *       queries: [{n, sql, time_ms}],
 *       callers: [callKey],     // methods that called this one
 *       callees: [callKey],     // methods that this one called
 *     }>,
 *     roots: [callKey],         // entry points — render these
 *   }
 */
function buildCallGraph(queries) {
  const nodes = new Map()

  const ensure = (f) => {
    const key = `${f.class}->${f.method}`
    let n = nodes.get(key)
    if (!n) {
      n = {
        call: key,
        class: f.class,
        method: f.method,
        file: f.file ?? null,
        hostPath: f.host_path ?? null,
        line: f.line ?? null,
        queries: [],
        callers: new Set(),
        callees: new Set(),
      }
      nodes.set(key, n)
    }
    return n
  }

  for (const q of queries) {
    const bt = q.backtrace || []
    // Only our code, only complete frames.
    const appFrames = bt.filter(f => f.is_src && f.class && f.method)
    if (appFrames.length === 0) continue

    // Deepest frame = the one that triggered the SQL (e.g. Repository method).
    const leaf = ensure(appFrames[0])
    leaf.queries.push({
      n: q.n,
      sql: q.sql,
      time_ms: q.time_ms ?? 0,
      time: q.time ?? '',
    })

    // Connect frames into a chain: appFrames[0] was called by appFrames[1],
    // appFrames[1] was called by appFrames[2], etc.
    for (let i = 0; i < appFrames.length - 1; i++) {
      const child = ensure(appFrames[i])
      const parent = ensure(appFrames[i + 1])
      child.callers.add(parent.call)
      parent.callees.add(child.call)
    }
  }

  // Set → array for serialisation / v-for stability.
  const nodesArr = new Map()
  for (const [k, n] of nodes) {
    nodesArr.set(k, {
      ...n,
      callers: [...n.callers],
      callees: [...n.callees],
    })
  }

  // Roots: nodes that nobody in this graph called. (External callers like
  // kernel.handle are not in the graph because they have no `is_src` frame.)
  const allKeys = new Set(nodesArr.keys())
  const roots = []
  for (const [k, n] of nodesArr) {
    if (n.callers.length === 0) roots.push(k)
  }

  return { nodes: nodesArr, roots }
}