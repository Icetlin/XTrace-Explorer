<template>
  <div v-if="res" class="res-bar" :class="{ 'res-bar--expanded': expanded }" @click="expanded = !expanded">
    <span class="bar-label bar-label--res">RES</span>
    <span class="res-status" :class="statusClass">{{ res.status ?? '?' }}</span>
    <template v-if="res.location">
      <span class="res-sep">→</span>
      <span class="res-location">{{ res.location }}</span>
    </template>
    <template v-if="res.cookies?.length && !expanded">
      <span class="res-sep">·</span>
      <span class="res-cookies-label">set-cookie</span>
      <span class="res-cookies-count">{{ res.cookies.length }}</span>
    </template>

    <button
      class="res-copy"
      :title="copied ? 'Copied!' : 'Copy response + cookie lifecycle as text'"
      @click.stop="copyRes"
    >{{ copied ? '✓ copied' : '⧉ copy' }}</button>

    <span class="bar-toggle">{{ expanded ? '▾' : '▸' }}</span>

    <!-- Expanded cookies: name + lifetime + causal source (created → emitted) -->
    <div v-if="expanded" class="bar-detail" @click.stop>
      <template v-if="res.cookies?.length">
        <span class="bar-detail-section">set-cookie</span>
        <div class="res-cookie-list">
          <div v-for="c in res.cookies" :key="c.name" class="res-cookie-row">
            <div class="res-cookie-head">
              <span
                class="res-cookie"
                :title="c.value ? `${c.name}=${c.value}` : c.name"
              >{{ c.name }}<span v-if="c.value" class="res-cookie-val">={{ c.value }}</span></span>
              <span v-if="c.lifetime" class="res-cookie-meta res-cookie-life">{{ c.lifetime }}</span>
              <span v-if="c.samesite" class="res-cookie-meta">{{ c.samesite }}</span>
              <span v-if="c.origin" class="res-cookie-origin" :class="`res-cookie-origin--${c.origin}`" title="relative to the incoming request">{{ c.origin }}</span>
            </div>
            <!-- Full lifecycle timeline: gate → created → emitted → cleared. -->
            <div v-if="c.steps?.length" class="res-cookie-flow">
              <template v-for="(s, i) in c.steps" :key="i">
                <span v-if="i > 0" class="res-flow-arrow">→</span>
                <button class="res-loc" :class="{ 'res-loc--nofile': !s.file_abs }" :title="s.file ? `show ${s.file}` : ''" @click.stop="openCode(s)">
                  <span class="res-loc-tag" :class="`res-loc-tag--${s.kind}`">{{ s.kind }}</span><span class="res-loc-body">{{ s.phase }}<span v-if="s.listener" class="res-cookie-listener"> · {{ s.listener }}</span><span v-if="s.file" class="res-loc-file"> · {{ s.file }}</span></span>
                </button>
              </template>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useTraceStore } from '../stores/trace'

const props = defineProps({
  res: { type: Object, default: null },
})

const store = useTraceStore()
const expanded = ref(false)
const copied = ref(false)

// Serialise the response + full cookie lifecycle to plain text so it can be
// pasted elsewhere (e.g. shared for analysis).
function buildResText() {
  const r = props.res
  if (!r) return ''
  const lines = [`RES ${r.status ?? '?'}${r.location ? ' → ' + r.location : ''}`]
  for (const c of r.cookies || []) {
    const meta = [c.lifetime, c.samesite, c.origin].filter(Boolean).join(', ')
    lines.push(`${c.name}${c.value ? '=' + c.value : ''}${meta ? '  (' + meta + ')' : ''}`)
    for (const s of c.steps || []) {
      const loc = [s.phase, s.listener, s.file].filter(Boolean).join(' · ')
      lines.push(`    ${s.kind}: ${loc}`)
    }
  }
  return lines.join('\n')
}

async function copyRes() {
  try {
    await navigator.clipboard.writeText(buildResText())
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch { /* clipboard blocked — no-op */ }
}

// Open the exact PHP site (e.g. RememberMe/ResponseListener.php:43) in the CodeView
// panel. file_abs is the container path ("/abs/path.php:LINE") which /api/source can
// read directly — CodeView derives the file + line hint from it.
function openCode(site) {
  if (!site?.file_abs) return
  store.setCodeNode({
    file_abs: site.file_abs,
    line_no: site.line_no ?? null,
    sig: '',
    depth: 0,
  })
}

const statusClass = computed(() => {
  const s = props.res?.status
  if (!s) return ''
  if (s < 300) return 'res-status--2xx'
  if (s < 400) return 'res-status--3xx'
  if (s < 500) return 'res-status--4xx'
  return 'res-status--5xx'
})
</script>

<style scoped>
.res-bar {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  cursor: pointer;
  gap: 6px;
  padding: 4px 20px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  background: rgba(255, 255, 255, 0.01);
  border-bottom: 1px solid rgba(255, 255, 255, 0.03);
  color: #4a5a70;
  flex-shrink: 0;
  position: relative;
  z-index: 1;
  overflow: hidden;
  min-height: 28px;
}
.res-bar--expanded {
  flex-wrap: wrap;
  overflow: visible;
}

.bar-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.08em;
  border-radius: 3px;
  padding: 1px 5px;
  flex-shrink: 0;
}
.bar-label--res {
  color: #3a4a2a;
  background: rgba(50, 80, 30, 0.25);
}

