<template>
  <canvas ref="canvas" class="desert-bg" />
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const canvas = ref(null)

function draw(ctx, W, H) {
  ctx.clearRect(0, 0, W, H)

  const HZ = H * 0.28  // horizon high up

  // Sky — dark reddish-grey, nearly black at top
  const sky = ctx.createLinearGradient(0, 0, 0, HZ)
  sky.addColorStop(0,   '#080608')
  sky.addColorStop(0.6, '#0d0a0c')
  sky.addColorStop(1,   '#1a1010')
  ctx.fillStyle = sky
  ctx.fillRect(0, 0, W, HZ)

  // Ground — dark rust/ochre plain
  const ground = ctx.createLinearGradient(0, HZ, 0, H)
  ground.addColorStop(0,   '#1a1008')
  ground.addColorStop(0.15,'#150e06')
  ground.addColorStop(1,   '#0a0804')
  ctx.fillStyle = ground
  ctx.fillRect(0, HZ, W, H - HZ)

  // Far ridge — low, jagged, very dark
  drawRidge(ctx, W, HZ, [
    [0.00, 0.92], [0.04, 0.78], [0.07, 0.85], [0.11, 0.70], [0.14, 0.80],
    [0.18, 0.65], [0.22, 0.75], [0.26, 0.60], [0.30, 0.72], [0.34, 0.58],
    [0.38, 0.68], [0.42, 0.55], [0.46, 0.66], [0.50, 0.52], [0.54, 0.63],
    [0.58, 0.48], [0.62, 0.60], [0.66, 0.72], [0.70, 0.55], [0.74, 0.68],
    [0.78, 0.50], [0.82, 0.62], [0.86, 0.74], [0.90, 0.58], [0.94, 0.70],
    [0.98, 0.80], [1.00, 0.92],
  ], '#120c0a')

  // Mid buttes — angular flat-topped mesas, mars-style
  drawButtes(ctx, W, HZ, [
    { x: 0.02,  w: 0.11, h: 0.55, flat: 0.35, color: '#16100c' },
    { x: 0.20,  w: 0.07, h: 0.40, flat: 0.45, color: '#140e0a' },
    { x: 0.31,  w: 0.15, h: 0.65, flat: 0.30, color: '#181208' },
    { x: 0.52,  w: 0.06, h: 0.35, flat: 0.50, color: '#14100a' },
    { x: 0.63,  w: 0.18, h: 0.70, flat: 0.25, color: '#1a1208' },
    { x: 0.84,  w: 0.10, h: 0.50, flat: 0.40, color: '#160e08' },
    { x: 0.94,  w: 0.08, h: 0.42, flat: 0.42, color: '#14100a' },
  ])

  // Foreground rocks — darkest, closest, low
  drawRocks(ctx, W, HZ, [
    { x: 0.00, w: 0.08, h: 0.18 },
    { x: 0.15, w: 0.05, h: 0.12 },
    { x: 0.38, w: 0.07, h: 0.15 },
    { x: 0.58, w: 0.04, h: 0.10 },
    { x: 0.72, w: 0.09, h: 0.20 },
    { x: 0.91, w: 0.06, h: 0.14 },
  ])

  // Dust haze at horizon — very subtle warm glow
  const haze = ctx.createLinearGradient(0, HZ - 6, 0, HZ + 12)
  haze.addColorStop(0,   'rgba(0,0,0,0)')
  haze.addColorStop(0.5, 'rgba(60, 30, 10, 0.12)')
  haze.addColorStop(1,   'rgba(0,0,0,0)')
  ctx.fillStyle = haze
  ctx.fillRect(0, HZ - 6, W, 18)

  // Stars
  drawStars(ctx, W, HZ * 0.9)
}

function drawRidge(ctx, W, HZ, pts, color) {
  ctx.beginPath()
  ctx.moveTo(0, HZ)
  for (const [rx, ry] of pts) {
    ctx.lineTo(rx * W, HZ - ry * HZ * 0.4)
  }
  ctx.lineTo(W, HZ)
  ctx.closePath()
  ctx.fillStyle = color
  ctx.fill()
}

function drawButtes(ctx, W, HZ, buttes) {
  for (const b of buttes) {
    const x = b.x * W
    const w = b.w * W
    const totalH = b.h * HZ * 0.85
    const flatW = b.flat  // fraction of w that is flat top
    const flatStart = x + w * (1 - flatW) / 2
    const flatEnd   = x + w * (1 + flatW) / 2
    const top = HZ - totalH

    ctx.beginPath()
    ctx.moveTo(x, HZ)
    // left steep slope with slight ledge
    ctx.lineTo(x + w * 0.06, top + totalH * 0.30)
    ctx.lineTo(x + w * 0.10, top + totalH * 0.12)
    ctx.lineTo(flatStart, top)
    // flat top
    ctx.lineTo(flatEnd, top)
    // right slope
    ctx.lineTo(x + w * 0.90, top + totalH * 0.12)
    ctx.lineTo(x + w * 0.94, top + totalH * 0.30)
    ctx.lineTo(x + w, HZ)
    ctx.closePath()
    ctx.fillStyle = b.color
    ctx.fill()

    // subtle cliff-face vertical crack lines
    ctx.strokeStyle = 'rgba(0,0,0,0.25)'
    ctx.lineWidth = 0.5
    for (let i = 1; i <= 2; i++) {
      const cx = x + w * (0.25 * i)
      ctx.beginPath()
      ctx.moveTo(cx, top + totalH * 0.15)
      ctx.lineTo(cx + w * 0.02, top + totalH * 0.6)
      ctx.stroke()
    }
  }
}

function drawRocks(ctx, W, HZ, rocks) {
  ctx.fillStyle = '#0e0a06'
  for (const r of rocks) {
    const x = r.x * W
    const w = r.w * W
    const h = r.h * HZ
    ctx.beginPath()
    ctx.moveTo(x, HZ)
    ctx.lineTo(x + w * 0.15, HZ - h * 0.6)
    ctx.lineTo(x + w * 0.35, HZ - h)
    ctx.lineTo(x + w * 0.65, HZ - h * 0.9)
    ctx.lineTo(x + w * 0.85, HZ - h * 0.5)
    ctx.lineTo(x + w, HZ)
    ctx.closePath()
    ctx.fill()
  }
}

function drawStars(ctx, W, H) {
  let s = 12345
  function rand() {
    s = (s * 16807) % 2147483647
    return (s - 1) / 2147483646
  }
  for (let i = 0; i < 160; i++) {
    const x = rand() * W
    const y = rand() * H
    const r = rand() * 0.6 + 0.15
    const a = rand() * 0.35 + 0.08
    ctx.globalAlpha = a
    ctx.fillStyle = '#c8b8a8'
    ctx.beginPath()
    ctx.arc(x, y, r, 0, Math.PI * 2)
    ctx.fill()
  }
  ctx.globalAlpha = 1
}

onMounted(() => {
  const c = canvas.value
  const ctx = c.getContext('2d')
  let ro

  function resize() {
    c.width  = c.offsetWidth
    c.height = c.offsetHeight
    draw(ctx, c.width, c.height)
  }

  ro = new ResizeObserver(resize)
  ro.observe(c)
  resize()

  onUnmounted(() => ro.disconnect())
})
</script>

<style scoped>
.desert-bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  display: block;
  z-index: 0;
}
</style>
