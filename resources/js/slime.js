/**
 * Slime Time — the arcade's first TOY.
 *
 * A self-registering `<slime-time>` custom element, plain browser JS, no build
 * step, mounted exactly like `fart-dash.js`.
 *
 * NOT a cabinet. No score, no lives, no run, nothing to post:
 * `ArcadeGame::ranked()` must return false. Nothing here is persisted.
 *
 * THE MECHANIC IS THROWING GOO AT THINGS AND WATCHING IT SPLAT.
 *
 * Everything in this file serves that one sentence, so it is worth writing
 * down what a good splat actually requires — none of it is decoration:
 *
 * 1. A THROW HAS TO LEAVE. While you drag, the far side of the blob is glued
 *    to whatever it is sitting on (that is what pulls a strand instead of
 *    sliding the body). So a release above a speed threshold has to BREAK
 *    every bond at once and give the impulse to ALL points, not just the lump
 *    in your fingers — otherwise the held goo flies and the rest stays put.
 *
 * 2. THERE HAS TO BE STUFF TO HIT. The room has a floor, three walls, two
 *    shelves and a bucket. They are all the same kind of object (a box with
 *    faces), so one collision routine handles splatting on top of a shelf,
 *    against a wall, and — the best one — onto the underside of a shelf.
 *
 * 3. SLIME IS RATE-DEPENDENT. Thrown hard it behaves almost brittle: it
 *    pancakes on contact and HOLDS that shape for a moment. Left alone it
 *    flows back. That is modelled by spiking plastic flow on impact (`hot`)
 *    and healing it away over about a second. Without this a throw just
 *    bounces and re-rounds, which reads as a ball.
 *
 * 4. IT HAS TO CLING, THEN FAIL. Contact points bond to the surface they hit.
 *    The bond breaks under load — from the weight of the rest of the blob, or
 *    when the constraint solve drags the point too far — so goo thrown at a
 *    ceiling sticks, sags, drips, and eventually peels off in one lump. The
 *    cling-then-peel is the payoff; the impact is only the setup.
 *
 * 5. THE HIT NEEDS EVIDENCE. Speed-scaled sound, a spray of droplets that
 *    leave their own little marks, a fading stain on the surface, and a small
 *    kick of the whole board on the hard ones.
 *
 * Events: `st-splat` (with force), `st-blob` when the count changes.
 */

const W = 320;
const H = 460;

const FLOOR = 426;
const WALL = 8;

/** Ring resolution per blob. 26 is round enough and cheap enough for a tablet. */
const RING = 26;

const MAX_BLOBS = 4;

const SUBSTEPS = 5;
const GRAVITY = 1500;

/**
 * Viscoelasticity.
 *
 * Real slime has memory: hold it stretched and it stays stretched for a moment
 * before creeping back. Edge rest lengths drift toward whatever length they are
 * held at once strain passes YIELD, then forget it again.
 *
 * The plastic floor is 0.85 and not lower: the perimeter IS the volume model
 * here, so edges that may shrink freely collapse the ring into a permanently
 * undersized blob that no pressure term can re-inflate.
 */
const YIELD = 0.28;
const PLASTIC_RATE = 2.6;
const HEAL_RATE = 0.55;
const PLASTIC_MIN = 0.85;

/** Hard ceiling on a strand, so a fast fling can never tear the ring open. */
const MAX_STRAIN = 3.2;

/** Surface tension: how hard the outline pulls itself smooth each solve. */
const TENSION = 0.16;

/**
 * Pressure, applied along the OUTLINE NORMAL and clamped per substep. A strong
 * area constraint resolved from the centroid throws the far side of the blob
 * across the screen the moment you drag one edge; this only thickens a strand
 * where it is thin. Total volume is handled separately by recoverVolume().
 */
const PRESSURE = 1.0;
const PRESSURE_CLAMP = 0.22;
const PRESSURE_STEP_MAX = 1.2;
const AREA_FLOW = 1.1;
const AREA_MIN = 0.93;
const AREA_MAX = 1.04;
const VOLUME_STEP = 0.006;

/** Viscosity: how much neighbouring goo drags on itself. This is the sluggish. */
const VISCOSITY = 0.3;

/** Hydrostatic slump: contact points spread under the weight stacked above. */
const SLUMP = 0.5;
const SLUMP_MAX = 2.3;

/** Impact thresholds, in px per frame of closing speed. */
const SPLAT_MIN = 3.2;
const STICK_MIN = 6.5;
const THROW_MIN = 1.4;

/** How far a constraint may drag a bonded point before the bond tears. */
const BOND_BREAK = 7;

const SKINS = [
    { name: 'Slime', fill: '#a8f08a', deep: '#4fae63', gloss: 'rgba(255,255,255,0.5)' },
    { name: 'Bubblegum', fill: '#ff8ac7', deep: '#c2418c', gloss: 'rgba(255,255,255,0.55)' },
    { name: 'Grape', fill: '#c9a0ff', deep: '#7a48c9', gloss: 'rgba(255,255,255,0.5)' },
    { name: 'Custard', fill: '#ffe14d', deep: '#c79a15', gloss: 'rgba(255,255,255,0.55)' },
    { name: 'Orange Goo', fill: '#ff9f45', deep: '#c25d12', gloss: 'rgba(255,255,255,0.5)' },
];

/**
 * The room.
 *
 * Every surface is a box, including the floor and walls, so one routine covers
 * all of them and adding a thing to splat against is one entry in this list.
 * The walls and floor extend well off-board so a fast point cannot tunnel past
 * their far face and pop out the other side.
 */
const BOXES = [
    { x: -60, y: FLOOR, w: W + 120, h: 200, kind: 'floor', face: 'top' },
    { x: -60, y: -200, w: 60 + WALL, h: H + 400, kind: 'wall', face: 'right' },
    { x: W - WALL, y: -200, w: 60 + WALL, h: H + 400, kind: 'wall', face: 'left' },
    { x: -60, y: -200, w: W + 120, h: 200 + WALL, kind: 'ceiling', face: 'bottom' },
    { x: WALL, y: 148, w: 104, h: 14, kind: 'shelf' },
    { x: W - WALL - 104, y: 250, w: 104, h: 14, kind: 'shelf' },
    { x: 128, y: 386, w: 62, h: FLOOR - 386, kind: 'bucket' },
];

function clamp(v, lo, hi) {
    return v < lo ? lo : (v > hi ? hi : v);
}

function rr(ctx, x, y, w, h, r) {
    const k = Math.min(r, w / 2, h / 2);

    ctx.beginPath();
    ctx.moveTo(x + k, y);
    ctx.arcTo(x + w, y, x + w, y + h, k);
    ctx.arcTo(x + w, y + h, x, y + h, k);
    ctx.arcTo(x, y + h, x, y, k);
    ctx.arcTo(x, y, x + w, y, k);
    ctx.closePath();
}

