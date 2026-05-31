import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export const useTraceStore = defineStore('trace', () => {
  // All known files from DB
  const files = ref([])

  // openTabs: array of { fileId, name, status, toc, totalLines, annotations, progress }
  const openTabs = ref([])
  const activeTabFileId = ref(null)

  const favourites = ref([])

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
      await Promise.all([
        loadToc(fileId),
        loadMeta(fileId),
        loadAnnotations(fileId),
        scanFavourites(fileId),
      ])
    }
  }

  function switchToTab(fileId) {
    if (openTabs.value.find(t => t.fileId === fileId)) {
      activeTabFileId.value = fileId
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
      await Promise.all([loadToc(fileId), loadMeta(fileId), loadAnnotations(fileId)])
      return true
    }
    return false
  }

  async function loadToc(fileId) {
    const { data } = await axios.get(`/api/toc/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.toc = data
  }

  async function loadMeta(fileId) {
    const { data } = await axios.get(`/api/meta/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.totalLines = data.total_lines || 0
  }

  async function loadAnnotations(fileId) {
    const { data } = await axios.get(`/api/annotations/${fileId}`)
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.annotations = data
  }

  async function fetchPath(fileId, lineNo, fromLine = 0) {
    const params = { line_no: lineNo }
    if (fromLine > 0) params.from_line = fromLine
    const { data } = await axios.get(`/api/path/${fileId}`, { params })
    return data // [{line_no, depth, sig}, ...] root-first
  }

  async function fetchChildren(fileId, lineNo, depth, raw = false) {
    const params = { line_no: lineNo, depth }
    if (raw) params.raw = 1
    const { data } = await axios.get(`/api/children/${fileId}`, { params })
    return data
  }

  async function scanFavourites(fileId) {
    const { data } = await axios.get(`/api/favourites-scan/${fileId}`)
    // Store in the tab
    const tab = openTabs.value.find(t => t.fileId === fileId)
    if (tab) tab.favScan = data
  }

  async function loadFavourites() {
    const { data } = await axios.get('/api/favourites')
    favourites.value = data
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
    await Promise.all(readyTabs.map(t => scanFavourites(t.fileId)))
  }

  function matchFavourites(text) {
    if (!text || !favourites.value.length) return []
    return favourites.value.filter(f => f.pattern && text.includes(f.pattern))
  }

  async function fetchObject(fileId, lineNo, argIdx) {
    const { data } = await axios.get(`/api/object/${fileId}`, { params: { line_no: lineNo, arg_idx: argIdx } })
    return data
  }

  async function search(fileId, query) {
    if (query.length < 2) return []
    const { data } = await axios.get(`/api/search/${fileId}`, { params: { q: query } })
    return data
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

  return {
    files, openTabs, activeTabFileId, currentTab, currentFile, toc, totalLines, annotations, favourites,
    loadFiles, selectFile, switchToTab, closeTab, pollStatus,
    fetchChildren, fetchPath, fetchObject, search,
    addAnnotation, deleteAnnotation,
    loadFavourites, addFavourite, deleteFavourite, matchFavourites, scanFavourites,
  }
})
