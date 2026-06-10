import { defineStore } from 'pinia'
import { ref, computed, shallowRef, markRaw } from 'vue'
import axios from 'axios'

export const useTraceStore = defineStore('trace', () => {
  // All known files from DB
  const files = ref([])

  // openTabs: array of { fileId, name, status, toc, totalLines, annotations, progress }
  const openTabs = ref([])
  const activeTabFileId = ref(null)

  const favourites = ref([])
  const pollingIds = new Set() // fileIds currently being polled
  const listenerFilters = ref([])
  const eventFilters = ref([])
  const appNamespaces = ref([]) // [{namespace, label}]
  const pathMapping = ref({ local: '', docker: '', project: '' }) // local↔docker path mapping for IDE integration

  const currentTab = computed(() =>
    openTabs.value.find(t => t.fileId === activeTabFileId.value) ?? null
  )
  const currentFile = computed(() => currentTab.value
    ? { file_id: currentTab.value.fileId, name: currentTab.value.name, status: currentTab.value.status, progress: currentTab.value.progress }
    : null
  )
  const toc = computed(() => currentTab.value?.toc ?? [])
  const totalLines = computed(() => currentTab.value?.totalLines ?? 0)
  const annotations = computed(() => currentTab.value?.annotations ?? [])

  async function loadFiles() {
    const { data } = await axios.get('/api/files')
    files.value = data
  }

  function getOrCreateTab(fileId, name) {
    let tab = openTabs.value.find(t => t.fileId === fileId)
    if (!tab) {
      tab = { fileId, name: name || String(fileId), status: 'pending', toc: [], totalLines: 0, annotations: [], progress: 0, favScan: {} }
      openTabs.value = [...openTabs.value, tab]
    }
    return tab
  }

  async function selectFile(fileId, name) {
    const { data } = await axios.get(`/api/status/${fileId}`)
    const tab = getOrCreateTab(fileId, data.name || name)
    tab.status = data.status
    tab.progress = data.progress || 0
    activeTabFileId.value = fileId
    if (data.status === 'ready' && !tab.toc.length) {
      // Critical: open tab as soon as toc/meta/annotations are loaded.
      await Promise.all([
        loadToc(fileId),
        loadMeta(fileId),
        loadAnnotations(fileId),
      ])
      // Non-critical: scanFavourites is heavy on first call (cold cache, big trace
      // can take 30-180s). Don't block tab opening — run in background, show
      // a "scanning" indicator via tab.scanning (set inside scanFavourites).
      scanFavourites(fileId).catch(() => {})
    } else if (data.status === 'parsing' || data.status === 'pending') {
      startPolling(fileId)
    }
  }

  function switchToTab(fileId) {
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) {
      activeTabFileId.value = fileId
      // Re-scan if favScan is empty but tab is ready (e.g. opened before favourites were added)
      if (tab.status === 'ready' && (!tab.favScan || !Object.keys(tab.favScan).length)) {
        scanFavourites(fileId)
      }
    }
  }

  function closeTab(fileId) {
    openTabs.value = openTabs.value.filter(t => t.fileId !== fileId)
    if (activeTabFileId.value === fileId) {
      activeTabFileId.value = openTabs.value[0]?.fileId ?? null
    }
  }

  async function pollStatus(fileId) {
    const { data } = await axios.get(`/api/status/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) {
      tab.status = data.status
      tab.progress = data.progress || 0
    }
    if (data.status === 'ready') {
      pollingIds.delete(fileId)
      await Promise.all([loadToc(fileId), loadMeta(fileId), loadAnnotations(fileId)])
      // Heavy scan in background, non-blocking
      scanFavourites(fileId).catch(() => {})
      return true
    }
    if (data.status === 'error') {
      pollingIds.delete(fileId)
      return true
    }
    return false
  }

  async function reparse(fileId) {
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) { tab.status = 'pending'; tab.progress = 0; tab.toc = []; tab.totalLines = 0 }
    await axios.post(`/api/reparse/${fileId}`)
    startPolling(fileId)
  }

  function startPolling(fileId) {
    if (pollingIds.has(fileId)) return
    pollingIds.add(fileId)
    const interval = setInterval(async () => {
      const done = await pollStatus(fileId)
      if (done) clearInterval(interval)
    }, 2000)
  }

  async function loadToc(fileId) {
    const { data } = await axios.get(`/api/toc/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.toc = markRaw(data)
  }

  async function loadMeta(fileId) {
    const { data } = await axios.get(`/api/meta/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) {
      tab.totalLines = data.total_lines || 0
      tab.request = data.request || null
      tab.response = data.response || null
    }
  }

  async function loadAnnotations(fileId) {
    const { data } = await axios.get(`/api/annotations/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.annotations = markRaw(data)
  }

  async function fetchPath(fileId, lineNo, fromLine = 0) {
    const params = { line_no: lineNo }
    if (fromLine > 0) params.from_line = fromLine
    const { data } = await axios.get(`/api/path/${fileId}`, { params })
    return data // [{line_no, depth, sig}, ...] root-first
  }

  // In-memory caches — keyed by stable strings, capped at 200 entries each (LRU-lite)
  const _childrenCache = new Map()  // `${fileId}:${lineNo}:${depth}` → data
  const _sourceCache   = new Map()  // `${file}:${hint}` → data
  const _varCtxCache   = new Map()  // `${fileId}:${lineNo}:${depth}` → data
  const CACHE_MAX = 200
  function _cacheSet(map, key, value) {
    if (map.size >= CACHE_MAX) map.delete(map.keys().next().value)
    map.set(key, value)
  }

  async function fetchChildren(fileId, lineNo, depth, raw = false) {
    const key = `${fileId}:${lineNo}:${depth}`
    if (!raw && _childrenCache.has(key)) return _childrenCache.get(key)
    const params = { line_no: lineNo, depth }
    if (raw) params.raw = 1
    const { data } = await axios.get(`/api/children/${fileId}`, { params })
    if (!raw) _cacheSet(_childrenCache, key, data)
    return data
  }

  // Synchronous: returns file_abs of first child if already in cache, else null
  function getListenerFileAbs(fileId, lineNo, depth) {
    const key = `${fileId}:${lineNo}:${depth}`
    const cached = _childrenCache.get(key)
    if (!cached) return null
    const first = (cached.children || []).find(c => c.file_abs)
    return first?.file_abs ?? null
  }

  async function fetchAppCalls(fileId, eventIdx) {
    const { data } = await axios.get(`/api/app-calls/${fileId}/${eventIdx}`)
    return data
  }

  async function fetchVarContext(fileId, lineNo, depth) {
    const key = `${fileId}:${lineNo}:${depth}`
    if (_varCtxCache.has(key)) return _varCtxCache.get(key)
    try {
      const { data } = await axios.get(`/api/var-context/${fileId}`, { params: { line_no: lineNo, depth } })
      _cacheSet(_varCtxCache, key, data)
      return data
    } catch {
      return null
    }
  }

  // Extract PHP class FQCN and method name from a trace sig like
  // "App\\Repository\\Foo->getBaz". Returns { class, method } or null.
  // Returns null class/method for constructors so CodeView shows the
  // enclosing class body rather than just the ctor.
  function extractSigParts(sig) {
    if (!sig) return { class: null, method: null }
    const m = sig.match(/^(.+?)(?:->|::)(\w+)$/)
    if (!m) return { class: null, method: null }
    const klass = m[1]
    const method = m[2]
    if (method === '__construct') return { class: klass, method: null }
    return { class: klass, method }
  }

  function extractMethodName(sig) {
    return extractSigParts(sig).method
  }

  function extractClassName(sig) {
    return extractSigParts(sig).class
  }

  async function fetchSource(file, hint, method = null, klass = null) {
    // Class+method disambiguates the cache key — same (file, hint) but
    // different (class, method) pair can resolve to different source files
    // (e.g. Repository::method called from a Service shows the Repository,
    // not the Service).
    const key = `${file}:${hint}:${method ?? ''}:${klass ?? ''}`
    if (_sourceCache.has(key)) return _sourceCache.get(key)
    try {
      const params = { file, hint }
      if (method) params.method = method
      if (klass) params.class = klass
      const { data } = await axios.get('/api/source', { params })
      _cacheSet(_sourceCache, key, data)
      return data
    } catch (e) {
      // Surface the server's error message so the user sees what went wrong
      // (e.g. "File not found on server: foo.php" instead of a silent failure).
      const msg = e?.response?.data?.error || e?.message || 'fetch failed'
      console.warn('fetchSource', file, hint, method, klass, msg)
      return { error: msg, file, lines: {}, fn_from: 0, fn_to: 0 }
    }
  }

  async function scanFavourites(fileId) {
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.scanning = true
    try {
      // First call: trigger scan (returns 202+scanning if cold, 200+data if warm)
      let res = await axios.get(`/api/favourites-scan/${fileId}`)
      if (res.status === 200) {
        if (tab) tab.favScan = markRaw(res.data)
        return
      }
      // 202: scanning. Poll status until ready (or timeout 10 min).
      const deadline = Date.now() + 10 * 60 * 1000
      while (Date.now() < deadline) {
        await new Promise(r => setTimeout(r, 2000))
        res = await axios.get(`/api/favourites-scan/${fileId}/status`)
        if (res.status === 200) {
          if (tab) tab.favScan = markRaw(res.data)
          return
        }
      }
    } catch (e) {
      console.warn('scanFavourites failed', e)
    } finally {
      if (tab) tab.scanning = false
    }
  }

  async function loadFavourites() {
    const { data } = await axios.get('/api/favourites')
    favourites.value = data
    if (data.length) rescanAllTabs().catch(() => {})  // fire-and-forget
  }

  async function addFavourite(pattern, label = null) {
    await axios.post('/api/favourites', { pattern, label })
    await loadFavourites()
    await rescanAllTabs()
  }

  async function deleteFavourite(id) {
    await axios.delete(`/api/favourites/${id}`)
    favourites.value = favourites.value.filter(f => f.id !== id)
    await rescanAllTabs()
  }

  async function rescanAllTabs() {
    const readyTabs = openTabs.value.filter(t => t.status === 'ready')
    // Fire in parallel, don't await — UI shows "scanning" via tab.scanning
    await Promise.allSettled(readyTabs.map(t => scanFavourites(t.fileId)))
  }

  function matchFavourites(text) {
    if (!text || !favourites.value.length) return []
    return favourites.value.filter(f => f.pattern && text.includes(f.pattern))
  }

  function favMatchesInRange(fileId, fromLine, toLine) {
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (!tab?.favScan) return []
    const seen = new Set()
    const result = []
    for (const listeners of Object.values(tab.favScan)) {
      for (const hits of Object.values(listeners)) {
        for (const h of hits) {
          if (h.line_no >= fromLine && h.line_no <= toLine && !seen.has(h.pattern)) {
            seen.add(h.pattern)
            result.push({ pattern: h.pattern, label: h.label })
          }
        }
      }
    }
    return result
  }

  async function fetchObject(fileId, lineNo, argIdx) {
    const { data } = await axios.get(`/api/object/${fileId}`, { params: { line_no: lineNo, arg_idx: argIdx } })
    return data
  }

  async function fetchFindObject(fileId, lineNo, className) {
    const { data } = await axios.get(`/api/find-object/${fileId}`, { params: { line_no: lineNo, class: className } })
    return data
  }

  async function fetchArray(fileId, lineNo, argIdx) {
    const { data } = await axios.get(`/api/array/${fileId}`, { params: { line_no: lineNo, arg_idx: argIdx } })
    return data
  }

  async function expandItem(fileId, raw) {
    const { data } = await axios.post(`/api/expand-item/${fileId}`, { raw })
    return data
  }

  async function search(fileId, query) {
    if (query.length < 2) return []
    const { data } = await axios.get(`/api/search/${fileId}`, { params: { q: query } })
    return data
  }

  async function fetchTimings(fileId, limit = 50) {
    const { data } = await axios.get(`/api/timings/${fileId}`, { params: { limit } })
    return data
  }

  const _settingsCache = ref({})

  async function loadSettings() {
    try {
      const { data } = await axios.get('/api/settings')
      listenerFilters.value = data.listener_filters || []
      eventFilters.value = data.event_filters || []
      appNamespaces.value = data.app_namespaces || []
      pathMapping.value = { local: data.project_path || '', docker: data.docker_project_path || '', project: data.ide_project_name || '' }
      _settingsCache.value = data
      return data
    } catch { return {} }
  }

  async function saveSettings(payload) {
    const { data } = await axios.post('/api/settings', payload)
    listenerFilters.value = payload.listener_filters || []
    eventFilters.value = payload.event_filters || []
    appNamespaces.value = payload.app_namespaces || []
    pathMapping.value = { local: payload.project_path || '', docker: payload.docker_project_path || '', project: payload.ide_project_name || '' }
    _settingsCache.value = { ..._settingsCache.value, ...payload }
    return data
  }

  async function addListenerFilter(pattern) {
    if (listenerFilters.value.includes(pattern)) return
    const current = _settingsCache.value
    await saveSettings({
      traces_host_path: current.traces_host_path || '',
      project_path: current.project_path || '',
      project_name: current.project_name || '',
      listener_filters: [...listenerFilters.value, pattern],
      event_filters: eventFilters.value,
    })
  }

  function isListenerFiltered(sig) {
    if (!listenerFilters.value.length || !sig) return false
    return listenerFilters.value.some(f => sig.includes(f))
  }

  async function addEventFilter(pattern) {
    if (eventFilters.value.includes(pattern)) return
    const current = _settingsCache.value
    await saveSettings({
      traces_host_path: current.traces_host_path || '',
      project_path: current.project_path || '',
      project_name: current.project_name || '',
      listener_filters: listenerFilters.value,
      event_filters: [...eventFilters.value, pattern],
    })
  }

  function isEventFiltered(name) {
    if (!eventFilters.value.length || !name) return false
    return eventFilters.value.some(f => name.includes(f))
  }

  async function addAnnotation(fileId, lineNo, text) {
    await axios.post(`/api/annotations/${fileId}`, { line_no: lineNo, text })
    await loadAnnotations(fileId)
  }

  async function deleteAnnotation(annotId) {
    await axios.delete(`/api/annotations/item/${annotId}`)
    if (currentTab.value) {
      currentTab.value.annotations = currentTab.value.annotations.filter(a => a.id !== annotId)
    }
  }

  // ── Selection for export ──
  // Each item: { type: 'event'|'listener'|'call', sig, line_no, args?, breadcrumb: [{sig,line_no}] }
  const selection = computed(() => currentTab.value?.selection ?? [])

  function toggleSelection(item) {
    const tab = currentTab.value
    if (!tab) return
    if (!tab.selection) tab.selection = []
    const idx = tab.selection.findIndex(s => s.line_no === item.line_no)
    if (idx !== -1) {
      tab.selection = tab.selection.filter((_, i) => i !== idx)
    } else {
      tab.selection = [...tab.selection, item]
    }
  }

  function clearSelection() {
    if (currentTab.value) currentTab.value.selection = []
  }

  function isSelected(lineNo) {
    return currentTab.value?.selection?.some(s => s.line_no === lineNo) ?? false
  }

  // ── Session persistence ──
  const STORAGE_KEY = 'xtrace-session'

  function persistSession() {
    const data = {
      tabs: openTabs.value.map(t => ({ fileId: t.fileId, name: t.name })),
      activeTabFileId: activeTabFileId.value,
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data))
  }

  async function restoreSession() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (!raw) return
      const { tabs, activeTabFileId: savedActive } = JSON.parse(raw)
      if (!tabs?.length) return
      // Load all tabs in parallel, then set the active one
      await Promise.all(tabs.map(t => selectFile(t.fileId, t.name)))
      if (savedActive && openTabs.value.find(t => t.fileId === savedActive)) {
        activeTabFileId.value = savedActive
      }
    } catch { /* ignore corrupt storage */ }
  }

  // Active code node for split view — set when user clicks any node with file_abs
  const activeCodeNode = ref(null)
  const activeCodeAncestorLineNos = ref(new Set())
  function setCodeNode(node, ancestorCrumbs = []) {
    activeCodeNode.value = node
    const nos = new Set(ancestorCrumbs.map(c => c.line_no))
    if (node?.line_no != null) nos.add(node.line_no)
    activeCodeAncestorLineNos.value = nos
  }
  function isCodeActive(lineNo) {
    return activeCodeAncestorLineNos.value.has(lineNo)
  }

  // The actual file path shown in CodeView (may differ from activeCodeNode.file_abs)
  const activeCodeFile = ref(null)
  function setActiveCodeFile(path) { activeCodeFile.value = path }

  // Hovered line for bidirectional tree↔code highlight
  const hoveredCodeLine = ref(null)  // absolute file path + line, e.g. "/src/Foo.php:42"
  function setHoveredCodeLine(fileAbsWithLine) { hoveredCodeLine.value = fileAbsWithLine }

  // ── Theme ──
  const theme = ref(localStorage.getItem('xtrace-theme') || 'dark')
  function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    localStorage.setItem('xtrace-theme', theme.value)
  }

  return {
    files, openTabs, activeTabFileId, currentTab, currentFile, toc, totalLines, annotations, favourites,
    listenerFilters, eventFilters, appNamespaces, pathMapping,
    loadFiles, selectFile, switchToTab, closeTab, pollStatus, startPolling, reparse,
    fetchChildren, fetchPath, fetchObject, fetchFindObject, fetchArray, expandItem, fetchSource, fetchVarContext, fetchAppCalls, getListenerFileAbs, search, fetchTimings, extractMethodName, extractClassName, extractSigParts,
    addAnnotation, deleteAnnotation,
    loadFavourites, addFavourite, deleteFavourite, matchFavourites, favMatchesInRange, scanFavourites,
    loadSettings, saveSettings, addListenerFilter, isListenerFiltered, addEventFilter, isEventFiltered,
    selection, toggleSelection, clearSelection, isSelected,
    persistSession, restoreSession,
    activeCodeNode, activeCodeAncestorLineNos, setCodeNode, isCodeActive,
    activeCodeFile, setActiveCodeFile,
    hoveredCodeLine, setHoveredCodeLine,
    theme, toggleTheme,
  }
})