const Sfx = {
    ac: null,

    ctx() {
        // ONE LINE EDITED FROM THE BUNDLE. The arcade has a single sound
        // toggle in the page header, and every game reads this key at the
        // moment it plays rather than holding its own mute state — see
        // <x-sound-toggle>. Re-apply this when replacing the file from a
        // newer bundle; ArcadeToyTest fails if it goes missing.
        if (localStorage.getItem('fq-muted') === '1') {
            return null;
        }

        if (!this.ac) {
            const C = window.AudioContext || window.webkitAudioContext;

            if (!C) {
                return null;
            }

            this.ac = new C();
        }

        if (this.ac.state === 'suspended') {
            this.ac.resume();
        }

        return this.ac;
    },

    /**
     * The splat: filtered noise, decaying fast. Wet, not percussive — a click
     * makes the slime feel like a rock. Force moves both the brightness and
     * the length, which is what separates a lob from a hurl by ear alone.
     */
    splat(force) {
        const ac = this.ctx();

        if (!ac) {
            return;
        }

        const dur = 0.1 + force * 0.16;
        const len = Math.floor(ac.sampleRate * dur);
        const buf = ac.createBuffer(1, len, ac.sampleRate);
        const d = buf.getChannelData(0);

        for (let i = 0; i < len; i++) {
            d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 2.2);
        }

        const src = ac.createBufferSource();
        const filt = ac.createBiquadFilter();
        const g = ac.createGain();

        src.buffer = buf;
        filt.type = 'lowpass';
        filt.frequency.setValueAtTime(420 + force * 1500, ac.currentTime);
        filt.frequency.exponentialRampToValueAtTime(170, ac.currentTime + dur);

        g.gain.setValueAtTime(clamp(0.12 + force * 0.4, 0, 0.55), ac.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + dur);

        src.connect(filt);
        filt.connect(g);
        g.connect(ac.destination);
        src.start();
    },

    /** A drip letting go: tiny, high, short. */
    drip() {
        const ac = this.ctx();

        if (!ac) {
            return;
        }

        const o = ac.createOscillator();
        const g = ac.createGain();

        o.type = 'sine';
        o.frequency.setValueAtTime(900, ac.currentTime);
        o.frequency.exponentialRampToValueAtTime(320, ac.currentTime + 0.09);

        g.gain.setValueAtTime(0.06, ac.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.1);

        o.connect(g);
        g.connect(ac.destination);
        o.start();
        o.stop(ac.currentTime + 0.12);
    },

    /** The stretch: a rising squeak that tracks how far it is being pulled. */
    stretch(amount) {
        const ac = this.ctx();

        if (!ac) {
            return;
        }

        const o = ac.createOscillator();
        const g = ac.createGain();

        o.type = 'sine';
        o.frequency.setValueAtTime(160 + amount * 340, ac.currentTime);
        o.frequency.linearRampToValueAtTime(200 + amount * 520, ac.currentTime + 0.08);

        g.gain.setValueAtTime(0.05, ac.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.1);

        o.connect(g);
        g.connect(ac.destination);
        o.start();
        o.stop(ac.currentTime + 0.12);
    },

    /** The throw: a short upward whoosh, so a fling feels committed. */
    whoosh() {
        const ac = this.ctx();

        if (!ac) {
            return;
        }

        const len = Math.floor(ac.sampleRate * 0.18);
        const buf = ac.createBuffer(1, len, ac.sampleRate);
        const d = buf.getChannelData(0);

        for (let i = 0; i < len; i++) {
            d[i] = (Math.random() * 2 - 1) * Math.sin((i / len) * Math.PI);
        }

        const src = ac.createBufferSource();
        const filt = ac.createBiquadFilter();
        const g = ac.createGain();

        src.buffer = buf;
        filt.type = 'bandpass';
        filt.Q.value = 1.4;
        filt.frequency.setValueAtTime(500, ac.currentTime);
        filt.frequency.exponentialRampToValueAtTime(1700, ac.currentTime + 0.16);

        g.gain.setValueAtTime(0.12, ac.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.18);

        src.connect(filt);
        filt.connect(g);
        g.connect(ac.destination);
        src.start();
    },

    boing() {
        const ac = this.ctx();

        if (!ac) {
            return;
        }

        const o = ac.createOscillator();
        const g = ac.createGain();

        o.type = 'triangle';
        o.frequency.setValueAtTime(180, ac.currentTime);
        o.frequency.exponentialRampToValueAtTime(620, ac.currentTime + 0.13);
        o.frequency.exponentialRampToValueAtTime(240, ac.currentTime + 0.28);

        g.gain.setValueAtTime(0.22, ac.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.3);

        o.connect(g);
        g.connect(ac.destination);
        o.start();
        o.stop(ac.currentTime + 0.32);
    },
};

/* ------------------------------------------------------------------ *
 * One blob
 * ------------------------------------------------------------------ */

class Blob {
    constructor(x, y, r, skin) {
        this.skin = skin;
        this.pts = [];
        this.r0 = r;

        for (let i = 0; i < RING; i++) {
            const a = (i / RING) * Math.PI * 2;

            this.pts.push({
                x: x + Math.cos(a) * r,
                y: y + Math.sin(a) * r,
                px: x + Math.cos(a) * r,
                py: y + Math.sin(a) * r,
                bond: null,
            });
        }

        this.rest = (2 * Math.PI * r) / RING;
        this.area0 = Math.PI * r * r;
        this.rests = new Float64Array(RING).fill(this.rest);
        this.areaTarget = this.area0;
        this.rEff = r;

        this.blink = 2 + Math.random() * 3;
        this.face = { x: x, y: y, vx: 0, vy: 0 };
        this.squash = 0;
        this.grabbed = -1;
        this.hit = 0;

        // Rate dependence. Spikes on a hard landing and decays: while it is
        // hot the goo deforms plastically, so a splat keeps its pancake.
        this.hot = 0;
        this.drip = 0;
    }

    centroid() {
        let x = 0;
        let y = 0;

        this.pts.forEach((p) => {
            x += p.x;
            y += p.y;
        });

        return { x: x / RING, y: y / RING };
    }

    area() {
        let a = 0;

        for (let i = 0; i < RING; i++) {
            const p = this.pts[i];
            const q = this.pts[(i + 1) % RING];

            a += p.x * q.y - q.x * p.y;
        }

        return Math.abs(a) / 2;
    }

    /**
     * How far the held lump has been pulled away from the body. Zero unless
     * grabbed: radius-based measures can't tell a spread puddle from a strand,
     * which left the mouth permanently gaping at rest.
     */
    stretchAmount() {
        if (this.grabbed < 0) {
            return 0;
        }

        const c = this.centroid();
        const p = this.pts[this.grabbed];

        return clamp(Math.hypot(p.x - c.x, p.y - c.y) / Math.max(this.rEff, 1) - 1, 0, 2);
    }

