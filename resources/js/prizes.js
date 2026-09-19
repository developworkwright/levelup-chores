/* FQPrizes — prize-counter art, drawn in code so it ships verbatim.
 *
 * Same shape as cosmetics.js: one global, no build step, every item is a key +
 * a palette. Everything returns an SVG string on a 0 0 64 64 box with no
 * background, so it sits on the counter shelf, on the pet's floor and in the
 * parent console at any size.
 *
 *   FQPrizes.snack(key)   the thing the pet eats when you feed it
 *   FQPrizes.toy(key)     the thing it keeps and bats at
 *   FQPrizes.bed(key)     the thing on the floor it naps in
 *   FQPrizes.token(n)     the coin itself
 *   FQPrizes.CATALOG      slot / key / name / cost, priced by the brief's bands
 *
 * Sizing rule: a pet is 80-120px tall on a phone. A snack renders at 26px (the
 * size the emoji already drops at), a toy at 34px, a bed at 96px wide. The
 * style is chunky arcade: one dark outline, a top-lit gradient, a floor shadow
 * and one glint. Detail lives in silhouette and shading, never in thin lines,
 * so nothing here dies below the size it's shown at.
 *
 * Colours come only from the palettes; lighter and darker shades are mixed
 * from them at draw time by shade(). Gradient ids are unique per draw, so any
 * number of items can sit inline on one page.
 */
