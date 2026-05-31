// Deterministic color per favourite pattern — same pattern always gets same color
const PALETTE = [
  { hue: 210, name: 'blue'   },
  { hue: 160, name: 'teal'   },
  { hue: 270, name: 'purple' },
  { hue: 35,  name: 'amber'  },
  { hue: 190, name: 'cyan'   },
  { hue: 340, name: 'rose'   },
  { hue: 130, name: 'green'  },
  { hue: 55,  name: 'yellow' },
  { hue: 15,  name: 'orange' },
  { hue: 300, name: 'violet' },
]

function hashStr(s) {
  let h = 0
  for (let i = 0; i < s.length; i++) h = Math.imul(31, h) + s.charCodeAt(i) | 0
  return Math.abs(h)
}

export function favColor(pattern) {
  const { hue } = PALETTE[hashStr(pattern || '') % PALETTE.length]
  return {
    text:       `hsl(${hue}, 45%, 58%)`,
    textDim:    `hsl(${hue}, 35%, 38%)`,
    bg:         `hsl(${hue}, 30%, 7%)`,
    bgHover:    `hsl(${hue}, 30%, 9%)`,
    border:     `hsl(${hue}, 30%, 16%)`,
    borderLeft: `hsl(${hue}, 50%, 38%)`,
    bubble:     `hsl(${hue}, 28%, 18%)`,
  }
}