    bonded() {
        let n = 0;

        this.pts.forEach((p) => {
            if (p.bond) {
                n += 1;
            }
        });

        return n;
    }

    unstick() {
        this.pts.forEach((p) => {
            p.bond = null;
        });
    }
}

/* ------------------------------------------------------------------ *
 * The toy
 * ------------------------------------------------------------------ */

class SlimeTime {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.scale = 1;
        this.phase = 'title';
        this.raf = null;
        this.last = 0;
        this.t = 0;
        this.skin = 0;
        this.drops = [];
        this.marks = [];
        this.pointer = null;
        this.stretchSfx = 0;
        this.shake = 0;

        this.blobs = [];
        this.spawn(W / 2, 320, 54);
    }

    spawn(x, y, r) {
        if (this.blobs.length >= MAX_BLOBS) {
            return;
        }

        this.blobs.push(new Blob(x, y, r, SKINS[this.skin]));
        this.emit('st-blob', { blobs: this.blobs.length });
    }

    reset() {
        this.blobs = [];
        this.drops = [];
        this.marks = [];
        this.spawn(W / 2, 320, 54);
    }

    cycleSkin() {
        this.skin = (this.skin + 1) % SKINS.length;
        this.blobs.forEach((b) => {
            b.skin = SKINS[this.skin];
        });
    }

    boing() {
        this.blobs.forEach((b) => {
            b.unstick();
            b.pts.forEach((p) => {
                p.py = p.y + 12 + Math.random() * 4;
            });
        });

        Sfx.boing();
    }

    mount() {
        this.onResize = () => this.resize();
        window.addEventListener('resize', this.onResize);
        this.resize();

        // A ResizeObserver on the canvas itself, not just window 'resize': the
        // arcade's rail collapsing to the phone strip changes the box without
        // the viewport changing, and draw() scales by width.
        if (window.ResizeObserver) {
            this.ro = new ResizeObserver(() => this.resize());
            this.ro.observe(this.canvas);
        }

        const loop = (t) => {
            const dt = Math.min(0.04, (t - this.last) / 1000 || 0);

            this.last = t;
            this.step(dt);
            this.draw();
            this.raf = requestAnimationFrame(loop);
        };

        this.raf = requestAnimationFrame(loop);
    }

    unmount() {
        cancelAnimationFrame(this.raf);
        window.removeEventListener('resize', this.onResize);

        if (this.ro) {
            this.ro.disconnect();
            this.ro = null;
        }
    }

    resize() {
        const rect = this.canvas.getBoundingClientRect();

        if (rect.width === 0) {
            return;
        }

        const dpr = Math.min(2.5, window.devicePixelRatio || 1);

        this.canvas.width = Math.round(rect.width * dpr);
        this.canvas.height = Math.round(rect.height * dpr);
        this.scale = (rect.width / W) * dpr;
    }

    emit(name, detail) {
        this.canvas.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
    }

    tap() {
        if (this.phase !== 'playing') {
            this.phase = 'playing';
        }
    }

    /* ---------------- input ---------------- */

    grab(x, y) {
        this.tap();

        let best = null;
        let bestD = 46;

        this.blobs.forEach((b) => {
            b.pts.forEach((p, i) => {
                const d = Math.hypot(p.x - x, p.y - y);

                if (d < bestD) {
                    bestD = d;
                    best = { b, i };
                }
            });
        });

        if (!best) {
            // Empty space: drop another one there. Tapping the background is
            // the most obvious thing a kid will try, so it should do something.
            this.spawn(clamp(x, 40, W - 40), clamp(y, 40, FLOOR - 60), 34 + Math.random() * 12);

            return;
        }

        // Picking goo up off a wall peels it: the bonds under your fingers let
        // go, the rest keeps clinging until the strand between them tears.
        for (let o = -3; o <= 3; o++) {
            best.b.pts[(best.i + o + RING) % RING].bond = null;
        }

        best.b.grabbed = best.i;
        this.pointer = { x, y, px: x, py: y, vx: 0, vy: 0 };
    }

    move(x, y) {
        if (!this.pointer) {
            return;
        }

        // Smoothed pointer velocity, kept so a release can hand its momentum
        // to the slime. One frame's delta alone makes flings wildly
        // inconsistent between a 60Hz and a 120Hz screen.
        this.pointer.vx = this.pointer.vx * 0.6 + (x - this.pointer.x) * 0.4;
        this.pointer.vy = this.pointer.vy * 0.6 + (y - this.pointer.y) * 0.4;
        this.pointer.x = x;
        this.pointer.y = y;
    }

    /**
     * Let go.
     *
     * Below THROW_MIN this is a drop: the goo sags off your finger and the
     * bonds holding the rest of it stay put. Above it, it is a THROW — every
     * bond in that blob breaks at once and the impulse goes to every point,
     * because a throw is a translation. The held lump gets a little extra so
     * the blob leaves your hand trailing rather than as a rigid disc.
     */
    release() {
        const p = this.pointer;

        this.blobs.forEach((b) => {
            if (b.grabbed < 0 || !p) {
                b.grabbed = -1;

                return;
            }

            const speed = Math.hypot(p.vx, p.vy);
            const thrown = speed > THROW_MIN;

            if (thrown) {
                b.unstick();

                b.pts.forEach((q) => {
                    q.px -= p.vx * 1.15;
                    q.py -= p.vy * 1.15;
                });

                Sfx.whoosh();
            }

            for (let o = -4; o <= 4; o++) {
                const q = b.pts[(b.grabbed + o + RING) % RING];
                const w = 1 - Math.abs(o) / 5;

                q.px -= p.vx * w * (thrown ? 0.5 : 1.6);
                q.py -= p.vy * w * (thrown ? 0.5 : 1.6);
            }

            b.grabbed = -1;
        });

        this.pointer = null;
    }

    /* ---------------- physics ---------------- */

    step(dt) {
        this.t += dt;

        if (dt <= 0) {
            return;
        }

        const h = dt / SUBSTEPS;

        for (let s = 0; s < SUBSTEPS; s++) {
            this.integrate(h);
            this.viscosity();
            this.solve(h);
        }

        this.settle();
        this.shake = Math.max(0, this.shake - dt * 3.4);

        this.blobs.forEach((b) => {
            // Plastic flow, then healing. Once per frame: a slow material
            // property, not a constraint. `hot` is the rate dependence — a
            // hard splat deforms and STAYS deformed for about a second.
            const flow = PLASTIC_RATE * (1 + b.hot * 3.5);
            const heal = HEAL_RATE * (1 - b.hot * 0.7);

            for (let i = 0; i < RING; i++) {
                const p = b.pts[i];
                const q = b.pts[(i + 1) % RING];
                const d = Math.hypot(q.x - p.x, q.y - p.y);
                const strain = (d - b.rests[i]) / b.rest;

                if (Math.abs(strain) > YIELD) {
                    const over = strain - Math.sign(strain) * YIELD;

                    b.rests[i] += over * b.rest * flow * dt;
                }

                b.rests[i] += (b.rest - b.rests[i]) * heal * dt;
                b.rests[i] = clamp(b.rests[i], b.rest * PLASTIC_MIN, b.rest * MAX_STRAIN);
            }

            const now = b.area();

            b.areaTarget += (now - b.areaTarget) * AREA_FLOW * dt;
            b.areaTarget = clamp(b.areaTarget, b.area0 * AREA_MIN, b.area0 * AREA_MAX);
            b.rEff = Math.sqrt(Math.max(now, 1) / Math.PI);

            b.hot = Math.max(0, b.hot - dt * 0.85);
            b.blink -= dt;

            if (b.blink < -0.14) {
                b.blink = 2.4 + Math.random() * 3.4;
            }

            b.squash *= 1 - 4 * dt;
            b.hit = Math.max(0, b.hit - dt * 2.4);

            this.dripFrom(b, dt);

            // The face chases the centroid instead of being pinned to it, so
            // the eyes lag when it's flung and settle when it lands.
            const c = b.centroid();

            b.face.vx += (c.x - b.face.x) * 90 * dt;
            b.face.vy += (c.y - b.face.y) * 90 * dt;
            b.face.vx *= 1 - 6 * dt;
            b.face.vy *= 1 - 6 * dt;
            b.face.x += b.face.vx * dt;
            b.face.y += b.face.vy * dt;
        });

        this.stepDrops(dt);
        this.marks = this.marks.filter((m) => {
            m.life -= dt;

            return m.life > 0;
        });

        if (this.pointer) {
            const pulling = this.blobs.find((b) => b.grabbed >= 0);

            this.stretchSfx -= dt;

            if (pulling && this.stretchSfx <= 0) {
                const amount = pulling.stretchAmount();

                if (amount > 0.12) {
                    Sfx.stretch(clamp(amount, 0, 1));
                    this.stretchSfx = 0.16;
                }
            }

            this.pointer.px = this.pointer.x;
            this.pointer.py = this.pointer.y;
        }
    }

    /** Goo stuck overhead lets go of itself, one drop at a time. */
    dripFrom(b, dt) {
        const hanging = b.pts.filter((p) => p.bond && p.bond.ny > 0.5);

        if (!hanging.length) {
            b.drip = 0;

            return;
        }

        b.drip -= dt;

        if (b.drip > 0) {
            return;
        }

        b.drip = 0.35 + Math.random() * 0.5;

        const p = hanging[Math.floor(Math.random() * hanging.length)];

        this.drops.push({
            x: p.x,
            y: p.y + 4,
            vx: (Math.random() - 0.5) * 20,
            vy: 20,
            r: 2 + Math.random() * 2.4,
            life: 2.4,
            skin: b.skin,
        });

        Sfx.drip();
    }

    integrate(h) {
        this.blobs.forEach((b) => {
            b.pts.forEach((p) => {
                // Bonded points integrate too: their bond is a spring, so they
                // keep velocity and the goo around them keeps wobbling.
                const vx = (p.x - p.px) * 0.99;
                const vy = (p.y - p.py) * 0.99;

                p.px = p.x;
                p.py = p.y;
                p.x += vx;
                p.y += vy + GRAVITY * h * h;
            });
        });
    }

    /**
     * Viscosity pass.
     *
     * Damps each point's velocity RELATIVE to its neighbour, along the edge
     * between them. Global damping just makes everything slow; this makes the
     * goo drag on itself, so a pulled lump arrives late and a released one
     * oozes back instead of twanging.
     */
    viscosity() {
        this.blobs.forEach((b) => {
            for (let i = 0; i < RING; i++) {
                const p = b.pts[i];
                const q = b.pts[(i + 1) % RING];
                const dx = q.x - p.x;
                const dy = q.y - p.y;
                const d = Math.hypot(dx, dy);

                if (d < 0.0001) {
                    continue;
                }

                const nx = dx / d;
                const ny = dy / d;
                const vrel = (q.x - q.px - (p.x - p.px)) * nx
                    + (q.y - q.py - (p.y - p.py)) * ny;
                const imp = vrel * VISCOSITY * 0.5;

                p.px -= nx * imp;
                p.py -= ny * imp;
                q.px += nx * imp;
                q.py += ny * imp;
            }
        });
    }

    solve(h) {
        this.blobs.forEach((b) => {
            for (let it = 0; it < 2; it++) {
                // Jacobi, not Gauss-Seidel: corrections are accumulated and
                // applied together. Solving the ring in index order biases
                // every correction one way round the loop, and with floor
                // friction that bias becomes a blob that walks to the wall.
                const dxs = new Float64Array(RING);
                const dys = new Float64Array(RING);

                for (let i = 0; i < RING; i++) {
                    const j = (i + 1) % RING;
                    const p = b.pts[i];
                    const q = b.pts[j];
                    const dx = q.x - p.x;
                    const dy = q.y - p.y;
                    const d = Math.hypot(dx, dy) || 0.0001;
                    const rest = b.rests[i];
                    const cap = b.rest * MAX_STRAIN;

                    // Compression is resisted much harder than extension: that
                    // asymmetry is what makes it feel like goo rather than a
                    // rubber band. Past the cap the edge goes rigid, which is
                    // what stops a fling from tearing the ring inside out.
                    let stiff = d > rest ? 0.16 : 0.45;

                    if (d > cap) {
                        stiff = 1;
                    }

                    const target = d > cap ? cap : rest;
                    const k = ((d - target) / d) * 0.5 * stiff;

                    dxs[i] += dx * k;
                    dys[i] += dy * k;
                    dxs[j] -= dx * k;
                    dys[j] -= dy * k;
                }

                for (let i = 0; i < RING; i++) {
                    b.pts[i].x += dxs[i];
                    b.pts[i].y += dys[i];
                }

                // Surface tension. Without it a stretched strand grows spikes
                // where single points get dragged ahead of their neighbours;
                // with it the silhouette stays a curve, which is most of what
                // reads as "wet" on screen.
                const sx = new Float64Array(RING);
                const sy = new Float64Array(RING);

                for (let i = 0; i < RING; i++) {
                    const p = b.pts[i];
                    const a2 = b.pts[(i - 1 + RING) % RING];
                    const b2 = b.pts[(i + 1) % RING];

                    sx[i] = ((a2.x + b2.x) / 2 - p.x) * TENSION;
                    sy[i] = ((a2.y + b2.y) / 2 - p.y) * TENSION;
                }

                for (let i = 0; i < RING; i++) {
                    b.pts[i].x += sx[i];
                    b.pts[i].y += sy[i];
                }

                const a = b.area();
                const err = clamp(
                    (b.areaTarget - a) / b.areaTarget,
                    -PRESSURE_CLAMP,
                    PRESSURE_CLAMP,
                );
                const grabbed = b.grabbed;

                for (let i = 0; i < RING; i++) {
                    const p = b.pts[i];
                    const prev = b.pts[(i - 1 + RING) % RING];
                    const next = b.pts[(i + 1) % RING];
                    const tx = next.x - prev.x;
                    const ty = next.y - prev.y;
                    const tl = Math.hypot(tx, ty) || 0.0001;
                    const nx = ty / tl;
                    const ny = -tx / tl;

                    let hold = 0;

                    if (grabbed >= 0) {
                        let o = Math.abs(i - grabbed);

                        if (o > RING / 2) {
                            o = RING - o;
                        }

                        // Smooth ramp across a third of the ring rather than a
                        // cliff: the cliff let the far side take a 30px
                        // correction in one frame while the held lump took none.
                        hold = clamp(1 - o / (RING * 0.34), 0, 1);
                    }

                    const push = clamp(
                        err * PRESSURE * (tl / (b.rest * 2)) * (1 - hold * 0.85),
                        -PRESSURE_STEP_MAX,
                        PRESSURE_STEP_MAX,
                    );

                    p.x += nx * push;
                    p.y += ny * push;
                }
            }
        });

        this.collideBoxes();
        this.slump();
        this.recoverVolume();
        this.collideBlobs();
        this.holdBonds();

        if (this.pointer) {
            this.blobs.forEach((b) => {
                if (b.grabbed < 0) {
                    return;
                }

                // Narrow and sharply weighted: a wide, strong pin means the
                // finger drags the whole blob's mass instead of pulling a
                // lump of it away from the body.
                for (let o = -3; o <= 3; o++) {
                    const p = b.pts[(b.grabbed + o + RING) % RING];
                    const w = Math.pow(1 - Math.abs(o) / 4, 2.2);

                    p.x += (this.pointer.x - p.x) * w * 0.7;
                    p.y += (this.pointer.y - p.y) * w * 0.7;
                }
            });
        }
    }

    /**
     * Collision against every box in the room, with the splat as a first-class
     * outcome rather than an afterthought.
     *
     * The exit face is chosen by which way the point was TRAVELLING, not just
     * by which face is nearest, so goo arriving fast at a thin shelf doesn't
     * get squirted out of the side of it.
     */
    collideBoxes() {
        this.blobs.forEach((b) => {
            const c = b.centroid();

            BOXES.forEach((box) => {
                // ONE exit face per (blob, box) pair, decided from where the
                // blob's centre of mass is relative to the box. Deciding per
                // point — from its previous position, which is also inside the
                // box once a point has been swallowed by a 14px shelf — pushed
                // the points above a prop up and the ones below it down, which
                // sews the ring through the prop and locks the blob in mid-air.
                let face = box.face;

                if (!face) {
                    const ax = (c.x - (box.x + box.w / 2)) / (box.w / 2);
                    const ay = (c.y - (box.y + box.h / 2)) / (box.h / 2);

                    if (Math.abs(ay) >= Math.abs(ax)) {
                        face = ay < 0 ? 'top' : 'bottom';
                    } else {
                        face = ax < 0 ? 'left' : 'right';
                    }
                }

                b.pts.forEach((p, i) => {
                    if (p.x < box.x || p.x > box.x + box.w || p.y < box.y || p.y > box.y + box.h) {
                        return;
                    }

                    // The far side of a GRABBED blob stays stuck to the floor
                    // until the strand between it and the finger goes taut —
                    // the difference between pulling a string of slime out of a
                    // puddle and sliding the whole body like a hockey puck.
                    if (box.kind === 'floor' && b.grabbed >= 0) {
                        let away = Math.abs(i - b.grabbed);

                        if (away > RING / 2) {
                            away = RING - away;
                        }

                        if (away > RING / 3) {
                            const n = b.pts[(i + 1) % RING];
                            const taut = Math.hypot(n.x - p.x, n.y - p.y) > b.rests[i] * 2.1;

                            if (!taut) {
                                p.x = p.px;
                                p.y = FLOOR;
                                p.py = FLOOR;

                                return;
                            }
                        }
                    }

                    const vx = p.x - p.px;
                    const vy = p.y - p.py;

                    let nx = 0;
                    let ny = 0;
                    let depth = 0;

                    if (face === 'top') {
                        ny = -1;
                        depth = p.y - box.y;
                    } else if (face === 'bottom') {
                        ny = 1;
                        depth = box.y + box.h - p.y;
                    } else if (face === 'left') {
                        nx = -1;
                        depth = p.x - box.x;
                    } else {
                        nx = 1;
                        depth = box.x + box.w - p.x;
                    }

                    p.x += nx * depth;
                    p.y += ny * depth;

                    // Closing speed along the surface normal is the impact;
                    // everything about the splat scales off this one number.
                    const closing = -(vx * nx + vy * ny);
                    const tanx = -ny;
                    const tany = nx;
                    const vt = vx * tanx + vy * tany;

                    // Almost no bounce, heavy tangential friction: slime that
                    // pings off a wall reads as rubber.
                    const vn = -closing * 0.05;
                    const keep = box.kind === 'floor' ? 0.7 : 0.55;
                    const nvx = tanx * vt * keep + nx * vn;
                    const nvy = tany * vt * keep + ny * vn;

                    p.px = p.x - nvx;
                    p.py = p.y - nvy;

                    if (closing > SPLAT_MIN) {
                        this.splat(b, i, nx, ny, closing, box);
                    }
                });
            });
        });
    }

    /**
     * The splat itself.
     *
     * Pancake the contact neighbourhood ALONG the surface (mirrored pairs, so
     * it spreads instead of shoving the blob sideways), heat the goo so the
     * pancake persists, bond it to the surface, and leave evidence: droplets,
     * a stain, a sound, and a kick of the board.
     */
    splat(b, i, nx, ny, force, box) {
        const strength = clamp(force / 14, 0, 1);
        const tanx = -ny;
        const tany = nx;

        if (b.hit <= 0) {
            b.squash = strength;
            b.hot = Math.max(b.hot, strength);
            b.hit = 0.34;
            this.shake = Math.max(this.shake, strength * 0.9);
            Sfx.splat(strength);
            this.emit('st-splat', { force: strength, surface: box.kind });

            const p0 = b.pts[i];

            this.marks.push({
                x: p0.x,
                y: p0.y,
                nx,
                ny,
                r: 10 + strength * 26,
                life: 3 + strength * 5,
                max: 3 + strength * 5,
                skin: b.skin,
            });

            for (let d = 0; d < 3 + Math.round(strength * 9); d++) {
                const spread = (Math.random() - 0.5) * 2;

                this.drops.push({
                    x: p0.x + tanx * spread * 18,
                    y: p0.y + tany * spread * 18,
                    vx: tanx * spread * 180 * strength + nx * 40 * strength,
                    vy: tany * spread * 180 * strength + ny * 40 * strength - 30,
                    r: 2 + Math.random() * 3,
                    life: 0.9 + Math.random() * 0.6,
                    skin: b.skin,
                });
            }
        }

        // Spread the contact along the surface in mirrored pairs: equal and
        // opposite, so this cannot become net sideways motion.
        for (let o = 1; o <= 4; o++) {
            const a = b.pts[(i + o + RING) % RING];
            const c = b.pts[(i - o + RING) % RING];
            const w = (1 - o / 5) * strength * 6;

            a.x += tanx * w;
            a.y += tany * w;
            a.px += tanx * w;
            a.py += tany * w;
            c.x -= tanx * w;
            c.y -= tany * w;
            c.px -= tanx * w;
            c.py -= tany * w;
        }

        if (force > STICK_MIN && box.kind !== 'floor') {
            const p = b.pts[i];

            p.bond = { x: p.x, y: p.y, nx, ny, life: 0.8 + strength * 2.6 };
        }
    }

    /**
     * Bonds.
     *
     * A bond is a SOFT spring to the point where the goo hit, not a nail. A
     * hard pin (position forced, velocity zeroed) freezes the ring rigid the
     * instant it touches anything — which is exactly what a soft body must
     * never do. The spring lets the surrounding goo keep jiggling while the
     * contact holds, and it tears when the weight of the rest of the blob
     * drags it past BOND_BREAK, or when its life runs out. Cling, sag, peel.
     */
    holdBonds() {
        this.blobs.forEach((b) => {
            b.pts.forEach((p) => {
                if (!p.bond) {
                    return;
                }

                p.bond.life -= 1 / 60 / SUBSTEPS;

                const dx = p.bond.x - p.x;
                const dy = p.bond.y - p.y;

                // Torn by load, or simply expired. Without the clock a bond in
                // a corner — where the solve can never pull it far enough to
                // break — holds the goo there forever.
                if (p.bond.life <= 0 || Math.hypot(dx, dy) > BOND_BREAK) {
                    p.bond = null;

                    return;
                }

                p.x += dx * 0.6;
                p.y += dy * 0.6;
            });
        });
    }

    /** Uniform scale toward the target area, applied to x AND px. */
    recoverVolume() {
        this.blobs.forEach((b) => {
            const a = b.area();

            if (a < 1) {
                return;
            }

            const f = clamp(Math.sqrt(b.areaTarget / a), 1 - VOLUME_STEP, 1 + VOLUME_STEP);

            if (Math.abs(f - 1) < 0.00002) {
                return;
            }

            const c = b.centroid();

            b.pts.forEach((p) => {
                const dx = (c.x + (p.x - c.x) * f) - p.x;
                const dy = (c.y + (p.y - c.y) * f) - p.y;

                p.x += dx;
                p.y += dy;
                p.px += dx;
                p.py += dy;
            });
        });
    }

    /**
     * Spread whatever is resting on the floor under the weight above it. A ring
     * of points has nothing to turn vertical load into horizontal flow, so
     * without this a landed blob is a ball parked on a line.
     */
    slump() {
        this.blobs.forEach((b) => {
            if (b.grabbed >= 0) {
                return;
            }

            const c = b.centroid();
            let top = FLOOR;
            let half = 0;

            b.pts.forEach((p) => {
                top = Math.min(top, p.y);
                half = Math.max(half, Math.abs(p.x - c.x));
            });

            // Fades out as the puddle widens rather than switching off at a
            // threshold — the on/off version made a resting blob oscillate
            // wall to wall on a nine-second period.
            const room = clamp(1 - half / (b.r0 * SLUMP_MAX), 0, 1);

            if (room <= 0) {
                return;
            }

            const load = clamp((FLOOR - top) / (b.r0 * 2), 0, 1.5) * room;

            // Mirrored PAIRS about the centroid, not merely a sum-zero set: the
            // contact points are unequal left to right, so a sum-zero set still
            // spreads asymmetrically and the puddle walks.
            const left = [];
            const right = [];

            b.pts.forEach((p) => {
                if (p.bond || p.y < FLOOR - 4) {
                    return;
                }

                const dx = p.x - c.x;

                if (dx < 0) {
                    left.push({ p, d: -dx });
                } else if (dx > 0) {
                    right.push({ p, d: dx });
                }
            });

            left.sort((m, n) => n.d - m.d);
            right.sort((m, n) => n.d - m.d);

            const pairs = Math.min(left.length, right.length);

            for (let i = 0; i < pairs; i++) {
                const d = load * SLUMP;

                left[i].p.x -= d;
                left[i].p.px -= d;
                right[i].p.x += d;
                right[i].p.px += d;
            }
        });
    }

    /**
     * Static friction. A resting puddle should not move at all: any net
     * horizontal velocity below a threshold is simply removed, which is the
     * honest fix for pixel-level asymmetries that otherwise accumulate into a
     * blob that crawls to the wall.
     */
    settle() {
        this.blobs.forEach((b) => {
            if (b.grabbed >= 0) {
                return;
            }

            let grounded = 0;
            let vx = 0;

            b.pts.forEach((p) => {
                vx += p.x - p.px;

                if (p.y > FLOOR - 4) {
                    grounded += 1;
                }
            });

            vx /= RING;

            if (grounded < 3 || Math.abs(vx) > 0.9) {
                return;
            }

            b.pts.forEach((p) => {
                p.px += vx;
            });
        });
    }

    /** Cheap blob-on-blob: push points out of the other blob's rough radius. */
    collideBlobs() {
        for (let i = 0; i < this.blobs.length; i++) {
            for (let j = i + 1; j < this.blobs.length; j++) {
                const a = this.blobs[i];
                const b = this.blobs[j];
                const ca = a.centroid();
                const cb = b.centroid();
                const ra = Math.sqrt(a.area() / Math.PI) * 0.94;
                const rb = Math.sqrt(b.area() / Math.PI) * 0.94;

                a.pts.forEach((p) => {
                    if (p.bond) {
                        return;
                    }

                    const dx = p.x - cb.x;
                    const dy = p.y - cb.y;
                    const d = Math.hypot(dx, dy) || 0.0001;

                    if (d < rb) {
                        const push = (rb - d) * 0.4;

                        p.x += (dx / d) * push;
                        p.y += (dy / d) * push;
                    }
                });

                b.pts.forEach((p) => {
                    if (p.bond) {
                        return;
                    }

                    const dx = p.x - ca.x;
                    const dy = p.y - ca.y;
                    const d = Math.hypot(dx, dy) || 0.0001;

                    if (d < ra) {
                        const push = (ra - d) * 0.4;

                        p.x += (dx / d) * push;
                        p.y += (dy / d) * push;
                    }
                });
            }
        }
    }

    /** Droplets. They fly, they land, and they leave a small stain. */
    stepDrops(dt) {
        this.drops = this.drops.filter((d) => {
            d.vy += GRAVITY * 0.42 * dt;
            d.x += d.vx * dt;
            d.y += d.vy * dt;
            d.life -= dt;

            const hitBox = BOXES.find(
                (box) => d.x >= box.x && d.x <= box.x + box.w && d.y >= box.y && d.y <= box.y + box.h,
            );

            if (hitBox) {
                this.marks.push({
                    x: d.x,
                    y: d.y,
                    nx: 0,
                    ny: -1,
                    r: d.r * 1.9,
                    life: 2.4,
                    max: 2.4,
                    skin: d.skin,
                });

                return false;
            }

            return d.life > 0;
        });
    }

    /* ---------------- drawing ---------------- */

    draw() {
        const ctx = this.ctx;

        if (!ctx || this.canvas.width === 0) {
            return;
        }

        ctx.setTransform(this.scale, 0, 0, this.scale, 0, 0);
        ctx.clearRect(0, 0, W, H);

        const g = ctx.createLinearGradient(0, 0, 0, H);

        g.addColorStop(0, '#1b0f30');
        g.addColorStop(1, '#0a0512');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);

        ctx.save();

        if (this.shake > 0) {
            ctx.translate(
                Math.sin(this.t * 70) * this.shake * 3,
                Math.cos(this.t * 61) * this.shake * 2,
            );
        }

        this.drawRoom();
        this.drawMarks();

        this.drops.forEach((d) => {
            ctx.globalAlpha = clamp(d.life * 2, 0, 1);
            ctx.fillStyle = d.skin.fill;
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r, 0, 6.29);
            ctx.fill();
            ctx.globalAlpha = 1;
        });

        this.blobs.forEach((b) => this.drawBlob(b));
        ctx.restore();

        if (this.phase === 'title') {
            this.drawTitle();

            return;
        }

        this.drawHud();
    }

    drawRoom() {
        const ctx = this.ctx;

        BOXES.forEach((box) => {
            if (box.kind === 'wall' || box.kind === 'ceiling') {
                ctx.fillStyle = '#120a22';
                ctx.fillRect(box.x, box.y, box.w, box.h);

                return;
            }

            if (box.kind === 'floor') {
                ctx.fillStyle = '#150c26';
                ctx.fillRect(box.x, box.y, box.w, box.h);
                ctx.fillStyle = '#2e1b4d';
                ctx.fillRect(box.x, box.y - 2, box.w, 3);

                ctx.strokeStyle = 'rgba(90,60,150,0.35)';
                ctx.lineWidth = 1;

                for (let i = 0; i < 8; i++) {
                    const x = 20 + i * 40;

                    ctx.beginPath();
                    ctx.moveTo(x, FLOOR);
                    ctx.lineTo(x, H);
                    ctx.stroke();
                }

                return;
            }

            if (box.kind === 'bucket') {
                ctx.fillStyle = '#241539';
                rr(ctx, box.x, box.y, box.w, box.h, 5);
                ctx.fill();
                ctx.strokeStyle = '#5c3c96';
                ctx.lineWidth = 2;
                rr(ctx, box.x + 1, box.y + 1, box.w - 2, box.h - 2, 5);
                ctx.stroke();

                ctx.fillStyle = '#3a2360';
                rr(ctx, box.x - 4, box.y - 4, box.w + 8, 7, 3);
                ctx.fill();

                return;
            }

            ctx.fillStyle = '#3a2360';
            rr(ctx, box.x, box.y, box.w, box.h, 3);
            ctx.fill();
            ctx.fillStyle = '#5c3c96';
            ctx.fillRect(box.x, box.y, box.w, 3);
            ctx.fillStyle = 'rgba(10,5,18,0.4)';
            ctx.fillRect(box.x, box.y + box.h - 2, box.w, 2);
        });
    }

    drawMarks() {
        const ctx = this.ctx;

        this.marks.forEach((m) => {
            const k = clamp(m.life / m.max, 0, 1);

            ctx.save();
            ctx.globalAlpha = k * 0.42;
            ctx.translate(m.x, m.y);
            ctx.rotate(Math.atan2(m.ny, m.nx) + Math.PI / 2);
            ctx.fillStyle = m.skin.fill;
            ctx.beginPath();
            ctx.ellipse(0, 0, m.r, m.r * 0.42, 0, 0, 6.29);
            ctx.fill();
            ctx.restore();
        });
    }

    drawBlob(b) {
        const ctx = this.ctx;
        const pts = b.pts;
        const c = b.centroid();

        ctx.fillStyle = 'rgba(10,5,18,0.42)';
        ctx.beginPath();
        ctx.ellipse(c.x, FLOOR + 4, Math.max(16, b.rEff * 0.9), 6, 0, 0, 6.29);
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo((pts[0].x + pts[RING - 1].x) / 2, (pts[0].y + pts[RING - 1].y) / 2);

        for (let i = 0; i < RING; i++) {
            const p = pts[i];
            const n = pts[(i + 1) % RING];

            ctx.quadraticCurveTo(p.x, p.y, (p.x + n.x) / 2, (p.y + n.y) / 2);
        }

        ctx.closePath();

        const grad = ctx.createLinearGradient(c.x, c.y - b.rEff, c.x, c.y + b.rEff);

        grad.addColorStop(0, b.skin.fill);
        grad.addColorStop(1, b.skin.deep);
        ctx.fillStyle = grad;
        ctx.fill();

        ctx.strokeStyle = 'rgba(10,5,18,0.3)';
        ctx.lineWidth = 1.6;
        ctx.stroke();

        ctx.save();
        ctx.clip();

        ctx.fillStyle = b.skin.gloss;
        ctx.beginPath();
        ctx.ellipse(
            c.x - b.rEff * 0.34,
            c.y - b.rEff * 0.42,
            b.rEff * 0.34,
            b.rEff * 0.2,
            -0.5,
            0,
            6.29,
        );
        ctx.fill();

        ctx.globalAlpha = 0.35;
        ctx.fillStyle = b.skin.gloss;
        ctx.beginPath();
        ctx.arc(c.x + b.rEff * 0.42, c.y + b.rEff * 0.2, b.rEff * 0.1, 0, 6.29);
        ctx.fill();
        ctx.globalAlpha = 1;

        this.drawFace(b);
        ctx.restore();
    }

    drawFace(b) {
        const ctx = this.ctx;
        const c = b.centroid();
        const eye = Math.max(3.4, b.rEff * 0.15);
        const gap = Math.max(9, b.rEff * 0.3);
        const gx = clamp(b.face.vx / 90, -1, 1) * eye * 0.5;
        const gy = clamp(b.face.vy / 90, -1, 1) * eye * 0.5;
        const closed = b.blink < 0;

        ctx.fillStyle = '#0a0512';

        if (closed) {
            ctx.fillRect(c.x - gap - eye, c.y - eye * 0.2, eye * 2, 2.2);
            ctx.fillRect(c.x + gap - eye, c.y - eye * 0.2, eye * 2, 2.2);
        } else {
            ctx.beginPath();
            ctx.ellipse(c.x - gap, c.y, eye, eye * 1.15, 0, 0, 6.29);
            ctx.ellipse(c.x + gap, c.y, eye, eye * 1.15, 0, 0, 6.29);
            ctx.fill();

            ctx.fillStyle = '#f7f0ff';
            ctx.beginPath();
            ctx.arc(c.x - gap + gx, c.y - eye * 0.3 + gy, eye * 0.34, 0, 6.29);
            ctx.arc(c.x + gap + gx, c.y - eye * 0.3 + gy, eye * 0.34, 0, 6.29);
            ctx.fill();
        }

        const open = Math.max(b.squash, b.stretchAmount() * 0.6);

        ctx.strokeStyle = '#0a0512';
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.beginPath();

        if (open > 0.18) {
            ctx.ellipse(c.x, c.y + eye * 2.4, eye * 0.9, eye * (0.5 + open), 0, 0, 6.29);
            ctx.fillStyle = '#0a0512';
            ctx.fill();
        } else {
            ctx.moveTo(c.x - eye * 0.8, c.y + eye * 2.2);
            ctx.quadraticCurveTo(c.x, c.y + eye * 3, c.x + eye * 0.8, c.y + eye * 2.2);
            ctx.stroke();
        }
    }

    drawHud() {
        const ctx = this.ctx;

        ctx.textBaseline = 'middle';
        ctx.font = '600 9px "JetBrains Mono", ui-monospace, monospace';
        ctx.textAlign = 'left';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText(SKINS[this.skin].name.toUpperCase(), 14, 22);

        ctx.textAlign = 'right';
        ctx.fillStyle = '#6f6288';
        ctx.fillText(this.blobs.length + ' / ' + MAX_BLOBS + ' BLOBS', W - 14, 22);

        ctx.textAlign = 'center';
        ctx.fillStyle = '#6f6288';
        ctx.fillText('FLING IT AT SOMETHING', W / 2, H - 12);
    }

    drawTitle() {
        const ctx = this.ctx;

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.font = '800 40px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#a8f08a';
        ctx.fillText('Slime Time', W / 2, 74);

        ctx.font = '400 14px Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#b0a3cc';
        ctx.fillText('Grab it, throw it, splat it.', W / 2, 108);
        ctx.fillText('It sticks. Then it drips.', W / 2, 130);

        const pulse = 0.6 + Math.sin(performance.now() / 320) * 0.4;

        ctx.globalAlpha = pulse;
        ctx.font = '800 16px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#f7f0ff';
        ctx.fillText('GRAB THE SLIME', W / 2, 172);
        ctx.globalAlpha = 1;
    }
}

