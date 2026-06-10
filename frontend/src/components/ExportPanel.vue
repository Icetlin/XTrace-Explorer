<template>
  <transition name="export-slide">
    <div v-if="selection.length" class="export-panel">
      <span class="export-count">{{ selection.length }} selected</span>
      <button class="export-btn" @click="copyMarkdown">{{ copied ? '✓ copied' : 'copy MD' }}</button>
      <button class="export-btn export-btn--dl" @click="downloadMarkdown">↓ .md</button>
      <button class="export-clear" @click="store.clearSelection()" title="Clear selection">✕</button>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useTraceStore } from '../stores/trace'
import { usePerfTrack } from '../perfTrack'

const store = useTraceStore()
usePerfTrack('ExportPanel', { category: 'render' })
const selection = computed(() => store.selection)
const copied = ref(false)

function sigSource(sig) {
  if (!sig) return null
  for (const { namespace, label } of store.appNamespaces) {
    if (sig.startsWith(namespace)) return label || namespace.replace(/\\+$/, '').split('\\').pop()
  }
  if (sig.startsWith('Symfony\\')) return 'sf'
  const parts = sig.split('\\')
  if (parts.length < 2) return null
  const bundle = parts.find(p => p.endsWith('Bundle'))
  if (bundle) return bundle.replace(/Bundle$/, '')
  return parts[0] || null
}

// ClassName->method without namespace
function shortSig(sig) {
  if (!sig) return '?'
  const sep = Math.max(sig.lastIndexOf('->'), sig.lastIndexOf('::'))
  if (sep === -1) return sig
  return sig.slice(0, sep).split('\\').pop() + sig.slice(sep)
}

// fav badges for a sig+args text
function favBadges(sig, args) {
  const text = [sig, ...(args || [])].join(' ')
  const matches = store.matchFavourites(text)
  if (!matches.length) return ''
  return ' ' + matches.map(m => `[${m.label || m.pattern}]`).join(' ')
}

function buildMarkdown() {
  const tab = store.currentTab
  const lines = []

  // ── Request / Response header ──
  const req = tab?.request
  const res = tab?.response
  if (req) {
    const time = req.started_at ? req.started_at.match(/\d{2}:\d{2}:\d{2}/)?.[0] : ''
    lines.push(`# ${req.method || 'GET'} ${req.host || ''}${req.uri}${req.query ? '?' + req.query : ''}  →  ${res?.status ?? '?'}${time ? '  [' + time + ']' : ''}`)
    lines.push('')
    lines.push('## Request')
    if (req.content_type) lines.push(`- Content-Type: \`${req.content_type}\``)
    if (req.remote_addr)  lines.push(`- IP: \`${req.remote_addr}\``)
    if (req.cookies && Object.keys(req.cookies).length) {
      const ck = Object.entries(req.cookies).map(([k, v]) => `\`${k}=${v.length > 30 ? v.slice(0, 30) + '…' : v}\``).join('  ')
      lines.push(`- Cookies: ${ck}`)
    }
    lines.push('')
  }
  if (res) {
    lines.push('## Response')
    if (res.status)   lines.push(`- Status: \`${res.status}\``)
    if (res.location) lines.push(`- Location: \`${res.location}\``)
    if (res.cookies?.length) {
      const ck = res.cookies.map(c => `\`${c.name}${c.value ? '=' + c.value : ''}\``).join('  ')
      lines.push(`- Set-Cookie: ${ck}`)
    }
    lines.push('')
  }

  // ── Selected trace nodes — rendered as a tree ──
  const sorted = [...selection.value].sort((a, b) => a.line_no - b.line_no)
  if (!sorted.length) return lines.join('\n').trimEnd()

  lines.push('## Trace')
  lines.push('')

  // Group items by event → listener bucket.
  // breadcrumb layout:
  //   event item:    breadcrumb=[]
  //   listener item: breadcrumb=[{event}]
  //   call item:     breadcrumb=[{event}, {listener}, ...ancestors]
  const buckets = new Map() // key=`${eventSig}::${listenerLineNo}` → { eventSig, listenerSig, listenerLineNo, listenerSrc, items[] }

  for (const item of sorted) {
    const crumbs = item.breadcrumb || []

    let eventSig, listenerSig, listenerLineNo
    if (item.type === 'event') {
      eventSig      = item.sig
      listenerSig   = null
      listenerLineNo = null
    } else if (item.type === 'listener') {
      eventSig      = crumbs[0]?.sig ?? '(unknown event)'
      listenerSig   = item.sig
      listenerLineNo = item.line_no
    } else {
      // call: crumbs = [event, listener, ...ancestors]
      eventSig      = crumbs[0]?.sig ?? '(unknown event)'
      listenerSig   = crumbs[1]?.sig ?? null
      listenerLineNo = crumbs[1]?.line_no ?? null
    }

    const bucketKey = `${eventSig}::${listenerLineNo ?? '_'}`
    if (!buckets.has(bucketKey)) {
      buckets.set(bucketKey, { eventSig, listenerSig, listenerLineNo, items: [] })
    }
    buckets.get(bucketKey).items.push(item)
  }

  // Collect unique events in order
  const seenEvents = []
  const eventBuckets = new Map() // eventSig → [bucket, ...]
  for (const bucket of buckets.values()) {
    if (!eventBuckets.has(bucket.eventSig)) {
      seenEvents.push(bucket.eventSig)
      eventBuckets.set(bucket.eventSig, [])
    }
    eventBuckets.get(bucket.eventSig).push(bucket)
  }

  for (const eventSig of seenEvents) {
    lines.push(`### ${eventSig}`)
    lines.push('')

    const eventBucketList = eventBuckets.get(eventSig)
    // Sort listener buckets by listenerLineNo (nulls first for event-only items)
    eventBucketList.sort((a, b) => (a.listenerLineNo ?? -1) - (b.listenerLineNo ?? -1))

    let firstBucket = true
    for (const bucket of eventBucketList) {
      const listenerItems = bucket.items.filter(i => i.type === 'listener')
      const callItems     = bucket.items.filter(i => i.type === 'call')
      const eventItems    = bucket.items.filter(i => i.type === 'event')

      // Pure event-only bucket (no listener)
      if (!bucket.listenerSig) {
        for (const _ of eventItems) { /* event header already printed above */ }
        continue
      }

      if (!firstBucket) lines.push('')
      firstBucket = false

      // Listener header line
      const src    = sigSource(bucket.listenerSig)
      const srcTag = src ? ` *(${src})*` : ''
      const badges = listenerItems.length ? favBadges(bucket.listenerSig, []) : ''
      lines.push(`- **${shortSig(bucket.listenerSig)}**${srcTag}${badges}  \`${bucket.listenerLineNo?.toLocaleString() ?? ''}\``)

      // Call nodes as a tree under this listener
      if (callItems.length) {
        // Determine per-call ancestor depth: crumbs = [event, listener, ...ancestors]
        // ancestor depth = crumbs.length - 2; 0 means direct child of listener
        const maxDepth = Math.max(...callItems.map(i => Math.max(0, (i.breadcrumb?.length ?? 2) - 2)))

        for (let ci = 0; ci < callItems.length; ci++) {
          const item      = callItems[ci]
          const nextItem  = callItems[ci + 1] ?? null
          const crumbs    = item.breadcrumb || []
          const depth     = Math.max(0, crumbs.length - 2)
          const nextDepth = nextItem ? Math.max(0, (nextItem.breadcrumb?.length ?? 2) - 2) : -1

          // Build tree prefix: each level contributes 2 chars
          // Use └─ for last child at that depth, ├─ otherwise
          const isLast = nextDepth < depth
          const connector = isLast ? '└─' : '├─'
          const indent    = depth === 0 ? '  ' : '  ' + '    '.repeat(depth - 1) + '    '
          const prefix    = `${indent}${connector} `

          const args      = item.args?.length ? ' ' + item.args.map(a => `\`${a}\``).join(' ') : ''
          const filePart  = item.file ? ` *${item.file}*` : ''
          const badge     = favBadges(item.sig, item.args)
          const callSrc   = sigSource(item.sig)
          const callSrcTag = callSrc ? ` *(${callSrc})*` : ''
          const lineNo    = `  \`${item.line_no.toLocaleString()}\``

          lines.push(`${prefix}\`${shortSig(item.sig)}\`${callSrcTag}${args}${badge}${filePart}${lineNo}`)
        }
      }
    }

    lines.push('')
  }

  return lines.join('\n').trimEnd()
}

