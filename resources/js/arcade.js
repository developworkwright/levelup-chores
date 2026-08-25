/*
 * Stack the Mess — the game on the front door.
 *
 * A slab of household clutter slides across the top of the tower; you tap to
 * drop it, and whatever hangs over the edge shears off and falls away. The
 * tower narrows every time you miss, so a run ends when there is nothing left
 * to land on. Score is floors.
 *
 * Two things are worth knowing before changing anything in here:
 *
 * 1. Everything is drawn in a fixed 320x460 coordinate system and scaled to
 *    whatever the element is actually sized at. Physics is therefore identical
 *    on a phone and a desktop, which matters because both play the same board.
 *
 * 2. The milestone ladder is owned by `ArcadeService::MILESTONES` in PHP and
 *    passed in, because the leaderboard labels a score with the same words the
 *    banner does. `SCENERY` below is keyed to that ladder *by index* — entry 8
 *    is the artwork for whatever milestone 8 is called. `ArcadeMilestoneTest`
 *    fails if the two lists stop being the same length.
 */

const W = 320;
const H = 460;

/** Height of one floor, and so the height of every slab and every shear. */
const FLOOR_H = 26;
const START_W = 178;

/** Screen y of world y=0 when the camera is on the ground. */
const BASE_Y = 400;
/** How far below the top of the frame the sliding slab is held. */
const TOP_GAP = 120;

/** Misalignment forgiven entirely, in world px. Generous on purpose: the
 *  mercy width below is the only way a long run gets its tower back. */
const PERFECT = 2.6;
const MERCY_EVERY = 4;
const MERCY_WIDTH = 9;

const SIDE_MARGIN = 8;

/* ------------------------------------------------------------------ *
 * Small helpers
 * ------------------------------------------------------------------ */

/** The same hash monsters.js uses, for the same reason: stable per-seed art. */
const rnd = (s) => {
    const x = Math.sin(s * 127.1) * 43758.5453;

    return x - Math.floor(x);
};

const pick = (list, seed) => list[Math.floor(rnd(seed) * list.length) % list.length];

const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);

/** Multiply a hex colour toward black — cheap, consistent shading. */
function shade(hex, k) {
    const n = parseInt(hex.slice(1), 16);

    return 'rgb(' + Math.round(((n >> 16) & 255) * k) + ',' +
        Math.round(((n >> 8) & 255) * k) + ',' +
        Math.round((n & 255) * k) + ')';
}

function mixHex(a, b, t) {
    const x = parseInt(a.slice(1), 16);
    const y = parseInt(b.slice(1), 16);
    const c = (sh) => Math.round((((x >> sh) & 255) * (1 - t)) + (((y >> sh) & 255) * t));

    return 'rgb(' + c(16) + ',' + c(8) + ',' + c(0) + ')';
}

/** Rounded rect as a path. Hand-rolled rather than ctx.roundRect so the game
 *  does not quietly stop drawing on an older tablet browser. */