/* ------------------------------------------------------------------ *
 * The element
 * ------------------------------------------------------------------ */

class SlimeTimeElement extends HTMLElement {
    connectedCallback() {
        if (this.game) {
            return;
        }

        this.style.display = 'block';
        this.style.position = 'relative';

        const wrap = document.createElement('div');

        wrap.style.cssText = 'width:100%;display:flex;flex-direction:column;gap:10px;align-items:center;';

        const canvas = document.createElement('canvas');

        // draw() scales by width alone: anything other than 320:460 puts the
        // floor off the bottom of the canvas and the slime falls out of frame.
        canvas.style.cssText = 'width:100%;aspect-ratio:320 / 460;height:auto;display:block;'
            + 'border-radius:18px;background:#0a0512;touch-action:none;cursor:grab;';

        wrap.appendChild(canvas);
        this.appendChild(wrap);

        this.game = new SlimeTime(canvas);
        this.game.mount();

        wrap.appendChild(this.buildPad());
        this.wire(canvas);
    }

    disconnectedCallback() {
        if (this.game) {
            this.game.unmount();
            this.game = null;
        }

        if (this.onKey) {
            window.removeEventListener('keydown', this.onKey);
            this.onKey = null;
        }
    }

    /** Four verbs, not a control panel. A toy with a settings screen stops being a toy. */
    buildPad() {
        const pad = document.createElement('div');

        pad.style.cssText = 'flex:none;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;';

        const add = (label, fn) => {
            const b = document.createElement('button');

            b.type = 'button';
            b.textContent = label;
            b.style.cssText = 'height:44px;padding:0 14px;border:1px solid #3a2360;'
                + 'background:#150c26;color:#a8f08a;border-radius:13px;'
                + 'font:800 13px "Baloo 2",system-ui,sans-serif;cursor:pointer;';

            b.addEventListener('click', () => {
                this.game.tap();
                fn();
            });

            pad.appendChild(b);
        };

        add('Boing', () => this.game.boing());
        add('+ Blob', () => this.game.spawn(W / 2 + (Math.random() - 0.5) * 80, 70, 30 + Math.random() * 16));
        add('Color', () => this.game.cycleSkin());
        add('Reset', () => this.game.reset());

        return pad;
    }

