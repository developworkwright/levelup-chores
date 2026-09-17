/* FQCosmetics — generated cosmetic art for the Family Quest cosmetic locker.
 * Same shape as monsters.js: no build step, no image assets, one global.
 *
 *   FQCosmetics.SLOTS                 the seven wearable slots, in locker order
 *   FQCosmetics.ITEMS                 the catalog (slot, key, name, cost, stock)
 *   FQCosmetics.bySlot(slot)          catalog filtered
 *   FQCosmetics.item(slot, key)
 *   FQCosmetics.frameSvg(key, opts)   ring, 100x100 viewBox, fills its container
 *   FQCosmetics.avatarSvg(key, opts)  character, 100x100 viewBox
 *   FQCosmetics.theme(key)            { bg, panel, line, ink, muted, accent, accent2 }
 *   FQCosmetics.plate(key)            { background, border, ink, clip, letter }
 *   FQCosmetics.pattern(key)          a CSS `background` shorthand string
 *   FQCosmetics.cabinet(key)          { bezel, marquee, marqueeInk, glow }
 *   FQCosmetics.sparkSvg(key, opts)   tap/celebration effect, 100x100 viewBox
 */
(function () {
  function attrs(a) {
    var s = '';
    for (var k in a) if (a[k] !== undefined && a[k] !== null) s += ' ' + k + '="' + a[k] + '"';
    return s;
  }
  function el(t, a, inner) {
    return '<' + t + attrs(a) + (inner === undefined ? '/>' : '>' + inner + '</' + t + '>');
  }
  function svg(inner, opts) {
    opts = opts || {};
    return '<svg viewBox="0 0 100 100" width="100%" height="100%" style="display:block;overflow:visible' +
      (opts.css ? ';' + opts.css : '') + '" xmlns="http://www.w3.org/2000/svg">' + inner + '</svg>';
  }
  function pt(cx, cy, r, deg) {
    var a = (deg - 90) * Math.PI / 180;
    return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
  }
  function poly(points, a) {
    return el('polygon', Object.assign({ points: points.map(function (p) { return p[0].toFixed(2) + ',' + p[1].toFixed(2); }).join(' ') }, a));
  }
  function grad(id, c1, c2) {
    return el('linearGradient', { id: id, x1: '0', y1: '0', x2: '1', y2: '1' },
      el('stop', { offset: '0', 'stop-color': c1 }) + el('stop', { offset: '1', 'stop-color': c2 }));
  }
  var uid = 0;

  /* Motion. The art is code, so an animation is one inline `animation:` on a
   * group plus a shared @keyframes in the host document — no extra payload and
   * nothing per-item to author. The host must carry FQCosmetics.KEYFRAMES
   * (same deal as monsters.js needing @keyframes fqbreath). */
  var KEYFRAMES = [
    '@keyframes fqspin { to { transform: rotate(360deg); } }',
    '@keyframes fqtick { to { transform: rotate(360deg); } }',
    '@keyframes fqthrob { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.06); opacity: .74; } }',
    '@keyframes fqbob { 0%,100% { transform: translateY(0) scaleY(1); } 50% { transform: translateY(-3px) scaleY(1.03); } }',
    '@keyframes fqsway { 0%,100% { transform: rotate(-3deg); } 50% { transform: rotate(3deg); } }',
    '@keyframes fqblink { 0%,92%,100% { transform: scaleY(1); } 96% { transform: scaleY(.08); } }',
    '@keyframes fqflicker { 0%,100% { opacity: 1; } 42% { opacity: .55; } 46% { opacity: 1; } 61% { opacity: .4; } 65% { opacity: 1; } }',
    '@keyframes fqdrift { to { background-position: 120px 120px; } }',
    '@keyframes fqradiate { 0% { transform: scale(.72); opacity: .35; } 55% { opacity: 1; } 100% { transform: scale(1.12); opacity: 0; } }'
  ].join('\n');

  var MOTION = {
    spin: 'fqspin 14s linear infinite',
    spinfast: 'fqspin 6s linear infinite',
    tick: 'fqtick 9s steps(12) infinite',
    throb: 'fqthrob 2.4s ease-in-out infinite',
    bob: 'fqbob 2.9s ease-in-out infinite',
    sway: 'fqsway 3.6s ease-in-out infinite',
    blink: 'fqblink 4.2s ease-in-out infinite',
    flicker: 'fqflicker 4.2s steps(1,end) infinite',
    radiate: 'fqradiate 1.9s ease-out infinite',
    drift: 'fqdrift 26s linear infinite'
  };

  function moving(inner, kind, origin) {
    if (!kind || !MOTION[kind]) return inner;
    return el('g', { style: 'animation:' + MOTION[kind] + ';transform-origin:' + (origin || '50px 50px') + ';transform-box:view-box' }, inner);
  }

  /* Set just before a recipe runs, so the shared eyes() helper can blink. */
  var BLINK = false;

  /* ---------------------------------------------------------------- frames */

  var FRAMES = {
    hairline: function (p) {
      return el('circle', { cx: 50, cy: 50, r: 46, fill: 'none', stroke: p.c1, 'stroke-width': 2.5 });
    },
    double: function (p) {
      return el('circle', { cx: 50, cy: 50, r: 47, fill: 'none', stroke: p.c1, 'stroke-width': 3 }) +
        el('circle', { cx: 50, cy: 50, r: 40, fill: 'none', stroke: p.c2, 'stroke-width': 1.5, opacity: 0.8 });
    },
    dashed: function (p) {
      return el('circle', { cx: 50, cy: 50, r: 46, fill: 'none', stroke: p.c1, 'stroke-width': 5, 'stroke-dasharray': '11 8', 'stroke-linecap': 'round' });
    },
    spikes: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 43, fill: 'none', stroke: p.c2, 'stroke-width': 3 });
      for (var i = 0; i < 12; i++) {
        var a = i * 30;
        out += poly([pt(50, 50, 56, a), pt(50, 50, 43, a - 8), pt(50, 50, 43, a + 8)], { fill: p.c1 });
      }
      return out;
    },
    bolts: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 45, fill: 'none', stroke: p.c2, 'stroke-width': 3.5 });
      for (var i = 0; i < 4; i++) {
        var c = pt(50, 50, 45, i * 90);
        out += poly([[c[0], c[1] - 8], [c[0] + 6, c[1]], [c[0], c[1] + 8], [c[0] - 6, c[1]]], { fill: p.c1 });
      }
      return out;
    },
    beads: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 44, fill: 'none', stroke: p.c2, 'stroke-width': 1.5, opacity: 0.7 });
      for (var i = 0; i < 20; i++) {
        var c = pt(50, 50, 44, i * 18);
        out += el('circle', { cx: c[0].toFixed(2), cy: c[1].toFixed(2), r: i % 2 ? 2.2 : 4, fill: i % 2 ? p.c2 : p.c1 });
      }
      return out;
    },
    crown: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 45, fill: 'none', stroke: p.c2, 'stroke-width': 3.5 });
      for (var i = -2; i <= 2; i++) {
        var a = i * 20;
        var h = 60 - Math.abs(i) * 5;
        out += poly([pt(50, 50, h, a), pt(50, 50, 45, a - 9), pt(50, 50, 45, a + 9)], { fill: p.c1 });
      }
      return out;
    },
    orbit: function (p) {
      var id = 'fqcg' + (++uid);
      return el('defs', {}, grad(id, p.c1, p.c2)) +
        el('circle', { cx: 50, cy: 50, r: 46, fill: 'none', stroke: 'url(#' + id + ')', 'stroke-width': 6 }) +
        el('circle', { cx: 50, cy: 50, r: 36, fill: 'none', stroke: p.c2, 'stroke-width': 1, opacity: 0.5, 'stroke-dasharray': '2 6' }) +
        el('circle', { cx: 50, cy: 4, r: 7, fill: p.c1 });
    },
    fangs: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 47, fill: 'none', stroke: p.c2, 'stroke-width': 5 });
      for (var i = 0; i < 14; i++) {
        var a = i * 25.7;
        out += poly([pt(50, 50, 33, a), pt(50, 50, 46, a - 7), pt(50, 50, 46, a + 7)], { fill: p.c1 });
      }
      return out;
    },
    paws: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 44, fill: 'none', stroke: p.c2, 'stroke-width': 2, opacity: 0.8 });
      for (var i = 0; i < 6; i++) {
        var a = i * 60, c = pt(50, 50, 45, a);
        out += el('circle', { cx: c[0].toFixed(2), cy: c[1].toFixed(2), r: 5.4, fill: p.c1 });
        for (var j = -1; j <= 1; j++) {
          var t = pt(c[0], c[1], 7.6, a + j * 34);
          out += el('circle', { cx: t[0].toFixed(2), cy: t[1].toFixed(2), r: 2.4, fill: p.c1 });
        }
      }
      return out;
    },
    stitch: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 45, fill: 'none', stroke: p.c2, 'stroke-width': 6 });
      for (var i = 0; i < 10; i++) {
        var a = i * 36, i1 = pt(50, 50, 37, a), o1 = pt(50, 50, 53, a);
        out += el('line', { x1: i1[0].toFixed(2), y1: i1[1].toFixed(2), x2: o1[0].toFixed(2), y2: o1[1].toFixed(2), stroke: p.c1, 'stroke-width': 3, 'stroke-linecap': 'round' });
      }
      return out;
    },
    candy: function (p) {
      return el('circle', { cx: 50, cy: 50, r: 46, fill: 'none', stroke: p.c1, 'stroke-width': 8, 'stroke-dasharray': '10 10' }) +
        el('circle', { cx: 50, cy: 50, r: 46, fill: 'none', stroke: p.c2, 'stroke-width': 8, 'stroke-dasharray': '10 10', 'stroke-dashoffset': 10 });
    },
    ticket: function (p) {
      var out = el('circle', { cx: 50, cy: 50, r: 46, fill: 'none', stroke: p.c1, 'stroke-width': 5 }) +
        el('circle', { cx: 50, cy: 50, r: 38, fill: 'none', stroke: p.c2, 'stroke-width': 1.5, 'stroke-dasharray': '5 5' });
      [0, 90, 180, 270].forEach(function (a) {
        var c = pt(50, 50, 46, a);
        out += el('circle', { cx: c[0].toFixed(2), cy: c[1].toFixed(2), r: 6.5, fill: p.bg || '#0a0512', stroke: p.c1, 'stroke-width': 2 });
      });
      return out;
    }
  };

  /* --------------------------------------------------------------- avatars */

  function eyes(p, opts) {
    opts = opts || {};
    var y = opts.y || 46, dx = opts.dx || 13, r = opts.r || 7;
    var inner = el('circle', { cx: 50 - dx, cy: y, r: r, fill: p.eye }) +
      el('circle', { cx: 50 + dx, cy: y, r: r, fill: p.eye }) +
      el('circle', { cx: 50 - dx + 1, cy: y + 1, r: r * 0.42, fill: p.pupil }) +
      el('circle', { cx: 50 + dx + 1, cy: y + 1, r: r * 0.42, fill: p.pupil });
    if (!BLINK) return inner;
    return el('g', { style: 'animation:' + MOTION.blink + ';transform-origin:50px ' + y + 'px;transform-box:view-box' }, inner);
  }
  function smile(p, y, w) {
    return el('path', { d: 'M' + (50 - w) + ' ' + y + ' Q 50 ' + (y + 9) + ' ' + (50 + w) + ' ' + y, fill: 'none', stroke: p.pupil, 'stroke-width': 3, 'stroke-linecap': 'round' });
  }

  var AVATARS = {
    bean: function (p) {
      return el('ellipse', { cx: 50, cy: 56, rx: 30, ry: 36, fill: p.body }) +
        el('ellipse', { cx: 50, cy: 66, rx: 22, ry: 22, fill: p.shade, opacity: 0.35 }) +
        eyes(p, { y: 48 }) + smile(p, 66, 11);
    },
    ghost: function (p) {
      return el('path', { d: 'M20 60 A30 34 0 0 1 80 60 L80 88 L68 78 L56 88 L44 78 L32 88 L20 78 Z', fill: p.body }) +
        eyes(p, { y: 52, dx: 12, r: 8 });
    },
    robot: function (p) {
      return el('rect', { x: 20, y: 30, width: 60, height: 56, rx: 14, fill: p.body }) +
        el('rect', { x: 28, y: 38, width: 44, height: 26, rx: 8, fill: p.shade }) +
        el('rect', { x: 36, y: 46, width: 9, height: 10, rx: 2, fill: p.eye }) +
        el('rect', { x: 55, y: 46, width: 9, height: 10, rx: 2, fill: p.eye }) +
        el('rect', { x: 40, y: 72, width: 20, height: 5, rx: 2.5, fill: p.shade }) +
        el('line', { x1: 50, y1: 30, x2: 50, y2: 16, stroke: p.body, 'stroke-width': 4 }) +
        el('circle', { cx: 50, cy: 13, r: 6, fill: p.eye });
    },
    slime: function (p) {
      var out = el('path', { d: 'M14 82 Q14 34 50 34 Q86 34 86 82 Z', fill: p.body });
      [26, 44, 62, 78].forEach(function (x, i) {
        out += el('circle', { cx: x, cy: 82 + (i % 2 ? 5 : 8), r: i % 2 ? 4 : 6, fill: p.body });
      });
      return out + eyes(p, { y: 56, dx: 12, r: 8 }) + smile(p, 72, 9);
    },
    star: function (p) {
      var pts = [];
      for (var i = 0; i < 10; i++) pts.push(pt(50, 52, i % 2 ? 20 : 46, i * 36));
      return poly(pts, { fill: p.body }) + eyes(p, { y: 50, dx: 10, r: 6 }) + smile(p, 62, 8);
    },
    cat: function (p) {
      return poly([[26, 36], [34, 12], [48, 32]], { fill: p.body }) +
        poly([[74, 36], [66, 12], [52, 32]], { fill: p.body }) +
        el('circle', { cx: 50, cy: 58, r: 32, fill: p.body }) +
        eyes(p, { y: 54, dx: 12, r: 8 }) +
        el('circle', { cx: 50, cy: 70, r: 4, fill: p.pupil }) +
        el('line', { x1: 18, y1: 68, x2: 34, y2: 70, stroke: p.shade, 'stroke-width': 2 }) +
        el('line', { x1: 82, y1: 68, x2: 66, y2: 70, stroke: p.shade, 'stroke-width': 2 });
    },
    cloud: function (p) {
      return el('circle', { cx: 32, cy: 58, r: 20, fill: p.body }) +
        el('circle', { cx: 68, cy: 58, r: 20, fill: p.body }) +
        el('circle', { cx: 50, cy: 44, r: 25, fill: p.body }) +
        el('rect', { x: 14, y: 56, width: 72, height: 22, rx: 11, fill: p.body }) +
        eyes(p, { y: 48, dx: 12, r: 7 }) + smile(p, 64, 9);
    },
    wolf: function (p) {
      return poly([[24, 40], [30, 10], [50, 32]], { fill: p.body }) +
        poly([[76, 40], [70, 10], [50, 32]], { fill: p.body }) +
        el('circle', { cx: 50, cy: 54, r: 31, fill: p.body }) +
        el('ellipse', { cx: 50, cy: 70, rx: 17, ry: 13, fill: p.shade }) +
        eyes(p, { y: 48, dx: 13, r: 7 }) +
        poly([[50, 62], [56, 70], [44, 70]], { fill: p.pupil });
    },
    bunny: function (p) {
      return el('rect', { x: 32, y: 6, width: 11, height: 36, rx: 5.5, fill: p.body, transform: 'rotate(-9 37 24)' }) +
        el('rect', { x: 57, y: 6, width: 11, height: 36, rx: 5.5, fill: p.body, transform: 'rotate(9 63 24)' }) +
        el('rect', { x: 35, y: 12, width: 5, height: 26, rx: 2.5, fill: p.shade, transform: 'rotate(-9 37 24)' }) +
        el('rect', { x: 60, y: 12, width: 5, height: 26, rx: 2.5, fill: p.shade, transform: 'rotate(9 63 24)' }) +
        el('circle', { cx: 50, cy: 62, r: 28, fill: p.body }) +
        eyes(p, { y: 58, dx: 11, r: 6.5 }) +
        el('circle', { cx: 50, cy: 72, r: 4, fill: p.shade });
    },
    pumpkin: function (p) {
      return el('rect', { x: 46, y: 14, width: 8, height: 16, rx: 3, fill: p.shade }) +
        el('ellipse', { cx: 50, cy: 60, rx: 34, ry: 30, fill: p.body }) +
        el('path', { d: 'M50 30 Q42 60 50 90', fill: 'none', stroke: p.shade, 'stroke-width': 2, opacity: 0.5 }) +
        el('path', { d: 'M50 30 Q58 60 50 90', fill: 'none', stroke: p.shade, 'stroke-width': 2, opacity: 0.5 }) +
        poly([[30, 48], [44, 56], [30, 60]], { fill: p.pupil }) +
        poly([[70, 48], [56, 56], [70, 60]], { fill: p.pupil }) +
        poly([[34, 68], [42, 74], [46, 68], [54, 74], [58, 68], [66, 74], [62, 80], [38, 80]], { fill: p.pupil });
    },
    moth: function (p) {
      return el('ellipse', { cx: 28, cy: 52, rx: 20, ry: 27, fill: p.shade, transform: 'rotate(-18 28 52)' }) +
        el('ellipse', { cx: 72, cy: 52, rx: 20, ry: 27, fill: p.shade, transform: 'rotate(18 72 52)' }) +
        el('ellipse', { cx: 50, cy: 56, rx: 12, ry: 28, fill: p.body }) +
        el('line', { x1: 46, y1: 30, x2: 34, y2: 12, stroke: p.body, 'stroke-width': 3 }) +
        el('line', { x1: 54, y1: 30, x2: 66, y2: 12, stroke: p.body, 'stroke-width': 3 }) +
        el('circle', { cx: 43, cy: 40, r: 6.5, fill: p.eye }) +
        el('circle', { cx: 57, cy: 40, r: 6.5, fill: p.eye }) +
        el('circle', { cx: 43, cy: 40, r: 2.4, fill: p.pupil }) +
        el('circle', { cx: 57, cy: 40, r: 2.4, fill: p.pupil });
    },
    skull: function (p) {
      var out = el('path', { d: 'M22 48 A28 30 0 0 1 78 48 L78 66 Q78 78 66 78 L34 78 Q22 78 22 66 Z', fill: p.body }) +
        eyes({ eye: p.pupil, pupil: p.eye }, { y: 50, dx: 13, r: 11 });
      for (var i = 0; i < 4; i++) out += el('rect', { x: 39 + i * 6, y: 66, width: 4, height: 11, fill: p.pupil });
      return out;
    }
  };

  /* ---------------------------------------------------------------- sparks */

  var SPARKS = {
    coins: function (p) {
      var out = '';
      for (var i = 0; i < 9; i++) {
        var c = pt(50, 50, 20 + (i % 3) * 13, i * 40);
        out += el('circle', { cx: c[0].toFixed(1), cy: c[1].toFixed(1), r: 7 - (i % 3), fill: p.c1, opacity: 1 - (i % 3) * 0.22 }) +
          el('circle', { cx: c[0].toFixed(1), cy: c[1].toFixed(1), r: 3 - (i % 3) * 0.6, fill: p.c2, opacity: 0.8 });
      }
      return out;
    },
    stars: function (p) {
      var out = '';
      for (var i = 0; i < 7; i++) {
        var c = pt(50, 50, 16 + (i % 3) * 15, i * 51);
        var pts = [];
        for (var j = 0; j < 8; j++) pts.push(pt(c[0], c[1], j % 2 ? 3 : 11 - (i % 3) * 2.5, j * 45));
        out += poly(pts, { fill: i % 2 ? p.c1 : p.c2, opacity: 1 - (i % 3) * 0.2 });
      }
      return out;
    },
    confetti: function (p) {
      var out = '';
      for (var i = 0; i < 14; i++) {
        var c = pt(50, 50, 14 + (i % 4) * 11, i * 26);
        out += el('rect', { x: (c[0] - 4).toFixed(1), y: (c[1] - 2).toFixed(1), width: 9, height: 4.5, rx: 1.5, fill: i % 3 === 0 ? p.c1 : (i % 3 === 1 ? p.c2 : p.c3 || p.c1), transform: 'rotate(' + (i * 37) + ' ' + c[0].toFixed(1) + ' ' + c[1].toFixed(1) + ')', opacity: 0.95 - (i % 4) * 0.15 });
      }
      return out;
    },
    bubbles: function (p) {
      var out = '';
      for (var i = 0; i < 11; i++) {
        var c = pt(50, 50, 13 + (i % 4) * 12, i * 33);
        out += el('circle', { cx: c[0].toFixed(1), cy: c[1].toFixed(1), r: 9 - (i % 4) * 2, fill: 'none', stroke: i % 2 ? p.c1 : p.c2, 'stroke-width': 2.2, opacity: 0.9 - (i % 4) * 0.18 });
      }
      return out;
    },
    shards: function (p) {
      var out = '';
      for (var i = 0; i < 10; i++) {
        var a = i * 36, r0 = 14 + (i % 3) * 8;
        out += poly([pt(50, 50, r0, a), pt(50, 50, r0 + 22 - (i % 3) * 5, a - 4), pt(50, 50, r0 + 22 - (i % 3) * 5, a + 4)], { fill: i % 2 ? p.c1 : p.c2, opacity: 0.95 - (i % 3) * 0.2 });
      }
      return out;
    },
    bats: function (p) {
      var out = '';
      for (var i = 0; i < 8; i++) {
        var c = pt(50, 50, 16 + (i % 3) * 13, i * 45), s = 1 - (i % 3) * 0.2;
        out += poly([
          [c[0], c[1] - 3 * s], [c[0] + 11 * s, c[1] - 8 * s], [c[0] + 8 * s, c[1] + 2 * s], [c[0] + 12 * s, c[1] + 5 * s],
          [c[0], c[1] + 4 * s],
          [c[0] - 12 * s, c[1] + 5 * s], [c[0] - 8 * s, c[1] + 2 * s], [c[0] - 11 * s, c[1] - 8 * s]
        ], { fill: i % 2 ? p.c1 : p.c2, opacity: 0.95 - (i % 3) * 0.18 });
      }
      return out;
    },
    pawpops: function (p) {
      var out = '';
      for (var i = 0; i < 7; i++) {
        var c = pt(50, 50, 15 + (i % 3) * 14, i * 51), s = 1 - (i % 3) * 0.22;
        out += el('circle', { cx: c[0].toFixed(1), cy: c[1].toFixed(1), r: 6 * s, fill: i % 2 ? p.c1 : p.c2 });
        for (var j = -1; j <= 1; j++) {
          var t = pt(c[0], c[1], 8.4 * s, i * 51 + j * 34);
          out += el('circle', { cx: t[0].toFixed(1), cy: t[1].toFixed(1), r: 2.6 * s, fill: i % 2 ? p.c1 : p.c2 });
        }
      }
      return out;
    },
    hearts: function (p) {
      var out = '';
      for (var i = 0; i < 8; i++) {
        var c = pt(50, 50, 15 + (i % 3) * 13, i * 45), s = 0.9 - (i % 3) * 0.2;
        out += el('path', {
          d: 'M' + c[0].toFixed(1) + ' ' + (c[1] + 9 * s).toFixed(1) +
            ' C' + (c[0] - 13 * s).toFixed(1) + ' ' + (c[1] - 2 * s).toFixed(1) + ' ' + (c[0] - 7 * s).toFixed(1) + ' ' + (c[1] - 12 * s).toFixed(1) + ' ' + c[0].toFixed(1) + ' ' + (c[1] - 4 * s).toFixed(1) +
            ' C' + (c[0] + 7 * s).toFixed(1) + ' ' + (c[1] - 12 * s).toFixed(1) + ' ' + (c[0] + 13 * s).toFixed(1) + ' ' + (c[1] - 2 * s).toFixed(1) + ' ' + c[0].toFixed(1) + ' ' + (c[1] + 9 * s).toFixed(1) + ' Z',
          fill: i % 2 ? p.c1 : p.c2, opacity: 0.95 - (i % 3) * 0.18
        });
      }
      return out;
    },
    drips: function (p) {
      var out = '';
      for (var i = 0; i < 8; i++) {
        var x = 14 + i * 10, y = 26 + ((i * 17) % 34);
        out += el('path', { d: 'M' + x + ' ' + y + ' q 7 12 0 20 q -7 -8 0 -20 z', fill: i % 2 ? p.c1 : p.c2, opacity: 0.95 - (i % 3) * 0.2 }) +
          el('circle', { cx: x, cy: y + 30, r: 3.5 - (i % 2), fill: i % 2 ? p.c2 : p.c1, opacity: 0.7 });
      }
      return out;
    }
  };

  /* ---------------------------------------------------------------- tokens */

  var THEMES = {
    midnight: { name: 'Midnight', bg: '#07030f', panel: '#0e0719', line: '#2e1b4d', ink: '#f7f0ff', muted: '#8c7bab', accent: '#ffc93d', accent2: '#c9a0ff' },
    lagoon: { name: 'Lagoon', bg: '#04121a', panel: '#07202c', line: '#12465c', ink: '#effbff', muted: '#6f9fb0', accent: '#54e8d0', accent2: '#6bd5ff' },
    ember: { name: 'Ember', bg: '#160604', panel: '#240c07', line: '#5a1e12', ink: '#fff2ec', muted: '#b08272', accent: '#ff8a4d', accent2: '#ffd06b' },
    swamp: { name: 'Swamp', bg: '#060e07', panel: '#0c1a0e', line: '#1f4426', ink: '#f0fff3', muted: '#78a884', accent: '#7dffb0', accent2: '#d6ff4d' },
    bubblegum: { name: 'Bubblegum', bg: '#1a0614', panel: '#2a0a21', line: '#5d1846', ink: '#fff0fa', muted: '#c084a8', accent: '#ff8ac7', accent2: '#ffe14d' },

    hollow: { name: 'Hollow Night', bg: '#0b0603', panel: '#160c05', line: '#42200a', ink: '#fff3e6', muted: '#a8734a', accent: '#ff7a18', accent2: '#ffc93d' },
    boneyard: { name: 'Boneyard', bg: '#080809', panel: '#121215', line: '#2c2c33', ink: '#f4f2ec', muted: '#8a8a94', accent: '#e8e2d2', accent2: '#c2182f' },
    cottontail: { name: 'Cottontail', bg: '#170610', panel: '#260c1b', line: '#5d1846', ink: '#fff0fa', muted: '#c084a8', accent: '#ff5fa2', accent2: '#ffffff' },
    witchlight: { name: 'Witchlight', bg: '#06090a', panel: '#0d1613', line: '#22453a', ink: '#eafff6', muted: '#7ba394', accent: '#7dffb0', accent2: '#c9a0ff' },
    safari: { name: 'Safari', bg: '#120d06', panel: '#1e160c', line: '#4a3418', ink: '#fff8ec', muted: '#ab8f66', accent: '#e0a243', accent2: '#7dffb0' }
  };

  var PLATES = {
    standard: { name: 'Standard', background: '#150c26', border: '1px solid #3a2360', ink: '#f7f0ff', radius: '10px', clip: 'none' },
    ribbon: { name: 'Ribbon', background: 'linear-gradient(135deg,#8f1734,#3b0c1d)', border: '1px solid #e0365b', ink: '#fff0f3', radius: '4px', clip: 'polygon(0 0,100% 0,calc(100% - 12px) 50%,100% 100%,0 100%,12px 50%)' },
    metal: { name: 'Brushed metal', background: 'repeating-linear-gradient(90deg,#3a3a46 0 2px,#4a4a58 2px 4px)', border: '1px solid #6b6b7d', ink: '#f4f6ff', radius: '6px', clip: 'none' },
    neon: { name: 'Neon tube', background: '#0a0512', border: '2px solid #54e8d0', ink: '#d8fff8', radius: '99px', clip: 'none', shadow: '0 0 12px #54e8d055, inset 0 0 8px #54e8d033' },
    tape: { name: 'Sticky tape', background: '#ffe14d', border: 'none', ink: '#231702', radius: '2px', clip: 'polygon(3px 0,100% 2px,calc(100% - 4px) 100%,0 calc(100% - 3px))', skew: '-1.4deg' },
    bone: { name: 'Bone', background: '#f4f2ec', border: '2px solid #c9c4b4', ink: '#161418', radius: '4px', clip: 'polygon(0 22%,6px 0,22% 8%,50% 0,78% 8%,100% 0,100% 78%,94% 100%,78% 92%,50% 100%,22% 92%,0 100%)' },
    jack: { name: 'Jack-o-plate', background: 'linear-gradient(135deg,#ff7a18,#b34300)', border: '2px solid #0b0603', ink: '#180a02', radius: '6px', clip: 'none', shadow: '0 0 14px rgba(255,122,24,.45)' },
    candyplate: { name: 'Candy Stripe', background: 'repeating-linear-gradient(-40deg,#ff5fa2 0 7px,#fff 7px 14px)', border: '2px solid #fff', ink: '#2b1020', radius: '99px', clip: 'none' },
    pawplate: { name: 'Pawprint', background: 'radial-gradient(#8a5a2b 2px, transparent 2.4px) 0 0 / 10px 10px, #e0a243', border: '2px solid #4a3418', ink: '#1c1208', radius: '8px', clip: 'none' },
    pixel: { name: 'Pixel plate', background: '#1d1036', border: '3px solid #a06bff', ink: '#e9dcff', radius: '0px', clip: 'polygon(6px 0,calc(100% - 6px) 0,100% 6px,100% calc(100% - 6px),calc(100% - 6px) 100%,6px 100%,0 calc(100% - 6px),0 6px)' }
  };

  var PATTERNS = {
    plain: { name: 'Plain dark', css: '#0a0512' },
    dots: { name: 'Dot field', css: 'radial-gradient(#2e1b4d 1.6px, transparent 1.7px) 0 0 / 14px 14px, #0a0512' },
    grid: { name: 'Blueprint', css: 'repeating-linear-gradient(0deg,#1b1030 0 1px,transparent 1px 18px), repeating-linear-gradient(90deg,#1b1030 0 1px,transparent 1px 18px), #0a0512' },
    carbon: { name: 'Carbon', css: 'repeating-linear-gradient(45deg,#120a22 0 5px,#0a0512 5px 10px)' },
    stars: { name: 'Night sky', css: 'radial-gradient(#fff 1px, transparent 1.4px) 3px 7px / 37px 41px, radial-gradient(#c9a0ff 1px, transparent 1.3px) 19px 23px / 53px 61px, #07030f' },
    slimewave: { name: 'Slime wave', css: 'repeating-radial-gradient(circle at 50% 120%, #10321f 0 8px, #0a0512 8px 22px)' },
    bricks: { name: 'Dungeon brick', css: 'repeating-linear-gradient(0deg,#1a1030 0 1px,transparent 1px 16px), repeating-linear-gradient(90deg,#1a1030 0 1px,transparent 1px 32px) 0 0 / 32px 32px, #0d0718' },
    tickets: { name: 'Ticket run', css: 'repeating-linear-gradient(-30deg,#171033 0 14px,#0a0512 14px 30px)' },
    pumpkinstripe: { name: 'Trick or Treat', css: 'repeating-linear-gradient(-45deg,#ff7a18 0 12px,#0b0603 12px 24px)' },
    cobweb: { name: 'Cobwebs', css: 'repeating-radial-gradient(circle at 12% 0%, transparent 0 16px, #2a2a33 16px 17px), repeating-conic-gradient(from 200deg at 12% 0%, #2a2a33 0 0.4deg, transparent 0.4deg 14deg), #080809' },
    candystripe: { name: 'Candy Stripe', css: 'repeating-linear-gradient(-40deg,#ff5fa2 0 13px,#ffffff 13px 26px)' },
    leopard: { name: 'Leopard', css: 'radial-gradient(circle at 30% 30%, #2a1a08 0 5px, transparent 5.5px) 0 0 / 26px 26px, radial-gradient(circle at 70% 75%, #2a1a08 0 4px, transparent 4.5px) 0 0 / 26px 26px, #e0a243' },
    pawtrail: { name: 'Paw Trail', css: 'radial-gradient(#4a3418 3px, transparent 3.4px) 6px 6px / 34px 34px, radial-gradient(#4a3418 1.4px, transparent 1.8px) 2px 2px / 34px 34px, #1e160c' }
  };

  var CABINETS = {
    house: { name: 'House standard', bezel: 'linear-gradient(160deg,#1d1036,#0a0512)', edge: '#3a2360', marquee: '#150c26', marqueeInk: '#c9a0ff', glow: 'rgba(160,107,255,0.22)' },
    chrome: { name: 'Chrome diner', bezel: 'linear-gradient(160deg,#5a5a68,#22222c)', edge: '#8d8da0', marquee: 'repeating-linear-gradient(90deg,#d9dbe6 0 3px,#b6b9c9 3px 6px)', marqueeInk: '#1a1a24', glow: 'rgba(220,225,240,0.2)' },
    firewood: { name: 'Firewood', bezel: 'linear-gradient(160deg,#4a1c0c,#1a0804)', edge: '#a34a1c', marquee: '#2b0d04', marqueeInk: '#ff8a4d', glow: 'rgba(255,138,77,0.25)' },
    sticker: { name: 'Sticker bombed', bezel: 'linear-gradient(160deg,#1d1036,#0a0512)', edge: '#ff8ac7', marquee: 'repeating-linear-gradient(-14deg,#ff8ac7 0 10px,#ffe14d 10px 20px,#7dffb0 20px 30px)', marqueeInk: '#231702', glow: 'rgba(255,138,199,0.28)' },
    hauntcab: { name: 'Haunted', bezel: 'linear-gradient(160deg,#101317,#05060a)', edge: '#2f4a3a', marquee: '#07120c', marqueeInk: '#7dffb0', glow: 'rgba(125,255,176,0.3)' },
    pumpkincab: { name: 'Pumpkin', bezel: 'linear-gradient(160deg,#b34300,#2a0f02)', edge: '#ff7a18', marquee: '#0b0603', marqueeInk: '#ff7a18', glow: 'rgba(255,122,24,0.3)' },
    furcab: { name: 'Leopard', bezel: 'linear-gradient(160deg,#c78a35,#4a3418)', edge: '#2a1a08', marquee: 'radial-gradient(circle at 30% 40%, #2a1a08 0 4px, transparent 4.5px) 0 0 / 14px 14px, #e0a243', marqueeInk: '#1c1208', glow: 'rgba(224,162,67,0.26)' },
    voidcab: { name: 'The Void', bezel: '#000000', edge: '#1f1f1f', marquee: '#000000', marqueeInk: '#6f6288', glow: 'rgba(255,255,255,0.05)' }
  };

  /* -------------------------------------------------------------- catalog
   * stock: 'shelf'   — always buyable
   *        'weekly'  — in this week's rotation, comes back another week
   *        'limited' — this week only, never restocked, tradable
   */
  var ITEMS = [
    // frames
    { slot: 'frame', key: 'hairline', name: 'Hairline', cost: 2, stock: 'shelf', p: { c1: '#c9a0ff', c2: '#6a3fb0' } },
    { slot: 'frame', key: 'double', name: 'Double Ring', cost: 3, stock: 'shelf', p: { c1: '#7dffb0', c2: '#2f6f4d' } },
    { slot: 'frame', key: 'dashed', name: 'Dot Dash', cost: 3, stock: 'shelf', p: { c1: '#6bd5ff', c2: '#2a5d7a' } },
    { slot: 'frame', key: 'ticket', name: 'Ticket Stub', cost: 5, stock: 'shelf', p: { c1: '#ffc93d', c2: '#7a6a12', bg: '#0a0512' } },
    { slot: 'frame', key: 'spikes', name: 'Spikes', cost: 7, stock: 'weekly', anim: 'throb', p: { c1: '#ff8ac7', c2: '#8f1734' } },
    { slot: 'frame', key: 'bolts', name: 'Four Bolts', cost: 5, stock: 'weekly', p: { c1: '#d6ff4d', c2: '#4a5d12' } },
    { slot: 'frame', key: 'orbit', name: 'Orbit', cost: 8, stock: 'weekly', anim: 'spin', p: { c1: '#54e8d0', c2: '#a06bff' } },
    { slot: 'frame', key: 'crown', name: 'Paper Crown', cost: 8, stock: 'limited', anim: 'tick', p: { c1: '#ffe14d', c2: '#ffc93d' } },
    { slot: 'frame', key: 'beads', name: 'Bead String', cost: 4, stock: 'shelf', p: { c1: '#ffc93d', c2: '#c9a0ff' } },
    { slot: 'frame', key: 'fangs', name: 'Fangs', cost: 5, stock: 'shelf', tag: 'monster', p: { c1: '#f4f2ec', c2: '#c2182f' } },
    { slot: 'frame', key: 'stitch', name: 'Stitches', cost: 4, stock: 'shelf', tag: 'monster', p: { c1: '#e8e2d2', c2: '#2c2c33' } },
    { slot: 'frame', key: 'paws', name: 'Paw Ring', cost: 4, stock: 'shelf', tag: 'animal', p: { c1: '#e0a243', c2: '#4a3418' } },
    { slot: 'frame', key: 'candy', name: 'Candy Stripe', cost: 7, stock: 'weekly', tag: 'pink', anim: 'spinfast', p: { c1: '#ff5fa2', c2: '#ffffff' } },
    // avatars
    { slot: 'avatar', key: 'bean', name: 'Bean', cost: 2, stock: 'shelf', p: { body: '#a06bff', shade: '#3b1f6b', eye: '#fff6fb', pupil: '#17061f' } },
    { slot: 'avatar', key: 'ghost', name: 'Sheet Ghost', cost: 3, stock: 'shelf', p: { body: '#d8d4ee', shade: '#8b87a8', eye: '#ffffff', pupil: '#17061f' } },
    { slot: 'avatar', key: 'slime', name: 'Slime', cost: 6, stock: 'shelf', anim: 'bob', p: { body: '#7dffb0', shade: '#2f6f4d', eye: '#f0fff3', pupil: '#05170c' } },
    { slot: 'avatar', key: 'cat', name: 'Alley Cat', cost: 6, stock: 'shelf', anim: 'blink', p: { body: '#3a3a46', shade: '#8d8da0', eye: '#ffe14d', pupil: '#0a0a0e' } },
    { slot: 'avatar', key: 'cloud', name: 'Rain Cloud', cost: 4, stock: 'shelf', p: { body: '#6bd5ff', shade: '#2a5d7a', eye: '#f2fbff', pupil: '#04121a' } },
    { slot: 'avatar', key: 'robot', name: 'Tin Robot', cost: 6, stock: 'weekly', p: { body: '#b6b9c9', shade: '#4a4a58', eye: '#ff8a4d', pupil: '#14141c' } },
    { slot: 'avatar', key: 'star', name: 'Gold Star', cost: 6, stock: 'weekly', p: { body: '#ffc93d', shade: '#7a6a12', eye: '#fffbe8', pupil: '#231702' } },
    { slot: 'avatar', key: 'skull', name: 'Bonehead', cost: 8, stock: 'limited', tag: 'monster', anim: 'blink', p: { body: '#f2f0ff', shade: '#8b87a8', eye: '#ff8ac7', pupil: '#0a0512' } },
    { slot: 'avatar', key: 'wolf', name: 'Wolf', cost: 5, stock: 'shelf', tag: 'animal', p: { body: '#6b6b7d', shade: '#3a3a46', eye: '#ffe14d', pupil: '#0a0a0e' } },
    { slot: 'avatar', key: 'bunny', name: 'Bunny', cost: 4, stock: 'shelf', tag: 'pink', p: { body: '#ffffff', shade: '#ff9ec4', eye: '#2b1020', pupil: '#ff5fa2' } },
    { slot: 'avatar', key: 'pumpkin', name: 'Jack', cost: 7, stock: 'shelf', tag: 'orange', anim: 'flicker', p: { body: '#ff7a18', shade: '#b34300', eye: '#ffe14d', pupil: '#0b0603' } },
    { slot: 'avatar', key: 'moth', name: 'The Moth', cost: 8, stock: 'weekly', tag: 'monster', anim: 'sway', p: { body: '#4a4458', shade: '#8b87a8', eye: '#ffe14d', pupil: '#0a0512' } },
    // themes
    { slot: 'theme', key: 'midnight', name: 'Midnight', cost: 0, stock: 'shelf', owned: true },
    { slot: 'theme', key: 'lagoon', name: 'Lagoon', cost: 5, stock: 'shelf' },
    { slot: 'theme', key: 'swamp', name: 'Swamp', cost: 5, stock: 'shelf' },
    { slot: 'theme', key: 'ember', name: 'Ember', cost: 6, stock: 'weekly' },
    { slot: 'theme', key: 'bubblegum', name: 'Bubblegum', cost: 6, stock: 'weekly' },
    { slot: 'theme', key: 'witchlight', name: 'Witchlight', cost: 8, stock: 'limited', tag: 'monster' },
    { slot: 'theme', key: 'hollow', name: 'Hollow Night', cost: 6, stock: 'shelf', tag: 'orange' },
    { slot: 'theme', key: 'boneyard', name: 'Boneyard', cost: 6, stock: 'shelf', tag: 'monster' },
    { slot: 'theme', key: 'cottontail', name: 'Cottontail', cost: 7, stock: 'shelf', tag: 'pink' },
    { slot: 'theme', key: 'safari', name: 'Safari', cost: 6, stock: 'weekly', tag: 'animal' },
    // plates
    { slot: 'plate', key: 'standard', name: 'Standard', cost: 0, stock: 'shelf', owned: true },
    { slot: 'plate', key: 'metal', name: 'Brushed Metal', cost: 3, stock: 'shelf' },
    { slot: 'plate', key: 'pixel', name: 'Pixel Plate', cost: 4, stock: 'shelf' },
    { slot: 'plate', key: 'neon', name: 'Neon Tube', cost: 5, stock: 'weekly' },
    { slot: 'plate', key: 'ribbon', name: 'Ribbon', cost: 6, stock: 'weekly' },
    { slot: 'plate', key: 'tape', name: 'Sticky Tape', cost: 7, stock: 'limited' },
    { slot: 'plate', key: 'bone', name: 'Bone', cost: 4, stock: 'shelf', tag: 'monster' },
    { slot: 'plate', key: 'jack', name: 'Jack-o-plate', cost: 5, stock: 'shelf', tag: 'orange' },
    { slot: 'plate', key: 'candyplate', name: 'Candy Stripe', cost: 5, stock: 'shelf', tag: 'pink' },
    { slot: 'plate', key: 'pawplate', name: 'Pawprint', cost: 4, stock: 'weekly', tag: 'animal' },
    // patterns
    { slot: 'pattern', key: 'plain', name: 'Plain Dark', cost: 0, stock: 'shelf', owned: true },
    { slot: 'pattern', key: 'dots', name: 'Dot Field', cost: 2, stock: 'shelf' },
    { slot: 'pattern', key: 'grid', name: 'Blueprint', cost: 3, stock: 'shelf' },
    { slot: 'pattern', key: 'carbon', name: 'Carbon', cost: 3, stock: 'shelf' },
    { slot: 'pattern', key: 'bricks', name: 'Dungeon Brick', cost: 4, stock: 'shelf' },
    { slot: 'pattern', key: 'tickets', name: 'Ticket Run', cost: 4, stock: 'weekly' },
    { slot: 'pattern', key: 'slimewave', name: 'Slime Wave', cost: 7, stock: 'weekly', anim: 'drift' },
    { slot: 'pattern', key: 'stars', name: 'Night Sky', cost: 7, stock: 'limited', anim: 'drift' },
    { slot: 'pattern', key: 'pumpkinstripe', name: 'Trick or Treat', cost: 6, stock: 'shelf', tag: 'orange', anim: 'drift' },
    { slot: 'pattern', key: 'cobweb', name: 'Cobwebs', cost: 5, stock: 'shelf', tag: 'monster' },
    { slot: 'pattern', key: 'candystripe', name: 'Candy Stripe', cost: 4, stock: 'shelf', tag: 'pink' },
    { slot: 'pattern', key: 'leopard', name: 'Leopard', cost: 5, stock: 'shelf', tag: 'animal' },
    { slot: 'pattern', key: 'pawtrail', name: 'Paw Trail', cost: 3, stock: 'weekly', tag: 'animal' },
    // cabinets
    { slot: 'cabinet', key: 'house', name: 'House Standard', cost: 0, stock: 'shelf', owned: true },
    { slot: 'cabinet', key: 'chrome', name: 'Chrome Diner', cost: 4, stock: 'shelf' },
    { slot: 'cabinet', key: 'firewood', name: 'Firewood', cost: 5, stock: 'shelf' },
    { slot: 'cabinet', key: 'sticker', name: 'Sticker Bombed', cost: 6, stock: 'weekly' },
    { slot: 'cabinet', key: 'voidcab', name: 'The Void', cost: 8, stock: 'limited' },
    { slot: 'cabinet', key: 'hauntcab', name: 'Haunted', cost: 6, stock: 'shelf', tag: 'monster' },
    { slot: 'cabinet', key: 'pumpkincab', name: 'Pumpkin', cost: 5, stock: 'shelf', tag: 'orange' },
    { slot: 'cabinet', key: 'furcab', name: 'Leopard', cost: 6, stock: 'weekly', tag: 'animal' },
    // sparks
    { slot: 'spark', key: 'coins', name: 'Coin Burst', cost: 0, stock: 'shelf', owned: true, p: { c1: '#ffc93d', c2: '#7a6a12' } },
    { slot: 'spark', key: 'stars', name: 'Star Pop', cost: 3, stock: 'shelf', p: { c1: '#ffe14d', c2: '#c9a0ff' } },
    { slot: 'spark', key: 'confetti', name: 'Confetti', cost: 3, stock: 'shelf', p: { c1: '#ff8ac7', c2: '#7dffb0', c3: '#6bd5ff' } },
    { slot: 'spark', key: 'bubbles', name: 'Bubbles', cost: 4, stock: 'shelf', p: { c1: '#6bd5ff', c2: '#54e8d0' } },
    { slot: 'spark', key: 'shards', name: 'Shards', cost: 6, stock: 'weekly', anim: 'radiate', p: { c1: '#a06bff', c2: '#ff8ac7' } },
    { slot: 'spark', key: 'drips', name: 'Slime Drips', cost: 7, stock: 'limited', anim: 'radiate', p: { c1: '#7dffb0', c2: '#d6ff4d' } },
    { slot: 'spark', key: 'bats', name: 'Bat Swarm', cost: 6, stock: 'shelf', tag: 'monster', anim: 'radiate', p: { c1: '#0b0603', c2: '#ff7a18' } },
    { slot: 'spark', key: 'hearts', name: 'Hearts', cost: 4, stock: 'shelf', tag: 'pink', p: { c1: '#ff5fa2', c2: '#ffffff' } },
    { slot: 'spark', key: 'pawpops', name: 'Paw Pops', cost: 4, stock: 'weekly', tag: 'animal', p: { c1: '#e0a243', c2: '#4a3418' } }
  ];

  var SLOTS = [
    { key: 'frame', name: 'Frame', blurb: 'The ring around your face', icon: 'fa-circle-notch' },
    { key: 'avatar', name: 'Avatar', blurb: 'Who you are in the house', icon: 'fa-face-grin-stars' },
    { key: 'plate', name: 'Name plate', blurb: 'What your name sits on', icon: 'fa-id-badge' },
    { key: 'theme', name: 'Theme', blurb: 'The whole app, recolored', icon: 'fa-palette' },
    { key: 'pattern', name: 'Home pattern', blurb: 'Behind your own pages', icon: 'fa-border-all' },
    { key: 'cabinet', name: 'Cabinet skin', blurb: 'Your arcade machine', icon: 'fa-gamepad' },
    { key: 'spark', name: 'Tap effect', blurb: 'What flies out when you win', icon: 'fa-wand-magic-sparkles' }
  ];

  function fallback(map, key) { return map[key] ? key : Object.keys(map)[0]; }

  /* Image-generation prompts, one per slot that takes a file. Shipped to the
   * parent console so a copy button is all the authoring tooling needed.
   * Shape is fixed on purpose: a subject line to edit, then geometry and
   * negative blocks to leave alone — the geometry is what image models get
   * wrong by default. */
  var PROMPTS = {
    frame: 'A decorative circular ring of [SUBJECT: gilded stag antlers and oak leaves], rendered as [STYLE: a realistic photograph of a carved object].\n\nGEOMETRY — follow exactly:\n· Square image, 512x512, fully transparent background.\n· The ring is centered and touches all four edges of a centered circle.\n· Art occupies ONLY the outer band: nothing inside the middle 62% of the\n  image, which must be 100% transparent. A face goes there.\n· The ring closes — no gap, no top-heavy crest; readable as a circle at 40px.\n· Even visual weight all the way round; no element taller than a quarter of\n  the image.\n\nMUST NOT INCLUDE: any face, head, person or animal body inside the ring;\ntext, numerals or logos; a background, sky, ground or backdrop of any kind;\ndrop shadows or outer glow (the app adds those); frames within frames;\nwatermarks; anything cropped at the image edge.',
    avatar: 'A single [SUBJECT: gray timber wolf] character, head and shoulders only, facing the viewer, rendered as [STYLE: a realistic photograph].\n\nGEOMETRY — follow exactly:\n· Square image, 512x512, fully transparent background.\n· One subject, centered, filling about 80% of the frame with even margins.\n· Head and shoulders only — cropped at the chest, never full body.\n· Eyes clearly visible and looking at the viewer; expression friendly.\n· Silhouette must read at 30px: one clear outline, no thin protruding\n  details.\n\nMUST NOT INCLUDE: a background, room, sky or ground; more than one\ncharacter; hands, arms, props or held objects; text or logos; a circular\nframe, ring, border or badge around the subject (the frame slot provides\nthat); shadows cast onto a floor; anything cropped at the image edge except\nthe chest.',
    pattern: 'A seamless repeating background pattern of [SUBJECT: small pawprints], rendered as [STYLE: flat two-color print] on a dark [COLOR: #1e160c] ground.\n\nGEOMETRY — follow exactly:\n· Square image, 512x512, no transparency.\n· PERFECTLY SEAMLESS: left edge continues into right edge, top into bottom,\n  with no visible seam when tiled 3x3.\n· Low contrast and quiet — this sits behind white text, so nothing brighter\n  than 35% luminance and no large light areas.\n· Motif repeats at least 6 times across the image; even density, no focal\n  point, no centered hero element, no vignette.\n\nMUST NOT INCLUDE: text or numerals; a border or margin; gradients that run\nedge to edge (they break the tile); people or faces; bright highlights; any\nsingle dominant object.',
    plate: 'A blank decorative name plaque of [SUBJECT: weathered bone], horizontal, rendered as [STYLE: a realistic photograph], with nothing written on it.\n\nGEOMETRY — follow exactly:\n· 768x192, fully transparent outside the plaque.\n· The plaque is centered and fills the frame with a small even margin.\n· The middle 70% is a FLAT, EVEN, UNDECORATED surface — a name is printed\n  over it and must stay legible. Decoration lives at the two ends only.\n· Left and right ends are mirror images so the plaque reads as symmetrical.\n\nMUST NOT INCLUDE: any text, letters, numerals or scribbles; a face or\ncharacter; a background or surface behind the plaque; heavy texture, holes\nor cracks through the middle band; drop shadow.',
    cabinet: 'An upright arcade cabinet made of [SUBJECT: scorched firewood and iron bands], three-quarter front view, rendered as [STYLE: a realistic photograph].\n\nGEOMETRY — follow exactly:\n· 800x1000, fully transparent outside the cabinet.\n· The cabinet stands upright, centered, filling the frame with a small even\n  margin, and is not tilted.\n· The screen area is a plain BLACK rectangle in the upper third — the game\n  is drawn into it, so it must be empty and unreflective.\n· The marquee strip above the screen is blank: no logo, no lettering.\n\nMUST NOT INCLUDE: any game imagery, screenshot, character or artwork on the\nscreen; text, logos or lettering anywhere; a room, floor, wall or\nbackground; people or hands; reflections or glare on the screen; a cast\nshadow.',
    spark: 'A burst of [SUBJECT: small orange bats] flying outward from the center, rendered as [STYLE: flat vector shapes].\n\nGEOMETRY — follow exactly:\n· Square image, 512x512, fully transparent background.\n· 8 to 14 separate small elements arranged in a ring radiating outward from\n  the center, spaced evenly.\n· The exact center is EMPTY — this animates outward from a tapped button.\n· Every element is a simple solid shape that reads at 12px; no fine detail.\n· Elements get slightly smaller toward the outside.\n\nMUST NOT INCLUDE: a background or backdrop; one large central object; text\nor numerals; motion blur, streaks or speed lines; a circle, ring or frame\nenclosing the burst; realistic shading or gradients.'
  };

  /* Flavor sets — the house favorites, so a kid can dress to one theme. */
  var TAGS = [
    { key: 'all', name: 'Everything', ink: '#c9a0ff', border: '#6a3fb0' },
    { key: 'monster', name: 'Monsters', ink: '#e8e2d2', border: '#c2182f' },
    { key: 'orange', name: 'Orange & black', ink: '#ff7a18', border: '#ff7a18' },
    { key: 'pink', name: 'Pink & white', ink: '#ff5fa2', border: '#ff5fa2' },
    { key: 'animal', name: 'Animals', ink: '#e0a243', border: '#e0a243' },
    { key: 'house', name: 'House basics', ink: '#8c7bab', border: '#3a2360' }
  ];

  window.FQCosmetics = {
    SLOTS: SLOTS,
    ITEMS: ITEMS,
    TAGS: TAGS,
    PROMPTS: PROMPTS,
    THEMES: THEMES,
    bySlot: function (slot) { return ITEMS.filter(function (i) { return i.slot === slot; }); },
    item: function (slot, key) { return ITEMS.find(function (i) { return i.slot === slot && i.key === key; }); },
    slot: function (key) { return SLOTS.find(function (s) { return s.key === key; }); },
    frameSvg: function (key, opts) {
      opts = opts || {};
      var it = this.item('frame', key) || this.bySlot('frame')[0];
      var p = Object.assign({}, it.p, opts.p || {});
      return svg(moving(FRAMES[fallback(FRAMES, it.key)](p), opts.anim === undefined ? it.anim : opts.anim));
    },
    avatarSvg: function (key, opts) {
      opts = opts || {};
      var it = this.item('avatar', key) || this.bySlot('avatar')[0];
      var p = Object.assign({}, it.p, opts.p || {});
      var anim = opts.anim === undefined ? it.anim : opts.anim;
      BLINK = anim === 'blink';
      var body = AVATARS[fallback(AVATARS, it.key)](p);
      BLINK = false;
      return svg(moving(body, anim === 'blink' ? null : anim, '50px 84px'));
    },
    sparkSvg: function (key, opts) {
      opts = opts || {};
      var it = this.item('spark', key) || this.bySlot('spark')[0];
      var anim = opts.anim === undefined ? it.anim : opts.anim;
      return svg(moving(SPARKS[fallback(SPARKS, it.key)](it.p || { c1: '#ffc93d', c2: '#c9a0ff' }), anim));
    },
    /** The `animation` shorthand a pattern wants, or '' for a still one. */
    patternAnim: function (key) {
      var it = ITEMS.find(function (i) { return i.slot === 'pattern' && i.key === key; });
      return it && it.anim ? MOTION[it.anim] || '' : '';
    },
    KEYFRAMES: KEYFRAMES,
    MOTION: MOTION,
    theme: function (key) { return THEMES[fallback(THEMES, key)]; },
    plate: function (key) { return PLATES[fallback(PLATES, key)]; },
    pattern: function (key) { return PATTERNS[fallback(PATTERNS, key)].css; },
    patternName: function (key) { return PATTERNS[fallback(PATTERNS, key)].name; },
    cabinet: function (key) { return CABINETS[fallback(CABINETS, key)]; },
    /** The one preview every screen needs: a kid wearing a set. */
    worn: function (set) {
      return {
        frame: this.item('frame', set.frame),
        avatar: this.item('avatar', set.avatar),
        plate: this.plate(set.plate),
        theme: this.theme(set.theme),
        pattern: this.pattern(set.pattern),
        cabinet: this.cabinet(set.cabinet),
        spark: this.item('spark', set.spark)
      };
    }
  };
})();
