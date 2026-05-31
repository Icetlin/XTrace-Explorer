<template>
  <div v-if="req" class="req-bar">
    <span class="req-method" :class="`req-method--${(req.method || 'GET').toLowerCase()}`">
      {{ req.method || '?' }}
    </span>
    <span class="req-uri">{{ req.host ? req.host : '' }}{{ req.uri }}</span>
    <span v-if="req.query" class="req-query">?{{ req.query }}</span>
    <span class="req-sep">·</span>
    <span class="req-time">{{ formatTime(req.started_at || req.request_time) }}</span>
    <span v-if="req.content_type" class="req-sep">·</span>
    <span v-if="req.content_type" class="req-ct">{{ shortContentType(req.content_type) }}</span>
    <span v-if="req.remote_addr" class="req-sep">·</span>
    <span v-if="req.remote_addr" class="req-ip">{{ req.remote_addr }}</span>

    <!-- Cookies -->
    <template v-if="cookieEntries.length">
      <span class="req-sep">·</span>
      <span class="req-cookies-label">cookies</span>
      <span
        v-for="[k, v] in cookieEntries"
        :key="k"
        class="req-cookie"
        :title="`${k}=${v}`"
      >{{ k }}<span class="req-cookie-val">={{ truncate(v, 18) }}</span></span>
    </template>

    <!-- Referer -->
    <template v-if="req.referer">
      <span class="req-sep">·</span>
      <span class="req-ref" :title="req.referer">↩ {{ shortReferer(req.referer) }}</span>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  req: { type: Object, default: null },
})

const cookieEntries = computed(() =>
  props.req?.cookies ? Object.entries(props.req.cookies) : []
)

function formatTime(val) {
  if (!val) return ''
  // "2026-05-30 20:34:36.703988" or unix timestamp float
  if (typeof val === 'string') {
    // "2026-05-30 20:34:36.703988" → "20:34:36"
    const m = val.match(/\d{2}:\d{2}:\d{2}/)
    return m ? m[0] : val
  }
  if (typeof val === 'number') {
    return new Date(val * 1000).toLocaleTimeString()
  }
  return ''
}

function shortContentType(ct) {
  if (!ct) return ''
  if (ct.includes('json')) return 'JSON'
  if (ct.includes('form')) return 'form'
  if (ct.includes('html')) return 'HTML'
  return ct.split(';')[0].split('/').pop()
}

function shortReferer(ref) {
  try {
    const u = new URL(ref)
    return u.pathname || u.hostname
  } catch {
    return ref.slice(0, 30)
  }
}

function truncate(s, n) {
  return s && s.length > n ? s.slice(0, n) + '…' : s
}
</script>

<style scoped>
.req-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  padding: 5px 20px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  background: rgba(255, 255, 255, 0.02);
  border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  color: #4a5a70;
  flex-shrink: 0;
  position: relative;
  z-index: 1;
}

.req-method {
  font-weight: 700;
  font-size: 10px;
  letter-spacing: 0.05em;
  padding: 1px 6px;
  border-radius: 3px;
  flex-shrink: 0;
}
.req-method--post { color: #7a9a50; background: rgba(80, 120, 40, 0.15); }
.req-method--get  { color: #5a8aaa; background: rgba(50, 100, 150, 0.15); }
.req-method--put  { color: #a08040; background: rgba(140, 100, 30, 0.15); }
.req-method--delete { color: #9a5050; background: rgba(140, 60, 50, 0.15); }
.req-method--patch  { color: #8a60a0; background: rgba(100, 70, 130, 0.15); }

.req-uri { color: #8a9aaa; }
.req-query { color: #6a7a8a; }
.req-sep { color: #252535; }
.req-time { color: #4a5060; }
.req-ct { color: #4a5a6a; }
.req-ip { color: #3a4a5a; }

.req-cookies-label { color: #383848; font-size: 10px; }
.req-cookie {
  color: #5a6a5a;
  font-size: 10px;
  background: rgba(60, 80, 50, 0.12);
  border: 1px solid rgba(60, 80, 50, 0.2);
  border-radius: 3px;
  padding: 0 5px;
}
.req-cookie-val { color: #3a4a38; }

.req-ref { color: #3a4a5a; font-size: 10px; }
</style>
