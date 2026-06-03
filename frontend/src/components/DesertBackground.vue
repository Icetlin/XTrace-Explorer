<template>
  <canvas ref="canvas" class="desert-bg" />
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useTraceStore } from '../stores/trace'

const canvas = ref(null)
const store = useTraceStore()

function draw(ctx, W, H) {
  const isLight = document.documentElement.getAttribute('data-theme') === 'light'
  ctx.clearRect(0, 0, W, H)

  if (isLight) {
    // Light theme — soft blue-white sky
    const skyGrad = ctx.createLinearGradient(0, 0, 0, H)
    skyGrad.addColorStop(0, '#dde8f8')
    skyGrad.addColorStop(1, '#f0f4fc')
    ctx.fillStyle = skyGrad
    ctx.fillRect(0, 0, W, H)
  } else {
    // Dark theme — deep night
    ctx.fillStyle = '#0a0a14'
    ctx.fillRect(0, 0, W, H)
    drawStars(ctx, W, H * 0.58)
  }

  // Single dune — smooth bezier hill
  const duneY = H * 0.72
  ctx.beginPath()
  ctx.moveTo(0, H)
  ctx.lineTo(0, duneY + H * 0.06)
  ctx.bezierCurveTo(W * 0.15, duneY + H * 0.04, W * 0.38, duneY - H * 0.08, W * 0.58, duneY)
  ctx.bezierCurveTo(W * 0.72, duneY + H * 0.05, W * 0.88, duneY + H * 0.03, W, duneY + H * 0.04)
  ctx.lineTo(W, H)
  ctx.closePath()

  if (isLight) {
    const duneGrad = ctx.createLinearGradient(0, duneY - H * 0.08, 0, H)
    duneGrad.addColorStop(0, '#c8d8f0')
    duneGrad.addColorStop(1, '#b8cce8')
    ctx.fillStyle = duneGrad
  } else {
    const duneGrad = ctx.createLinearGradient(0, duneY - H * 0.08, 0, H)
    duneGrad.addColorStop(0, '#0e0e1c')
    duneGrad.addColorStop(1, '#080810')
    ctx.fillStyle = duneGrad
  }
  ctx.fill()

  // Horizon glow
  const glow = ctx.createLinearGradient(0, duneY - 20, 0, duneY + 20)
  if (isLight) {
    glow.addColorStop(0,   'rgba(120,160,220,0)')
    glow.addColorStop(0.5, 'rgba(120,160,220,0.10)')
    glow.addColorStop(1,   'rgba(120,160,220,0)')
  } else {
    glow.addColorStop(0,   'rgba(30,40,80,0)')
    glow.addColorStop(0.5, 'rgba(30,40,80,0.07)')
    glow.addColorStop(1,   'rgba(30,40,80,0)')
  }
  ctx.fillStyle = glow
  ctx.fillRect(0, duneY - 20, W, 40)
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
    const a = rand() * 0.3 + 0.08
    ctx.globalAlpha = a
    ctx.fillStyle = '#b8d8b8'
    ctx.beginPath()
    ctx.arc(x, y, r, 0, Math.PI * 2)
    ctx.fill()
  }
  ctx.globalAlpha = 1
}

