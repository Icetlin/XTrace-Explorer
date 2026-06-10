import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios'
import App from './App.vue'
import { usePerfStore } from './stores/perf'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.mount('#app')

// ── Frontend perf: track every axios call (client-side duration) ──
// Pinia is now installed, so the store is usable.
const perf = usePerfStore()

// Mark start on the request.
axios.interceptors.request.use(config => {
  config.metadata = { startTime: performance.now() }
  return config
})

// Measure total client-side time (includes network + browser + server response).
// Excludes the perf endpoint itself to avoid pollution.
axios.interceptors.response.use(
  response => {
    const url = response.config?.url || ''
    if (!url.includes('/api/timings')) {
      const dur = performance.now() - (response.config.metadata?.startTime ?? performance.now())
      const method = (response.config.method || 'get').toUpperCase()
      // Strip query string for compactness; keep path only.
      const path = url.split('?')[0]
      perf.record(`${method} ${path}`, 'api', dur, { status: response.status })
    }
    return response
  },
  error => {
    const url = error.config?.url || ''
    if (!url.includes('/api/timings')) {
      const dur = performance.now() - (error.config?.metadata?.startTime ?? performance.now())
      const method = (error.config?.method || 'get').toUpperCase()
      const path = url.split('?')[0]
      perf.record(`${method} ${path}`, 'api', dur, {
        status: error.response?.status ?? 0,
        error: true,
      })
    }
    return Promise.reject(error)
  }
)

// ── Page-load metrics (paint, DCL, load) from the browser's Performance API ──
// We schedule after a short delay so first-paint / FCP entries are populated,
// and use PerformanceObserver (more robust than getEntriesByType for paint timing).
function recordBootMetrics() {
  try {
    const nav = performance.getEntriesByType('navigation')[0]
    if (nav) {
      const dcl = nav.domContentLoadedEventEnd - nav.startTime
      const load = nav.loadEventEnd - nav.startTime
      const ttfb = nav.responseStart - nav.startTime
      perf.record(`page load (DCL ${Math.round(dcl)}ms / load ${Math.round(load)}ms)`, 'boot', load, {
        dcl: Math.round(dcl), load: Math.round(load), ttfb: Math.round(ttfb),
      })
    }
  } catch { /* navigation timing not supported */ }

  try {
    const paints = performance.getEntriesByType('paint')
    for (const p of paints) {
      const ms = Math.round(p.startTime)
      perf.record(`paint: ${p.name} (${ms}ms)`, 'render', ms, { name: p.name })
    }
  } catch { /* paint timing not supported */ }

  // Live LCP — record the final value when the user navigates / on visibility change.
  if (typeof PerformanceObserver !== 'undefined') {
    try {
      let lcpValue = 0
      const lcpObs = new PerformanceObserver(list => {
        for (const entry of list.getEntries()) {
          lcpValue = entry.startTime
        }
      })
      lcpObs.observe({ type: 'largest-contentful-paint', buffered: true })
      // Flush when tab is hidden, so we capture the final LCP.
      const flushLcp = () => {
        if (lcpValue > 0) {
          perf.record(`LCP (${Math.round(lcpValue)}ms)`, 'render', lcpValue)
          lcpValue = 0
        }
        document.removeEventListener('visibilitychange', flushLcp)
        lcpObs.disconnect()
      }
      document.addEventListener('visibilitychange', flushLcp, { once: true })
    } catch { /* LCP not supported */ }
  }
}

// Run after a tick so paint entries are available.
setTimeout(recordBootMetrics, 100)