    wire(canvas) {
        const at = (e) => {
            const r = canvas.getBoundingClientRect();

            return {
                x: ((e.clientX - r.left) / r.width) * W,
                y: ((e.clientY - r.top) / r.height) * H,
            };
        };

        canvas.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            canvas.style.cursor = 'grabbing';

            const p = at(e);

            this.game.grab(p.x, p.y);
        });

        canvas.addEventListener('pointermove', (e) => {
            if (!this.game.pointer) {
                return;
            }

            e.preventDefault();

            const p = at(e);

            this.game.move(p.x, p.y);
        });

        const up = (e) => {
            e.preventDefault();
            canvas.style.cursor = 'grab';
            this.game.release();
        };

        canvas.addEventListener('pointerup', up);
        canvas.addEventListener('pointercancel', up);

        this.onKey = (e) => {
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                this.game.tap();
                this.game.boing();

                return;
            }

            if (e.key === 'b' || e.key === 'B') {
                this.game.spawn(W / 2, 70, 34);
            }

            if (e.key === 'c' || e.key === 'C') {
                this.game.cycleSkin();
            }

            if (e.key === 'r' || e.key === 'R') {
                this.game.reset();
            }
        };

        window.addEventListener('keydown', this.onKey);
    }
}

if (!customElements.get('slime-time')) {
    customElements.define('slime-time', SlimeTimeElement);
}