.bar-toggle {
  color: #2a2a3a;
  font-size: 10px;
  cursor: pointer;
  margin-left: auto;
  flex-shrink: 0;
  padding: 1px 6px;
  border-radius: 3px;
  transition: color 0.1s, background 0.1s;
}
.bar-toggle:hover { color: #7a9acc; background: rgba(255,255,255,0.04); }

.res-copy {
  margin-left: auto;
  font-family: inherit;
  font-size: 9px;
  letter-spacing: 0.04em;
  color: #4a6a55;
  background: rgba(50, 80, 50, 0.12);
  border: 1px solid rgba(60, 90, 55, 0.22);
  border-radius: 3px;
  padding: 1px 6px;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.1s, color 0.1s;
}
.res-copy:hover { color: #7cc090; background: rgba(60, 110, 65, 0.18); }

.bar-detail {
  flex-basis: 100%;
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  padding-top: 5px;
  border-top: 1px solid rgba(255,255,255,0.03);
  margin-top: 3px;
  align-items: center;
}
.bar-detail-section {
  font-size: 9.5px;
  font-weight: 600;
  letter-spacing: 0.06em;
  color: #2a3a2a;
  flex-shrink: 0;
}

.res-status {
  font-weight: 700;
  font-size: 10px;
  letter-spacing: 0.05em;
  padding: 1px 6px;
  border-radius: 3px;
  flex-shrink: 0;
}
.res-status--2xx { color: #5a9a50; background: rgba(60, 120, 40, 0.15); }
.res-status--3xx { color: #8a8a40; background: rgba(120, 110, 30, 0.15); }
.res-status--4xx { color: #9a6040; background: rgba(140, 70, 40, 0.15); }
.res-status--5xx { color: #9a4040; background: rgba(140, 50, 40, 0.15); }

.res-sep      { color: #252535; flex-shrink: 0; }
.res-location { color: #6a7a5a; font-size: 10.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.res-cookies-label { color: #383848; font-size: 10px; flex-shrink: 0; }
.res-cookies-count {
  font-size: 10px;
  color: #3a4a30;
  background: rgba(50, 70, 30, 0.15);
  border: 1px solid rgba(50, 70, 30, 0.2);
  border-radius: 3px;
  padding: 0 5px;
  flex-shrink: 0;
}
.res-cookie {
  color: #5a6a50;
  font-size: 10px;
  background: rgba(50, 70, 40, 0.12);
  border: 1px solid rgba(50, 70, 40, 0.2);
  border-radius: 3px;
  padding: 0 5px;
  white-space: nowrap;
}
.res-cookie-val { color: #3a4a30; }

.res-cookie-list {
  flex-basis: 100%;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.res-cookie-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 3px 0 3px 7px;
  border-left: 2px solid rgba(60, 90, 50, 0.18);
}
.res-cookie-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.res-cookie-meta {
  font-size: 9.5px;
  color: #55684a;
  background: rgba(60, 80, 40, 0.1);
  border-radius: 3px;
  padding: 0 4px;
  flex-shrink: 0;
}
.res-cookie-life { color: #7a8a4a; }
.res-cookie-origin {
  font-size: 8.5px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  border-radius: 2px;
  padding: 0 4px;
  flex-shrink: 0;
}
.res-cookie-origin--new     { color: #3a8a5a; background: rgba(40, 120, 70, 0.18); }
.res-cookie-origin--rotated { color: #4a7ab0; background: rgba(50, 90, 150, 0.18); }
.res-cookie-origin--resent  { color: #6a7080; background: rgba(80, 90, 110, 0.16); }
.res-cookie-origin--removed { color: #a05a4a; background: rgba(150, 60, 40, 0.18); }
.res-cookie-origin--cleared { color: #9a7a3a; background: rgba(150, 110, 40, 0.18); }

.res-cookie-flow {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 5px;
}
.res-loc {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-family: inherit;
  font-size: 10px;
  color: #6a7a5a;
  background: rgba(255, 255, 255, 0.02);
  border: 1px solid rgba(80, 100, 60, 0.18);
  border-radius: 3px;
  padding: 1px 6px 1px 3px;
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s;
}
.res-loc:hover { background: rgba(90, 120, 70, 0.12); border-color: rgba(90, 130, 70, 0.4); }
.res-loc-tag {
  font-size: 8px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  border-radius: 2px;
  padding: 0 4px;
  flex-shrink: 0;
}
.res-loc-tag--gate    { color: #7a5aa0; background: rgba(110, 70, 160, 0.2); }
.res-loc-tag--created { color: #7a6a2a; background: rgba(120, 100, 30, 0.2); }
.res-loc-tag--emitted { color: #3a8a5a; background: rgba(40, 110, 70, 0.2); }
.res-loc-tag--set     { color: #5a6a70; background: rgba(70, 90, 110, 0.18); }
.res-loc-tag--cleared { color: #a05a4a; background: rgba(150, 60, 40, 0.2); }
.res-loc-body { color: #6a7a5a; }
.res-flow-arrow { color: #4a5a44; font-size: 11px; flex-shrink: 0; }
.res-cookie-listener { color: #8a9a6a; }
.res-loc-file { color: #7a8560; }
.res-loc--nofile { cursor: default; opacity: 0.7; }
.res-loc--nofile:hover { background: rgba(255, 255, 255, 0.02); border-color: rgba(80, 100, 60, 0.18); }
</style>
