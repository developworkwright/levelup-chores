(function () {
if (window.FQMonsters) return;
const R = s => { const x = Math.sin(s * 127.1) * 43758.5453; return x - Math.floor(x); };
let UID = 0;

const el = (tag, a, inner) => {
  let s = '<' + tag;
  for (const k in a) if (a[k] !== null && a[k] !== undefined) s += ' ' + k + '="' + a[k] + '"';
  return inner === undefined || inner === null ? s + '/>' : s + '>' + inner + '</' + tag + '>';
};

const STAGES = [
  { key: 'fresh', label: 'Lurking', open: 0.30, eye: 0.55, tilt: 0, wear: 0, breath: 4, taunt: 'You will never finish all those chores.' },
  { key: 'angry', label: 'Angry', open: 0.62, eye: 0.34, tilt: -3, wear: 0.28, breath: 3, taunt: 'Hey! Who taught you to tidy up?' },
  { key: 'damaged', label: 'Hurting', open: 0.85, eye: 0.85, tilt: 5, wear: 0.6, breath: 2.2, taunt: 'Stop that. I mean it. Stop.' },
  { key: 'desperate', label: 'Cornered', open: 1.0, eye: 1.15, tilt: -8, wear: 0.9, breath: 1.3, taunt: 'No no no not the vacuum NOT THE VACUUM' },
  { key: 'defeated', label: 'Defeated', open: 0.12, eye: 0.12, tilt: 15, wear: 1, breath: 6, taunt: 'Beaten by a bunch of kids with a mop.' }
];

const limb = (d, w, o) => el('path', { d, fill: 'none', stroke: 'var(--limb)', 'stroke-width': w, 'stroke-linecap': 'round', opacity: o });

function claw(x, y, a, len, w) {
  let s = '';
  for (let i = -1; i < 2; i++) {
    const r = (a + i * 30) * Math.PI / 180;
    s += el('path', { d: 'M ' + x + ' ' + y + ' L ' + (x + Math.cos(r) * len) + ' ' + (y + Math.sin(r) * len), stroke: 'var(--limb)', 'stroke-width': w, 'stroke-linecap': 'round', fill: 'none' });
  }
  return s;
}

function eye(cx, cy, r, st, opt) {
  opt = opt || {};
  const rx = r, ry = r * (opt.tall || 1.18);
  const pr = Math.max(0.9, r * 0.26 * st.eye);
  const ir = Math.max(pr + 1.1, r * 0.44 * st.eye);
  let s = el('ellipse', { cx, cy, rx: rx + 1.8, ry: ry + 1.8, fill: 'var(--ink)' });
  s += el('ellipse', { cx, cy, rx, ry, fill: 'var(--sclera)' });
  if (st.key === 'defeated') {
    const k = r * 0.62;
    s += el('path', {
      d: 'M ' + (cx - k) + ' ' + (cy - k) + ' L ' + (cx + k) + ' ' + (cy + k) + ' M ' + (cx + k) + ' ' + (cy - k) + ' L ' + (cx - k) + ' ' + (cy + k),
      stroke: 'var(--ink)', 'stroke-width': Math.max(2.4, r * 0.24), 'stroke-linecap': 'round'
    });
    return el('g', {}, s);
  }
  if (st.wear > 0.2) {
    const n = st.wear > 0.7 ? 4 : 2;
    for (let i = 0; i < n; i++) {
      const a = (R(cx + i * 7) * 2 - 1) * 2.4;
      const sx = cx + Math.cos(a) * rx * 0.35, sy = cy + Math.sin(a) * ry * 0.35;
      s += el('path', {
        d: 'M ' + sx + ' ' + sy + ' Q ' + (sx + Math.cos(a) * rx * 0.45) + ' ' + (sy + Math.sin(a) * ry * 0.3) + ' ' + (cx + Math.cos(a) * rx * 0.95) + ' ' + (cy + Math.sin(a) * ry * 0.95),
        stroke: '#c8264a', 'stroke-width': 1, fill: 'none', opacity: 0.25 + 0.5 * st.wear
      });
    }
  }
  const ey = cy + r * 0.05;
  s += el('circle', { cx, cy: ey, r: ir, fill: 'var(--eye)' });
  s += el('circle', { cx, cy: ey, r: pr, fill: '#07030d' });
  s += el('circle', { cx: cx - r * 0.3, cy: ey - r * 0.34, r: Math.max(1, r * 0.13), fill: '#fff', opacity: 0.85 });
  s += el('path', {
    d: 'M ' + (cx - rx - 2) + ' ' + (cy - ry * 0.15) + ' Q ' + cx + ' ' + (cy - ry * (0.4 + 1.5 * st.open)) + ' ' + (cx + rx + 2) + ' ' + (cy - ry * 0.15) +
       ' L ' + (cx + rx + 2) + ' ' + (cy - ry - 5) + ' L ' + (cx - rx - 2) + ' ' + (cy - ry - 5) + ' Z',
    fill: 'var(--shade)'
  });
  if (!opt.noBrow) {
    const side = opt.side !== undefined ? opt.side : (cx < 100 ? -1 : 1);
    const ang = -side * (11 + 11 * st.open);
    const bw = rx + 5, bh = Math.max(3.4, r * 0.34);
    const by = cy - ry * 0.88;
    s += el('rect', {
      x: cx - bw, y: by - bh, width: bw * 2, height: bh, rx: bh * 0.4, fill: 'var(--ink)',
      transform: 'rotate(' + ang + ' ' + cx + ' ' + by + ')'
    });
  }
  return el('g', {}, s);
}

function grin(o) {
  const st = o.st, open = st.open, half = o.w / 2, cx = o.cx, cy = o.cy;
  const x0 = cx - half, x1 = cx + half, n = o.n;
  const up = 5 + 11 * open, down = 9 + 34 * open;
  const d = 'M ' + x0 + ' ' + cy + ' Q ' + cx + ' ' + (cy - up) + ' ' + x1 + ' ' + cy + ' Q ' + cx + ' ' + (cy + down) + ' ' + x0 + ' ' + cy + ' Z';
  const id = 'fqg' + (++UID);
  const tw = o.w / n, th = 13 + 26 * open, twn = tw * 0.86;
  let teeth = '';
  for (let i = 0; i < n; i++) {
    const tx = x0 + i * tw + (tw - twn) / 2, mid = tx + twn / 2;
    const jt = 0.45 + 1.15 * R(i + 1), jb = 0.4 + 1.0 * R(i + 9);
    const topBase = cy - up - 5, botBase = cy + down + 5;
    teeth += el('polygon', { points: tx + ',' + topBase + ' ' + (tx + twn) + ',' + topBase + ' ' + (mid + (R(i + 3) - 0.5) * tw * 0.6) + ',' + (topBase + th * jt + 7), fill: 'var(--teeth)' });
    if (R(i + 13) > 0.28) teeth += el('polygon', { points: tx + ',' + botBase + ' ' + (tx + twn) + ',' + botBase + ' ' + (mid + (R(i + 5) - 0.5) * tw * 0.6) + ',' + (botBase - th * jb - 6), fill: 'var(--teeth)' });
  }
  let s = el('defs', {}, el('clipPath', { id }, el('path', { d })));
  s += el('path', { d, fill: '#14040c' });
  s += el('g', { 'clip-path': 'url(#' + id + ')' }, el('ellipse', { cx, cy: cy + down * 0.72, rx: half * 0.42, ry: down * 0.3, fill: '#5c0d29' }) + teeth);
  s += el('path', { d, fill: 'none', stroke: 'var(--ink)', 'stroke-width': 3, 'stroke-linejoin': 'round' });
  s += el('path', { d, fill: 'none', stroke: '#fff', 'stroke-width': 1.2, 'stroke-linejoin': 'round', opacity: 0.16 });
  if (st.wear > 0.15) {
    const cr = st.wear;
    [[x0, -1], [x1, 1]].forEach(([x, dir]) => {
      s += el('path', {
        d: 'M ' + x + ' ' + cy + ' l ' + (dir * 7) + ' ' + (-6 - 8 * cr) + ' l ' + (dir * -3) + ' ' + (-2 - 3 * cr) + ' l ' + (dir * 8) + ' ' + (-5 - 7 * cr),
        stroke: 'var(--ink)', 'stroke-width': 2.2, fill: 'none', 'stroke-linecap': 'round', opacity: 0.5 + 0.5 * cr
      });
    });
  }
  const g = el('g', {}, s);
  return o.rot ? el('g', { transform: 'rotate(' + o.rot + ' ' + cx + ' ' + cy + ')' }, g) : g;
}

function maw(cx, cy, r, st, n) {
  const rr = r * (0.42 + 0.58 * st.open);
  let s = el('circle', { cx, cy, r: rr, fill: '#14040c', stroke: 'var(--shade)', 'stroke-width': 2.5 });
  for (let i = 0; i < n; i++) {
    const a = i / n * Math.PI * 2;
    const t = rr * (0.42 + 0.2 * R(i + 2));
    const px = cx + Math.cos(a) * rr, py = cy + Math.sin(a) * rr;
    const nx = cx + Math.cos(a) * (rr - t), ny = cy + Math.sin(a) * (rr - t);
    const w = rr * 0.34;
    s += el('polygon', {
      points: (px - Math.sin(a) * w) + ',' + (py + Math.cos(a) * w) + ' ' + (px + Math.sin(a) * w) + ',' + (py - Math.cos(a) * w) + ' ' + nx + ',' + ny,
      fill: 'var(--teeth)'
    });
  }
  s += el('circle', { cx, cy, r: rr * 0.3, fill: '#4a0a22' });
  return el('g', {}, s);
}

function shade(d) {
  const id = 'fqc' + (++UID);
  return el('defs', {}, el('clipPath', { id }, el('path', { d }))) +
    el('g', { 'clip-path': 'url(#' + id + ')' },
      el('ellipse', { cx: 100, cy: 222, rx: 130, ry: 92, fill: '#07030d', opacity: 0.34 }) +
      el('ellipse', { cx: 62, cy: 34, rx: 72, ry: 62, fill: '#ffffff', opacity: 0.1 }) +
      el('path', { d, fill: 'none', stroke: 'var(--glow)', 'stroke-width': 7, opacity: 0.28 })
    );
}

function tears(st, pts) {
  if (st.wear <= 0) return '';
  let s = '';
  pts.forEach(([x, y, a], i) => {
    if (i / pts.length >= st.wear) return;
    const t = 'rotate(' + a + ' ' + x + ' ' + y + ')';
    s += el('path', { d: 'M ' + (x - 10) + ' ' + y + ' L ' + (x + 10) + ' ' + y, stroke: 'var(--ink)', 'stroke-width': 3.4, 'stroke-linecap': 'round', opacity: 0.9, transform: t });
    s += el('path', { d: 'M ' + (x - 8) + ' ' + (y - 4) + ' l 4 8 m 4 -8 l 4 8 m 4 -8 l 4 8', stroke: 'var(--teeth)', 'stroke-width': 1.5, 'stroke-linecap': 'round', fill: 'none', opacity: 0.6, transform: t });
  });
  return s;
}

function motes(st, seed, n) {
  if (st.wear <= 0.1) return '';
  let s = '';
  for (let i = 0; i < n; i++) {
    if (i / n >= st.wear) continue;
    const x = 22 + R(seed + i) * 156, y = 120 + R(seed + i * 3) * 66;
    s += el('circle', { cx: x, cy: y, r: 1.6 + R(seed + i * 7) * 3, fill: 'var(--body)', opacity: 0.55 });
  }
  return s;
}

const SKINS = {
  gnash: (st) => {
    const swing = 6 + 20 * st.open;
    const body = 'M 100 16 C 56 16 40 52 44 98 C 48 144 56 182 100 182 C 144 182 152 144 156 98 C 160 52 144 16 100 16 Z';
    let s = limb('M 60 96 C 30 104 ' + (16 - swing) + ' 140 22 186', 8);
    s += limb('M 140 96 C 170 104 ' + (184 + swing) + ' 140 178 186', 8);
    s += claw(22, 186, 100, 18, 3.6) + claw(178, 186, 80, 18, 3.6);
    s += el('path', { d: 'M 64 42 L 54 4 L 88 30 Z', fill: 'var(--shade)' });
    s += el('path', { d: 'M 136 42 L 146 4 L 112 30 Z', fill: 'var(--shade)' });
    s += el('path', { d: body, fill: 'var(--body)' });
    s += shade(body);
    s += el('path', { d: 'M 100 20 L 100 52', stroke: 'var(--ink)', 'stroke-width': 2, opacity: 0.45 });
    s += tears(st, [[70, 56, -22], [134, 50, 16], [58, 156, 8], [142, 150, -14], [100, 176, 0]]);
    s += eye(72, 72, 23, st) + eye(128, 72, 23, st);
    s += grin({ cx: 100, cy: 122, w: 106, n: 8, st });
    return s;
  },

  sockmoth: (st) => {
    const wingPath = (dir, up) => {
      const ox = 100 + dir * 52, oy = up ? 80 : 126, rx = up ? 58 : 42, ry = up ? 44 : 30, N = 28;
      const pts = [];
      for (let i = 0; i < N; i++) {
        const a = Math.PI * 2 * i / N;
        const outer = Math.cos(a) * dir > -0.1;
        const jag = outer && i % 2 ? 1 - (0.16 + 0.26 * R(i * 1.7 + dir * 5 + (up ? 0 : 40))) * (0.7 + 0.3 * st.wear) : 1;
        pts.push([(ox + Math.cos(a) * rx * jag).toFixed(1), (oy + Math.sin(a) * ry * jag).toFixed(1)]);
      }
      return 'M ' + pts.map(p => p[0] + ' ' + p[1]).join(' L ') + ' Z';
    };
    let s = '';
    [[-1, false], [1, false], [-1, true], [1, true]].forEach(([dir, up]) => {
      const d = wingPath(dir, up);
      s += el('path', { d, fill: 'var(--body)', opacity: up ? 0.95 : 0.75 });
      s += el('path', { d, fill: 'none', stroke: 'var(--ink)', 'stroke-width': 1.6, 'stroke-linejoin': 'round', opacity: 0.7 });
      if (up) {
        s += el('circle', { cx: 100 + dir * 60, cy: 78, r: 15, fill: 'var(--shade)' });
        s += el('circle', { cx: 100 + dir * 60, cy: 78, r: 6, fill: 'var(--eye)', opacity: 0.5 });
      }
      for (let i = 0; i < 4; i++) {
        const a = (-60 + i * 40) * Math.PI / 180;
        s += el('path', { d: 'M ' + (100 + dir * 12) + ' ' + (up ? 88 : 116) + ' L ' + (100 + dir * (12 + Math.cos(a) * (up ? 78 : 58))) + ' ' + ((up ? 88 : 116) + Math.sin(a) * (up ? 34 : 24)), stroke: 'var(--ink)', 'stroke-width': 1.2, opacity: 0.35, fill: 'none' });
      }
    });
    for (let i = 0; i < 8; i++) {
      if (i / 8 >= st.wear) continue;
      const d = i % 2 ? 1 : -1;
      const x = 100 + d * (36 + R(i) * 56), y = 66 + R(i + 5) * 76;
      s += el('circle', { cx: x, cy: y, r: 3 + R(i + 2) * 7, fill: '#09040f', opacity: 0.9 });
    }
    s += limb('M 90 46 C 76 26 68 16 58 8', 3) + limb('M 110 46 C 124 26 132 16 142 8', 3);
    s += el('circle', { cx: 58, cy: 8, r: 4.5, fill: 'var(--shade)' }) + el('circle', { cx: 142, cy: 8, r: 4.5, fill: 'var(--shade)' });
    const body = 'M 100 34 C 82 34 74 50 74 72 L 72 130 C 72 164 86 184 100 184 C 114 184 128 164 128 130 L 126 72 C 126 50 118 34 100 34 Z';
    s += el('path', { d: body, fill: 'var(--shade)' });
    s += shade(body);
    for (let i = 0; i < 5; i++) s += el('path', { d: 'M 74 ' + (128 + i * 12) + ' Q 100 ' + (134 + i * 12) + ' 126 ' + (128 + i * 12), stroke: 'var(--ink)', 'stroke-width': 1.6, fill: 'none', opacity: 0.45 });
    [[85, 62, 9], [115, 60, 9], [100, 80, 10.5], [77, 84, 6.5], [123, 86, 6], [100, 46, 6]].forEach(([x, y, r]) => { s += eye(x, y, r, st, { tall: 1.12, noBrow: r < 8 }); });
    s += grin({ cx: 100, cy: 138, w: 62, n: 9, st, rot: 90 });
    s += motes(st, 3, 12);
    return s;
  },

  crumbler: (st) => {
    const lump = 'M 100 30 C 66 26 42 46 40 72 C 18 82 20 120 40 130 C 44 160 70 178 100 176 C 130 178 156 160 160 130 C 180 120 182 82 160 72 C 158 46 134 26 100 30 Z';
    let s = limb('M 46 108 C 14 122 6 158 18 184', 8) + limb('M 154 108 C 186 122 194 158 182 184', 8);
    s += claw(18, 184, 110, 16, 3.6) + claw(182, 184, 70, 16, 3.6);
    s += el('path', { d: lump, fill: 'var(--body)' });
    s += shade(lump);
    for (let i = 0; i < 9; i++) {
      const x = 40 + R(i + 11) * 120, y = 44 + R(i + 21) * 118;
      s += el('circle', { cx: x, cy: y, r: 4 + R(i + 31) * 7, fill: 'var(--shade)', opacity: 0.5 });
    }
    s += tears(st, [[62, 60, -18], [140, 68, 20], [100, 46, 4], [56, 140, 12], [148, 138, -10]]);
    s += eye(72, 78, 17, st) + eye(124, 68, 20, st) + eye(98, 100, 10, st, { tall: 1.05 });
    s += grin({ cx: 100, cy: 142, w: 116, n: 13, st });
    s += motes(st, 17, 12);
    return s;
  },

  tangleboy: (st) => {
    const splay = 4 + 16 * st.open;
    const cable = (d, w, o) => el('path', { d, fill: 'none', stroke: 'var(--limb)', 'stroke-width': w, 'stroke-linecap': 'round', opacity: o || 1 });
    const prong = (x, y, a) => el('g', { transform: 'rotate(' + a + ' ' + x + ' ' + y + ')' },
      el('rect', { x: x - 6, y: y - 2, width: 5, height: 13, rx: 2, fill: 'var(--glow)' }) +
      el('rect', { x: x + 1, y: y - 2, width: 5, height: 13, rx: 2, fill: 'var(--glow)' }));
    let s = cable('M 60 44 C 4 74 6 156 66 152', 6, 0.75) + cable('M 140 44 C 196 74 194 156 134 152', 6, 0.75);
    s += cable('M 74 66 C 34 96 42 150 82 158', 4, 0.5) + cable('M 126 66 C 166 96 158 150 118 158', 4, 0.5);
    s += cable('M 90 190 L 94 128', 8) + cable('M 110 190 L 106 128', 8);
    s += prong(90, 188, 0) + prong(110, 188, 0);
    s += cable('M 82 96 C ' + (40 - splay) + ' 120 ' + (30 - splay) + ' 164 40 192', 6) + cable('M 118 96 C ' + (160 + splay) + ' 120 ' + (170 + splay) + ' 164 160 192', 6);
    s += claw(40, 192, 104, 16, 3.4) + claw(160, 192, 76, 16, 3.4);
    const body = 'M 100 22 C 74 22 70 54 74 86 L 80 142 C 80 160 120 160 120 142 L 126 86 C 130 54 126 22 100 22 Z';
    s += el('path', { d: body, fill: 'var(--body)' });
    s += shade(body);
    s += el('path', { d: body, fill: 'none', stroke: 'var(--glow)', 'stroke-width': 2, opacity: 0.45 });
    for (let i = 0; i < 4; i++) s += el('path', { d: 'M ' + (76 + i) + ' ' + (96 + i * 16) + ' Q 100 ' + (104 + i * 16) + ' ' + (124 - i) + ' ' + (96 + i * 16), stroke: 'var(--ink)', 'stroke-width': 2, fill: 'none', opacity: 0.5 });
    s += tears(st, [[92, 112, 80], [108, 138, 80], [96, 74, 10], [104, 152, 0]]);
    s += grin({ cx: 100, cy: 120, w: 58, n: 8, st, rot: 90 });
    s += eye(100, 48, 32, st, { tall: 1 });
    return s;
  },

  'mold-king': (st) => {
    const head = 'M 100 40 C 62 38 42 62 44 92 C 26 104 30 138 52 144 C 60 168 78 180 100 178 C 122 180 140 168 148 144 C 170 138 174 104 156 92 C 158 62 138 38 100 40 Z';
    let s = limb('M 78 176 C 70 188 66 194 62 198', 6) + limb('M 122 176 C 130 188 134 194 138 198', 6);
    s += limb('M 48 104 C 16 118 10 154 22 182', 7) + limb('M 152 104 C 184 118 190 154 178 182', 7);
    s += claw(22, 182, 108, 14, 3.2) + claw(178, 182, 72, 14, 3.2);
    s += el('path', { d: 'M 56 42 L 62 8 L 82 32 L 100 2 L 118 32 L 138 8 L 144 42 Z', fill: '#ffc93d' });
    s += el('path', { d: 'M 56 42 L 62 8 L 82 32 L 100 2 L 118 32 L 138 8 L 144 42 Z', fill: 'none', stroke: '#8a6510', 'stroke-width': 2, 'stroke-linejoin': 'round' });
    s += el('path', { d: head, fill: 'var(--body)' });
    s += shade(head);
    for (let i = 0; i < 8; i++) {
      const x = 50 + R(i + 41) * 100, y = 56 + R(i + 51) * 106;
      s += el('circle', { cx: x, cy: y, r: 5 + R(i + 61) * 6, fill: 'var(--shade)', opacity: 0.55 });
    }
    for (let i = 0; i < 6; i++) {
      if (i / 6 >= st.wear) continue;
      const x = 52 + i * 20;
      s += el('path', { d: 'M ' + x + ' 170 q 3 14 0 22', stroke: 'var(--glow)', 'stroke-width': 3, fill: 'none', 'stroke-linecap': 'round', opacity: 0.55 });
    }
    s += tears(st, [[68, 62, -18], [132, 60, 18], [100, 168, 0], [50, 128, 10], [150, 126, -10]]);
    s += eye(76, 98, 18, st) + eye(124, 98, 18, st) + eye(100, 66, 11, st, { tall: 1.1 });
    s += grin({ cx: 100, cy: 140, w: 92, n: 10, st });
    return s;
  },

  dustwyrm: (st) => {
    const sway = 8 * (1 - st.wear * 0.5);
    const hx = 96, hy = 62, hr = 38;
    let s = '';
    s += el('path', { d: 'M 118 194 C 150 176 62 156 82 132 C 98 112 ' + hx + ' ' + (hy + 30) + ' ' + hx + ' ' + hy, fill: 'none', stroke: 'var(--shade)', 'stroke-width': 30, 'stroke-linecap': 'round', opacity: 0.9 });
    [[126, 190, 24], [96 + sway, 168, 21], [80, 140, 19], [104 - sway, 112, 18]].forEach(([x, y, r]) => {
      s += el('circle', { cx: x, cy: y, r, fill: 'var(--body)' });
      s += el('circle', { cx: x, cy: y, r, fill: 'none', stroke: 'var(--ink)', 'stroke-width': 2, opacity: 0.55 });
      s += el('circle', { cx: x - r * 0.3, cy: y - r * 0.35, r: r * 0.55, fill: '#fff', opacity: 0.06 });
    });
    for (let i = 0; i < 20; i++) {
      const a = (i / 20) * Math.PI * 2, len = 20 + R(i + 71) * 26;
      s += el('path', { d: 'M ' + (hx + Math.cos(a) * hr * 0.9) + ' ' + (hy + Math.sin(a) * hr * 0.9) + ' L ' + (hx + Math.cos(a) * (hr + len)) + ' ' + (hy + Math.sin(a) * (hr + len)), stroke: 'var(--shade)', 'stroke-width': 2.6, 'stroke-linecap': 'round', opacity: 0.9 });
    }
    s += el('circle', { cx: hx, cy: hy, r: hr, fill: 'var(--body)' });
    s += shade('M ' + hx + ' ' + (hy - hr) + ' a ' + hr + ' ' + hr + ' 0 1 0 0.1 0 Z');
    s += tears(st, [[hx, hy + 30, 0], [86, 140, 20], [126, 186, -14], [76, 52, 30]]);
    for (let i = 0; i < 6; i++) {
      const a = (-170 + i * 60) * Math.PI / 180;
      s += eye(hx + Math.cos(a) * 27, hy + Math.sin(a) * 27, 8.5, st, { tall: 1.1, noBrow: true });
    }
    s += maw(hx, hy, 20, st, 13);
    s += motes(st, 91, 14);
    return s;
  },

  static: (st) => {
    let s = limb('M 82 192 L 84 150', 7) + limb('M 118 192 L 116 150', 7);
    s += limb('M 66 118 C 34 134 24 166 34 190', 6) + limb('M 134 118 C 166 134 176 166 166 190', 6);
    s += claw(34, 190, 104, 14, 3.2) + claw(166, 190, 76, 14, 3.2);
    s += limb('M 78 42 L 60 8', 3) + limb('M 122 42 L 140 8', 3);
    s += el('circle', { cx: 60, cy: 8, r: 4.5, fill: 'var(--glow)' }) + el('circle', { cx: 140, cy: 8, r: 4.5, fill: 'var(--glow)' });
    s += el('path', { d: 'M 78 132 L 122 132 L 130 178 L 70 178 Z', fill: 'var(--shade)' });
    const box = 'M 56 36 h 88 a 12 12 0 0 1 12 12 v 88 a 12 12 0 0 1 -12 12 h -88 a 12 12 0 0 1 -12 -12 v -88 a 12 12 0 0 1 12 -12 Z';
    s += el('path', { d: box, fill: 'var(--body)' });
    s += shade(box);
    const screen = 'M 60 44 h 80 v 84 h -80 Z';
    s += el('path', { d: screen, fill: '#07030d' });
    const id = 'fqsc' + (++UID);
    s += el('defs', {}, el('clipPath', { id }, el('path', { d: screen })));
    let lines = '';
    for (let i = 0; i < 21; i++) lines += el('rect', { x: 60, y: 44 + i * 4, width: 80, height: 1.7, fill: 'var(--glow)', opacity: (0.08 + 0.16 * R(i + 1 + st.wear * 9)).toFixed(3) });
    s += el('g', { 'clip-path': 'url(#' + id + ')' }, lines);
    s += tears(st, [[74, 40, -10], [126, 132, 12], [100, 30, 0], [52, 96, 90]]);
    s += eye(80, 72, 14, st) + eye(120, 72, 14, st);
    s += grin({ cx: 100, cy: 106, w: 66, n: 8, st });
    return s;
  },

  chorus: (st) => {
    const robe = 'M 100 18 C 76 18 66 44 68 76 L 50 182 C 84 192 116 192 150 182 L 132 76 C 134 44 124 18 100 18 Z';
    let s = limb('M 72 86 C 36 106 26 152 34 190', 6) + limb('M 128 86 C 164 106 174 152 166 190', 6);
    s += claw(34, 190, 104, 16, 3.4) + claw(166, 190, 76, 16, 3.4);
    s += el('path', { d: robe, fill: 'var(--body)' });
    s += shade(robe);
    s += eye(84, 50, 15, st) + eye(116, 50, 15, st);
    s += grin({ cx: 100, cy: 88, w: 62, n: 8, st });
    s += grin({ cx: 76, cy: 128, w: 38, n: 5, st });
    s += grin({ cx: 126, cy: 152, w: 44, n: 6, st });
    s += tears(st, [[100, 112, 0], [62, 158, 20], [140, 176, -14], [100, 34, 0]]);
    return s;
  },

  drip: (st) => {
    const dripShape = (x, y, len, w) => el('rect', { x: x - w / 2, y: y - 3, width: w, height: len, rx: w / 2, fill: 'var(--body)' }) + el('circle', { cx: x, cy: y + len, r: w * 0.72, fill: 'var(--body)' });
    const blob = 'M 100 24 C 60 24 40 58 44 100 C 48 140 60 168 100 168 C 140 168 152 140 156 100 C 160 58 140 24 100 24 Z';
    let s = limb('M 54 96 C 20 118 14 158 26 188', 8) + limb('M 146 96 C 180 118 186 158 174 188', 8);
    s += claw(26, 188, 104, 16, 3.6) + claw(174, 188, 76, 16, 3.6);
    s += el('path', { d: blob, fill: 'var(--body)' });
    s += shade(blob);
    for (let i = 0; i < 6; i++) { const x = 58 + i * 17; s += dripShape(x, 162, 8 + R(i + 3) * 16 + 24 * st.wear, 7); }
    s += eye(76, 78, 21, st) + eye(128, 72, 16, st);
    s += el('rect', { x: 72, y: 92, width: 8, height: 18 + 34 * st.wear, rx: 4, fill: '#07030d', opacity: 0.8 });
    s += el('rect', { x: 124, y: 84, width: 6, height: 14 + 28 * st.wear, rx: 3, fill: '#07030d', opacity: 0.8 });
    s += grin({ cx: 100, cy: 124, w: 98, n: 10, st });
    return s;
  },

  wallcrack: (st) => {
    const w = 15 + 22 * st.open;
    const left = [], right = [];
    for (let i = 0; i <= 11; i++) {
      const y = 8 + i * 17, k = w * (0.35 + 0.65 * Math.sin(i / 11 * Math.PI));
      left.push([(100 - k * (0.7 + 0.6 * R(i + 1))).toFixed(1), y]);
      right.unshift([(100 + k * (0.7 + 0.6 * R(i + 9))).toFixed(1), y]);
    }
    const d = 'M ' + left.concat(right).map(p => p[0] + ' ' + p[1]).join(' L ') + ' Z';
    let s = el('rect', { x: -10, y: -10, width: 220, height: 230, fill: 'var(--body)' });
    s += el('rect', { x: -10, y: -10, width: 220, height: 230, fill: 'var(--shade)', opacity: 0.35 });
    for (let i = 0; i < 22; i++) s += el('circle', { cx: 20 + R(i + 3) * 160, cy: 10 + R(i + 17) * 180, r: 1 + R(i + 31) * 2.4, fill: 'var(--shade)', opacity: 0.5 });
    for (let i = 0; i < 6; i++) {
      if (i / 6 >= st.wear) continue;
      const y = 24 + R(i + 5) * 150, dir = i % 2 ? 1 : -1;
      s += el('path', { d: 'M ' + (100 + dir * 12) + ' ' + y + ' l ' + (dir * 18) + ' ' + (-8 + R(i) * 16) + ' l ' + (dir * 14) + ' ' + (10 - R(i + 2) * 18), stroke: 'var(--shade)', 'stroke-width': 2.4, fill: 'none', opacity: 0.9 });
    }
    s += el('path', { d, fill: '#07030d' });
    s += el('path', { d, fill: 'none', stroke: 'var(--glow)', 'stroke-width': 2, opacity: 0.45 });
    [[-1, 62], [1, 128]].forEach(([dir, y]) => {
      s += el('path', { d: 'M ' + (100 + dir * 8) + ' ' + y + ' q ' + (dir * 26) + ' 4 ' + (dir * 34) + ' 26', stroke: 'var(--limb)', 'stroke-width': 13, fill: 'none', 'stroke-linecap': 'round' });
      s += el('circle', { cx: 100 + dir * 42, cy: y + 30, r: 9, fill: 'var(--limb)' });
      s += claw(100 + dir * 46, y + 34, dir > 0 ? 30 : 150, 20, 4.5);
    });
    s += eye(100, 74, 18, st);
    s += grin({ cx: 100, cy: 132, w: 40, n: 6, st, rot: 90 });
    return s;
  },

  rattle: (st) => {
    let s = limb('M 70 112 C 34 128 24 162 34 190', 6) + limb('M 130 112 C 166 128 176 162 166 190', 6);
    s += el('circle', { cx: 34, cy: 190, r: 10, fill: 'var(--body)' }) + el('circle', { cx: 166, cy: 190, r: 10, fill: 'var(--body)' });
    s += el('rect', { x: 96, y: 116, width: 9, height: 74, rx: 4, fill: 'var(--shade)' });
    for (let i = 0; i < 5; i++) {
      if (i / 5 >= 1 - st.wear * 0.6) continue;
      const y = 128 + i * 14;
      s += el('path', { d: 'M 72 ' + y + ' Q 100 ' + (y + 12) + ' 128 ' + y, stroke: 'var(--body)', 'stroke-width': 7, fill: 'none', 'stroke-linecap': 'round' });
    }
    const skull = 'M 100 18 C 66 18 50 44 52 76 C 54 106 74 122 100 122 C 126 122 146 106 148 76 C 150 44 134 18 100 18 Z';
    s += el('path', { d: skull, fill: 'var(--body)' });
    s += shade(skull);
    s += tears(st, [[76, 34, -18], [126, 30, 16], [100, 116, 0], [60, 96, 70]]);
    s += eye(78, 62, 16, st) + eye(122, 62, 16, st);
    s += grin({ cx: 100, cy: 98, w: 70, n: 9, st });
    return s;
  },

  hushpuppet: (st) => {
    let s = '';
    [66, 100, 134].forEach(x => { s += el('path', { d: 'M ' + x + ' 0 L ' + (x + (x - 100) * 0.14) + ' 46', stroke: 'var(--glow)', 'stroke-width': 1.4, fill: 'none', opacity: 0.55 }); });
    s += limb('M 74 120 C 44 138 36 168 46 192', 6) + limb('M 126 120 C 156 138 164 168 154 192', 6);
    s += claw(46, 192, 104, 13, 3) + claw(154, 192, 76, 13, 3);
    s += el('path', { d: 'M 84 118 L 116 118 L 124 188 L 76 188 Z', fill: 'var(--shade)' });
    const head = 'M 100 28 C 62 28 46 56 48 92 C 50 126 70 144 100 144 C 130 144 150 126 152 92 C 154 56 138 28 100 28 Z';
    s += el('path', { d: head, fill: 'var(--body)' });
    s += shade(head);
    s += el('path', { d: 'M 48 96 Q 100 108 152 96', stroke: 'var(--ink)', 'stroke-width': 2, fill: 'none', opacity: 0.5 });
    s += tears(st, [[70, 44, -20], [130, 40, 18], [100, 140, 0], [56, 112, 60], [144, 110, -60]]);
    s += eye(78, 68, 16, st) + eye(122, 68, 16, st);
    s += grin({ cx: 100, cy: 108, w: 74, n: 9, st });
    return s;
  },

  longlegs: (st) => {
    const splay = 1 + 0.22 * st.open, sag = 10 * st.wear;
    let s = '';
    // Eight jointed legs: knee well above the body, foot planted wide — the
    // black-widow stance. Back legs first so the front pair reads on top.
    for (let i = 3; i >= 0; i--) {
      [-1, 1].forEach(dir => {
        const out = (34 + i * 15) * splay;
        const knee = 34 + i * 26 + sag;
        const foot = 100 + dir * (out + 44 + i * 6) * splay;
        const d = 'M 100 ' + (150 - i * 3) +
          ' Q ' + (100 + dir * out * 0.8) + ' ' + knee + ' ' + (100 + dir * (out + 18)) + ' ' + (knee + 4) +
          ' Q ' + (100 + dir * (out + 40)) + ' ' + (knee + 30) + ' ' + foot + ' ' + (196 - i * 2);
        s += el('path', { d, fill: 'none', stroke: 'var(--limb)', 'stroke-width': 7 - i * 0.7, 'stroke-linecap': 'round' });
        s += el('path', { d, fill: 'none', stroke: 'var(--glow)', 'stroke-width': 1.2, 'stroke-linecap': 'round', opacity: 0.18 });
        s += el('circle', { cx: 100 + dir * (out + 18), cy: knee + 4, r: 4 - i * 0.4, fill: 'var(--shade)' });
      });
    }
    // Abdomen: glossy, bulbous, hanging below the head.
    const abd = 'M 100 106 C 66 106 52 132 52 156 C 52 182 74 196 100 196 C 126 196 148 182 148 156 C 148 132 134 106 100 106 Z';
    s += el('path', { d: abd, fill: 'var(--body)' });
    s += shade(abd);
    s += el('ellipse', { cx: 84, cy: 132, rx: 15, ry: 20, fill: '#ffffff', opacity: 0.09, transform: 'rotate(-18 84 132)' });
    // The hourglass. Two stacked triangles, brighter the more rattled it gets.
    const hg = 'M 84 138 L 116 138 L 100 158 Z M 84 182 L 116 182 L 100 160 Z';
    s += el('path', { d: hg, fill: 'var(--eye)', opacity: 0.75 + 0.25 * st.open });
    s += el('path', { d: hg, fill: 'none', stroke: '#07030d', 'stroke-width': 1.2, opacity: 0.6 });
    s += tears(st, [[70, 150, 24], [132, 168, -20], [100, 192, 0], [60, 176, 10]]);
    // Cephalothorax, small and low-slung.
    const ceph = 'M 100 66 C 76 66 64 82 64 100 C 64 118 80 128 100 128 C 120 128 136 118 136 100 C 136 82 124 66 100 66 Z';
    s += el('path', { d: ceph, fill: 'var(--body)' });
    s += shade(ceph);
    // Fangs, under the eye rows.
    const gape = 3 + 7 * st.open;
    [-1, 1].forEach(dir => {
      s += el('path', { d: 'M ' + (100 + dir * 8) + ' 116 q ' + (dir * 5) + ' ' + (12 + gape) + ' ' + (dir * (2 + gape * 0.5)) + ' ' + (22 + gape), fill: 'none', stroke: 'var(--teeth)', 'stroke-width': 6, 'stroke-linecap': 'round' });
      s += el('path', { d: 'M ' + (100 + dir * 8) + ' 116 q ' + (dir * 5) + ' ' + (12 + gape) + ' ' + (dir * (2 + gape * 0.5)) + ' ' + (22 + gape), fill: 'none', stroke: '#07030d', 'stroke-width': 1.4, 'stroke-linecap': 'round', opacity: 0.55 });
    });
    // Eight eyes: two big front-facing, six small in two rows above.
    s += eye(88, 100, 11, st, { tall: 1.15 }) + eye(112, 100, 11, st, { tall: 1.15 });
    [[78, 84, 5], [92, 79, 5.5], [108, 79, 5.5], [122, 84, 5], [86, 92, 4], [114, 92, 4]].forEach(([x, y, r]) => {
      s += eye(x, y, r, st, { tall: 1.1, noBrow: true });
    });
    return s;
  },

  leaner: (st) => {
    const open = st.open;
    // The neck never straightens; it only gets worse. Defeated drops the head
    // all the way onto the shoulder, which is the whole silhouette.
    // Two breaks, not one lean: the neck goes one way and the skull tips the
    // other, so it reads as a thing that has been put back together wrong.
    const dead = st.key === 'defeated';
    const neckA = dead ? 44 : -20 - 18 * open;
    const headA = dead ? 30 : -17 - 15 * open;
    const reach = 4 + 16 * open;
    let s = '';
    // Legs: long, thin, slightly knock-kneed.
    s += limb('M 92 202 L 97 146', 6) + limb('M 110 202 L 105 146', 6);
    s += el('path', { d: 'M 84 202 L 100 202', stroke: 'var(--limb)', 'stroke-width': 5, 'stroke-linecap': 'round' });
    s += el('path', { d: 'M 104 202 L 120 202', stroke: 'var(--limb)', 'stroke-width': 5, 'stroke-linecap': 'round' });
    // Arms hang past the knees. Fingers, not claws — too many joints.
    [-1, 1].forEach(dir => {
      const sx = 100 + dir * 15, ex = 100 + dir * (30 + reach);
      s += limb('M ' + sx + ' 100 C ' + (100 + dir * (34 + reach)) + ' 122 ' + (100 + dir * (30 + reach)) + ' 150 ' + ex + ' 176', 5.5);
      for (let i = -1; i < 2; i++) {
        s += el('path', { d: 'M ' + ex + ' 176 q ' + (dir * 3 + i * 3) + ' 12 ' + (dir * 2 + i * 5) + ' 22', stroke: 'var(--limb)', 'stroke-width': 3, fill: 'none', 'stroke-linecap': 'round' });
      }
    });
    // Torso: narrow, high-shouldered, no waist to speak of.
    const torso = 'M 100 86 C 84 86 79 106 81 124 L 86 154 C 86 166 114 166 114 154 L 119 124 C 121 106 116 86 100 86 Z';
    s += el('path', { d: torso, fill: 'var(--body)' });
    s += shade(torso);
    s += el('path', { d: torso, fill: 'none', stroke: 'var(--glow)', 'stroke-width': 1.6, opacity: 0.3 });
    s += tears(st, [[92, 112, 74], [108, 138, 74], [100, 158, 0], [88, 96, 20]]);
    // Neck and head, cocked as one piece so the angle reads as a break.
    let h = el('path', { d: 'M 94 98 L 106 98 L 108 54 L 92 54 Z', fill: 'var(--limb)' });
    h += el('path', { d: 'M 94 78 L 106 74', stroke: 'var(--shade)', 'stroke-width': 2, opacity: 0.7 });
    h += el('path', { d: 'M 94 88 L 106 84', stroke: 'var(--shade)', 'stroke-width': 2, opacity: 0.5 });
    let hd = '';
    const head = 'M 100 2 C 82 2 74 24 74 48 C 74 72 84 86 100 86 C 116 86 126 72 126 48 C 126 24 118 2 100 2 Z';
    hd += el('path', { d: head, fill: 'var(--body)' });
    hd += shade(head);
    hd += el('path', { d: head, fill: 'none', stroke: 'var(--glow)', 'stroke-width': 1.8, opacity: 0.35 });
    // Sockets: holes, not eyes. A rim of glow is all that catches the light.
    [[89, 32], [111, 32]].forEach(([ex, ey]) => {
      const rx = 7 + 2 * open, ry = 10 + 3 * open;
      hd += el('ellipse', { cx: ex, cy: ey, rx: rx + 1.4, ry: ry + 1.4, fill: 'var(--glow)', opacity: 0.16 });
      hd += el('ellipse', { cx: ex, cy: ey, rx, ry, fill: '#000' });
      if (st.key === 'defeated') {
        hd += el('path', { d: 'M ' + (ex - 5) + ' ' + (ey - 6) + ' L ' + (ex + 5) + ' ' + (ey + 6) + ' M ' + (ex + 5) + ' ' + (ey - 6) + ' L ' + (ex - 5) + ' ' + (ey + 6) + '', stroke: 'var(--glow)', 'stroke-width': 2.4, 'stroke-linecap': 'round', opacity: 0.75 });
      }
    });
    // The mouth is a hole. No teeth, no tongue, no back of the throat.
    const mw = 6 + 7 * open, mh = 5 + 17 * open;
    hd += el('ellipse', { cx: 100, cy: 62 + mh * 0.5, rx: mw + 1.3, ry: mh + 1.3, fill: 'var(--glow)', opacity: 0.16 });
    hd += el('ellipse', { cx: 100, cy: 62 + mh * 0.5, rx: mw, ry: mh, fill: '#000' });
    h += el('g', { transform: 'rotate(' + headA + ' 100 56)' }, hd);
    s += el('g', { transform: 'rotate(' + neckA + ' 100 98)' }, h);
    return s;
  },

  balloonhead: (st) => {
    const head = 'M 100 16 C 58 16 38 50 38 88 C 38 126 66 152 100 152 C 134 152 162 126 162 88 C 162 50 142 16 100 16 Z';
    let s = el('path', { d: 'M 100 176 C 88 186 112 190 100 200', stroke: 'var(--glow)', 'stroke-width': 2, fill: 'none', opacity: 0.6 });
    s += limb('M 66 116 C 40 140 34 168 42 192', 5) + limb('M 134 116 C 160 140 166 168 158 192', 5);
    s += claw(42, 192, 104, 12, 2.8) + claw(158, 192, 76, 12, 2.8);
    s += el('path', { d: head, fill: 'var(--body)' });
    s += shade(head);
    s += el('path', { d: 'M 92 150 L 108 150 L 100 176 Z', fill: 'var(--shade)' });
    for (let i = 0; i < 5; i++) {
      if (i / 5 >= st.wear) continue;
      const a = -40 + i * 40;
      s += el('path', { d: 'M 100 88 l ' + (Math.cos(a * Math.PI / 180) * 46).toFixed(1) + ' ' + (Math.sin(a * Math.PI / 180) * 46).toFixed(1), stroke: 'var(--ink)', 'stroke-width': 2, fill: 'none', opacity: 0.5 });
    }
    s += eye(76, 72, 19, st) + eye(124, 72, 19, st);
    s += grin({ cx: 100, cy: 114, w: 82, n: 9, st });
    return s;
  }
};

const SKIN_LIST = [
  { key: 'gnash', name: 'Gnash', tagline: 'Lives in the toy box. Eats anything left on the floor.', p: { body: '#d81b7a', shade: '#6d0b3b', glow: '#ff5fb0', teeth: '#fff6fb', eye: '#ffe14d', limb: '#8f1046' } },
  { key: 'sockmoth', name: 'The Sockmoth', tagline: 'Made of every sock that never came back.', p: { body: '#8e8ba6', shade: '#37364a', glow: '#c3c0dd', teeth: '#f2f0ff', eye: '#ff8ac7', limb: '#5b5872' } },
  { key: 'crumbler', name: 'Crumbler', tagline: 'Scraped itself together from under the sofa.', p: { body: '#a9752f', shade: '#472f0e', glow: '#e0a558', teeth: '#fff3dc', eye: '#9cff5e', limb: '#6b4818' } },
  { key: 'tangleboy', name: 'Tangleboy', tagline: 'A knot of chargers that learned to walk.', p: { body: '#3d5170', shade: '#18202e', glow: '#5cc8ff', teeth: '#e8f7ff', eye: '#5cc8ff', limb: '#4a6088' } },
  { key: 'mold-king', name: 'The Mold King', tagline: 'Crowned himself at the back of the fridge.', p: { body: '#5f8f3a', shade: '#213811', glow: '#9cff5e', teeth: '#f4ffe8', eye: '#ffc93d', limb: '#3d5c22' } },
  { key: 'dustwyrm', name: 'Dustwyrm', tagline: 'Long, grey, and full of things you dropped.', p: { body: '#7a7288', shade: '#2c2836', glow: '#b9aed4', teeth: '#fbf7ff', eye: '#ff6b6b', limb: '#4c4658' } },
  { key: 'static', name: 'Static', tagline: 'Came in on a channel nobody subscribes to.', p: { body: '#4a5a66', shade: '#161e24', glow: '#8fe9ff', teeth: '#eaffff', eye: '#8fe9ff', limb: '#3a4750' } },
  { key: 'chorus', name: 'The Chorus', tagline: 'One voice per mouth, and it knows your name.', p: { body: '#301426', shade: '#150912', glow: '#ff3b6b', teeth: '#ffe9f0', eye: '#ff3b6b', limb: '#4a2036' } },
  { key: 'drip', name: 'Drip', tagline: 'Left the tap running for eleven years.', p: { body: '#241b32', shade: '#0f0a17', glow: '#54ffd5', teeth: '#e8fff8', eye: '#54ffd5', limb: '#34284a' } },
  { key: 'wallcrack', name: 'Wallcrack', tagline: 'Not a shadow. The wall is not that thick.', p: { body: '#6a6660', shade: '#3a3733', glow: '#ff9a3d', teeth: '#fff2e2', eye: '#ff9a3d', limb: '#4a4741' } },
  { key: 'rattle', name: 'Rattle', tagline: 'Plays its own ribs when the house goes quiet.', p: { body: '#d9d2c2', shade: '#5f5849', glow: '#fff6e2', teeth: '#ffffff', eye: '#9cff5e', limb: '#8a8271' } },
  { key: 'hushpuppet', name: 'Hushpuppet', tagline: 'Somebody else is holding the strings.', p: { body: '#c9b48f', shade: '#5b4a30', glow: '#ffe6bd', teeth: '#fffaf0', eye: '#e0365b', limb: '#7d6845' } },
  { key: 'longlegs', name: 'Longlegs', tagline: 'Hangs in the corner. Counts everyone who walks under.', p: { body: '#161320', shade: '#07050c', glow: '#e8e4f5', teeth: '#f4f0ff', eye: '#e0213f', limb: '#241f33' } },
  { key: 'leaner', name: 'The Leaner', tagline: 'Stands at the end of the hall with its head on wrong.', p: { body: '#4a4360', shade: '#1a1626', glow: '#d6cff0', teeth: '#eae6ff', eye: '#d6cff0', limb: '#5c5478' } },
  { key: 'balloonhead', name: 'Balloonhead', tagline: 'Left over from a party nobody remembers.', p: { body: '#d8354a', shade: '#6c0f1d', glow: '#ff8a9c', teeth: '#fff2f4', eye: '#ffe14d', limb: '#8f1a2c' } }
];

window.FQMonsters = {
  STAGES: STAGES,
  SKINS: SKIN_LIST,
  svg: function (skinKey, stageKey, opts) {
    opts = opts || {};
    const sk = SKIN_LIST.find(s => s.key === skinKey) || SKIN_LIST[0];
    const st = STAGES.find(s => s.key === stageKey) || STAGES[0];
    const p = sk.p;
    const vars = '--body:' + p.body + ';--shade:' + p.shade + ';--glow:' + p.glow + ';--teeth:' + p.teeth +
      ';--eye:' + p.eye + ';--limb:' + (p.limb || p.shade) + ';--ink:#07030d;--sclera:#f7f4ff';
    const dread = opts.dread === undefined ? 0.55 : opts.dread;
    return '<svg viewBox="0 0 200 210" preserveAspectRatio="xMidYMid meet" style="' + vars + ';width:100%;height:100%;display:block">' +
      el('ellipse', { cx: 100, cy: 196, rx: 66 - 22 * st.wear, ry: 9, fill: '#000', opacity: 0.55 }) +
      el('ellipse', { cx: 100, cy: 196, rx: 74 - 22 * st.wear, ry: 13, fill: p.glow, opacity: 0.06 + 0.12 * dread }) +
      '<g style="transform:rotate(' + st.tilt + 'deg) scale(1.1);transform-origin:100px 172px;animation:fqbreath ' + st.breath + 's ease-in-out infinite">' +
        SKINS[sk.key](st) +
      '</g></svg>';
  },
  cardBg: function (skinKey, dread) {
    const sk = SKIN_LIST.find(s => s.key === skinKey) || SKIN_LIST[0];
    const hex = sk.p.glow, a = 0.05 + 0.14 * (dread === undefined ? 0.55 : dread);
    const n = parseInt(hex.slice(1), 16);
    const rgba = 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a.toFixed(3) + ')';
    return 'radial-gradient(120% 90% at 50% 92%, ' + rgba + ' 0%, #0d0718 45%, #0a0512 100%)';
  }
};

})();
