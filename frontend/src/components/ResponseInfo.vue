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

    <span class="bar-toggle">{{ expanded ? '▾' : '▸' }}</span>

    <!-- Expanded cookies -->
    <div v-if="expanded" class="bar-detail">
      <template v-if="res.cookies?.length">
        <span class="bar-detail-section">set-cookie</span>
        <span
          v-for="c in res.cookies"
          :key="c.name"
          class="res-cookie"
          :title="c.value ? `${c.name}=${c.value}` : c.name"
        >{{ c.name }}<span v-if="c.value" class="res-cookie-val">={{ c.value }}</span></span>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  res: { type: Object, default: null },
})

const expanded = ref(false)

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
</style>