(function () {
  const ink = '#1b0f30';
  let n = 0;
  const uid = () => 'fqp' + (n++).toString(36);

  const hex = (c) => { const m = c.replace('#', ''); return [0, 2, 4].map(i => parseInt(m.slice(i, i + 2), 16)); };
  const shade = (c, k) => {
    const [r, g, b] = hex(c);
    const t = k < 0 ? 0 : 255, a = Math.abs(k);
    const mix = (v) => Math.round(v + (t - v) * a);
    return '#' + [mix(r), mix(g), mix(b)].map(v => v.toString(16).padStart(2, '0')).join('');
  };
  const box = (inner) => '<svg viewBox="0 0 64 64" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">' + inner + '</svg>';
  /* Top-lit vertical gradient. Returns [defs, fill]. */
  const lin = (c, up, down) => { const id = uid(); return ['<linearGradient id="' + id + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + shade(c, up == null ? .22 : up) + '"/><stop offset="1" stop-color="' + shade(c, down == null ? -.28 : down) + '"/></linearGradient>', 'url(#' + id + ')']; };
  /* Off-centre radial for round things. */
  const rad = (c) => { const id = uid(); return ['<radialGradient id="' + id + '" cx=".38" cy=".32" r=".8"><stop offset="0" stop-color="' + shade(c, .3) + '"/><stop offset=".6" stop-color="' + c + '"/><stop offset="1" stop-color="' + shade(c, -.38) + '"/></radialGradient>', 'url(#' + id + ')']; };
  const O = 'stroke="' + ink + '" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"';
  const o2 = 'stroke="' + ink + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"';
  const shadow = (cx, cy, rx) => '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + rx + '" ry="3" fill="' + ink + '" opacity=".38"/>';
  const glint = (cx, cy, rx, ry, rot) => '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + rx + '" ry="' + (ry || rx / 2) + '" fill="#ffffff" opacity=".55"' + (rot ? ' transform="rotate(' + rot + ' ' + cx + ' ' + cy + ')"' : '') + '/>';
  const dots = (pts, r, fill) => pts.map(([x, y]) => '<circle cx="' + x + '" cy="' + y + '" r="' + r + '" fill="' + fill + '"/>').join('');

  /* ---------------------------------------------------------------- snacks */
  const SNACKS = {
    meat: (c) => { const [d, f] = lin(c[0]); return box('<defs>' + d + '</defs>' + shadow(32, 56, 22) +
      '<rect x="14" y="22" width="36" height="24" rx="12" fill="' + f + '" ' + O + '/>' +
      '<path d="M16 15a7 7 0 1 1 0 14a7 7 0 1 1 0-14zM16 39a7 7 0 1 1 0 14a7 7 0 1 1 0-14z" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M22 28c4-2 8-2 12 0M24 38c4 2 8 2 12 0" stroke="' + shade(c[0], -.45) + '" stroke-width="2.5" stroke-linecap="round"/>' +
      '<rect x="36" y="27" width="10" height="4" rx="2" fill="' + c[2] + '"/>' + glint(24, 26, 5, 2, -20)); },
    burger: (c) => { const [d1, bun] = lin(c[0]); const [d2, patty] = lin(c[2], .1, -.4); return box('<defs>' + d1 + d2 + '</defs>' + shadow(32, 57, 22) +
      '<path d="M10 30a22 15 0 0 1 44 0z" fill="' + bun + '" ' + O + '/>' +
      '<path d="M8 30c4 6 8-4 12 2s8-6 12 0 8 6 12 0 8-6 12-2v5H8z" fill="' + c[1] + '" ' + O + '/>' +
      '<rect x="10" y="35" width="44" height="8" rx="4" fill="' + patty + '" ' + O + '/>' +
      '<path d="M14 40h36a7 7 0 0 1 4 4l-2 4c-3 2-5 4-8 4H20c-3 0-5-2-8-4l-2-4a7 7 0 0 1 4-4z" fill="' + bun + '" ' + O + '/>' +
      '<path d="M28 43l4 5 4-5" fill="' + c[3] + '" stroke="' + shade(c[3], -.5) + '" stroke-width="1.5"/>' +
      dots([[22, 21], [31, 17], [41, 21], [36, 24], [26, 26]], 1.6, c[3]) + glint(22, 22, 5, 2.5, -25)); },
    pizza: (c) => { const [d1, cheese] = lin(c[0], .18, -.15); return box('<defs>' + d1 + '</defs>' + shadow(32, 60, 22) +
      '<path d="M32 8 56 52H8z" fill="' + cheese + '" ' + O + '/>' +
      '<path d="M8 52h48l-3 6H11z" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M12 52c6-4 12-4 18 0s12 4 20 0" stroke="' + shade(c[1], -.4) + '" stroke-width="2" fill="none"/>' +
      [[32, 30], [22, 44], [42, 44]].map(([x, y]) => '<circle cx="' + x + '" cy="' + y + '" r="4.5" fill="' + c[2] + '" ' + o2 + '/><circle cx="' + (x - 1) + '" cy="' + (y - 1) + '" r="1.4" fill="' + shade(c[2], -.5) + '"/>').join('') +
      '<path d="M28 24c2 4 6 4 8 0" stroke="' + shade(c[0], -.45) + '" stroke-width="2" fill="none"/>' + glint(30, 17, 3, 1.5, -60)); },
    donut: (c) => { const [d1, dough] = lin(c[0]); const [d2, icing] = lin(c[1], .18, -.1); return box('<defs>' + d1 + d2 + '</defs>' + shadow(32, 58, 22) +
      '<circle cx="32" cy="33" r="22" fill="' + dough + '" ' + O + '/>' +
      '<path d="M10 33a22 22 0 0 1 44 0c0 4-4 4-6 7s-6-2-8 1-4 6-8 3-6 2-8-1-6-1-8-4-6-2-6-6z" fill="' + icing + '" ' + O + '/>' +
      '<circle cx="32" cy="33" r="7" fill="#0a0512" ' + O + '/>' +
      [[20, 22, -30, c[2]], [40, 20, 25, c[3]], [30, 15, 5, c[2]], [46, 30, 70, c[2]], [18, 33, 80, c[3]], [36, 27, -40, c[3]]].map(([x, y, r, col]) => '<rect x="' + (x - 3) + '" y="' + (y - 1.5) + '" width="6" height="3" rx="1.5" fill="' + col + '" transform="rotate(' + r + ' ' + x + ' ' + y + ')"/>').join('') +
      glint(24, 19, 4, 2, -30)); },
    sushi: (c) => { const [d1, rice] = lin(c[0], .05, -.14); const [d2, fish] = lin(c[1], .15, -.2); return box('<defs>' + d1 + d2 + '</defs>' + shadow(32, 56, 24) +
      '<rect x="10" y="28" width="44" height="26" rx="8" fill="' + rice + '" ' + O + '/>' +
      dots([[16, 36], [20, 46], [46, 38], [50, 48], [16, 50]], 1.6, shade(c[0], -.2)) +
      '<rect x="25" y="28" width="14" height="26" fill="' + c[2] + '" ' + O + '/>' +
      '<path d="M10 28a22 10 0 0 1 44 0z" fill="' + fish + '" ' + O + '/>' +
      '<path d="M16 25c3-3 6-3 9 0M30 22c3-3 6-3 9 0M42 25c3-3 6-3 9 0" stroke="' + c[3] + '" stroke-width="2.5" fill="none" stroke-linecap="round"/>' + glint(24, 22, 4, 2, -15)); },
    taco: (c) => { const [d1, shell] = lin(c[0], .12, -.22); return box('<defs>' + d1 + '</defs>' + shadow(32, 58, 24) +
      '<path d="M8 46a24 24 0 0 1 48 0z" fill="' + shade(c[0], -.3) + '" ' + O + '/>' +
      '<path d="M12 44c2-6 5-9 8-8s4 6 8 3 4-7 8-4 4 6 8 3 6-2 8 6z" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M8 46a24 24 0 0 1 24-24c-8 8-10 16-8 24z" fill="' + shell + '" ' + O + '/>' +
      '<path d="M56 46a24 24 0 0 0-24-24c8 8 10 16 8 24z" fill="' + shell + '" ' + O + '/>' +
      '<path d="M22 46c-2 6 0 10 4 10h12c4 0 6-4 4-10z" fill="' + c[2] + '" ' + O + '/>' +
      dots([[26, 40], [38, 38], [32, 43]], 2.6, c[3]) + glint(18, 34, 3, 5, 20)); },
    icecream: (c) => { const [d1, cone] = lin(c[1], .1, -.3); return box('<defs>' + d1 + '</defs>' + shadow(32, 59, 12) +
      '<path d="M20 32h24l-12 26z" fill="' + cone + '" ' + O + '/>' +
      '<path d="M24 38l16 8M22 44l10 8M26 34l14 10" stroke="' + shade(c[1], -.45) + '" stroke-width="1.8"/>' +
      '<path d="M15 26a11 11 0 0 1 22-1 10 10 0 0 1 12 1c0 5-3 9-6 10-2 4-6 4-8 2-2 3-6 3-8 1-3 1-8-1-12-13z" fill="' + c[0] + '" ' + O + '/>' +
      '<circle cx="40" cy="25" r="10" fill="' + c[2] + '" ' + O + '/>' +
      '<path d="M30 22c-1 5 0 9 3 12" stroke="' + shade(c[0], -.35) + '" stroke-width="2" fill="none"/>' +
      '<path d="M32 10c-2-4 3-6 4-2" stroke="' + shade(c[3], -.4) + '" stroke-width="2" fill="none"/><circle cx="33" cy="11" r="4.5" fill="' + c[3] + '" ' + O + '/>' +
      glint(22, 21, 4, 2, -30) + glint(44, 20, 3, 1.5, -30)); },
    hotdog: (c) => { const [d1, bun] = lin(c[0], .2, -.25); const [d2, saus] = lin(c[1], .18, -.28); return box('<defs>' + d1 + d2 + '</defs>' + shadow(32, 58, 26) +
      '<path d="M8 40c0-6 4-8 8-8h32c4 0 8 2 8 8s-4 14-8 14H16c-4 0-8-8-8-14z" fill="' + bun + '" ' + O + '/>' +
      '<rect x="5" y="25" width="54" height="15" rx="7.5" fill="' + saus + '" ' + O + '/>' +
      '<path d="M12 34c2-3 6-3 8 0s6 3 8 0 6-3 8 0 6 3 8 0 5-3 8 0" stroke="' + c[2] + '" stroke-width="3.5" fill="none" stroke-linecap="round"/>' +
      '<path d="M10 46c8 3 36 3 44 0" stroke="' + shade(c[0], -.45) + '" stroke-width="2" fill="none"/>' + glint(16, 29, 6, 2, -8)); },
    cupcake: (c) => { const [d1, cs] = lin(c[0], .12, -.3); const [d2, frost] = lin(c[1], .2, -.15); return box('<defs>' + d1 + d2 + '</defs>' + shadow(32, 58, 20) +
      '<path d="M14 36h36l-4 20H18z" fill="' + cs + '" ' + O + '/>' +
      '<path d="M20 38l2 16M28 38l1 16M36 38l-1 16M44 38l-2 16" stroke="' + shade(c[0], -.45) + '" stroke-width="2"/>' +
      '<path d="M12 36c0-8 6-12 10-12 2-6 8-8 12-6 2-4 8-6 12-2 6 2 8 8 6 12 2 4 0 8-4 8H16c-2 0-4 0-4 0z" fill="' + frost + '" ' + O + '/>' +
      '<path d="M18 36c2 4 4 4 6 0s4-4 6 0 4 4 6 0 4-4 6 0 4 4 6 0" stroke="' + c[3] + '" stroke-width="2" fill="none" opacity=".7"/>' +
      '<path d="M32 18c0-4 2-7 5-9" stroke="' + shade(c[2], -.4) + '" stroke-width="2" fill="none"/><circle cx="32" cy="19" r="5" fill="' + c[2] + '" ' + O + '/>' +
      dots([[22, 30], [40, 27], [30, 26]], 1.6, c[3]) + glint(22, 26, 4, 2, -25) + glint(30, 17, 1.6, 1)); },
    fishbone: (c) => { const [d1, bone] = lin(c[0], .1, -.25); return box('<defs>' + d1 + '</defs>' + shadow(32, 58, 24) +
      '<path d="M6 40l14-11v22z" fill="' + bone + '" ' + O + '/>' +
      '<path d="M20 40h24" stroke="' + ink + '" stroke-width="7" stroke-linecap="round"/><path d="M20 40h24" stroke="' + c[0] + '" stroke-width="3.5" stroke-linecap="round"/>' +
      '<path d="M25 40l-1-11M25 40l-1 11M32 40l-1-12M32 40l-1 12M39 40v-9M39 40v9" stroke="' + ink + '" stroke-width="5.5" stroke-linecap="round"/><path d="M25 40l-1-11M25 40l-1 11M32 40l-1-12M32 40l-1 12M39 40v-9M39 40v9" stroke="' + c[0] + '" stroke-width="2.5" stroke-linecap="round"/>' +
      '<path d="M43 28a11 12 0 0 1 0 24z" fill="' + bone + '" ' + O + '/>' +
      '<path d="M48 34c-3 2-5 5-5 6" stroke="' + shade(c[0], -.35) + '" stroke-width="2" fill="none"/>' +
      '<circle cx="49" cy="36" r="3" fill="#ffffff" ' + o2 + '/><circle cx="49.5" cy="36.5" r="1.4" fill="' + c[1] + '"/>' +
      '<path d="M46 46c2 1 4 1 6 0" stroke="' + ink + '" stroke-width="2" fill="none"/>'); },
    eyeball: (c) => { const [d1, jelly] = lin(c[0], .2, -.25); const [d2, white] = rad(c[1]); return box('<defs>' + d1 + d2 + '</defs>' + shadow(32, 58, 24) +
      '<path d="M10 55c-4-8 2-15 9-13 3-10 22-10 26 0 7-2 13 5 9 13z" fill="' + jelly + '" ' + O + '/>' +
      '<path d="M16 50c6 4 26 4 32 0" stroke="' + shade(c[0], -.4) + '" stroke-width="2" fill="none"/>' +
      '<circle cx="32" cy="33" r="15" fill="' + white + '" ' + O + '/>' +
      '<path d="M20 28c4-2 6-1 9 1M44 40c-2-3-4-4-7-4M22 41c3-1 5 0 7 1" stroke="' + c[2] + '" stroke-width="1.6" fill="none" opacity=".8"/>' +
      '<circle cx="34" cy="33" r="7.5" fill="' + c[2] + '" ' + O + '/>' +
      '<circle cx="34" cy="33" r="7.5" fill="none" stroke="' + shade(c[2], -.4) + '" stroke-width="2" stroke-dasharray="2 2.5"/>' +
      '<circle cx="34" cy="33" r="3.4" fill="' + ink + '"/>' + glint(27, 26, 3.2, 2, -35) + glint(37, 30, 1.2, 1)); },
    drumstick: (c) => { const [d1, meat] = lin(c[0], .2, -.3); return box('<defs>' + d1 + '</defs>' + shadow(32, 59, 22) +
      '<path d="M40 40l12 12" stroke="' + ink + '" stroke-width="9" stroke-linecap="round"/><path d="M40 40l12 12" stroke="' + c[1] + '" stroke-width="5" stroke-linecap="round"/>' +
      '<path d="M50 44a4 4 0 1 1 6 6 4 4 0 1 1-6 6 4 4 0 1 1-6-6z" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M18 14c14-3 26 7 26 20 0 9-7 15-15 14-10-1-18-12-13-24 1-3 2-8 2-10z" fill="' + meat + '" ' + O + '/>' +
      '<path d="M22 22c6-2 12 0 15 6M20 32c4 8 10 12 16 12" stroke="' + shade(c[0], -.4) + '" stroke-width="2" fill="none"/>' +
      dots([[28, 38], [35, 30], [24, 28]], 1.8, shade(c[0], -.5)) +
      '<path d="M22 20c4-3 8-3 12-1" stroke="' + c[2] + '" stroke-width="3" fill="none" stroke-linecap="round"/>' + glint(26, 24, 4, 2, -35)); },
  };

  const SNACK_PALETTES = {
    meat: ['#c46a3a', '#f2ecdf', '#8a4322'],
    burger: ['#f3b74b', '#7dffb0', '#a4522c', '#fff3d1'],
    pizza: ['#ffd36b', '#e9a23b', '#e0365b'],
    donut: ['#f0c98a', '#ff8ac7', '#ffe14d', '#7dffb0'],
    sushi: ['#f7f0ff', '#ff8a5c', '#1b0f30', '#7dffb0'],
    taco: ['#ffd36b', '#7dffb0', '#e9a23b', '#e0365b'],
    icecream: ['#ff8ac7', '#e0a769', '#c9a0ff', '#e0365b'],
    hotdog: ['#c46a3a', '#f3b74b', '#ffe14d'],
    cupcake: ['#c9a0ff', '#7dffb0', '#e0365b', '#2e1b4d'],
    fishbone: ['#f2ecdf', '#1b0f30'],
    eyeball: ['#7dffb0', '#f7f0ff', '#e0365b', '#ffffff'],
    drumstick: ['#c46a3a', '#f2ecdf', '#f3b74b'],
  };

  /* ------------------------------------------------------------------ toys */
  const TOYS = {
    ball: (c) => { const [d, f] = rad(c[0]); return box('<defs>' + d + '</defs>' + shadow(32, 57, 18) +
      '<circle cx="32" cy="32" r="22" fill="' + f + '" ' + O + '/>' +
      '<path d="M10 32h44" stroke="' + c[1] + '" stroke-width="7"/><path d="M10 32h44" ' + o2 + '/>' +
      '<path d="M32 10c9 12 9 32 0 44M32 10c-9 12-9 32 0 44" ' + O + ' fill="none"/>' +
      '<circle cx="32" cy="32" r="5" fill="' + c[1] + '" ' + o2 + '/>' + glint(23, 21, 5, 3, -40)); },
    bone: (c) => { const [d, f] = lin(c[0], .1, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 56, 24) +
      '<path d="M18 26a7 7 0 1 0-4 12 7 7 0 1 0 4 12c2 2 4 2 6 0h16c2 2 4 2 6 0a7 7 0 1 0 4-12 7 7 0 1 0-4-12c-2-2-4-2-6 0H24c-2-2-4-2-6 0z" fill="' + f + '" ' + O + '/>' +
      '<path d="M22 34h20" stroke="' + shade(c[0], -.3) + '" stroke-width="2"/>' +
      '<path d="M14 30l3 3M50 30l-3 3M14 44l3-3M50 44l-3-3" stroke="' + shade(c[0], -.3) + '" stroke-width="2"/>' + glint(22, 28, 3, 1.8, -30) + glint(44, 28, 3, 1.8, -30)); },
    yarn: (c) => { const [d, f] = rad(c[0]); return box('<defs>' + d + '</defs>' + shadow(32, 58, 20) +
      '<circle cx="32" cy="35" r="20" fill="' + f + '" ' + O + '/>' +
      '<path d="M15 27c10 2 20 10 26 22M24 17c8 6 14 16 16 30M13 40c12 0 22 6 28 14M20 22c12-2 24 6 30 16" stroke="' + shade(c[0], -.35) + '" stroke-width="2.6" fill="none" stroke-linecap="round"/>' +
      '<path d="M18 30c8 0 16 6 22 16M28 18c6 8 10 18 10 30" stroke="' + shade(c[0], .3) + '" stroke-width="1.6" fill="none" opacity=".7"/>' +
      '<path d="M48 22c5-5 8-9 10-14" stroke="' + ink + '" stroke-width="5" stroke-linecap="round"/><path d="M48 22c5-5 8-9 10-14" stroke="' + c[1] + '" stroke-width="2.5" stroke-linecap="round"/>' + glint(24, 25, 4, 2.5, -35)); },
    frisbee: (c) => { const [d, f] = lin(c[1], .2, -.2); return box('<defs>' + d + '</defs>' + shadow(32, 58, 26) +
      '<ellipse cx="32" cy="37" rx="25" ry="11" fill="' + c[0] + '" ' + O + '/>' +
      '<ellipse cx="32" cy="32" rx="25" ry="11" fill="' + f + '" ' + O + '/>' +
      '<ellipse cx="32" cy="32" rx="19" ry="7" fill="none" stroke="' + shade(c[1], -.3) + '" stroke-width="2"/>' +
      '<ellipse cx="32" cy="32" rx="10" ry="4" fill="' + c[2] + '" ' + o2 + '/>' +
      '<ellipse cx="32" cy="32" rx="4" ry="1.6" fill="' + c[1] + '"/>' + glint(18, 28, 6, 1.8, -8)); },
    mouse: (c) => { const [d, f] = lin(c[0], .15, -.3); return box('<defs>' + d + '</defs>' + shadow(30, 57, 20) +
      '<path d="M46 42c8-1 12 5 8 10" stroke="' + ink + '" stroke-width="5.5" stroke-linecap="round" fill="none"/><path d="M46 42c8-1 12 5 8 10" stroke="' + c[2] + '" stroke-width="3" stroke-linecap="round" fill="none"/>' +
      '<path d="M12 42c0-10 8-16 18-16 12 0 18 8 20 14 1 4-2 6-6 6H18c-4 0-6-1-6-4z" fill="' + f + '" ' + O + '/>' +
      '<circle cx="17" cy="28" r="7" fill="' + c[1] + '" ' + O + '/><circle cx="17" cy="28" r="3" fill="' + shade(c[1], -.35) + '"/>' +
      '<circle cx="42" cy="36" r="2.6" fill="' + ink + '"/><circle cx="41.4" cy="35.2" r=".9" fill="#ffffff"/>' +
      '<path d="M50 40l6-3M50 41l7 0" stroke="' + ink + '" stroke-width="1.5"/>' +
      '<rect x="22" y="44" width="6" height="4" rx="1" fill="' + c[2] + '" ' + o2 + '/><rect x="26" y="48" width="6" height="4" rx="1" fill="' + c[2] + '" ' + o2 + '/>' + glint(28, 32, 5, 2.5, -20)); },
    rocket: (c) => { const [d, f] = lin(c[0], .05, -.25); return box('<defs>' + d + '</defs>' + shadow(32, 60, 14) +
      '<path d="M26 48h12l-6 12z" fill="' + c[3] + '" ' + O + '/><path d="M29 48h6l-3 6z" fill="' + shade(c[3], .5) + '"/>' +
      '<path d="M20 38l-9 12h13zM44 38l9 12H40z" fill="' + c[2] + '" ' + O + '/>' +
      '<path d="M32 6c10 8 13 21 13 32H19c0-11 3-24 13-32z" fill="' + f + '" ' + O + '/>' +
      '<path d="M32 6c-3 8-4 20-4 32" stroke="' + shade(c[0], -.25) + '" stroke-width="2" fill="none"/>' +
      '<circle cx="32" cy="26" r="6.5" fill="' + c[1] + '" ' + O + '/><circle cx="32" cy="26" r="6.5" fill="none" stroke="' + shade(c[1], -.3) + '" stroke-width="1.5"/>' +
      '<rect x="20" y="38" width="24" height="4" fill="' + c[2] + '" ' + o2 + '/>' + glint(30, 23, 2, 1.2, -30) + glint(37, 15, 2, 4, 15)); },
    skull: (c) => { const [d, f] = rad(c[0]); return box('<defs>' + d + '</defs>' + shadow(32, 57, 18) +
      '<path d="M12 28a20 20 0 0 1 40 0c0 7-3 11-6 14v6a3 3 0 0 1-3 3H21a3 3 0 0 1-3-3v-6c-3-3-6-7-6-14z" fill="' + f + '" ' + O + '/>' +
      '<path d="M18 46h28" ' + o2 + '/>' +
      '<path d="M26 46v6M32 46v6M38 46v6" stroke="' + ink + '" stroke-width="2.2"/>' +
      '<path d="M20 28a5 6 0 1 1 10 2c-1 3-4 4-6 3s-5-2-4-5zM44 28a5 6 0 1 0-10 2c1 3 4 4 6 3s5-2 4-5z" fill="' + c[1] + '" ' + o2 + '/>' +
      '<circle cx="25" cy="30" r="2" fill="' + c[2] + '"/><circle cx="39" cy="30" r="2" fill="' + c[2] + '"/>' +
      '<path d="M30 40l2-4 2 4z" fill="' + c[1] + '"/>' +
      '<path d="M14 24c4-4 8-6 12-7" stroke="' + shade(c[0], -.25) + '" stroke-width="2" fill="none"/>' + glint(24, 18, 5, 2.5, -30)); },
    tennis: (c) => { const [d, f] = rad(c[0]); return box('<defs>' + d + '</defs>' + shadow(32, 57, 18) +
      '<circle cx="32" cy="32" r="22" fill="' + f + '" ' + O + '/>' +
      '<path d="M15 17c11 8 11 22 0 30M49 17c-11 8-11 22 0 30" stroke="' + ink + '" stroke-width="6" fill="none" stroke-linecap="round"/>' +
      '<path d="M15 17c11 8 11 22 0 30M49 17c-11 8-11 22 0 30" stroke="' + c[1] + '" stroke-width="3" fill="none" stroke-linecap="round"/>' +
      dots([[28, 24], [36, 22], [30, 42], [38, 40], [24, 33], [40, 32]], 1, shade(c[0], -.3)) + glint(25, 22, 5, 3, -40)); },
    duck: (c) => { const [d, f] = lin(c[0], .18, -.25); return box('<defs>' + d + '</defs>' + shadow(31, 58, 20) +
      '<path d="M10 40c0-8 8-12 18-12h6c8 0 14 4 14 12 0 8-8 14-20 14S10 48 10 40z" fill="' + f + '" ' + O + '/>' +
      '<path d="M8 36c-4 2-4 6 0 8" fill="' + c[0] + '" ' + O + '/>' +
      '<path d="M18 40c4-2 10-2 14 0" stroke="' + shade(c[0], -.3) + '" stroke-width="2" fill="none"/>' +
      '<circle cx="40" cy="22" r="12" fill="' + f + '" ' + O + '/>' +
      '<path d="M51 20c6 0 10 2 9 5-1 2-5 3-9 2z" fill="' + c[1] + '" ' + O + '/><path d="M51 24c3 1 6 1 9 0" ' + o2 + '/>' +
      '<circle cx="43" cy="19" r="3.4" fill="' + c[2] + '" ' + o2 + '/><circle cx="43.8" cy="19.4" r="1.6" fill="' + ink + '"/><circle cx="43" cy="18.4" r=".7" fill="#ffffff"/>' +
      '<path d="M36 12c1-3 4-4 6-2" stroke="' + ink + '" stroke-width="2" fill="none"/>' + glint(34, 17, 4, 2, -35) + glint(20, 36, 5, 2.5, -15)); },
    spiky: (c) => { const [d, f] = rad(c[1]); return box('<defs>' + d + '</defs>' + shadow(32, 60, 18) +
      '<path d="M32 4l5 10 10-5-2 11 11 2-9 7 9 7-11 2 2 11-10-5-5 10-5-10-10 5 2-11-11-2 9-7-9-7 11-2-2-11 10 5z" fill="' + c[0] + '" ' + O + '/>' +
      '<path d="M32 10l3 6M46 12l-2 7M52 24l-7 1M52 40l-7-1M46 52l-2-7M32 54l3-6M18 52l2-7M12 40l7-1M12 24l7 1M18 12l2 7" stroke="' + shade(c[0], .4) + '" stroke-width="1.6" opacity=".8"/>' +
      '<circle cx="32" cy="32" r="12" fill="' + f + '" ' + O + '/>' +
      '<circle cx="32" cy="32" r="12" fill="none" stroke="' + shade(c[1], -.35) + '" stroke-width="2" stroke-dasharray="3 3"/>' +
      glint(27, 27, 3.5, 2, -40)); },
  };

  const TOY_PALETTES = {
    ball: ['#e0365b', '#fff0f3'],
    bone: ['#f2ecdf', '#cbbfae'],
    yarn: ['#c9a0ff', '#7dffb0'],
    frisbee: ['#1f7a52', '#7dffb0', '#0a0512'],
    mouse: ['#b0a3cc', '#ff8ac7', '#ffe14d'],
    rocket: ['#f7f0ff', '#54e8d0', '#e0365b', '#ffe14d'],
    skull: ['#f2ecdf', '#1b0f30', '#7dffb0'],
    tennis: ['#d8ff5a', '#f7f0ff', '#ffffff'],
    duck: ['#ffe14d', '#ff8a5c', '#f7f0ff'],
    spiky: ['#c9a0ff', '#e0365b', '#f7f0ff'],
  };

  /* ------------------------------------------------------------------ beds */
  /* Underside at y=56. The band y=34-44 is where the pet lies, so it stays
   * open — nothing is drawn in front of it. */
  const BEDS = {
    cushion: (c) => { const [d, f] = lin(c[0], .15, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 57, 27) +
      '<path d="M6 44c0-8 6-12 12-12h28c6 0 12 4 12 12s-6 12-12 12H18c-6 0-12-4-12-12z" fill="' + f + '" ' + O + '/>' +
      '<path d="M12 42c0-4 3-6 7-6h26c4 0 7 2 7 6s-3 8-7 8H19c-4 0-7-4-7-8z" fill="' + c[1] + '" ' + o2 + '/>' +
      '<path d="M20 38l2 10M32 38v10M44 38l-2 10" stroke="' + c[2] + '" stroke-width="1.6" opacity=".45"/>' +
      dots([[8, 34], [56, 34], [8, 54], [56, 54]], 2.2, c[2]) +
      '<path d="M10 50c6 4 38 4 44 0" stroke="' + shade(c[0], -.45) + '" stroke-width="2" fill="none"/>' + glint(16, 35, 5, 1.6, -10)); },
    basket: (c) => { const [d, f] = lin(c[0], .1, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 57, 25) +
      '<path d="M9 33h46l-4 23H13z" fill="' + f + '" ' + O + '/>' +
      '<path d="M12 40h40M13 46h38M14 52h36" stroke="' + c[2] + '" stroke-width="2" opacity=".55"/>' +
      '<path d="M16 34l1 22M24 34l1 22M32 34v22M40 34l-1 22M48 34l-1 22" stroke="' + c[2] + '" stroke-width="2.4" opacity=".55"/>' +
      '<rect x="5" y="27" width="54" height="9" rx="4.5" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M10 31.5h44" stroke="' + shade(c[1], -.35) + '" stroke-width="2" stroke-dasharray="4 3"/>' + glint(14, 29, 4, 1.4)); },
    beanbag: (c) => { const [d, f] = rad(c[0]); return box('<defs>' + d + '</defs>' + shadow(32, 58, 26) +
      '<path d="M32 26c17 0 25 9 25 17s-12 13-25 13S7 51 7 43s8-17 25-17z" fill="' + f + '" ' + O + '/>' +
      '<path d="M12 44c6 6 34 6 40 0" stroke="' + c[1] + '" stroke-width="3" fill="none" stroke-linecap="round"/>' +
      '<path d="M14 36c4 5 32 5 36 0" stroke="' + shade(c[0], -.4) + '" stroke-width="2" fill="none"/>' +
      '<path d="M28 26l4 8 4-8" stroke="' + shade(c[0], -.4) + '" stroke-width="2" fill="none"/>' + glint(20, 33, 6, 2.5, -15)); },
    racer: (c) => { const [d, f] = lin(c[1], .15, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 60, 26) +
      '<path d="M6 44h52v9H6z" fill="' + c[0] + '" ' + O + '/>' +
      '<path d="M12 44l6-14h28l6 14z" fill="' + f + '" ' + O + '/>' +
      '<path d="M22 32h20l3 8H19z" fill="' + c[2] + '" ' + o2 + '/>' +
      '<path d="M10 46h44" stroke="' + shade(c[0], -.4) + '" stroke-width="2" stroke-dasharray="5 3"/>' +
      '<circle cx="18" cy="53" r="6.5" fill="' + c[2] + '" ' + O + '/><circle cx="18" cy="53" r="2.5" fill="' + c[0] + '"/>' +
      '<circle cx="46" cy="53" r="6.5" fill="' + c[2] + '" ' + O + '/><circle cx="46" cy="53" r="2.5" fill="' + c[0] + '"/>' +
      '<circle cx="30" cy="48" r="1.5" fill="' + c[1] + '"/><circle cx="36" cy="48" r="1.5" fill="' + c[1] + '"/>' + glint(24, 32, 3, 1.2, -25)); },
    cloud: (c) => { const [d, f] = lin(c[0], .05, -.2); return box('<defs>' + d + '</defs>' + shadow(32, 59, 22) +
      '<path d="M15 50a10 10 0 0 1 1-20 13 13 0 0 1 25-4 10 10 0 0 1 8 24z" fill="' + f + '" ' + O + '/>' +
      '<path d="M12 44a6 6 0 0 0 4 6M46 50a6 6 0 0 0 4-8" stroke="' + shade(c[0], -.25) + '" stroke-width="2" fill="none"/>' +
      '<path d="M24 42c2 2 4 2 6 0M36 44c2 2 4 2 6 0" stroke="' + c[1] + '" stroke-width="2.4" fill="none" stroke-linecap="round"/>' +
      dots([[10, 40], [54, 36], [30, 24]], 1.6, c[1]) + glint(24, 32, 5, 2.5, -20)); },
    throne: (c) => { const [d, f] = lin(c[0], .15, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 59, 24) +
      '<path d="M12 54V20l8 8 12-14 12 14 8-8v34z" fill="' + f + '" ' + O + '/>' +
      '<path d="M18 46h28M18 50h28" stroke="' + shade(c[0], -.35) + '" stroke-width="2"/>' +
      '<rect x="8" y="52" width="48" height="6" rx="3" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M16 32h6M42 32h6" stroke="' + shade(c[0], -.35) + '" stroke-width="2"/>' +
      '<circle cx="32" cy="34" r="4" fill="' + c[2] + '" ' + o2 + '/><circle cx="20" cy="26" r="2" fill="' + c[2] + '"/><circle cx="44" cy="26" r="2" fill="' + c[2] + '"/>' + glint(24, 22, 2, 3, 30)); },
    coffin: (c) => { const [d, f] = lin(c[0], .12, -.35); const [d2, lining] = lin(c[1], .1, -.35); return box('<defs>' + d + d2 + '</defs>' + shadow(32, 59, 24) +
      '<path d="M10 30l7-7h30l7 7-4 27H14z" fill="' + f + '" ' + O + '/>' +
      '<path d="M17 33h30l-3 20H20z" fill="' + lining + '" ' + o2 + '/>' +
      '<path d="M22 37l1 12M32 36v14M42 37l-1 12" stroke="' + shade(c[1], -.4) + '" stroke-width="1.6" opacity=".6"/>' +
      '<path d="M12 44l-3 1M52 44l3 1" stroke="' + c[2] + '" stroke-width="2"/>' +
      '<path d="M32 12v10M27 17h10" stroke="' + ink + '" stroke-width="5.5" stroke-linecap="round"/><path d="M32 12v10M27 17h10" stroke="' + c[2] + '" stroke-width="3" stroke-linecap="round"/>' +
      dots([[14, 30], [50, 30], [16, 54], [48, 54]], 1.6, c[2]) + glint(20, 27, 3, 1.2, -20)); },
    kennel: (c) => { const [d, f] = lin(c[0], .12, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 59, 26) +
      '<path d="M6 30L32 6l26 24v26H6z" fill="' + f + '" ' + O + '/>' +
      '<path d="M10 34h44M10 42h44M10 50h44" stroke="' + shade(c[0], -.4) + '" stroke-width="1.6" opacity=".6"/>' +
      '<path d="M2 32L32 4l30 28" stroke="' + ink + '" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M2 32L32 4l30 28" stroke="' + c[1] + '" stroke-width="4" stroke-linecap="round" fill="none"/>' +
      '<path d="M12 56V40a20 16 0 0 1 40 0v16z" fill="' + c[2] + '" ' + O + '/>' +
      '<path d="M16 56V41a16 12 0 0 1 32 0v15" stroke="' + shade(c[0], -.5) + '" stroke-width="2" fill="none"/>' +
      '<circle cx="32" cy="17" r="3.5" fill="' + c[3] + '" ' + o2 + '/>' + glint(22, 12, 3, 1.4, 45)); },
    hammock: (c) => { const [d, f] = lin(c[1], .12, -.3); return box('<defs>' + d + '</defs>' + shadow(9, 58, 5) + shadow(55, 58, 5) +
      '<path d="M9 56V20M55 56V20" stroke="' + ink + '" stroke-width="7" stroke-linecap="round"/><path d="M9 56V20M55 56V20" stroke="' + c[0] + '" stroke-width="4" stroke-linecap="round"/>' +
      '<path d="M8 22l6 6M56 22l-6 6" ' + o2 + '/>' +
      '<path d="M9 24c6 22 40 22 46 0-4 18-42 18-46 0z" fill="' + f + '" ' + O + '/>' +
      '<path d="M9 24c6 22 40 22 46 0" stroke="' + shade(c[1], -.4) + '" stroke-width="2" fill="none"/>' +
      '<path d="M16 32c3 9 29 9 32 0M14 28c3 12 33 12 36 0" stroke="' + c[2] + '" stroke-width="1.6" fill="none" opacity=".5"/>' +
      '<path d="M22 28v12M32 30v12M42 28v12" stroke="' + c[2] + '" stroke-width="1.8" opacity=".45"/>' +
      '<circle cx="9" cy="18" r="3" fill="' + c[0] + '" ' + o2 + '/><circle cx="55" cy="18" r="3" fill="' + c[0] + '" ' + o2 + '/>'); },
    pumpkin: (c) => { const [d, f] = lin(c[0], .15, -.3); return box('<defs>' + d + '</defs>' + shadow(32, 59, 24) +
      '<path d="M10 42c0-10 8-16 22-16s22 6 22 16-6 16-22 16S10 52 10 42z" fill="' + f + '" ' + O + '/>' +
      '<path d="M20 30c-3 6-3 18 0 26M32 27v30M44 30c3 6 3 18 0 26" stroke="' + shade(c[0], -.35) + '" stroke-width="2" fill="none"/>' +
      '<path d="M13 35c8-6 30-6 38 0c-4 4-8 5-19 5S17 39 13 35z" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M18 34c6 3 22 3 28 0" stroke="' + c[2] + '" stroke-width="2" fill="none" opacity=".8"/>' +
      '<path d="M32 27c-1-6 2-10 6-13" stroke="' + ink + '" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M32 27c-1-6 2-10 6-13" stroke="' + shade(c[2], -.5) + '" stroke-width="4" stroke-linecap="round" fill="none"/>' +
      '<path d="M22 50l3-5 3 5M36 50l3-5 3 5" fill="' + shade(c[0], -.5) + '" ' + o2 + '/>' + glint(22, 32, 3, 1.4, -20)); },
    cauldron: (c) => { const [d, f] = lin(c[0], .15, -.35); return box('<defs>' + d + '</defs>' + shadow(32, 61, 22) +
      '<path d="M18 54l-5 6M46 54l5 6" stroke="' + ink + '" stroke-width="6" stroke-linecap="round"/><path d="M18 54l-5 6M46 54l5 6" stroke="' + shade(c[0], -.2) + '" stroke-width="3" stroke-linecap="round"/>' +
      '<path d="M11 36c0 12 8 21 21 21s21-9 21-21z" fill="' + f + '" ' + O + '/>' +
      '<path d="M14 44c4 6 10 9 18 9s14-3 18-9" stroke="' + shade(c[0], -.5) + '" stroke-width="2" fill="none"/>' +
      '<path d="M16 42h32" stroke="' + shade(c[0], .25) + '" stroke-width="1.6" opacity=".6"/>' +
      '<ellipse cx="32" cy="36" rx="23" ry="6.5" fill="' + c[1] + '" ' + O + '/>' +
      '<path d="M14 36c6 3 30 3 36 0" stroke="' + c[2] + '" stroke-width="2" fill="none" opacity=".8"/>' +
      dots([[22, 35], [40, 34], [31, 37]], 2, c[2]) + '<circle cx="24" cy="30" r="1.8" fill="' + c[1] + '" opacity=".8"/><circle cx="42" cy="28" r="1.3" fill="' + c[1] + '" opacity=".6"/>' + glint(20, 40, 3, 1.4, -20)); },
  };

  const BED_PALETTES = {
    cushion: ['#c9a0ff', '#2e1b4d', '#f7f0ff'],
    basket: ['#c46a3a', '#e0a769', '#8a4322'],
    beanbag: ['#e0365b', '#fff0f3'],
    racer: ['#ffe14d', '#e0365b', '#1b0f30'],
    cloud: ['#f7f0ff', '#c9a0ff'],
    throne: ['#ffc93d', '#ffe14d', '#e0365b'],
    coffin: ['#2e1b4d', '#7a1f3a', '#c9a0ff'],
    kennel: ['#8a4322', '#e0365b', '#0a0512', '#ffe14d'],
    hammock: ['#8a4322', '#54e8d0', '#f7f0ff'],
    pumpkin: ['#ff8a2a', '#1b0f30', '#7dffb0'],
    cauldron: ['#2e1b4d', '#7dffb0', '#d8ff5a'],
  };

  /* ------------------------------------------------------ token and candy */
  const token = () => { const [d, f] = rad('#ffc93d'); return box('<defs>' + d + '</defs>' +
    '<circle cx="32" cy="32" r="25" fill="' + f + '" ' + O + '/>' +
    '<circle cx="32" cy="32" r="18" fill="none" stroke="' + ink + '" stroke-width="2"/>' +
    '<circle cx="32" cy="32" r="18" fill="none" stroke="#fff6b0" stroke-width="1.2" stroke-dasharray="2 3" opacity=".8"/>' +
    '<path d="M32 19v26M25 25h9.5a6.5 6.5 0 0 1 0 13H25" stroke="' + ink + '" stroke-width="3.5" fill="none" stroke-linecap="round"/>' +
    dots([[32, 10], [54, 32], [32, 54], [10, 32]], 1.4, ink) + glint(23, 21, 6, 3.5, -40)); };

  const candy = (hue) => { const c = 'hsl(' + hue + ' 90% 66%)'; const id = uid(); return box(
    '<defs><radialGradient id="' + id + '" cx=".38" cy=".32" r=".8"><stop offset="0" stop-color="hsl(' + hue + ' 90% 84%)"/><stop offset=".6" stop-color="' + c + '"/><stop offset="1" stop-color="hsl(' + hue + ' 80% 42%)"/></radialGradient></defs>' + shadow(32, 50, 16) +
    '<path d="M18 32l-12-9c-1 3-1 6 0 9-1 3-1 6 0 9zM46 32l12-9c1 3 1 6 0 9 1 3 1 6 0 9z" fill="hsl(' + hue + ' 80% 58%)" ' + O + '/>' +
    '<path d="M8 27l6 5-6 5M56 27l-6 5 6 5" stroke="hsl(' + hue + ' 80% 40%)" stroke-width="1.6" fill="none"/>' +
    '<circle cx="32" cy="32" r="15" fill="url(#' + id + ')" ' + O + '/>' +
    '<path d="M22 22c6 6 6 14 0 20M42 22c-6 6-6 14 0 20" stroke="#ffffff" stroke-width="2.5" fill="none" opacity=".7"/>' + glint(26, 25, 3.5, 2, -40)); };

  /* Prices follow the brief's bands: ticket 10, snacks 15-40, toys 40-100,
   * beds 80-150. Candy is parent-set, so it is not in here. */
  const CATALOG = [
    { slot: 'snack', key: 'meat', name: 'Meat block', cost: 0, note: 'The one every pet starts with' },
    { slot: 'snack', key: 'burger', name: 'Burger', cost: 15 },
    { slot: 'snack', key: 'pizza', name: 'Pizza slice', cost: 20 },
    { slot: 'snack', key: 'donut', name: 'Sprinkle donut', cost: 25 },
    { slot: 'snack', key: 'taco', name: 'Taco', cost: 30 },
    { slot: 'snack', key: 'sushi', name: 'Sushi', cost: 35 },
    { slot: 'snack', key: 'icecream', name: 'Double scoop', cost: 40 },
    { slot: 'toy', key: 'ball', name: 'Bouncy ball', cost: 40 },
    { slot: 'toy', key: 'bone', name: 'Chew bone', cost: 50 },
    { slot: 'toy', key: 'yarn', name: 'Yarn ball', cost: 60 },
    { slot: 'toy', key: 'frisbee', name: 'Frisbee', cost: 75 },
    { slot: 'toy', key: 'mouse', name: 'Clockwork mouse', cost: 90 },
    { slot: 'toy', key: 'rocket', name: 'Toy rocket', cost: 100 },
    { slot: 'bed', key: 'cushion', name: 'Velvet cushion', cost: 80 },
    { slot: 'bed', key: 'basket', name: 'Wicker basket', cost: 95 },
    { slot: 'bed', key: 'beanbag', name: 'Bean bag', cost: 110 },
    { slot: 'bed', key: 'cloud', name: 'Little cloud', cost: 130 },
    { slot: 'bed', key: 'racer', name: 'Racing bed', cost: 140 },
    { slot: 'bed', key: 'throne', name: 'Tiny throne', cost: 150 },
    /* The spooky half. */
    { slot: 'snack', key: 'hotdog', name: 'Hot dog', cost: 15 },
    { slot: 'snack', key: 'drumstick', name: 'Drumstick', cost: 20 },
    { slot: 'snack', key: 'cupcake', name: 'Slime cupcake', cost: 25 },
    { slot: 'snack', key: 'fishbone', name: 'Fish bone', cost: 30 },
    { slot: 'snack', key: 'eyeball', name: 'Eyeball jelly', cost: 40 },
    { slot: 'toy', key: 'tennis', name: 'Tennis ball', cost: 40 },
    { slot: 'toy', key: 'duck', name: 'Rubber duck', cost: 55 },
    { slot: 'toy', key: 'spiky', name: 'Spiky ball', cost: 70 },
    { slot: 'toy', key: 'skull', name: 'Squeaky skull', cost: 100 },
    { slot: 'bed', key: 'hammock', name: 'Hammock', cost: 80 },
    { slot: 'bed', key: 'kennel', name: 'Kennel', cost: 100 },
    { slot: 'bed', key: 'pumpkin', name: 'Pumpkin', cost: 115 },
    { slot: 'bed', key: 'cauldron', name: 'Cauldron', cost: 130 },
    { slot: 'bed', key: 'coffin', name: 'Coffin', cost: 150 },
  ];

  const draw = (slot, key) => {
    if (slot === 'snack') return (SNACKS[key] || SNACKS.meat)(SNACK_PALETTES[key] || SNACK_PALETTES.meat);
    if (slot === 'toy') return (TOYS[key] || TOYS.ball)(TOY_PALETTES[key] || TOY_PALETTES.ball);
    if (slot === 'bed') return (BEDS[key] || BEDS.cushion)(BED_PALETTES[key] || BED_PALETTES.cushion);
    if (slot === 'candy') return candy(Number(key) || 0);
    return token();
  };

  window.FQPrizes = {
    CATALOG,
    snack: (k) => draw('snack', k),
    toy: (k) => draw('toy', k),
    bed: (k) => draw('bed', k),
    candy,
    token,
    draw,
    SNACK_KEYS: Object.keys(SNACKS),
    TOY_KEYS: Object.keys(TOYS),
    BED_KEYS: Object.keys(BEDS),
  };
})();