function rr(ctx, x, y, w, h, r) {
    r = Math.max(0, Math.min(r, w / 2, h / 2));
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

/* ------------------------------------------------------------------ *
 * The clutter — what a floor is made of, and what it looks like
 * ------------------------------------------------------------------ */

const CLOTH = ['#7fd4ff', '#ffb3d1', '#ffe14d', '#a8f08a', '#c9a0ff', '#ff9a6b', '#f7f0ff'];
const RIMS = ['#7fb3ff', '#ff8ac7', '#54ffd5', '#ffc93d'];
const SPINES = ['#c0392b', '#1e6f8f', '#2e8b57', '#8e44ad', '#d4a017', '#3b4b7a'];
const BOXES = ['#e0365b', '#2f8fd6', '#3fae6a', '#a06bff', '#ff8a3d'];
const CEREAL = ['#ff8a3d', '#ffd23d', '#4fc3f7', '#ff5d8f', '#8ee06a'];
const ODDS = ['#ffe14d', '#54ffd5', '#ff8ac7', '#a06bff', '#ff9a6b', '#e8e4f5'];

/*
 * The chain. What you are stacking changes as the tower climbs, which is the
 * only reason to keep climbing once the number stops being interesting: the
 * laundry gives way to the dishes, the dishes to the pizza boxes, and by the
 * time you are in the clouds you are balancing the junk drawer.
 *
 * Every function draws into an arbitrary rect and is called with the slab's
 * *original* frame, not its sheared one — the caller clips. That is what makes
 * a shorn-off corner look like it came off the piece rather than like a new
 * smaller piece: both halves are the same drawing, seen through two holes.
 */
const ITEMS = {
    laundry(ctx, x, y, w, h, s) {
        const bands = [[0, 0.36], [0.36, 0.7], [0.7, 1]];

        bands.forEach(([top, bottom], i) => {
            const by = y + h * top;
            const bh = h * (bottom - top);
            const col = pick(CLOTH, s + i * 3.7);

            ctx.fillStyle = col;
            rr(ctx, x + 2, by, w - 4, bh - 1, 3);
            ctx.fill();

            ctx.fillStyle = shade(col, 0.72);
            rr(ctx, x + 2, by, Math.min(15, w * 0.17), bh - 1, 3);
            ctx.fill();

            ctx.strokeStyle = 'rgba(255,255,255,0.3)';
            ctx.lineWidth = 1;
            ctx.setLineDash([2, 3]);
            ctx.beginPath();
            ctx.moveTo(x + 7, by + bh * 0.55);
            ctx.lineTo(x + w - 6, by + bh * 0.55);
            ctx.stroke();
            ctx.setLineDash([]);
        });
    },

    dishes(ctx, x, y, w, h, s) {
        for (let i = 0; i < 3; i++) {
            const by = y + (h / 3) * i;
            const bh = h / 3;
            const inset = 2 + i * 1.6;

            ctx.fillStyle = '#efeaf7';
            rr(ctx, x + inset, by, w - inset * 2, bh - 1, bh / 2);
            ctx.fill();

            ctx.strokeStyle = pick(RIMS, s + i * 2.4);
            ctx.lineWidth = 1.3;
            rr(ctx, x + inset + 3.5, by + 1.6, Math.max(2, w - inset * 2 - 7), bh - 4.2, (bh - 4.2) / 2);
            ctx.stroke();

            ctx.fillStyle = 'rgba(255,255,255,0.6)';
            ctx.fillRect(x + inset + 7, by + 1.8, Math.max(4, w * 0.16), 1.1);
        }
    },

    pizza(ctx, x, y, w, h, s) {
        ctx.fillStyle = '#c8a16d';
        ctx.fillRect(x, y, w, h);

        ctx.fillStyle = '#a67f4e';
        ctx.fillRect(x, y + h - 5, w, 5);

        ctx.strokeStyle = 'rgba(88,60,30,0.5)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, y + 6.5);
        ctx.lineTo(x + w, y + 6.5);
        ctx.stroke();

        ctx.fillStyle = 'rgba(112,74,26,0.28)';
        ctx.beginPath();
        ctx.ellipse(x + w * (0.22 + rnd(s) * 0.55), y + h * 0.66, 8, 4, 0, 0, 6.29);
        ctx.fill();

        const lx = x + w * 0.5;
        ctx.fillStyle = '#8f2f2f';
        ctx.beginPath();
        ctx.arc(lx, y + h * 0.4, 4.4, 0, 6.29);
        ctx.fill();
        ctx.fillStyle = '#ffd76b';
        ctx.beginPath();
        ctx.arc(lx, y + h * 0.4, 2.3, 0, 6.29);
        ctx.fill();
    },

    socks(ctx, x, y, w, h, s) {
        ctx.fillStyle = '#241a36';
        ctx.fillRect(x, y, w, h);

        const n = Math.max(2, Math.round(w / 26));

        for (let i = 0; i < n; i++) {
            const col = pick(CLOTH, s + i * 2.3);

            ctx.save();
            ctx.translate(x + (w / n) * (i + 0.5), y + h * 0.55);
            ctx.rotate((rnd(s + i * 1.9) - 0.5) * 1.2);

            ctx.fillStyle = col;
            rr(ctx, -5, -9, 10, 13, 4);
            ctx.fill();
            ctx.beginPath();
            ctx.ellipse(2.5, 4, 8, 4.6, 0, 0, 6.29);
            ctx.fill();

            ctx.fillStyle = 'rgba(255,255,255,0.72)';
            ctx.fillRect(-5, -8.4, 10, 1.7);
            ctx.fillRect(-5, -5.2, 10, 1.7);
            ctx.restore();
        }
    },

    books(ctx, x, y, w, h, s) {
        for (let i = 0; i < 3; i++) {
            const by = y + (h / 3) * i;
            const bh = h / 3 - 0.8;
            const col = pick(SPINES, s + i * 1.7);
            const off = rnd(s + i * 4.1) * 7;

            ctx.fillStyle = col;
            rr(ctx, x + off, by, Math.max(2, w - off - 2), bh, 1.5);
            ctx.fill();

            ctx.fillStyle = '#f2e9d8';
            ctx.fillRect(x + w - off - 7, by + 1, 4, bh - 2);

            ctx.fillStyle = 'rgba(255,255,255,0.45)';
            ctx.fillRect(x + off + 5, by + bh * 0.36, Math.max(5, (w - off) * 0.32), 1.2);

            ctx.strokeStyle = 'rgba(0,0,0,0.32)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(x + off + 2.5, by);
            ctx.lineTo(x + off + 2.5, by + bh);
            ctx.stroke();
        }
    },

    games(ctx, x, y, w, h, s) {
        const col = pick(BOXES, s);

        ctx.fillStyle = col;
        ctx.fillRect(x, y, w, h);
        ctx.fillStyle = shade(col, 0.72);
        ctx.fillRect(x, y + h - 6, w, 6);

        const lw = Math.max(12, w * 0.56);
        const lx = x + (w - lw) / 2;

        ctx.fillStyle = '#f6f1ff';
        rr(ctx, lx, y + 4, lw, h - 13, 2);
        ctx.fill();

        ['#e0365b', '#ffe14d', '#54ffd5', '#a06bff'].forEach((dot, i) => {
            ctx.fillStyle = dot;
            ctx.beginPath();
            ctx.arc(lx + 5 + i * ((lw - 10) / 3), y + h * 0.33, 1.9, 0, 6.29);
            ctx.fill();
        });

        ctx.strokeStyle = 'rgba(40,20,60,0.45)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(lx + 4, y + h * 0.56);
        ctx.lineTo(lx + lw - 4, y + h * 0.56);
        ctx.stroke();
    },

    bags(ctx, x, y, w, h, s) {
        ctx.fillStyle = '#0f0a1a';
        ctx.fillRect(x, y, w, h);

        const n = Math.max(1, Math.round(w / 42));

        for (let i = 0; i < n; i++) {
            const cx = x + (w / n) * (i + 0.5);
            const r = Math.min(w / n, h * 1.6) * 0.5;

            ctx.fillStyle = '#1a1329';
            ctx.beginPath();
            ctx.ellipse(cx, y + h * 0.64, r, h * 0.4, 0, 0, 6.29);
            ctx.fill();

            ctx.fillStyle = 'rgba(255,255,255,0.12)';
            ctx.beginPath();
            ctx.ellipse(cx - r * 0.34, y + h * 0.52, r * 0.28, h * 0.18, -0.5, 0, 6.29);
            ctx.fill();

            ctx.fillStyle = '#241b38';
            ctx.beginPath();
            ctx.moveTo(cx - 5.5, y + h * 0.26);
            ctx.lineTo(cx, y + h * 0.04);
            ctx.lineTo(cx + 5.5, y + h * 0.26);
            ctx.closePath();
            ctx.fill();
        }
    },

    cereal(ctx, x, y, w, h, s) {
        const n = Math.max(1, Math.round(w / 34));

        for (let i = 0; i < n; i++) {
            const bx = x + (w / n) * i;
            const bw = Math.max(6, w / n - 1.5);
            const col = pick(CEREAL, s + i * 2.1);
            const pw = Math.max(5, bw - 8);

            ctx.fillStyle = col;
            ctx.fillRect(bx + 1, y, bw, h);
            ctx.fillStyle = 'rgba(0,0,0,0.22)';
            ctx.fillRect(bx + 1, y, bw, 3.5);

            ctx.fillStyle = '#fff6e0';
            rr(ctx, bx + 4, y + 6, pw, h - 11, 2);
            ctx.fill();

            ctx.fillStyle = shade(col, 0.62);
            ctx.beginPath();
            ctx.arc(bx + 4 + pw / 2, y + h * 0.52, 3.4, 0, 6.29);
            ctx.fill();
            ctx.fillStyle = '#fff6e0';
            ctx.beginPath();
            ctx.arc(bx + 3 + pw / 2, y + h * 0.47, 0.9, 0, 6.29);
            ctx.fill();
        }
    },

    junk(ctx, x, y, w, h, s) {
        ctx.fillStyle = '#3a2a1c';
        ctx.fillRect(x, y + h - 7, w, 7);
        ctx.fillStyle = '#4c3826';
        ctx.fillRect(x, y + h - 7, w, 2);

        const n = Math.max(2, Math.round(w / 24));

        for (let i = 0; i < n; i++) {
            const cx = x + (w / n) * (i + 0.5);
            const kind = Math.floor(rnd(s + i * 3.3) * 4);
            const col = pick(ODDS, s + i * 1.4);

            ctx.fillStyle = col;

            if (kind === 0) {
                ctx.beginPath();
                ctx.arc(cx, y + h - 13, 6, 0, 6.29);
                ctx.fill();
                ctx.fillStyle = shade(col, 0.65);
                ctx.beginPath();
                ctx.arc(cx, y + h - 13, 6, 0.4, 2.2);
                ctx.fill();
            } else if (kind === 1) {
                rr(ctx, cx - 6, y + h - 19, 12, 12, 2);
                ctx.fill();
                ctx.strokeStyle = 'rgba(0,0,0,0.4)';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(cx - 2, y + h - 19);
                ctx.lineTo(cx - 2, y + h - 7);
                ctx.moveTo(cx - 6, y + h - 15);
                ctx.lineTo(cx + 6, y + h - 15);
                ctx.stroke();
            } else if (kind === 2) {
                rr(ctx, cx - 3.5, y + h - 21, 7, 14, 3);
                ctx.fill();
                ctx.fillStyle = shade(col, 0.6);
                ctx.fillRect(cx - 2, y + h - 24, 4, 4);
            } else {
                ctx.beginPath();
                ctx.ellipse(cx, y + h - 11, 7, 5, 0, 0, 6.29);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(cx + 4, y + h - 16, 3.6, 0, 6.29);
                ctx.fill();
                ctx.fillStyle = '#07030d';
                ctx.beginPath();
                ctx.arc(cx + 5.4, y + h - 17, 0.8, 0, 6.29);
                ctx.fill();
            }
        }
    },
};

/** Which clutter a floor is made of. Ends open so the junk drawer runs forever. */
const CHAIN = [
    [4, 'laundry'],
    [9, 'dishes'],
    [14, 'pizza'],
    [19, 'socks'],
    [24, 'books'],
    [29, 'games'],
    [34, 'bags'],
    [39, 'cereal'],
];

function itemFor(floor) {
    for (const [until, type] of CHAIN) {
        if (floor < until) {
            return type;
        }
    }

    return 'junk';
}

/* ------------------------------------------------------------------ *
 * The climb — what you are passing on the way up
 * ------------------------------------------------------------------ */

/*
 * One entry per milestone in `ArcadeService::MILESTONES`, in the same order.
 * Each is handed the screen y of the height it belongs to and draws around it,
 * full width — the tower is drawn afterwards and covers the middle, so these
 * only ever have to be right at the edges.
 *
 * Why this matters more than it looks: a number on its own tells a six-year-old
 * nothing. Coming out through the roof tells them everything.
 */
const SCENERY = [
    // 0 — On the rug
    (ctx, y) => {
        ctx.fillStyle = '#0c0617';
        ctx.fillRect(0, y, W, H - y + 220);

        ctx.fillStyle = '#1c1130';
        ctx.fillRect(0, y - 5, W, 6);

        ctx.strokeStyle = 'rgba(255,255,255,0.045)';
        ctx.lineWidth = 1;
        for (let i = 0; i < 7; i++) {
            ctx.beginPath();
            ctx.moveTo(0, y + 13 + i * 11);
            ctx.lineTo(W, y + 13 + i * 11);
            ctx.stroke();
        }

        ctx.fillStyle = '#2b1a44';
        ctx.beginPath();
        ctx.ellipse(W / 2, y + 30, 132, 21, 0, 0, 6.29);
        ctx.fill();
        ctx.strokeStyle = '#3d2660';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.ellipse(W / 2, y + 30, 114, 16, 0, 0, 6.29);
        ctx.stroke();
    },

    // 1 — Sofa height
    (ctx, y) => {
        ctx.fillStyle = '#26173c';
        rr(ctx, 16, y, W - 32, 96, 12);
        ctx.fill();

        ctx.fillStyle = '#1b1030';
        for (let i = 0; i < 3; i++) {
            rr(ctx, 54 + i * 71, y + 9, 64, 46, 8);
            ctx.fill();
        }

        ctx.fillStyle = '#2f1d49';
        rr(ctx, 4, y + 20, 48, 76, 12);
        ctx.fill();
        rr(ctx, W - 52, y + 20, 48, 76, 12);
        ctx.fill();

        ctx.fillStyle = '#3a2458';
        rr(ctx, 66, y - 17, 36, 23, 6);
        ctx.fill();
    },

    // 2 — Light switch
    (ctx, y) => {
        ctx.fillStyle = '#2a1c40';
        rr(ctx, 28, y - 15, 24, 30, 4);
        ctx.fill();
        ctx.fillStyle = '#efe6ff';
        rr(ctx, 35, y - 9, 10, 15, 2);
        ctx.fill();

        ctx.fillStyle = '#2a1c40';
        rr(ctx, W - 64, y - 6, 32, 23, 4);
        ctx.fill();
        ctx.fillStyle = '#0f0919';
        ctx.beginPath();
        ctx.arc(W - 55, y + 3, 2.4, 0, 6.29);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(W - 44, y + 3, 2.4, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = '#1c1130';
        ctx.fillRect(W - 51, y + 15, 4, 46);
    },

    // 3 — Picture rail
    (ctx, y) => {
        const frame = (fx, fy, fw, fh, tilt, col) => {
            ctx.save();
            ctx.translate(fx + fw / 2, fy + fh / 2);
            ctx.rotate(tilt);
            ctx.translate(-fw / 2, -fh / 2);

            ctx.fillStyle = '#7d6039';
            rr(ctx, 0, 0, fw, fh, 2);
            ctx.fill();
            ctx.fillStyle = col;
            ctx.fillRect(4, 4, fw - 8, fh - 8);
            ctx.fillStyle = 'rgba(255,255,255,0.16)';
            ctx.beginPath();
            ctx.moveTo(6, fh - 7);
            ctx.lineTo(fw / 2, 11);
            ctx.lineTo(fw - 6, fh - 7);
            ctx.closePath();
            ctx.fill();
            ctx.restore();
        };

        frame(14, y - 26, 56, 46, 0, '#2d4a6b');
        frame(W - 80, y - 14, 64, 50, 0.1, '#4a2d5e');
    },

    // 4 — Window height
    (ctx, y) => {
        const wx = 18;
        const wy = y - 48;
        const ww = 84;
        const wh = 98;

        ctx.fillStyle = '#463320';
        rr(ctx, wx - 5, wy - 5, ww + 10, wh + 10, 4);
        ctx.fill();

        const g = ctx.createLinearGradient(0, wy, 0, wy + wh);
        g.addColorStop(0, '#0a0f2e');
        g.addColorStop(1, '#262c60');
        ctx.fillStyle = g;
        ctx.fillRect(wx, wy, ww, wh);

        ctx.fillStyle = '#fff6c8';
        ctx.beginPath();
        ctx.arc(wx + ww * 0.68, wy + 25, 9, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = 'rgba(255,255,255,0.8)';
        for (let i = 0; i < 7; i++) {
            ctx.fillRect(wx + 8 + rnd(i * 2.7) * (ww - 16), wy + 8 + rnd(i * 5.3) * (wh - 16), 1.6, 1.6);
        }

        ctx.fillStyle = '#463320';
        ctx.fillRect(wx + ww / 2 - 2, wy, 4, wh);
        ctx.fillRect(wx, wy + wh / 2 - 2, ww, 4);

        ctx.fillStyle = '#5c2a4a';
        rr(ctx, wx - 15, wy - 9, 19, wh + 22, 6);
        ctx.fill();
        ctx.fillStyle = '#6d3358';
        rr(ctx, wx + ww - 4, wy - 9, 19, wh + 22, 6);
        ctx.fill();
    },

    // 5 — Top shelf
    (ctx, y) => {
        ctx.fillStyle = '#4a3220';
        ctx.fillRect(W - 132, y, 132, 7);
        ctx.fillStyle = '#33220f';
        ctx.fillRect(W - 120, y + 7, 6, 13);

        for (let i = 0; i < 4; i++) {
            ctx.fillStyle = SPINES[i % SPINES.length];
            ctx.fillRect(W - 118 + i * 9, y - 26, 7, 26);
        }

        ctx.fillStyle = '#efe6ff';
        rr(ctx, W - 76, y - 15, 15, 15, 3);
        ctx.fill();
        ctx.strokeStyle = '#efe6ff';
        ctx.lineWidth = 2.4;
        ctx.beginPath();
        ctx.arc(W - 59, y - 8, 4.6, -1.2, 1.2);
        ctx.stroke();

        ctx.fillStyle = '#a8552f';
        rr(ctx, W - 42, y - 17, 23, 18, 3);
        ctx.fill();
        ctx.fillStyle = '#3fae6a';
        for (let i = 0; i < 5; i++) {
            ctx.save();
            ctx.translate(W - 30.5, y - 17);
            ctx.rotate(-1 + i * 0.5);
            ctx.beginPath();
            ctx.ellipse(0, -11, 4.4, 12, 0, 0, 6.29);
            ctx.fill();
            ctx.restore();
        }
    },

    // 6 — Ceiling
    (ctx, y) => {
        ctx.fillStyle = '#0e0719';
        ctx.fillRect(0, y - 12, W, 12);
        ctx.fillStyle = '#241638';
        ctx.fillRect(0, y - 16, W, 5);

        ctx.fillStyle = 'rgba(255,225,120,0.13)';
        ctx.beginPath();
        ctx.moveTo(44, y + 30);
        ctx.lineTo(76, y + 30);
        ctx.lineTo(108, y + 104);
        ctx.lineTo(12, y + 104);
        ctx.closePath();
        ctx.fill();

        ctx.fillStyle = '#241638';
        ctx.fillRect(58, y, 3, 14);
        ctx.fillStyle = '#3a2458';
        ctx.beginPath();
        ctx.moveTo(42, y + 30);
        ctx.lineTo(77, y + 30);
        ctx.lineTo(66, y + 13);
        ctx.lineTo(53, y + 13);
        ctx.closePath();
        ctx.fill();
        ctx.fillStyle = '#ffe9a8';
        ctx.beginPath();
        ctx.arc(59.5, y + 31, 5, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = '#2c1d44';
        ctx.fillRect(W - 82, y, 3, 15);
        rr(ctx, W - 122, y + 14, 42, 6, 3);
        ctx.fill();
        rr(ctx, W - 78, y + 14, 42, 6, 3);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(W - 80.5, y + 17, 6, 0, 6.29);
        ctx.fill();
    },

    // 7 — In the attic
    (ctx, y) => {
        ctx.fillStyle = '#3a2a1c';
        ctx.save();
        ctx.translate(0, y);
        ctx.rotate(-0.52);
        ctx.fillRect(-40, 0, 210, 11);
        ctx.restore();
        ctx.save();
        ctx.translate(W, y);
        ctx.rotate(0.52);
        ctx.fillRect(-170, 0, 210, 11);
        ctx.restore();

        ctx.fillStyle = '#6b4f30';
        rr(ctx, 6, y + 34, 54, 36, 2);
        ctx.fill();
        rr(ctx, W - 68, y + 26, 60, 44, 2);
        ctx.fill();
        ctx.fillStyle = '#54401f';
        ctx.fillRect(6, y + 45, 54, 4);
        ctx.fillRect(W - 68, y + 39, 60, 4);

        ctx.strokeStyle = 'rgba(230,225,245,0.2)';
        ctx.lineWidth = 1;
        for (let i = 1; i <= 3; i++) {
            ctx.beginPath();
            ctx.arc(0, y - 26, i * 17, 0, 1.571);
            ctx.stroke();
        }
        ctx.beginPath();
        ctx.moveTo(0, y - 26);
        ctx.lineTo(55, y - 26);
        ctx.moveTo(0, y - 26);
        ctx.lineTo(39, y + 13);
        ctx.moveTo(0, y - 26);
        ctx.lineTo(0, y + 28);
        ctx.stroke();
    },

    // 8 — Through the roof
    (ctx, y) => {
        ctx.fillStyle = '#2a1b3f';
        ctx.beginPath();
        ctx.moveTo(-12, y + 96);
        ctx.lineTo(W / 2, y - 8);
        ctx.lineTo(W + 12, y + 96);
        ctx.closePath();
        ctx.fill();

        ctx.strokeStyle = 'rgba(0,0,0,0.32)';
        ctx.lineWidth = 1;
        for (let i = 1; i < 8; i++) {
            const t = i / 8;
            ctx.beginPath();
            ctx.moveTo(-12 + (W / 2 + 12) * t, y + 96 - 104 * t);
            ctx.lineTo(W + 12 - (W / 2 + 12) * t, y + 96 - 104 * t);
            ctx.stroke();
        }

        ctx.fillStyle = '#4a2f2a';
        ctx.fillRect(32, y + 12, 30, 62);
        ctx.fillStyle = '#5c3b34';
        ctx.fillRect(28, y + 8, 38, 8);

        ctx.strokeStyle = '#6a5a8a';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(W - 46, y + 34);
        ctx.lineTo(W - 46, y - 10);
        ctx.stroke();
        ctx.fillStyle = '#6a5a8a';
        ctx.beginPath();
        ctx.moveTo(W - 46, y - 8);
        ctx.lineTo(W - 21, y);
        ctx.lineTo(W - 46, y + 8);
        ctx.closePath();
        ctx.fill();
    },

    // 9 — Treetops
    (ctx, y) => {
        ctx.fillStyle = '#152820';

        const tree = (tx, sc) => {
            ctx.beginPath();
            for (let i = 0; i < 10; i++) {
                const a = (i / 10) * 6.283;
                const r = (17 + rnd(tx + i * 1.3) * 13) * sc;
                const px = tx + Math.cos(a) * r;
                const py = y + 34 * sc + Math.sin(a) * r * 0.72;
                i ? ctx.lineTo(px, py) : ctx.moveTo(px, py);
            }
            ctx.closePath();
            ctx.fill();
        };

        tree(20, 1.25);
        tree(72, 0.9);
        tree(W - 26, 1.3);
        tree(W - 78, 0.95);
    },

    // 10 — In the clouds
    (ctx, y) => {
        const cloud = (cx, cy, sc, al) => {
            ctx.fillStyle = 'rgba(212,206,242,' + al + ')';
            ctx.beginPath();
            ctx.ellipse(cx, cy, 34 * sc, 13 * sc, 0, 0, 6.29);
            ctx.fill();
            ctx.beginPath();
            ctx.ellipse(cx - 23 * sc, cy + 5 * sc, 20 * sc, 9 * sc, 0, 0, 6.29);
            ctx.fill();
            ctx.beginPath();
            ctx.ellipse(cx + 25 * sc, cy + 4 * sc, 22 * sc, 10 * sc, 0, 0, 6.29);
            ctx.fill();
        };

        cloud(58, y + 12, 1.15, 0.17);
        cloud(W - 46, y - 30, 0.9, 0.13);
        cloud(W - 96, y + 58, 1.35, 0.09);
    },

    // 11 — Bird height
    (ctx, y) => {
        ctx.strokeStyle = 'rgba(232,228,246,0.5)';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';

        const bird = (bx, by, sc) => {
            ctx.beginPath();
            ctx.moveTo(bx - 7 * sc, by);
            ctx.quadraticCurveTo(bx - 3 * sc, by - 5.5 * sc, bx, by - sc);
            ctx.quadraticCurveTo(bx + 3 * sc, by - 5.5 * sc, bx + 7 * sc, by);
            ctx.stroke();
        };

        bird(42, y + 6, 1.25);
        bird(76, y - 16, 0.9);
        bird(W - 50, y + 28, 1.1);
        bird(W - 88, y - 6, 0.8);

        ctx.fillStyle = '#e0365b';
        ctx.beginPath();
        ctx.moveTo(W - 34, y + 60);
        ctx.lineTo(W - 22, y + 74);
        ctx.lineTo(W - 34, y + 90);
        ctx.lineTo(W - 46, y + 74);
        ctx.closePath();
        ctx.fill();
        ctx.strokeStyle = 'rgba(232,228,246,0.3)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(W - 34, y + 90);
        ctx.quadraticCurveTo(W - 25, y + 108, W - 37, y + 124);
        ctx.stroke();
    },

    // 12 — Stratosphere
    (ctx, y) => {
        ctx.strokeStyle = 'rgba(200,205,240,0.14)';
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        for (let i = 0; i < 4; i++) {
            const cy = y + i * 27 - 22;
            ctx.beginPath();
            ctx.moveTo(10 + rnd(i * 3.1) * 44, cy);
            ctx.quadraticCurveTo(W / 2, cy - 9, W - 14 - rnd(i * 7.7) * 44, cy + 5);
            ctx.stroke();
        }

        ctx.strokeStyle = 'rgba(255,255,255,0.26)';
        ctx.lineWidth = 2.4;
        ctx.beginPath();
        ctx.moveTo(W - 136, y + 50);
        ctx.lineTo(W - 34, y + 42);
        ctx.stroke();

        ctx.fillStyle = '#e8e4f5';
        ctx.beginPath();
        ctx.moveTo(W - 34, y + 42);
        ctx.lineTo(W - 18, y + 45);
        ctx.lineTo(W - 34, y + 48);
        ctx.lineTo(W - 29, y + 45);
        ctx.closePath();
        ctx.fill();
    },

    // 13 — Moonlit
    (ctx, y) => {
        const mx = 64;
        const my = y + 12;
        const r = 40;

        const g = ctx.createRadialGradient(mx, my, r * 0.5, mx, my, r * 2.7);
        g.addColorStop(0, 'rgba(255,246,200,0.20)');
        g.addColorStop(1, 'rgba(255,246,200,0)');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(mx, my, r * 2.7, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = '#f6efc9';
        ctx.beginPath();
        ctx.arc(mx, my, r, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = 'rgba(188,178,138,0.5)';
        [[-13, -10, 8], [11, 5, 11], [-6, 17, 6], [17, -17, 5]].forEach(([dx, dy, cr]) => {
            ctx.beginPath();
            ctx.arc(mx + dx, my + dy, cr, 0, 6.29);
            ctx.fill();
        });
    },

    // 14 — Outer space
    (ctx, y) => {
        ctx.fillStyle = '#7a4fd0';
        ctx.beginPath();
        ctx.arc(W - 54, y + 34, 26, 0, 6.29);
        ctx.fill();
        ctx.fillStyle = 'rgba(0,0,0,0.22)';
        ctx.beginPath();
        ctx.arc(W - 46, y + 40, 22, 0, 6.29);
        ctx.fill();

        ctx.strokeStyle = '#d8b4ff';
        ctx.lineWidth = 3;
        ctx.save();
        ctx.translate(W - 54, y + 34);
        ctx.rotate(-0.4);
        ctx.beginPath();
        ctx.ellipse(0, 0, 43, 11, 0, 0, 6.29);
        ctx.stroke();
        ctx.restore();

        ctx.fillStyle = '#4a6bd0';
        ctx.fillRect(6, y - 12, 24, 11);
        ctx.fillRect(58, y - 12, 24, 11);
        ctx.fillStyle = '#cfc6ea';
        rr(ctx, 33, y - 15, 22, 16, 3);
        ctx.fill();
        ctx.strokeStyle = '#cfc6ea';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(44, y + 1);
        ctx.lineTo(44, y + 13);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(44, y + 17, 5, 3.4, 6.0);
        ctx.stroke();
    },
];

/**
 * The sky, as a set of world-height stops. Indoors is the app's own purple;
 * the attic goes browner and dimmer; above the roof it opens into night; and
 * high enough up it drains to nearly black.
 *
 * @type {Array<[number, string, string]>}
 */
const SKY = [
    [0, '#1e1136', '#120a22'],
    [420, '#1b1030', '#140b26'],
    [560, '#1a1526', '#120d1e'],
    [700, '#1e1a4e', '#2c1c52'],
    [1050, '#111642', '#1c1a4c'],
    [1550, '#070a22', '#0d1030'],
    [2300, '#04030c', '#07050f'],
];

function skyStops(worldY) {
    let lo = SKY[0];
    let hi = SKY[SKY.length - 1];

    for (let i = 0; i < SKY.length - 1; i++) {
        if (worldY >= SKY[i][0] && worldY <= SKY[i + 1][0]) {
            lo = SKY[i];
            hi = SKY[i + 1];
            break;
        }
    }

    const t = clamp((worldY - lo[0]) / Math.max(1, hi[0] - lo[0]), 0, 1);

    return [mixHex(lo[1], hi[1], t), mixHex(lo[2], hi[2], t)];
}

/* ------------------------------------------------------------------ *
 * Sound
 * ------------------------------------------------------------------ */

/*
 * Three noises, synthesised rather than loaded, so the login page stays one
 * request. Reads the same `fq-muted` key the kid header's speaker button
 * writes, so muting the app mutes the arcade and vice versa.
 */
const Sfx = {
    ctx: null,

    open() {
        if (localStorage.getItem('fq-muted') === '1') {
            return null;
        }

        const AC = window.AudioContext || window.webkitAudioContext;

        if (!AC) {
            return null;
        }

        if (!this.ctx) {
            this.ctx = new AC();
        }

        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }

        return this.ctx;
    },

    blip(freq, dur, vol, slideTo) {
        const ac = this.open();

        if (!ac) {
            return;
        }

        const o = ac.createOscillator();
        const g = ac.createGain();

        o.type = 'triangle';
        o.frequency.setValueAtTime(freq, ac.currentTime);

        if (slideTo) {
            o.frequency.exponentialRampToValueAtTime(slideTo, ac.currentTime + dur);
        }

        g.gain.setValueAtTime(vol, ac.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + dur);

        o.connect(g);
        g.connect(ac.destination);
        o.start();
        o.stop(ac.currentTime + dur);
    },

    thud(dur, vol, cutoff) {
        const ac = this.open();

        if (!ac) {
            return;
        }

        const len = Math.max(1, Math.floor(ac.sampleRate * dur));
        const buf = ac.createBuffer(1, len, ac.sampleRate);
        const data = buf.getChannelData(0);

        for (let i = 0; i < len; i++) {
            data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 2);
        }

        const src = ac.createBufferSource();
        const filter = ac.createBiquadFilter();
        const gain = ac.createGain();

        src.buffer = buf;
        filter.type = 'lowpass';
        filter.frequency.value = cutoff;
        gain.gain.value = vol;

        src.connect(filter);
        filter.connect(gain);
        gain.connect(ac.destination);
        src.start();
    },
};

/* ------------------------------------------------------------------ *
 * The game
 * ------------------------------------------------------------------ */

class Stacker {
    constructor(canvas, milestones, hooks) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.milestones = milestones;
        this.hooks = hooks;
        this.scale = 1;
        this.raf = null;
        this.last = 0;
        this.reset();
    }

    /* -------------------------------------------------------------- *
     * Lifecycle
     * -------------------------------------------------------------- */

    mount() {
        this.observer = new ResizeObserver(() => this.resize());
        this.observer.observe(this.canvas);
        this.resize();
        this.last = performance.now();
        this.raf = requestAnimationFrame((t) => this.frame(t));
    }

    unmount() {
        if (this.raf) {
            cancelAnimationFrame(this.raf);
            this.raf = null;
        }

        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }

    resize() {
        const rect = this.canvas.getBoundingClientRect();

        if (rect.width < 1) {
            return;
        }

        const dpr = Math.min(2.5, window.devicePixelRatio || 1);

        this.canvas.width = Math.round(rect.width * dpr);
        this.canvas.height = Math.round(rect.height * dpr);
        this.scale = (rect.width / W) * dpr;
    }

    /* -------------------------------------------------------------- *
     * State
     * -------------------------------------------------------------- */

    reset() {
        this.phase = 'idle';
        this.floors = 0;
        this.combo = 0;
        this.milestone = 0;
        this.cam = 0;
        this.shake = 0;
        this.debris = [];
        this.effects = [];
        this.banner = null;

        const x = (W - START_W) / 2;

        this.tower = [{
            x,
            w: START_W,
            artX: x,
            artW: START_W,
            type: itemFor(0),
            seed: 1.7,
            squash: 0,
        }];

        this.spawnSlab();
    }

    start() {
        this.reset();
        this.phase = 'playing';
        this.hooks.onScore(0, this.milestones[0][1], 0);
    }

    spawnSlab() {
        const top = this.tower[this.tower.length - 1];
        const dir = this.tower.length % 2 === 0 ? 1 : -1;
        const travelLeft = SIDE_MARGIN;
        const travelRight = W - SIDE_MARGIN - top.w;
        const x = dir > 0 ? travelLeft : travelRight;

        this.slab = {
            x,
            w: top.w,
            dir,
            type: itemFor(this.tower.length),
            seed: this.tower.length * 3.4 + 0.7,
            speed: Math.min(235, 78 + this.floors * 4.4),
        };
    }

    /* -------------------------------------------------------------- *
     * Geometry
     * -------------------------------------------------------------- */

    /** Screen y of the top edge of the block at index `i`. */
    blockScreenY(i) {
        return BASE_Y - ((i + 1) * FLOOR_H - this.cam);
    }

    slabScreenY() {
        return BASE_Y - ((this.tower.length + 1) * FLOOR_H - this.cam);
    }

    /* -------------------------------------------------------------- *
     * Playing
     * -------------------------------------------------------------- */

    drop() {
        if (this.phase !== 'playing') {
            return;
        }

        const prev = this.tower[this.tower.length - 1];
        const slab = this.slab;
        const delta = slab.x - prev.x;
        const overlap = slab.w - Math.abs(delta);

        if (overlap <= 0) {
            this.collapse();

            return;
        }

        const slabY = this.slabScreenY();
        const perfect = Math.abs(delta) <= PERFECT;
        let block;

        if (perfect) {
            this.combo += 1;

            // Every fourth clean drop widens the tower again. Without it a long
            // run is a slow death by rounding: you can play flawlessly and
            // still lose to the width you gave up in the first ten floors.
            const w = this.combo % MERCY_EVERY === 0
                ? Math.min(START_W, prev.w + MERCY_WIDTH)
                : prev.w;
            const x = prev.x - (w - prev.w) / 2;

            block = { x, w, artX: x, artW: w, type: slab.type, seed: slab.seed, squash: 1 };

            this.effects.push({ kind: 'ring', x: x + w / 2, y: slabY + FLOOR_H / 2, t: 0, life: 0.5 });
            this.effects.push({
                kind: 'text',
                x: x + w / 2,
                y: slabY - 4,
                t: 0,
                life: 0.9,
                text: this.combo > 1 ? 'PERFECT ×' + this.combo : 'PERFECT',
            });

            Sfx.blip(520 + Math.min(8, this.combo) * 90, 0.16, 0.07, 1180);
        } else {
            this.combo = 0;

            const x = delta > 0 ? slab.x : prev.x;
            const shearX = delta > 0 ? prev.x + prev.w : slab.x;

            block = {
                x,
                w: overlap,
                artX: slab.x,
                artW: slab.w,
                type: slab.type,
                seed: slab.seed,
                squash: 1,
            };

            this.debris.push({
                x: shearX,
                y: slabY,
                w: Math.abs(delta),
                artOff: slab.x - shearX,
                artW: slab.w,
                type: slab.type,
                seed: slab.seed,
                vx: delta > 0 ? 46 : -46,
                vy: -26,
                rot: 0,
                vr: delta > 0 ? 3.6 : -3.6,
            });

            for (let i = 0; i < 6; i++) {
                this.effects.push({
                    kind: 'dust',
                    x: shearX + (delta > 0 ? 0 : Math.abs(delta)),
                    y: slabY + FLOOR_H * 0.6,
                    vx: (delta > 0 ? 1 : -1) * (18 + rnd(i * 2.3) * 46),
                    vy: -34 - rnd(i * 5.1) * 46,
                    t: 0,
                    life: 0.55,
                    col: pick(ODDS, i * 1.9),
                });
            }

            Sfx.thud(0.13, 0.16, 900);
            this.shake = 3.5;
        }

        this.tower.push(block);
        this.floors += 1;
        this.spawnSlab();
        this.checkMilestone();

        this.hooks.onScore(this.floors, this.milestones[this.milestone][1], this.combo);
    }

    checkMilestone() {
        let reached = this.milestone;

        for (let i = 0; i < this.milestones.length; i++) {
            if (this.floors >= this.milestones[i][0]) {
                reached = i;
            }
        }

        if (reached > this.milestone) {
            this.milestone = reached;
            this.banner = { text: this.milestones[reached][1], t: 0 };
            Sfx.blip(300, 0.5, 0.08, 900);
        }
    }

    collapse() {
        this.phase = 'over';

        const slab = this.slab;

        this.debris.push({
            x: slab.x,
            y: this.slabScreenY(),
            w: slab.w,
            artOff: 0,
            artW: slab.w,
            type: slab.type,
            seed: slab.seed,
            vx: slab.dir * 74,
            vy: -44,
            rot: 0,
            vr: slab.dir * 3.4,
        });

        // The tower does not just stop — the top of it lets go. The score is
        // already banked by this point, so losing the blocks costs nothing and
        // the run ends on something worth watching.
        const shed = Math.min(7, this.tower.length - 1);

        for (let k = 0; k < shed; k++) {
            const i = this.tower.length - 1;
            const b = this.tower[i];
            const y = this.blockScreenY(i);
            const away = rnd(i * 5.7) < 0.5 ? -1 : 1;

            this.tower.pop();

            this.debris.push({
                x: b.x,
                y,
                w: b.w,
                artOff: b.artX - b.x,
                artW: b.artW,
                type: b.type,
                seed: b.seed,
                vx: away * (28 + rnd(i * 1.3) * 92),
                vy: -56 - rnd(i * 2.3) * 74,
                rot: 0,
                vr: away * (1.4 + rnd(i * 3.9) * 3),
            });
        }

        this.shake = 15;
        Sfx.thud(0.5, 0.24, 420);
        Sfx.blip(220, 0.45, 0.09, 70);
        this.hooks.onOver(this.floors);
    }

    /* -------------------------------------------------------------- *
     * Frame
     * -------------------------------------------------------------- */

    frame(now) {
        this.raf = requestAnimationFrame((t) => this.frame(t));

        // Clamped so a backgrounded tab does not resume with a single enormous
        // step that teleports the slab through a whole sweep.
        const dt = Math.min(0.05, (now - this.last) / 1000);
        this.last = now;

        this.update(dt);
        this.draw();
    }

    update(dt) {
        const slab = this.slab;

        if (this.phase !== 'over' && slab) {
            const left = SIDE_MARGIN;
            const right = W - SIDE_MARGIN - slab.w;

            slab.x += slab.dir * slab.speed * dt;

            if (slab.x <= left) {
                slab.x = left;
                slab.dir = 1;
            } else if (slab.x >= right) {
                slab.x = right;
                slab.dir = -1;
            }
        }

        if (this.phase !== 'over') {
            const target = Math.max(0, (this.tower.length + 1) * FLOOR_H - (BASE_Y - TOP_GAP));
            this.cam += (target - this.cam) * Math.min(1, dt * 8);
        }

        this.shake = Math.max(0, this.shake - dt * 42);

        for (const b of this.tower) {
            if (b.squash > 0) {
                b.squash = Math.max(0, b.squash - dt * 5.5);
            }
        }

        this.debris = this.debris.filter((d) => {
            d.vy += 1150 * dt;
            d.x += d.vx * dt;
            d.y += d.vy * dt;
            d.rot += d.vr * dt;

            return d.y < H + 140;
        });

        this.effects = this.effects.filter((e) => {
            e.t += dt;

            if (e.kind === 'dust') {
                e.vy += 620 * dt;
                e.x += e.vx * dt;
                e.y += e.vy * dt;
            }

            return e.t < e.life;
        });

        if (this.banner) {
            this.banner.t += dt;

            if (this.banner.t > 1.9) {
                this.banner = null;
            }
        }
    }

    /* -------------------------------------------------------------- *
     * Drawing
     * -------------------------------------------------------------- */

    draw() {
        const ctx = this.ctx;

        if (!ctx || this.canvas.width === 0) {
            return;
        }

        ctx.setTransform(this.scale, 0, 0, this.scale, 0, 0);
        ctx.clearRect(0, 0, W, H);

        const jolt = this.shake > 0 ? (Math.random() - 0.5) * this.shake * 0.4 : 0;

        ctx.save();
        ctx.translate(jolt, this.shake > 0 ? (Math.random() - 0.5) * this.shake * 0.3 : 0);

        this.drawSky(ctx);
        this.drawStars(ctx);
        this.drawScenery(ctx);
        this.drawTower(ctx);
        this.drawDebris(ctx);

        if (this.phase !== 'over' && this.slab) {
            this.drawSlab(ctx);
        }

        this.drawEffects(ctx);
        ctx.restore();

        this.drawBanner(ctx);
        this.drawVignette(ctx);
    }

    drawSky(ctx) {
        const [top, bottom] = skyStops(this.cam + 200);
        const g = ctx.createLinearGradient(0, 0, 0, H);

        g.addColorStop(0, top);
        g.addColorStop(1, bottom);
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);

        // Wallpaper, while there is still a wall. Fades out as the ceiling
        // comes up so the transition to outdoors is gradual rather than a cut.
        const indoors = 1 - clamp((this.cam - 320) / 220, 0, 1);

        if (indoors > 0.01) {
            ctx.fillStyle = 'rgba(255,255,255,' + (0.014 * indoors).toFixed(4) + ')';

            for (let i = 0; i < 9; i++) {
                ctx.fillRect(i * 36 + 6, 0, 15, H);
            }
        }
    }

    drawStars(ctx) {
        const alpha = clamp((this.cam - 400) / 380, 0, 1);

        if (alpha <= 0.01) {
            return;
        }

        for (let i = 0; i < 90; i++) {
            const sx = rnd(i * 1.7) * W;
            const sy = ((rnd(i * 3.1) * (H + 220) + this.cam * 0.24) % (H + 220)) - 110;
            const r = 0.5 + rnd(i * 6.7) * 1.1;

            ctx.fillStyle = 'rgba(255,255,255,' + (alpha * (0.25 + rnd(i * 5.3) * 0.65)).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(sx, sy, r, 0, 6.29);
            ctx.fill();
        }
    }

    drawScenery(ctx) {
        for (let i = 0; i < this.milestones.length; i++) {
            const draw = SCENERY[i];

            if (!draw) {
                continue;
            }

            const y = BASE_Y - (this.milestones[i][0] * FLOOR_H - this.cam);

            if (y < -260 || y > H + 260) {
                continue;
            }

            ctx.save();
            draw(ctx, y);
            ctx.restore();
        }
    }

    drawTower(ctx) {
        for (let i = this.tower.length - 1; i >= 0; i--) {
            const y = this.blockScreenY(i);

            if (y > H + FLOOR_H) {
                break;
            }

            if (y < -FLOOR_H * 2) {
                continue;
            }

            this.drawBlock(ctx, this.tower[i], y);
        }
    }

    /**
     * One floor. The art is drawn in the slab's original frame and clipped to
     * whatever survived the shear, which is what keeps a narrow block looking
     * like the middle of a pile of laundry rather than a smaller pile.
     */
    drawBlock(ctx, b, y, squash) {
        const s = squash === undefined ? (b.squash || 0) : squash;

        ctx.save();

        if (s > 0) {
            const cx = b.x + b.w / 2;
            const cy = y + FLOOR_H;

            ctx.translate(cx, cy);
            ctx.scale(1 + s * 0.16, 1 - s * 0.24);
            ctx.translate(-cx, -cy);
        }

        ctx.save();
        rr(ctx, b.x, y, b.w, FLOOR_H, 4);
        ctx.clip();

        (ITEMS[b.type] || ITEMS.laundry)(ctx, b.artX, y, b.artW, FLOOR_H, b.seed);

        const light = ctx.createLinearGradient(0, y, 0, y + FLOOR_H);
        light.addColorStop(0, 'rgba(255,255,255,0.13)');
        light.addColorStop(0.42, 'rgba(255,255,255,0)');
        light.addColorStop(1, 'rgba(0,0,0,0.3)');
        ctx.fillStyle = light;
        ctx.fillRect(b.x, y, b.w, FLOOR_H);
        ctx.restore();

        ctx.strokeStyle = 'rgba(7,3,13,0.6)';
        ctx.lineWidth = 1.4;
        rr(ctx, b.x + 0.7, y + 0.7, b.w - 1.4, FLOOR_H - 1.4, 4);
        ctx.stroke();

        ctx.restore();
    }

    drawSlab(ctx) {
        const slab = this.slab;
        const y = this.slabScreenY();
        const top = this.tower[this.tower.length - 1];

        // Two ticks — one under the slab, one on the block it is aiming at.
        // Without them a perfect drop is luck; with them it is a decision, and
        // that is the whole difference between this and a coin toss.
        ctx.strokeStyle = 'rgba(255,225,77,0.32)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(top.x + top.w / 2, this.blockScreenY(this.tower.length - 1) + 3);
        ctx.lineTo(top.x + top.w / 2, this.blockScreenY(this.tower.length - 1) + FLOOR_H - 3);
        ctx.stroke();

        const glow = ctx.createRadialGradient(
            slab.x + slab.w / 2, y + FLOOR_H / 2, 4,
            slab.x + slab.w / 2, y + FLOOR_H / 2, slab.w * 0.72
        );
        glow.addColorStop(0, 'rgba(255,225,77,0.16)');
        glow.addColorStop(1, 'rgba(255,225,77,0)');
        ctx.fillStyle = glow;
        ctx.fillRect(slab.x - slab.w, y - FLOOR_H, slab.w * 3, FLOOR_H * 3);

        this.drawBlock(ctx, {
            x: slab.x,
            w: slab.w,
            artX: slab.x,
            artW: slab.w,
            type: slab.type,
            seed: slab.seed,
        }, y, 0);

        ctx.strokeStyle = 'rgba(255,225,77,0.5)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(slab.x + slab.w / 2, y + 3);
        ctx.lineTo(slab.x + slab.w / 2, y + FLOOR_H - 3);
        ctx.stroke();
    }

    drawDebris(ctx) {
        for (const d of this.debris) {
            ctx.save();
            ctx.translate(d.x + d.w / 2, d.y + FLOOR_H / 2);
            ctx.rotate(d.rot);
            ctx.translate(-(d.x + d.w / 2), -(d.y + FLOOR_H / 2));

            ctx.save();
            rr(ctx, d.x, d.y, d.w, FLOOR_H, 3);
            ctx.clip();
            (ITEMS[d.type] || ITEMS.laundry)(ctx, d.x + d.artOff, d.y, d.artW, FLOOR_H, d.seed);
            ctx.restore();

            ctx.strokeStyle = 'rgba(7,3,13,0.55)';
            ctx.lineWidth = 1.2;
            rr(ctx, d.x + 0.6, d.y + 0.6, Math.max(0.5, d.w - 1.2), FLOOR_H - 1.2, 3);
            ctx.stroke();
            ctx.restore();
        }
    }

    drawEffects(ctx) {
        for (const e of this.effects) {
            const k = e.t / e.life;

            if (e.kind === 'ring') {
                ctx.strokeStyle = 'rgba(255,225,77,' + ((1 - k) * 0.75).toFixed(3) + ')';
                ctx.lineWidth = 2.5 * (1 - k) + 0.5;
                ctx.beginPath();
                ctx.arc(e.x, e.y, 8 + k * 46, 0, 6.29);
                ctx.stroke();
            } else if (e.kind === 'text') {
                ctx.font = '800 15px Outfit, ui-sans-serif, system-ui, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = 'rgba(255,225,77,' + ((1 - k) * 0.95).toFixed(3) + ')';
                ctx.fillText(e.text, e.x, e.y - k * 26);
            } else {
                ctx.globalAlpha = 1 - k;
                ctx.fillStyle = e.col;
                ctx.fillRect(e.x - 1.5, e.y - 1.5, 3, 3);
                ctx.globalAlpha = 1;
            }
        }
    }

    drawBanner(ctx) {
        if (!this.banner) {
            return;
        }

        const t = this.banner.t;
        const alpha = t < 0.25 ? t / 0.25 : clamp((1.9 - t) / 0.6, 0, 1);

        ctx.textAlign = 'center';
        ctx.font = '800 27px Outfit, ui-sans-serif, system-ui, sans-serif';
        ctx.fillStyle = 'rgba(7,3,13,' + (alpha * 0.7).toFixed(3) + ')';
        ctx.fillText(this.banner.text.toUpperCase(), W / 2 + 2, 76 + 2);
        ctx.fillStyle = 'rgba(255,225,77,' + alpha.toFixed(3) + ')';
        ctx.fillText(this.banner.text.toUpperCase(), W / 2, 76);
    }

    drawVignette(ctx) {
        const g = ctx.createRadialGradient(W / 2, H * 0.44, H * 0.32, W / 2, H * 0.44, H * 0.86);

        g.addColorStop(0, 'rgba(0,0,0,0)');
        g.addColorStop(1, 'rgba(0,0,0,0.42)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);
    }
}

/* ------------------------------------------------------------------ *
 * Wiring
 * ------------------------------------------------------------------ */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqStacker', (vocab, milestones) => ({
        phase: 'idle',
        score: 0,
        combo: 0,
        altitude: milestones[0][1],
        best: 0,
        adjective: 0,
        noun: 0,
        posted: false,
        posting: false,
        muted: localStorage.getItem('fq-muted') === '1',
        game: null,

        init() {
            this.best = parseInt(localStorage.getItem('fq-arcade-best') || '0', 10) || 0;
            this.rollCodename();

            this.game = new Stacker(this.$refs.canvas, milestones, {
                onScore: (floors, altitude, combo) => {
                    this.score = floors;
                    this.altitude = altitude;
                    this.combo = combo;
                },
                onOver: (floors) => {
                    this.phase = 'over';

                    if (floors > this.best) {
                        this.best = floors;
                        localStorage.setItem('fq-arcade-best', String(floors));
                    }
                },
            });

            this.game.mount();
        },

        destroy() {
            if (this.game) {
                this.game.unmount();
            }
        },

        get codename() {
            return vocab.adjectives[this.adjective] + ' ' + vocab.nouns[this.noun];
        },

        rollCodename() {
            this.adjective = Math.floor(Math.random() * vocab.adjectives.length);
            this.noun = Math.floor(Math.random() * vocab.nouns.length);
        },

        play() {
            this.posted = false;
            this.rollCodename();
            this.score = 0;
            this.combo = 0;
            this.altitude = milestones[0][1];
            this.phase = 'playing';
            this.game.start();
        },

        /** The one input the game has. Everything else on screen is a button. */
        tap() {
            if (this.phase === 'playing') {
                this.game.drop();
            } else if (this.phase === 'idle') {
                this.play();
            }
        },

        toggleMute() {
            this.muted = !this.muted;
            localStorage.setItem('fq-muted', this.muted ? '1' : '0');
        },

        post() {
            if (this.posted || this.posting || this.score < 1) {
                return;
            }

            this.posting = true;

            this.$wire.post(this.score, this.adjective, this.noun).then(() => {
                this.posting = false;
                this.posted = true;
            });
        },
    }));
});