function _unused_drawScene(ctx, W, H) {
  const ox = W - 110
  const oy = H - 14
  ctx.save()
  ctx.translate(ox, oy)
  ctx.globalAlpha = 0.28

  const C = 'rgba(200,210,200,1)'  // single silhouette color

  ctx.fillStyle = C
  ctx.strokeStyle = C
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'

  // ── BUG silhouette — on its back, tilted, legs up ──
  ctx.save()
  ctx.translate(52, -6)
  ctx.rotate(0.25)

  // body oval
  ctx.beginPath(); ctx.ellipse(0, 0, 8, 11, 0, 0, Math.PI*2); ctx.fill()
  // head
  ctx.beginPath(); ctx.ellipse(0, -14, 5, 4, 0, 0, Math.PI*2); ctx.fill()
  // legs sticking up — 3 pairs
  ctx.lineWidth = 1.5
  ;[[-7,-2,-16,-10],[-7,3,-17,2],[-7,8,-14,14],[7,-2,16,-10],[7,3,17,2],[7,8,14,14]].forEach(([x1,y1,x2,y2])=>{
    ctx.beginPath(); ctx.moveTo(x1,y1); ctx.lineTo(x2,y2); ctx.stroke()
  })
  // antennae — limp/drooping
  ctx.lineWidth = 1.2
  ctx.beginPath(); ctx.moveTo(-3,-18); ctx.quadraticCurveTo(-10,-22,-8,-18); ctx.stroke()
  ctx.beginPath(); ctx.moveTo( 3,-18); ctx.quadraticCurveTo( 9,-24, 7,-19); ctx.stroke()
  ctx.restore()

  // ── CAT silhouette — sitting, profile, arm extended pressing bug ──

  // tail — sweeping curve
  ctx.lineWidth = 7
  ctx.beginPath()
  ctx.moveTo(6, 0)
  ctx.quadraticCurveTo(-28, -38, -2, -58)
  ctx.stroke()

  // body — rounded sitting blob
  ctx.beginPath()
  ctx.ellipse(10, -30, 26, 32, 0, 0, Math.PI*2)
  ctx.fill()

  // front leg — straight down pressing
  ctx.lineWidth = 10
  ctx.beginPath()
  ctx.moveTo(22, -14)
  ctx.lineTo(50, -8)
  ctx.stroke()

  // paw — rounded rectangle pressing the bug
  ctx.beginPath()
  ctx.ellipse(52, -6, 10, 6, -0.15, 0, Math.PI*2)
  ctx.fill()

  // head — circle
  ctx.beginPath()
  ctx.arc(4, -60, 20, 0, Math.PI*2)
  ctx.fill()

  // ears — two sharp triangles
  ctx.beginPath()
  ctx.moveTo(-10, -72); ctx.lineTo(-16, -86); ctx.lineTo(-2, -76)
  ctx.closePath(); ctx.fill()
  ctx.beginPath()
  ctx.moveTo(14, -74); ctx.lineTo(20, -88); ctx.lineTo(26, -76)
  ctx.closePath(); ctx.fill()

  // eye cutout — negative space (looking down at bug)
  // left eye
  ctx.globalCompositeOperation = 'destination-out'
  ctx.beginPath(); ctx.ellipse(-4, -60, 4, 5, 0.2, 0, Math.PI*2); ctx.fill()
  // right eye
  ctx.beginPath(); ctx.ellipse(10, -61, 4, 5, -0.2, 0, Math.PI*2); ctx.fill()
  ctx.globalCompositeOperation = 'source-over'

  ctx.restore()
}

