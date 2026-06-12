<template>
  <transition name="summary-modal">
    <div v-if="visible" class="summary-modal-wrap" @click.self="onCancel">
      <div class="summary-modal" :class="{ 'summary-modal--loading': loading, 'summary-modal--err': !!error }">
        <div class="summary-modal__header">
          <div class="summary-modal__title-block">
            <span class="summary-modal__title">AI Summary</span>
            <span v-if="stats" class="summary-modal__stats">
              <span class="stat-pill">{{ stats.chars ?? 0 }} chars</span>
              <span class="stat-pill">{{ stats.events ?? 0 }} events</span>
              <span class="stat-pill">{{ stats.sql_queries ?? 0 }} SQL</span>
              <span v-if="stats.sql_n_plus_1" class="stat-pill stat-pill--warn">{{ stats.sql_n_plus_1 }} N+1</span>
              <span v-if="stats.annotations" class="stat-pill">{{ stats.annotations }} annot</span>
              <span v-if="truncated" class="stat-pill stat-pill--warn">truncated</span>
            </span>
          </div>
          <button class="summary-modal__close" :disabled="loading" @click="onCancel" title="Esc">✕</button>
        </div>

        <div v-if="error" class="summary-modal__err">{{ error }}</div>

        <div class="summary-modal__body">
          <div v-if="loading" class="summary-modal__loading">
            <span class="spinner" />
            Building summary…
          </div>
          <textarea
            v-else
            ref="textareaEl"
            v-model="text"
            class="summary-modal__textarea"
            spellcheck="false"
            placeholder="(empty summary)"
            @keydown.escape.stop="onCancel"
            @keydown.meta.enter.prevent="onCopy"
            @keydown.ctrl.enter.prevent="onCopy"
          />
        </div>

        <div class="summary-modal__footer">
          <div class="summary-modal__hint">
            Edit if you want · <kbd>⌘/Ctrl+Enter</kbd> to copy · <kbd>Esc</kbd> to close
          </div>
          <div class="summary-modal__actions">
            <button class="sm-btn sm-btn--ghost" :disabled="loading || !text" @click="onCancel">Close</button>
            <button
              class="sm-btn sm-btn--primary"
              :class="{ 'sm-btn--ok': copyFlash }"
              :disabled="loading || !text"
              @click="onCopy"
            >{{ copyFlash ? '✓ Copied' : 'Copy to clipboard' }}</button>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  error:   { type: String, default: null },
  text:    { type: String, default: '' },
  stats:   { type: Object, default: null },
  truncated: { type: Boolean, default: false },
})
const emit = defineEmits(['copy', 'cancel', 'update:text'])

const textareaEl = ref(null)
const copyFlash = ref(false)
let copyTimer = null

// Local editable copy so user can tweak before copying. Sync from prop on change.
const text = ref(props.text)
watch(() => props.text, (v) => { text.value = v })
watch(text, (v) => { if (v !== props.text) emit('update:text', v) })

// Auto-select all on open so Cmd+C works without a click after textarea focus.
watch(() => props.visible, async (open) => {
  if (open) {
    await nextTick()
    textareaEl.value?.focus()
    textareaEl.value?.select()
  } else {
    copyFlash.value = false
  }
})

async function onCopy() {
  if (!text.value) return
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text.value)
    } else {
      const ta = document.createElement('textarea')
      ta.value = text.value
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
    }
    copyFlash.value = true
    clearTimeout(copyTimer)
    copyTimer = setTimeout(() => { copyFlash.value = false }, 1500)
    emit('copy', text.value)
  } catch (e) {
    console.error('copy failed:', e)
  }
}

function onCancel() {
  if (props.loading) return
  emit('cancel')
}

onBeforeUnmount(() => clearTimeout(copyTimer))
</script>

<style scoped>
/* ── Layout ── */
.summary-modal-wrap {
  position: fixed;
  inset: 0;
  z-index: 350; /* above .float-modal-wrap (300) and FloatCtrl (200) */
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}

.summary-modal {
  background: rgba(8, 10, 22, 0.94);
  backdrop-filter: blur(28px);
  -webkit-backdrop-filter: blur(28px);
  border: 1px solid rgba(50, 65, 110, 0.5);
  border-radius: 14px;
  box-shadow: 0 8px 60px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.03);
  width: min(960px, calc(100vw - 40px));
  height: min(720px, calc(100vh - 80px));
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
html[data-theme="light"] .summary-modal {
  background: rgba(240, 244, 255, 0.97);
  border-color: rgba(140, 160, 220, 0.5);
  box-shadow: 0 8px 60px rgba(80, 100, 200, 0.18), 0 0 0 1px rgba(80, 100, 200, 0.06);
}

.summary-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 20px 12px;
  border-bottom: 1px solid rgba(40, 50, 90, 0.4);
  flex-shrink: 0;
}
html[data-theme="light"] .summary-modal__header {
  border-bottom-color: rgba(140, 160, 210, 0.35);
}

.summary-modal__title-block {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  flex: 1;
}
.summary-modal__title {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  color: rgba(140, 165, 215, 0.85);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}
html[data-theme="light"] .summary-modal__title {
  color: rgba(30, 60, 140, 0.8);
}
.summary-modal__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}
.stat-pill {
  font-family: 'JetBrains Mono', monospace;
  font-size: 9.5px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.05);
  color: rgba(150, 170, 210, 0.75);
  border: 1px solid rgba(80, 100, 160, 0.15);
  white-space: nowrap;
}
.stat-pill--warn {
  background: rgba(255, 160, 60, 0.13);
  color: #ffb766;
  border-color: rgba(255, 160, 60, 0.35);
}
html[data-theme="light"] .stat-pill {
  background: rgba(80, 110, 200, 0.08);
  color: rgba(30, 60, 140, 0.7);
  border-color: rgba(80, 110, 200, 0.18);
}
html[data-theme="light"] .stat-pill--warn {
  background: rgba(255, 130, 30, 0.13);
  color: #c06000;
  border-color: rgba(255, 130, 30, 0.3);
}

