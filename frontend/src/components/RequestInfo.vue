<template>
  <div v-if="req" class="req-bar" :class="{ 'req-bar--expanded': expanded }" @click="expanded = !expanded">
    <span class="bar-label">REQ</span>
    <span class="req-method" :class="`req-method--${(req.method || 'GET').toLowerCase()}`">{{ req.method || '?' }}</span>
    <span class="req-uri">{{ req.host ? req.host : '' }}{{ req.uri }}</span>
    <span v-if="req.query" class="req-query">?{{ req.query }}</span>
    <span class="req-sep">·</span>
    <span class="req-time">{{ formatTime(req.started_at || req.request_time) }}</span>
    <span v-if="req.content_type" class="req-sep">·</span>
    <span v-if="req.content_type" class="req-ct">{{ shortContentType(req.content_type) }}</span>
    <span v-if="req.remote_addr" class="req-sep">·</span>
    <span v-if="req.remote_addr" class="req-ip">{{ req.remote_addr }}</span>
    <span v-if="req.referer" class="req-sep">·</span>
    <span v-if="req.referer" class="req-ref" :title="req.referer">↩ {{ shortReferer(req.referer) }}</span>

    <!-- Cookies collapsed summary -->
    <template v-if="cookieEntries.length && !expanded">
      <span class="req-sep">·</span>
      <span class="req-cookies-label">cookies</span>
      <span class="req-cookies-count">{{ cookieEntries.length }}</span>
    </template>

    <span class="bar-toggle">{{ expanded ? '▾' : '▸' }}</span>

    <!-- Expanded cookies -->
    <div v-if="expanded" class="bar-detail">
      <template v-if="cookieEntries.length">
        <span class="bar-detail-section">cookies</span>
        <span
          v-for="[k, v] in cookieEntries"
          :key="k"
          class="req-cookie"
          :title="`${k}=${v}`"
        >{{ k }}<span class="req-cookie-val">={{ truncate(v, 28) }}</span></span>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  req: { type: Object, default: null },
})

const expanded = ref(false)

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
  flex-wrap: nowrap;
  cursor: pointer;
  gap: 6px;
  padding: 4px 20px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  background: rgba(255, 255, 255, 0.025);
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  color: #7888a8;
  flex-shrink: 0;
  position: relative;
  z-index: 1;
  overflow: hidden;
  min-height: 28px;
}
.req-bar.req-bar--expanded {
  flex-wrap: wrap;
  overflow: visible;
}

.bar-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: #6888a8;
  background: rgba(50, 80, 120, 0.25);
  border-radius: 3px;
  padding: 1px 5px;
  flex-shrink: 0;
}

.bar-toggle {
  color: #5a6888;
  font-size: 10px;
  cursor: pointer;
  margin-left: auto;
  flex-shrink: 0;
  padding: 1px 6px;
  border-radius: 3px;
  transition: color 0.1s, background 0.1s;
}
.bar-toggle:hover { color: #90b8e8; background: rgba(255,255,255,0.05); }

.bar-detail {
  flex-basis: 100%;
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  padding-top: 5px;
  border-top: 1px solid rgba(255,255,255,0.04);
  margin-top: 3px;
  align-items: center;
}

.bar-detail-section {
  font-size: 9.5px;
  font-weight: 600;
  letter-spacing: 0.06em;
  color: #5a7858;
  flex-shrink: 0;
}

.req-method {
  font-weight: 700;
  font-size: 10px;
  letter-spacing: 0.05em;
  padding: 1px 6px;
  border-radius: 3px;
  flex-shrink: 0;
}
.req-method--post { color: #90c060; background: rgba(80, 140, 40, 0.2); }
.req-method--get  { color: #70a8d0; background: rgba(50, 110, 170, 0.2); }
.req-method--put  { color: #c8a050; background: rgba(160, 110, 30, 0.2); }
.req-method--delete { color: #c06060; background: rgba(160, 60, 50, 0.2); }
.req-method--patch  { color: #a878c8; background: rgba(110, 80, 150, 0.2); }

.req-uri { color: #a0b0c8; }
.req-query { color: #8090a8; }
.req-sep { color: #404860; }
.req-time { color: #6878a0; }
.req-ct { color: #6880a0; }
.req-ip { color: #587090; }

.req-cookies-label { color: #607880; font-size: 10px; flex-shrink: 0; }
.req-cookies-count {
  font-size: 10px;
  color: #70a070;
  background: rgba(60, 100, 60, 0.18);
  border: 1px solid rgba(60, 100, 60, 0.25);
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}
.req-cookie {
  color: #78a078;
  font-size: 10px;
  background: rgba(60, 100, 60, 0.14);
  border: 1px solid rgba(60, 100, 60, 0.22);
  border-radius: 3px;
  padding: 0 5px;
}
.req-cookie-val { color: #507868; }

.req-ref { color: #5878a0; font-size: 10px; }
</style>