function _unused_drawBug(ctx, W, H) {
  const s = 2.2
  const bx = W - 90
  const by = H - 30

  ctx.save()
  ctx.translate(bx, by)

  // shadow on ground
  ctx.globalAlpha = 0.08
  ctx.beginPath()
  ctx.ellipse(0, 8, 38 * s * 0.4, 6, 0, 0, Math.PI * 2)
  ctx.fillStyle = '#000'
  ctx.fill()

  ctx.globalAlpha = 1

  // ── LEGS (behind body) ──
  function leg(x1, y1, x2, y2, x3, y3) {
    ctx.beginPath()
    ctx.moveTo(x1 * s, y1 * s)
    ctx.quadraticCurveTo(x2 * s, y2 * s, x3 * s, y3 * s)
    ctx.strokeStyle = 'rgba(30,80,30,0.7)'
    ctx.lineWidth = 1.4
    ctx.stroke()
    // foot dot
    ctx.beginPath()
    ctx.arc(x3 * s, y3 * s, 1.2, 0, Math.PI * 2)
    ctx.fillStyle = 'rgba(40,90,40,0.6)'
    ctx.fill()
  }
  // left legs
  leg(-9, -2,  -16, -8,  -22, -12)
  leg(-9,  2,  -17,  2,  -24,   4)
  leg(-9,  7,  -16, 12,  -20,  17)
  // right legs
  leg( 9, -2,   16, -8,   22, -12)
  leg( 9,  2,   17,  2,   24,   4)
  leg( 9,  7,   16, 12,   20,  17)

  // ── ELYTRA (wing covers) ── 3D domed shape with gradient
  const elytraGrad = ctx.createRadialGradient(-3 * s, -6 * s, 1, 0, -2 * s, 14 * s)
  elytraGrad.addColorStop(0,   'rgba(80,160,80,0.85)')
  elytraGrad.addColorStop(0.4, 'rgba(40,110,45,0.9)')
  elytraGrad.addColorStop(1,   'rgba(10,45,15,0.95)')

  ctx.beginPath()
  ctx.ellipse(0, 0, 10 * s, 14 * s, 0, 0, Math.PI * 2)
  ctx.fillStyle = elytraGrad
  ctx.fill()

  // center line between wing covers
  ctx.beginPath()
  ctx.moveTo(0, -13 * s)
  ctx.lineTo(0,  12 * s)
  ctx.strokeStyle = 'rgba(10,40,10,0.5)'
  ctx.lineWidth = 0.8
  ctx.stroke()

  // highlight — top-left specular
  const hiGrad = ctx.createRadialGradient(-4 * s, -8 * s, 0, -4 * s, -8 * s, 7 * s)
  hiGrad.addColorStop(0,   'rgba(180,255,180,0.22)')
  hiGrad.addColorStop(1,   'rgba(180,255,180,0)')
  ctx.beginPath()
  ctx.ellipse(-2 * s, -7 * s, 5 * s, 4 * s, -0.5, 0, Math.PI * 2)
  ctx.fillStyle = hiGrad
  ctx.fill()

  // ── PRONOTUM (thorax shield) ──
  const proGrad = ctx.createRadialGradient(-2 * s, -16 * s, 0.5, 0, -15 * s, 7 * s)
  proGrad.addColorStop(0,   'rgba(90,170,90,0.9)')
  proGrad.addColorStop(0.5, 'rgba(45,115,50,0.92)')
  proGrad.addColorStop(1,   'rgba(12,50,18,0.95)')

  ctx.beginPath()
  ctx.ellipse(0, -14 * s, 7.5 * s, 5 * s, 0, 0, Math.PI * 2)
  ctx.fillStyle = proGrad
  ctx.fill()

  // pronotum highlight
  const phGrad = ctx.createRadialGradient(-2 * s, -16 * s, 0, -2 * s, -16 * s, 4 * s)
  phGrad.addColorStop(0,   'rgba(200,255,200,0.18)')
  phGrad.addColorStop(1,   'rgba(200,255,200,0)')
  ctx.beginPath()
  ctx.ellipse(-1.5 * s, -15.5 * s, 3.5 * s, 2.5 * s, -0.3, 0, Math.PI * 2)
  ctx.fillStyle = phGrad
  ctx.fill()

  // ── HEAD — facing us, slightly foreshortened ──
  const headGrad = ctx.createRadialGradient(-1.5 * s, -21 * s, 0.5, 0, -20 * s, 5.5 * s)
  headGrad.addColorStop(0,   'rgba(70,150,75,0.95)')
  headGrad.addColorStop(0.6, 'rgba(30,90,35,0.97)')
  headGrad.addColorStop(1,   'rgba(8,35,12,1)')

  ctx.beginPath()
  ctx.ellipse(0, -20 * s, 6 * s, 5 * s, 0, 0, Math.PI * 2)
  ctx.fillStyle = headGrad
  ctx.fill()

  // ── EYES — compound, bulging, staring at viewer ──
  function compoundEye(ex, ey) {
    // outer globe — dark
    ctx.beginPath()
    ctx.ellipse(ex * s, ey * s, 3.2 * s, 3.8 * s, 0, 0, Math.PI * 2)
    ctx.fillStyle = '#060e06'
    ctx.fill()

    // iris — deep green ring
    ctx.beginPath()
    ctx.ellipse(ex * s, ey * s, 2.5 * s, 3 * s, 0, 0, Math.PI * 2)
    ctx.fillStyle = '#1a4a1e'
    ctx.fill()

    // pupil — dead center, looking straight at us
    ctx.beginPath()
    ctx.arc(ex * s, ey * s, 1.4 * s, 0, Math.PI * 2)
    ctx.fillStyle = '#020602'
    ctx.fill()

    // specular reflection — tiny bright dot
    ctx.beginPath()
    ctx.arc((ex - 0.8) * s, (ey - 1.0) * s, 0.7 * s, 0, Math.PI * 2)
    ctx.fillStyle = 'rgba(180,255,180,0.7)'
    ctx.fill()
  }
  compoundEye(-4.2, -20.5)
  compoundEye( 4.2, -20.5)

  // ── ANTENNAE ──
  ctx.strokeStyle = 'rgba(35,90,35,0.8)'
  ctx.lineWidth = 1.0

  ctx.beginPath()
  ctx.moveTo(-3.5 * s, -24.5 * s)
  ctx.quadraticCurveTo(-9 * s, -33 * s, -7 * s, -40 * s)
  ctx.stroke()
  // antenna tip ball
  ctx.beginPath()
  ctx.arc(-7 * s, -40 * s, 1.5 * s, 0, Math.PI * 2)
  ctx.fillStyle = 'rgba(50,120,50,0.7)'
  ctx.fill()

  ctx.beginPath()
  ctx.moveTo(3.5 * s, -24.5 * s)
  ctx.quadraticCurveTo(9 * s, -33 * s, 7 * s, -40 * s)
  ctx.stroke()
  ctx.beginPath()
  ctx.arc(7 * s, -40 * s, 1.5 * s, 0, Math.PI * 2)
  ctx.fillStyle = 'rgba(50,120,50,0.7)'
  ctx.fill()

  ctx.restore()
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

  watch(() => store.theme, () => draw(ctx, c.width, c.height))

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