.summary-modal__close {
  background: none;
  border: none;
  color: rgba(80, 90, 135, 0.65);
  cursor: pointer;
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 5px;
  line-height: 1;
  transition: color 0.12s, background 0.12s;
}
.summary-modal__close:hover:not(:disabled) {
  color: rgba(210, 90, 90, 0.9);
  background: rgba(120, 20, 20, 0.2);
}
html[data-theme="light"] .summary-modal__close {
  color: rgba(60, 80, 150, 0.65);
}
.summary-modal__close:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.summary-modal__err {
  padding: 12px 20px;
  background: rgba(160, 50, 50, 0.18);
  color: #ff9b9b;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  border-bottom: 1px solid rgba(160, 50, 50, 0.3);
  flex-shrink: 0;
}

.summary-modal__body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 12px 20px;
}
.summary-modal__loading {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: rgba(140, 165, 215, 0.7);
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
}
.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: sm-spin 0.7s linear infinite;
  display: inline-block;
}
@keyframes sm-spin { to { transform: rotate(360deg); } }

.summary-modal__textarea {
  flex: 1;
  min-height: 0;
  background: rgba(0, 0, 0, 0.3);
  color: rgba(200, 215, 240, 0.92);
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 11.5px;
  line-height: 1.5;
  border: 1px solid rgba(50, 65, 110, 0.4);
  border-radius: 8px;
  padding: 12px 14px;
  outline: none;
  resize: none;
  white-space: pre;
  overflow: auto;
  tab-size: 2;
  width: 100%;
  box-sizing: border-box;
}
html[data-theme="light"] .summary-modal__textarea {
  background: rgba(255, 255, 255, 0.6);
  color: rgba(20, 40, 90, 0.92);
  border-color: rgba(140, 160, 220, 0.4);
}
.summary-modal__textarea:focus {
  border-color: rgba(80, 120, 200, 0.6);
}
.summary-modal__textarea::placeholder {
  color: rgba(120, 130, 160, 0.4);
}

.summary-modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 20px 14px;
  border-top: 1px solid rgba(40, 50, 90, 0.4);
  flex-shrink: 0;
  flex-wrap: wrap;
}
html[data-theme="light"] .summary-modal__footer {
  border-top-color: rgba(140, 160, 210, 0.35);
}
.summary-modal__hint {
  font-family: 'JetBrains Mono', monospace;
  font-size: 9.5px;
  color: rgba(120, 130, 160, 0.55);
}
.summary-modal__hint kbd {
  font-family: inherit;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(80, 100, 160, 0.2);
  border-radius: 3px;
  padding: 1px 5px;
  font-size: 9px;
  color: rgba(160, 180, 220, 0.85);
  margin: 0 2px;
}
html[data-theme="light"] .summary-modal__hint kbd {
  background: rgba(80, 110, 200, 0.08);
  border-color: rgba(80, 110, 200, 0.2);
  color: rgba(30, 60, 140, 0.85);
}
.summary-modal__actions {
  display: flex;
  gap: 6px;
}

.sm-btn {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 600;
  padding: 7px 14px;
  border-radius: 6px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
  letter-spacing: 0.03em;
}
.sm-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.sm-btn--ghost {
  background: transparent;
  color: rgba(150, 170, 210, 0.75);
  border-color: rgba(80, 100, 160, 0.3);
}
.sm-btn--ghost:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.05);
  color: rgba(200, 220, 255, 0.95);
}
html[data-theme="light"] .sm-btn--ghost {
  color: rgba(30, 60, 140, 0.7);
  border-color: rgba(80, 110, 200, 0.3);
}
html[data-theme="light"] .sm-btn--ghost:hover:not(:disabled) {
  background: rgba(80, 110, 200, 0.08);
  color: rgba(20, 50, 140, 0.95);
}
.sm-btn--primary {
  background: linear-gradient(180deg, rgba(60, 100, 200, 0.85), rgba(40, 80, 170, 0.85));
  color: #fff;
  border-color: rgba(80, 120, 220, 0.6);
  box-shadow: 0 1px 0 rgba(255, 255, 255, 0.1) inset, 0 1px 6px rgba(40, 80, 180, 0.4);
}
.sm-btn--primary:hover:not(:disabled) {
  background: linear-gradient(180deg, rgba(80, 120, 220, 0.95), rgba(50, 100, 200, 0.95));
}
.sm-btn--ok {
  background: linear-gradient(180deg, rgba(70, 200, 120, 0.85), rgba(50, 170, 100, 0.85)) !important;
  border-color: rgba(80, 220, 130, 0.6) !important;
}

/* ── Transition ── */
.summary-modal-enter-active {
  transition: opacity 0.18s ease;
}
.summary-modal-leave-active {
  transition: opacity 0.14s ease;
}
.summary-modal-enter-from, .summary-modal-leave-to {
  opacity: 0;
}
.summary-modal-enter-active .summary-modal,
.summary-modal-leave-active .summary-modal {
  transition: transform 0.18s cubic-bezier(0.34, 1.1, 0.64, 1);
}
.summary-modal-enter-from .summary-modal {
  transform: translateY(12px) scale(0.98);
}
.summary-modal-leave-to .summary-modal {
  transform: translateY(6px) scale(0.99);
}
</style>