async function copyMarkdown() {
  const md = buildMarkdown()
  await navigator.clipboard.writeText(md)
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

function downloadMarkdown() {
  const md = buildMarkdown()
  const tab = store.currentTab
  const name = (tab?.name || 'trace').replace(/\.xt$/, '') + '-export.md'
  const blob = new Blob([md], { type: 'text/markdown' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = name; a.click()
  URL.revokeObjectURL(url)
}
</script>

<style scoped>
.export-panel {
  position: fixed;
  bottom: 36px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  background: rgba(14, 20, 36, 0.92);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid rgba(60, 90, 140, 0.4);
  border-radius: 10px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.6);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  z-index: 100;
}

.export-count {
  color: #5a7aaa;
  font-weight: 600;
  padding-right: 4px;
}

.export-btn {
  background: rgba(50, 80, 130, 0.3);
  border: 1px solid rgba(60, 100, 160, 0.35);
  color: #7aaad8;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  border-radius: 5px;
  padding: 3px 10px;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
}
.export-btn:hover { background: rgba(60, 100, 160, 0.45); color: #aad0ff; }
.export-btn--dl { color: #6a9a70; border-color: rgba(60, 120, 70, 0.35); background: rgba(40, 80, 50, 0.25); }
.export-btn--dl:hover { background: rgba(50, 100, 60, 0.4); color: #90c890; }

.export-clear {
  background: none;
  border: none;
  color: #3a3a55;
  font-size: 12px;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 4px;
  transition: color 0.1s, background 0.1s;
  line-height: 1;
}
.export-clear:hover { color: #cc6060; background: rgba(140, 40, 40, 0.15); }

.export-slide-enter-active { transition: all 0.18s cubic-bezier(0.34, 1.56, 0.64, 1); }
.export-slide-leave-active { transition: all 0.14s ease-in; }
.export-slide-enter-from { opacity: 0; transform: translateX(-50%) translateY(12px); }
.export-slide-leave-to   { opacity: 0; transform: translateX(-50%) translateY(12px); }
</style>
