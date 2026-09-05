/**
 * Penguin Launch — a distance game, and the arcade's fifth cabinet.
 *
 * A self-registering `<penguin-launch>` custom element, plain browser JS, no
 * build step, mounted exactly like `ball-pit.js` and `grand-tour.js`.
 *
 * You are the penguin. Haul yourself back into the sling, let go, and get as
 * far across the ice as you can. Rings boost you, mines pop you back into the
 * air, and the run ends when you finally slide to a stop.
 *
 * DELIBERATELY BALLISTIC — there is no button in the air.
 *
 * Every metre after release was bought at the moment of launch, so the skill
 * is aim and power and the run is the consequence. Angry Birds, not Flappy
 * Bird: you commit, then you watch. It also means the game cannot be
 * button-mashed, which is exactly where Ball Pit came apart — with no input to
 * spam there is nothing to spam.
 *
 * Two rules hold the physics together, and both exist because the first pass
 * broke them:
 *
 *   1. A LAUNCH MUST FLY. Gravity is low and air drag is light, so a good pull
 *      buys seconds of air and a visible arc, not a bunny-hop. Height is the
 *      currency the rest of the run spends.
 *   2. THE PENGUIN NEVER GOES BACKWARDS. Forward speed is clamped at zero
 *      every frame, so a landing in a trough can slow you or stop you but can
 *      never roll you back down the hill. A run only ever ends forwards.
 *
 * Terrain is rolling hills and the camera is side-on, because slope is the
 * only thing that matters once you are moving: land shallow on a downslope and
 * you keep your speed, clip an upslope and you skip off the crest, hit
 * anything steep and you dump energy into the ice. All of that is legible from
 * the side and invisible from behind, which is why the chase camera was
 * dropped.
 *
 * Nothing punishes a bad run beyond ending it. `MILESTONES` is the design's
 * copy of `ArcadeService::MILESTONES` for this game, and every rung on it is
 * reachable — PHP owns the real ladder, the canvas only has to label a
 * distance with the same words.
 *
 * Events: `pl-score` on every metre, `pl-over` once per run. The page posts
 * the run; the game never does.
 */

const W = 320;
const H = 470;

/** The sling sits here in world space, and the run measures from it. */
const SLING_X = 96;
const START_X = 96;

const PENGUIN_R = 13;

/**
 * Flight.
 *
 * GRAVITY is deliberately about half of what a "realistic" pixel game would
 * use: at these speeds a heavier world turns every launch into a skipping
 * stone, and the whole appeal of a sling is the long silent arc at the top.
 */
const GRAVITY = 620;
const AIR_DRAG = 0.1;

/** The launch: pull fraction maps onto this speed range. */
const MAX_PULL = 96;
const SPEED_MIN = 420;
const SPEED_MAX = 1180;

/** The one-key charge angle, in radians above horizontal. */
const HELD_ANGLE = 0.52;

/**
 * Ice. RESTITUTION is how much of a bounce survives an impact.
 *
 * High on purpose: a chick that skips four or five times down a hill is
 * funnier than one that lands once and slides, and every bounce is another
 * chance to clip a ring arc. BOUNCE_MIN keeps the last few from degenerating
 * into a buzzing jitter on the surface.
 */
const RESTITUTION = 0.72;
const BOUNCE_MIN = 90;
const SLIDE_FRICTION = 0.36;

/** A run ends when forward speed dies, and only then. */
const DEAD_SPEED = 42;
const DEAD_TIME = 0.45;

/**
 * Glare ice.
 *
 * Stretches of polished blue ice that ACCELERATE a slide instead of bleeding
 * it. This is the reward for landing shallow: hitting a slick flat and fast
 * turns the ground itself into a boost, and the run keeps building after the
 * arc is spent. Friction nearly vanishes on it, so it also carries a dying
 * slide far enough to reach the next ring arc or mine.
 *
 * SLICK_MAX caps what the ice alone can give you — past that you have to earn
 * speed in the air.
 */
const SLICK_FRICTION = 0.04;
const SLICK_ACCEL = 560;
const SLICK_MAX = 1150;

/** Rings: a speed multiplier, and a chain that compounds within one arc. */
const RING_R = 22;
const RING_BOOST = 1.11;
const RING_CHAIN_MAX = 1.5;
const CHAIN_WINDOW = 1.4;

/** Mines are a pure rescue — a big pop upward, no cost, always good to hit. */
const MINE_R = 17;
const MINE_POP = 700;

/** Power-ups, both timed. */
const BALLOON_TIME = 3.4;
const BALLOON_GRAVITY = 0.34;
const MAGNET_TIME = 5;
const MAGNET_RANGE = 150;
const MAGNET_PULL = 620;

/** Metres per world pixel. Set so the ladder below spans real runs. */
const PX_PER_M = 10;

const MILESTONES = [
    [0, 'Belly on the ice'],
    [60, 'Off the shelf'],
    [140, 'Open ice'],
    [260, 'Iceberg alley'],
    [420, 'Past the whales'],
    [640, 'Halfway to the pole'],
    [900, 'Nobody can beat this'],
];

/* Postcard palette — 2a, kept literal so the canvas matches the mocks. */
const INK = '#0d2b45';
const ICE = '#ffffff';
const SEA = '#4bb8f0';
const DEEP_SEA = '#2a95d8';
const CORAL = '#ff6f61';
const GOLD = '#ffd166';
const BEAK = '#ff9f1c';
const BLUSH = '#ff9d9d';
const FAR_PEAK = '#cfeefb';
const MID_PEAK = '#bfe6f7';
const NEAR_PEAK = '#a8d9ef';
const PEAK_SHADE = 'rgba(37,102,150,0.2)';
const ICE_SHADE = '#e4f4fd';

const clamp = (v, lo, hi) => (v < lo ? lo : (v > hi ? hi : v));

/* ------------------------------------------------------------------ *
 * Terrain
 *
 * A closed form, not a generated array: any x can be asked for its height at
 * any time, which keeps collision, drawing and item placement in agreement
 * with no bookkeeping. The launch shelf is flat and the hills ramp in, so the
 * first second of every run is honest. Amplitudes are gentle on purpose —
 * deep troughs eat a slide instead of passing it along.
 * ------------------------------------------------------------------ */

const GROUND = 372;

function terrainY(x) {
    const ramp = clamp((x - 360) / 480, 0, 1);
    const hills = 34 * Math.sin(x / 300)
        + 16 * Math.sin(x / 128 + 1.3)
        + 7 * Math.sin(x / 57 + 2.1);

    return GROUND - hills * ramp;
}

/** Surface slope, from a numeric derivative. Cheap and stable enough. */
function terrainSlope(x) {
    return (terrainY(x + 3) - terrainY(x - 3)) / 6;
}

/* ------------------------------------------------------------------ *
 * Sound
 * ------------------------------------------------------------------ */

const Sfx = {
    ctx: null,

    wake() {
        // ONE LINE EDITED FROM THE BUNDLE. The arcade has a single sound
        // toggle in the page header, and every game reads this key at the
        // moment it plays rather than holding its own mute state — see
        // <x-sound-toggle>. Re-apply this when replacing the file from a
        // newer bundle; ArcadePenguinTest fails if it goes missing.
        if (localStorage.getItem('fq-muted') === '1') {
            return null;
        }

        if (!this.ctx && window.AudioContext) {
            this.ctx = new AudioContext();
        }

        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume();
        }

        return this.ctx;
    },

    tone(freq, dur, gain, to, type) {
        const ctx = this.wake();

        if (!ctx) {
            return;
        }

        const osc = ctx.createOscillator();
        const amp = ctx.createGain();

        osc.type = type || 'sine';
        osc.frequency.setValueAtTime(freq, ctx.currentTime);

        if (to) {
            osc.frequency.exponentialRampToValueAtTime(to, ctx.currentTime + dur);
        }

        amp.gain.setValueAtTime(gain, ctx.currentTime);
        amp.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + dur);

        osc.connect(amp);
        amp.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + dur);
    },

    stretch(p) {
        this.tone(180 + p * 260, 0.06, 0.05, null, 'triangle');
    },

    launch() {
        this.tone(220, 0.3, 0.13, 900, 'triangle');
    },

    ring(n) {
        this.tone(560 + n * 90, 0.13, 0.1, 1180 + n * 120);
    },

    mine() {
        this.tone(140, 0.26, 0.14, 640, 'square');
    },

    pickup() {
        this.tone(760, 0.16, 0.1, 1500);
    },

    skid(v) {
        this.tone(120, 0.1, clamp(v / 9000, 0.02, 0.09), 70, 'sawtooth');
    },

    over() {
        this.tone(420, 0.5, 0.12, 90, 'sawtooth');
    },
};

/* ------------------------------------------------------------------ *
 * The game
 * ------------------------------------------------------------------ */

class PenguinLaunch {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.scale = 1;
        this.last = 0;
        this.best = 0;
        this.phase = 'title';
        this.reset();
    }

    reset() {
        // On the title frame the penguin STANDS ON THE SHELF beside the sling,
        // not inside it: between the two posts it read as an unidentifiable
        // blob rather than a character.
        this.x = SLING_X - 46;
        this.y = terrainY(SLING_X - 46) - PENGUIN_R;
        this.vx = 0;
        this.vy = 0;
        this.spin = 0;
        this.squash = 0;
        this.grounded = true;

        this.pull = { x: 0, y: 0 };
        this.power = 0;
        this.dragging = false;
        this.holding = false;

        this.metres = 0;
        this.milestone = 0;
        this.rings = 0;
        this.mines = 0;
        this.chain = 0;
        this.chainAt = -99;
        this.balloon = 0;
        this.magnet = 0;
        this.still = 0;
        this.onSlick = false;
        this.shake = 0;
        this.t = 0;
        this.flash = null;

        this.items = [];
        this.frontier = 480;
        this.puffs = [];
        this.trail = [];
        this.bursts = [];
        this.shocks = [];

        this.cam = { x: 0, y: 0 };
        this.camTo();
        this.cam.x = this.camX;
        this.cam.y = this.camY;
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

    /* -------------------------------------------------------------- *
     * Input — a drag, and nothing at all once you are flying
     * -------------------------------------------------------------- */

    slingY() {
        return terrainY(SLING_X) - PENGUIN_R - 10;
    }

    /** Canvas pixels to world space, so a drag lands where it looks. */
    toWorld(e) {
        const rect = this.canvas.getBoundingClientRect();
        const k = W / rect.width;

        return {
            x: (e.clientX - rect.left) * k + this.cam.x,
            y: (e.clientY - rect.top) * k + this.cam.y,
        };
    }

    down(e) {
        Sfx.wake();

        if (this.phase === 'over') {
            this.reset();
            this.phase = 'title';

            return;
        }

        if (this.phase === 'title') {
            this.phase = 'charge';
        }

        if (this.phase !== 'charge') {
            return;
        }

        this.dragging = true;
        this.move(e);
    }

    move(e) {
        if (!this.dragging || this.phase !== 'charge') {
            return;
        }

        const p = this.toWorld(e);
        const ay = this.slingY();

        // The pull is clamped to a disc, so a wild drag off the canvas still
        // reads as a sensible angle at full power.
        let dx = p.x - SLING_X;
        let dy = p.y - ay;
        const len = Math.hypot(dx, dy) || 1;
        const capped = Math.min(len, MAX_PULL);

        dx = (dx / len) * capped;
        dy = (dy / len) * capped;

        // Only a pull BACK charges the sling. Dragging forward would otherwise
        // fire the penguin off the back of the shelf.
        if (dx > 0) {
            dx = 0;
        }

        const was = this.power;

        this.pull = { x: dx, y: dy };
        this.power = clamp(Math.hypot(dx, dy) / MAX_PULL, 0, 1);
        this.x = SLING_X + dx;
        this.y = Math.min(ay + dy, terrainY(this.x) - PENGUIN_R - 2);
        this.spin = Math.atan2(-dy, -dx) * 0.4;

        if (Math.abs(this.power - was) > 0.06) {
            Sfx.stretch(this.power);
        }
    }

    up() {
        if (!this.dragging || this.phase !== 'charge') {
            return;
        }

        this.dragging = false;

        if (this.power < 0.08) {
            return;
        }

        this.launch();
    }

    /** Keyboard and the pad button: hold to charge at a fixed good angle. */
    hold(on) {
        Sfx.wake();

        if (on) {
            if (this.phase === 'over') {
                this.reset();
                this.phase = 'title';
            }

            if (this.phase === 'title') {
                this.phase = 'charge';
            }

            this.holding = this.phase === 'charge';

            return;
        }

        if (this.holding && this.phase === 'charge' && this.power > 0.08) {
            this.launch();
        }

        this.holding = false;
    }

    launch() {
        const len = Math.hypot(this.pull.x, this.pull.y) || 1;
        const speed = SPEED_MIN + this.power * (SPEED_MAX - SPEED_MIN);

        this.vx = (-this.pull.x / len) * speed;
        this.vy = (-this.pull.y / len) * speed;
        this.y = Math.min(this.y, terrainY(this.x) - PENGUIN_R - 6);
        this.phase = 'flying';
        this.grounded = false;
        this.still = 0;
        this.pull = { x: 0, y: 0 };
        this.power = 0;
        this.shake = 0.35;
        Sfx.launch();
        this.emit('pl-score', { score: 0, label: MILESTONES[0][1], metres: 0 });
    }

    /* -------------------------------------------------------------- *
     * Step
     * -------------------------------------------------------------- */

    step(dt) {
        this.t += dt;
        this.shake = Math.max(0, this.shake - dt * 3);

        for (const p of this.puffs) {
            p.life -= dt;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.vy += 220 * dt;
        }

        this.puffs = this.puffs.filter((p) => p.life > 0);

        for (const b of this.bursts) {
            b.life -= dt;
            b.x += b.vx * dt;
            b.y += b.vy * dt;
            b.vy += 340 * dt;
            b.vx *= 1 - dt * 1.4;
        }

        this.bursts = this.bursts.filter((b) => b.life > 0);

        for (const k of this.shocks) {
            k.life -= dt;
            k.r += k.grow * dt;
        }

        this.shocks = this.shocks.filter((k) => k.life > 0);

        if (this.phase === 'charge' && this.holding) {
            // A held button walks the pull back at HELD_ANGLE, so the game is
            // fully playable with one key and no aiming — and at an angle that
            // actually flies, not one that skims.
            const p = clamp(this.power + dt * 1.4, 0, 1);

            this.power = p;
            this.pull = {
                x: -Math.cos(HELD_ANGLE) * MAX_PULL * p,
                y: Math.sin(HELD_ANGLE) * MAX_PULL * p,
            };
            this.x = SLING_X + this.pull.x;
            this.y = Math.min(this.slingY() + this.pull.y, terrainY(this.x) - PENGUIN_R - 2);
            this.spin = -HELD_ANGLE * 0.4;
        }

        if (this.phase === 'flying') {
            this.fly(dt);
        }

        this.camTo();
        this.cam.x += (this.camX - this.cam.x) * Math.min(1, dt * 7);
        this.cam.y += (this.camY - this.cam.y) * Math.min(1, dt * 5);
    }

    fly(dt) {
        this.balloon = Math.max(0, this.balloon - dt);
        this.magnet = Math.max(0, this.magnet - dt);

        const g = GRAVITY * (this.balloon > 0 ? BALLOON_GRAVITY : 1);

        this.vy += g * dt;

        // Drag only in the air; on the ice, friction does the work.
        if (!this.grounded) {
            const d = 1 - AIR_DRAG * dt;

            this.vx *= d;
            this.vy *= d;
        }

        this.x += this.vx * dt;
        this.y += this.vy * dt;

        this.ground(dt);

        // RULE 2, enforced last and unconditionally: never backwards.
        this.vx = Math.max(0, this.vx);

        this.generate();
        this.collect(dt);

        this.trail.push({ x: this.x, y: this.y, life: 0.5 });

        for (const t of this.trail) {
            t.life -= dt;
        }

        this.trail = this.trail.filter((t) => t.life > 0);

        this.squash = Math.max(0, this.squash - dt * 4.2);

        if (!this.grounded) {
            this.spin = Math.atan2(this.vy, Math.max(60, this.vx)) * 0.85;
        }

        const m = Math.max(0, Math.floor((this.x - START_X) / PX_PER_M));

        if (m > this.metres) {
            this.metres = m;
            this.bump();
        }

        // The run ends when forward progress dies, and only then.
        if (this.grounded && this.vx < DEAD_SPEED) {
            this.still += dt;

            if (this.still > DEAD_TIME) {
                this.over();
            }
        } else {
            this.still = 0;
        }
    }

    /**
     * Ice contact.
     *
     * Resolved against the surface normal rather than a flat floor, which is
     * what makes slope matter: a shallow landing on a downslope keeps almost
     * all its speed, an upslope converts speed into height, and a steep face
     * dumps energy as spray. No special cases, just the normal.
     */
    ground(dt) {
        const gy = terrainY(this.x) - PENGUIN_R;

        if (this.y < gy) {
            this.grounded = false;

            return;
        }

        const slope = terrainSlope(this.x);
        const nl = Math.hypot(1, slope);
        const nx = -slope / nl;
        const ny = -1 / nl;

        this.y = gy;

        const vn = this.vx * nx + this.vy * ny;

        // Leaving the surface is not landing on it. Without this, a launch
        // that starts at or below the ice line — which a full pull does, since
        // the sling pocket dips under the surface — gets snapped down and its
        // whole vertical velocity converted into a slide on frame one. That is
        // why the penguin never got any air.
        if (vn > 60) {
            this.y = Math.min(this.y, gy);
            this.grounded = false;

            return;
        }

        const tx = -ny;
        const ty = nx;
        let vt = this.vx * tx + this.vy * ty;

        const impact = Math.abs(vn);

        if (vn < 0) {
            const out = -vn * RESTITUTION;

            if (impact > 190) {
                this.spray(impact);
                Sfx.skid(impact * Math.abs(vt));
                this.shake = Math.max(this.shake, clamp(impact / 1400, 0, 0.4));
            }

            vt *= 1 - clamp(impact / 9000, 0, 0.18);
            this.vx = tx * vt + nx * out;
            this.vy = ty * vt + ny * out;

            // Squash on the way in, so a bounce reads as springy rather than
            // as the penguin simply changing direction.
            this.squash = clamp(impact / 900, 0.15, 1);
            this.grounded = out < BOUNCE_MIN;
        } else {
            this.grounded = true;
        }

        if (this.grounded) {
            // Sliding: friction bleeds the along-surface speed, and gravity
            // still pulls you down the slope, so a downhill is free distance.
            const slick = this.slickAt(this.x);
            const fric = slick ? SLICK_FRICTION : SLIDE_FRICTION;

            vt = Math.max(0, this.vx * tx + this.vy * ty);
            vt -= vt * fric * dt * (this.balloon > 0 ? 0.4 : 1);

            if (slick && vt < SLICK_MAX) {
                vt = Math.min(SLICK_MAX, vt + SLICK_ACCEL * dt);
            }

            this.vx = tx * vt;
            this.vy = ty * vt;
            this.spin += (Math.atan(slope) - this.spin) * Math.min(1, dt * 10);

            if (slick) {
                if (!this.onSlick) {
                    this.onSlick = true;
                    this.flash = { t: 0.45, text: 'SLICK!' };
                    Sfx.pickup();
                }

                if (Math.random() < dt * 26) {
                    this.puffs.push({
                        x: this.x - 6,
                        y: this.y + PENGUIN_R - 3,
                        vx: -160 - Math.random() * 200,
                        vy: -10 - Math.random() * 40,
                        r: 1.2 + Math.random() * 2,
                        life: 0.25 + Math.random() * 0.25,
                    });
                }
            } else {
                this.onSlick = false;

                if (this.vx > 120 && Math.random() < dt * 22) {
                    this.spray(this.vx * 0.5);
                }
            }
        }
    }

    /** The glare-ice patch under a given x, if any. */
    slickAt(x) {
        for (const it of this.items) {
            if (it.kind === 'slick' && x >= it.x && x <= it.x + it.w) {
                return it;
            }
        }

        return null;
    }

    burst(x, y, n, col) {
        for (let i = 0; i < n; i++) {
            const a = Math.random() * Math.PI * 2;
            const sp = 80 + Math.random() * 300;

            this.bursts.push({
                x: x,
                y: y,
                vx: Math.cos(a) * sp,
                vy: Math.sin(a) * sp - 60,
                r: 1.6 + Math.random() * 2.8,
                life: 0.3 + Math.random() * 0.4,
                col: col,
            });
        }
    }

    spray(v) {
        const n = clamp(Math.round(v / 130), 1, 7);

        for (let i = 0; i < n; i++) {
            this.puffs.push({
                x: this.x - 4,
                y: this.y + PENGUIN_R - 2,
                vx: -40 - Math.random() * 130,
                vy: -30 - Math.random() * 150,
                r: 1.5 + Math.random() * 3.4,
                life: 0.3 + Math.random() * 0.35,
            });
        }
    }

    /* -------------------------------------------------------------- *
     * The course, generated a screen at a time
     * -------------------------------------------------------------- */

    generate() {
        while (this.frontier < this.x + 1600) {
            const x = this.frontier;
            const roll = Math.random();

            if (roll < 0.42) {
                // A climbing arc of rings, hung where a real launch arc goes.
                const n = 3 + Math.floor(Math.random() * 3);
                const top = terrainY(x) - 150 - Math.random() * 190;

                for (let i = 0; i < n; i++) {
                    const t = i / (n - 1);

                    this.items.push({
                        kind: 'ring',
                        x: x + i * 88,
                        y: top + Math.sin(t * Math.PI) * -70 + 34,
                        gone: false,
                    });
                }

                this.frontier = x + n * 88 + 200;
            } else if (roll < 0.66) {
                // Mines sit ON the ice, so they catch a dying run.
                const mx = x + 60;

                this.items.push({ kind: 'mine', x: mx, y: terrainY(mx) - MINE_R + 4, gone: false });
                this.frontier = x + 320 + Math.random() * 240;
            } else if (roll < 0.82) {
                const w = 260 + Math.random() * 260;

                this.items.push({ kind: 'slick', x: x + 40, w: w, gone: false });
                this.frontier = x + w + 260;
            } else if (roll < 0.92) {
                const bx = x + 60;

                this.items.push({
                    kind: 'balloon',
                    x: bx,
                    y: terrainY(bx) - 110 - Math.random() * 140,
                    gone: false,
                });
                this.frontier = x + 400;
            } else {
                const gx = x + 60;

                this.items.push({
                    kind: 'magnet',
                    x: gx,
                    y: terrainY(gx) - 90 - Math.random() * 150,
                    gone: false,
                });
                this.frontier = x + 400;
            }
        }

        this.items = this.items.filter((it) => it.x > this.x - 400);
    }

    collect(dt) {
        for (const it of this.items) {
            if (it.gone || it.kind === 'slick') {
                continue;
            }

            // The magnet only reaches rings — a magnet that dragged mines to
            // you would be a punishment dressed as a power-up.
            if (this.magnet > 0 && it.kind === 'ring') {
                const dx = this.x - it.x;
                const dy = this.y - it.y;
                const d = Math.hypot(dx, dy);

                if (d < MAGNET_RANGE && d > 1) {
                    it.x += (dx / d) * MAGNET_PULL * dt;
                    it.y += (dy / d) * MAGNET_PULL * dt;
                }
            }

            const reach = it.kind === 'ring' ? RING_R : MINE_R + 4;

            if (Math.hypot(this.x - it.x, this.y - it.y) > reach + PENGUIN_R - 6) {
                continue;
            }

            it.gone = true;

            if (it.kind === 'ring') {
                this.chain = this.t - this.chainAt < CHAIN_WINDOW ? this.chain + 1 : 1;
                this.chainAt = this.t;
                this.rings += 1;

                const boost = Math.min(RING_BOOST + (this.chain - 1) * 0.03, RING_CHAIN_MAX);

                this.vx *= boost;
                this.vy = Math.min(this.vy * boost, this.vy - 30);
                Sfx.ring(Math.min(this.chain, 6));
                this.burst(it.x, it.y, 10, GOLD);
                this.shocks.push({ x: it.x, y: it.y, r: RING_R, grow: 260, life: 0.36, col: CORAL, w: 5 });
                this.flash = { t: 0.4, text: this.chain > 1 ? 'RING \u00d7' + this.chain : 'RING' };
            } else if (it.kind === 'mine') {
                this.mines += 1;
                this.vy = -MINE_POP;
                this.grounded = false;
                this.shake = 0.5;
                Sfx.mine();
                this.spray(700);
                this.burst(it.x, it.y, 18, CORAL);
                this.shocks.push({ x: it.x, y: it.y, r: MINE_R, grow: 460, life: 0.5, col: INK, w: 6 });
                this.flash = { t: 0.5, text: 'POP!' };
            } else if (it.kind === 'balloon') {
                this.balloon = BALLOON_TIME;
                Sfx.pickup();
                this.flash = { t: 0.45, text: 'FLOAT' };
            } else {
                this.magnet = MAGNET_TIME;
                Sfx.pickup();
                this.flash = { t: 0.45, text: 'MAGNET' };
            }
        }

        if (this.flash) {
            this.flash.t -= dt;

            if (this.flash.t <= 0) {
                this.flash = null;
            }
        }
    }

    bump() {
        let m = this.milestone;

        while (m + 1 < MILESTONES.length && this.metres >= MILESTONES[m + 1][0]) {
            m += 1;
        }

        this.milestone = m;
        this.emit('pl-score', {
            score: this.metres,
            label: MILESTONES[m][1],
            metres: this.metres,
            rings: this.rings,
        });
    }

    over() {
        if (this.phase !== 'flying') {
            return;
        }

        this.phase = 'over';
        this.best = Math.max(this.best, this.metres);
        Sfx.over();
        this.emit('pl-over', {
            score: this.metres,
            label: MILESTONES[this.milestone][1],
            metres: this.metres,
            rings: this.rings,
            mines: this.mines,
        });
    }

    /* -------------------------------------------------------------- *
     * Camera
     * -------------------------------------------------------------- */

    camTo() {
        const gy = terrainY(this.x);

        this.camX = this.x - W * 0.33;

        // Two rules, whichever puts the camera higher: keep the ice near the
        // bottom when low, and keep the penguin in frame when high.
        this.camY = Math.min(gy - H * 0.74, this.y - H * 0.34);
    }

    /* -------------------------------------------------------------- *
     * Draw
     * -------------------------------------------------------------- */

    draw() {
        const ctx = this.ctx;

        ctx.setTransform(this.scale, 0, 0, this.scale, 0, 0);
        ctx.clearRect(0, 0, W, H);

        if (this.shake > 0) {
            ctx.translate((Math.random() - 0.5) * this.shake * 10, (Math.random() - 0.5) * this.shake * 10);
        }

        this.drawSky();
        this.drawPeaks();
        this.drawFloes();
        this.drawIce();

        ctx.save();
        ctx.translate(-this.cam.x, -this.cam.y);
        this.drawItems();
        this.drawShadow();
        this.drawTrail();
        this.drawSling();
        this.drawPenguin();
        this.drawPuffs();
        this.drawShocks();
        this.drawBursts();
        ctx.restore();

        this.drawFlakes();
        this.drawVignette();
        this.drawHud();

        if (this.phase === 'title' || this.phase === 'charge') {
            this.drawCharge();
        }

        if (this.phase === 'over') {
            this.drawOver();
        }
    }

    /**
     * The backdrop, in four parallax layers.
     *
     * Depth is the whole job of a side-on distance game: without it, speed is
     * a number in the corner rather than something you feel. Sky, three ridge
     * bands and the sea each scroll at their own rate, and shading is done
     * with offset silhouettes rather than gradients wherever it has to read at
     * speed — which keeps the flat-vector postcard style honest.
     */
    drawSky() {
        const ctx = this.ctx;
        const g = ctx.createLinearGradient(0, 0, 0, H);

        g.addColorStop(0, '#3fb3ee');
        g.addColorStop(0.3, '#72cdf7');
        g.addColorStop(0.6, '#aee3fc');
        g.addColorStop(0.85, '#e2f5ff');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);

        const sx = 252 - this.cam.x * 0.018;
        const sy = 68 - this.cam.y * 0.035;

        // Slow rays, so the sun is a light source rather than a sticker.
        ctx.save();
        ctx.translate(sx, sy);
        ctx.rotate(this.t * 0.06);
        ctx.fillStyle = 'rgba(255,246,201,0.15)';

        for (let i = 0; i < 12; i++) {
            ctx.rotate(Math.PI / 6);
            ctx.beginPath();
            ctx.moveTo(0, -6);
            ctx.lineTo(122, -22);
            ctx.lineTo(122, 22);
            ctx.lineTo(0, 6);
            ctx.closePath();
            ctx.fill();
        }

        ctx.restore();

        ctx.fillStyle = 'rgba(255,240,180,0.3)';
        ctx.beginPath();
        ctx.arc(sx, sy, 50, 0, Math.PI * 2);
        ctx.fill();

        const sg = ctx.createRadialGradient(sx - 9, sy - 9, 3, sx, sy, 37);

        sg.addColorStop(0, '#fffdf0');
        sg.addColorStop(0.5, '#ffe066');
        sg.addColorStop(1, '#ffcf3f');
        ctx.fillStyle = sg;
        ctx.beginPath();
        ctx.arc(sx, sy, 37, 0, Math.PI * 2);
        ctx.fill();

        this.drawClouds();
    }

    /** Clouds as clustered lozenges with a shaded underside. */
    drawClouds() {
        const ctx = this.ctx;
        const rows = [
            { y: 88, s: 1.15, a: 0.95, k: 0.09, span: 470 },
            { y: 126, s: 0.85, a: 0.8, k: 0.15, span: 390 },
            { y: 172, s: 1, a: 0.6, k: 0.23, span: 540 },
        ];

        for (const r of rows) {
            const off = ((-this.cam.x * r.k) % r.span + r.span) % r.span;

            for (let i = -1; i < 3; i++) {
                const x = off + i * r.span;
                const y = r.y - this.cam.y * r.k * 0.5;

                ctx.globalAlpha = r.a * 0.5;
                ctx.fillStyle = '#cfeafb';
                this.cloudBody(x, y + 6 * r.s, r.s);

                ctx.globalAlpha = r.a;
                ctx.fillStyle = ICE;
                this.cloudBody(x, y, r.s);
            }
        }

        ctx.globalAlpha = 1;
    }

    cloudBody(x, y, s) {
        const ctx = this.ctx;

        ctx.beginPath();
        ctx.ellipse(x, y, 44 * s, 19 * s, 0, 0, Math.PI * 2);
        ctx.ellipse(x + 34 * s, y + 5 * s, 30 * s, 14 * s, 0, 0, Math.PI * 2);
        ctx.ellipse(x - 32 * s, y + 6 * s, 26 * s, 12 * s, 0, 0, Math.PI * 2);
        ctx.ellipse(x + 8 * s, y - 12 * s, 24 * s, 15 * s, 0, 0, Math.PI * 2);
        ctx.fill();
    }

    /**
     * Ridge bands.
     *
     * Each band is filled once, then the SAME silhouette is filled again
     * shifted right and down — which leaves a lit rim on every north-west face
     * and a shadow on every south-east one. One extra fill buys the whole
     * sense of volume, and it survives at any scroll speed.
     */
    drawPeaks() {
        const ctx = this.ctx;
        const bands = [
            { k: 0.22, base: 276, amp: 52, col: FAR_PEAK, step: 0.0054, ph: 0.7, dx: 9, dy: 7 },
            { k: 0.38, base: 296, amp: 44, col: MID_PEAK, step: 0.0082, ph: 0, dx: 8, dy: 6 },
            { k: 0.58, base: 314, amp: 32, col: NEAR_PEAK, step: 0.0125, ph: 2.1, dx: 7, dy: 5 },
        ];

        for (const b of bands) {
            const path = (ox, oy) => {
                ctx.beginPath();
                ctx.moveTo(-4 + ox, H + 40);

                for (let sx = -4; sx <= W + 4; sx += 4) {
                    const wx = (this.cam.x * b.k + sx) * b.step;
                    const y = b.base - this.cam.y * b.k
                        - (Math.abs(Math.sin(wx + b.ph)) * b.amp
                            + Math.abs(Math.sin(wx * 2.3 + b.ph)) * b.amp * 0.42
                            + Math.abs(Math.sin(wx * 5.1 + b.ph)) * b.amp * 0.14);

                    ctx.lineTo(sx + ox, y + oy);
                }

                ctx.lineTo(W + 4 + ox, H + 40);
                ctx.closePath();
            };

            ctx.save();
            path(0, 0);
            ctx.fillStyle = b.col;
            ctx.fill();
            ctx.clip();
            path(b.dx, b.dy);
            ctx.fillStyle = PEAK_SHADE;
            ctx.fill();
            ctx.restore();
        }
    }

    /** Floes on the water line: flat-topped chunks, closer than the ridges. */
    drawFloes() {
        const ctx = this.ctx;
        const span = 300;
        const off = ((-this.cam.x * 0.72) % span + span) % span;

        for (let i = -1; i < 3; i++) {
            const x = off + i * span;
            const y = 352 - this.cam.y * 0.72;
            const w = 54;

            ctx.fillStyle = '#8fd8f5';
            ctx.beginPath();
            ctx.moveTo(x - w, y + 16);
            ctx.lineTo(x - w * 0.6, y - 12);
            ctx.lineTo(x + w * 0.2, y - 16);
            ctx.lineTo(x + w, y + 16);
            ctx.closePath();
            ctx.fill();

            ctx.fillStyle = ICE;
            ctx.beginPath();
            ctx.moveTo(x - w * 0.6, y - 12);
            ctx.lineTo(x + w * 0.2, y - 16);
            ctx.lineTo(x + w * 0.34, y - 8);
            ctx.lineTo(x - w * 0.44, y - 4);
            ctx.closePath();
            ctx.fill();
        }
    }

    /**
     * The ice.
     *
     * Sampled straight from terrainY, so what you see is exactly what you hit
     * — the collision surface and the drawn surface cannot drift apart. Sea
     * underneath with a scalloped waterline, a lit snow rim on top, faint
     * contour bands for speed, and crevasses keyed to world position so they
     * belong to the ice rather than to the view.
     */
    drawIce() {
        const ctx = this.ctx;
        const pts = [];

        for (let sx = -6; sx <= W + 6; sx += 5) {
            pts.push([sx, terrainY(this.cam.x + sx) - this.cam.y]);
        }

        const seaG = ctx.createLinearGradient(0, 0, 0, H);

        seaG.addColorStop(0, SEA);
        seaG.addColorStop(1, DEEP_SEA);
        ctx.fillStyle = seaG;
        ctx.beginPath();
        ctx.moveTo(-6, H);

        for (const p of pts) {
            ctx.lineTo(p[0], p[1] + 34);
        }

        ctx.lineTo(W + 6, H);
        ctx.closePath();
        ctx.fill();

        ctx.fillStyle = 'rgba(255,255,255,0.45)';

        for (let i = 0; i < pts.length; i += 3) {
            const p = pts[i];
            const bob = Math.sin(this.t * 1.6 + (this.cam.x + p[0]) * 0.03) * 2;

            ctx.beginPath();
            ctx.arc(p[0], p[1] + 34 + bob, 7, Math.PI, 0);
            ctx.fill();
        }

        const iceG = ctx.createLinearGradient(0, 0, 0, H);

        iceG.addColorStop(0, ICE);
        iceG.addColorStop(1, ICE_SHADE);

        ctx.save();
        ctx.beginPath();
        ctx.moveTo(-6, H);

        for (const p of pts) {
            ctx.lineTo(p[0], p[1]);
        }

        ctx.lineTo(W + 6, H);
        ctx.closePath();
        ctx.fillStyle = iceG;
        ctx.fill();
        ctx.clip();

        ctx.fillStyle = 'rgba(13,43,69,0.045)';

        const band = 24;
        const startY = Math.floor(this.cam.y / band) * band;

        for (let y = startY; y < this.cam.y + H; y += band) {
            ctx.fillRect(-6, y - this.cam.y, W + 12, 2);
        }

        const step = 190;
        const first = Math.floor((this.cam.x - 40) / step) * step;

        for (let wx = first; wx < this.cam.x + W + 40; wx += step) {
            const jitter = Math.sin(wx * 0.017) * 70;
            const cx = wx + jitter - this.cam.x;
            const cy = terrainY(wx + jitter) - this.cam.y;
            const depth = 26 + Math.abs(Math.sin(wx * 0.031)) * 30;

            ctx.strokeStyle = 'rgba(80,163,214,0.4)';
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(cx, cy + 12);
            ctx.lineTo(cx + 5, cy + 12 + depth * 0.5);
            ctx.lineTo(cx - 2, cy + 12 + depth);
            ctx.stroke();
        }

        ctx.restore();

        ctx.strokeStyle = 'rgba(255,255,255,0.9)';
        ctx.lineWidth = 9;
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1] + 6);

        for (const p of pts) {
            ctx.lineTo(p[0], p[1] + 6);
        }

        ctx.stroke();

        ctx.strokeStyle = INK;
        ctx.lineWidth = 5;
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1]);

        for (const p of pts) {
            ctx.lineTo(p[0], p[1]);
        }

        ctx.stroke();
    }

    /** Contact shadow: the only cue for how high you actually are. */
    drawShadow() {
        const ctx = this.ctx;
        const gy = terrainY(this.x);
        const h = clamp((gy - PENGUIN_R - this.y) / 320, 0, 1);

        ctx.globalAlpha = 0.22 * (1 - h * 0.72);
        ctx.fillStyle = INK;
        ctx.beginPath();
        ctx.ellipse(this.x + 4, gy - 2, 16 - h * 7, 5 - h * 2, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = 1;
    }

    drawShocks() {
        const ctx = this.ctx;

        for (const k of this.shocks) {
            ctx.globalAlpha = clamp(k.life * 2, 0, 0.8);
            ctx.strokeStyle = k.col;
            ctx.lineWidth = k.w * clamp(k.life * 2.4, 0.2, 1);
            ctx.beginPath();
            ctx.arc(k.x, k.y, k.r, 0, Math.PI * 2);
            ctx.stroke();
        }

        ctx.globalAlpha = 1;
    }

    drawBursts() {
        const ctx = this.ctx;

        for (const b of this.bursts) {
            ctx.globalAlpha = clamp(b.life * 2.6, 0, 1);
            ctx.fillStyle = b.col;
            ctx.beginPath();
            ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.globalAlpha = 1;
    }

    /** Airborne snow in screen space, parallaxed — the nearest layer of all. */
    drawFlakes() {
        const ctx = this.ctx;

        if (!this.flakes) {
            this.flakes = [];

            for (let i = 0; i < 64; i++) {
                this.flakes.push({
                    x: Math.random() * W,
                    y: Math.random() * H,
                    r: 0.8 + Math.random() * 2.2,
                    k: 0.35 + Math.random() * 0.85,
                    sw: Math.random() * 6.3,
                });
            }
        }

        ctx.fillStyle = ICE;

        for (const f of this.flakes) {
            const x = ((f.x - this.cam.x * f.k * 0.4) % W + W) % W;
            const y = ((f.y - this.cam.y * f.k * 0.3) % H + H) % H;

            ctx.globalAlpha = 0.3 + f.k * 0.35;
            ctx.beginPath();
            ctx.arc(x + Math.sin(this.t * 1.4 + f.sw) * 3, y, f.r, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.globalAlpha = 1;
    }

    /** A cold vignette and a warm corner, to seat the flat art in real air. */
    drawVignette() {
        const ctx = this.ctx;
        const g = ctx.createRadialGradient(W * 0.42, H * 0.42, H * 0.3, W * 0.5, H * 0.5, H * 0.86);

        g.addColorStop(0, 'rgba(13,43,69,0)');
        g.addColorStop(1, 'rgba(13,43,69,0.16)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);

        const wg = ctx.createRadialGradient(W * 0.82, 62, 8, W * 0.82, 62, 210);

        wg.addColorStop(0, 'rgba(255,246,201,0.28)');
        wg.addColorStop(1, 'rgba(255,246,201,0)');
        ctx.fillStyle = wg;
        ctx.fillRect(0, 0, W, H);
    }

    /** The sling: two posts PLANTED ON THE SURFACE, and a stretched band. */
    drawSling() {
        const ctx = this.ctx;
        const ay = this.slingY();
        const surface = terrainY(SLING_X) + 4;
        const charging = this.phase === 'charge' || this.phase === 'title';
        const px = charging ? this.x : SLING_X;
        const py = charging ? this.y : ay;
        const posts = [
            { x: SLING_X - 34, top: ay - 44, tilt: -0.09 },
            { x: SLING_X + 12, top: ay - 50, tilt: 0.05 },
        ];

        // Back band first, so the penguin can sit between the two.
        ctx.strokeStyle = '#e05548';
        ctx.lineWidth = 8;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(posts[1].x, posts[1].top);
        ctx.lineTo(px, py);
        ctx.stroke();

        for (const p of posts) {
            const h = surface - p.top;

            ctx.save();
            ctx.translate(p.x, p.top);
            ctx.rotate(p.tilt);
            ctx.fillStyle = '#a06a33';
            ctx.strokeStyle = INK;
            ctx.lineWidth = 4;
            ctx.beginPath();
            ctx.roundRect(-7, 0, 14, h, 7);
            ctx.fill();
            ctx.stroke();

            // Grain, and a snow cap where the post meets the ice.
            ctx.fillStyle = 'rgba(255,255,255,0.25)';
            ctx.beginPath();
            ctx.roundRect(-4, 6, 3.5, h - 14, 2);
            ctx.fill();
            ctx.restore();

            ctx.fillStyle = ICE;
            ctx.beginPath();
            ctx.ellipse(p.x, surface - 2, 13, 5, 0, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.strokeStyle = CORAL;
        ctx.lineWidth = 8;
        ctx.beginPath();
        ctx.moveTo(posts[0].x, posts[0].top);
        ctx.lineTo(px, py);
        ctx.stroke();
    }

    drawItems() {
        const ctx = this.ctx;

        // Glare ice first: it is part of the ground, so everything sits on it.
        for (const it of this.items) {
            if (it.kind !== 'slick' || it.x + it.w < this.cam.x - 40 || it.x > this.cam.x + W + 40) {
                continue;
            }

            const from = Math.max(it.x, this.cam.x - 20);
            const to = Math.min(it.x + it.w, this.cam.x + W + 20);

            ctx.beginPath();
            ctx.moveTo(from, terrainY(from) - 1);

            for (let x = from; x <= to; x += 6) {
                ctx.lineTo(x, terrainY(x) - 1);
            }

            for (let x = to; x >= from; x -= 6) {
                ctx.lineTo(x, terrainY(x) + 13);
            }

            ctx.closePath();

            const sg = ctx.createLinearGradient(0, terrainY(from) - 6, 0, terrainY(from) + 14);

            sg.addColorStop(0, '#7fe3ff');
            sg.addColorStop(1, '#2fb6e8');
            ctx.fillStyle = sg;
            ctx.fill();

            ctx.strokeStyle = INK;
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(from, terrainY(from) - 1);

            for (let x = from; x <= to; x += 6) {
                ctx.lineTo(x, terrainY(x) - 1);
            }

            ctx.stroke();

            // Streaked highlights, so it reads as polished rather than painted.
            ctx.strokeStyle = 'rgba(255,255,255,0.75)';
            ctx.lineWidth = 2;

            for (let x = from + 14; x < to - 8; x += 34) {
                const y = terrainY(x) + 4;

                ctx.beginPath();
                ctx.moveTo(x, y);
                ctx.lineTo(x + 16, y + 2);
                ctx.stroke();
            }

            // Chevrons at the mouth: this is the direction it throws you.
            ctx.strokeStyle = ICE;
            ctx.lineWidth = 3;

            for (let i = 0; i < 3; i++) {
                const x = it.x + 16 + i * 15;

                if (x < this.cam.x - 20 || x > this.cam.x + W + 20) {
                    continue;
                }

                const y = terrainY(x) + 6;

                ctx.globalAlpha = 0.35 + 0.5 * ((Math.sin(this.t * 5 - i * 0.9) + 1) / 2);
                ctx.beginPath();
                ctx.moveTo(x, y - 5);
                ctx.lineTo(x + 7, y);
                ctx.lineTo(x, y + 5);
                ctx.stroke();
            }

            ctx.globalAlpha = 1;
        }

        for (const it of this.items) {
            if (it.gone || it.kind === 'slick' || it.x < this.cam.x - 60 || it.x > this.cam.x + W + 60) {
                continue;
            }

            if (it.kind === 'ring') {
                const bob = Math.sin(this.t * 2 + it.x * 0.01) * 3;
                const y = it.y + bob;

                ctx.globalAlpha = 0.28;
                ctx.fillStyle = GOLD;
                ctx.beginPath();
                ctx.arc(it.x, y, RING_R + 7, 0, Math.PI * 2);
                ctx.fill();
                ctx.globalAlpha = 1;

                ctx.strokeStyle = INK;
                ctx.lineWidth = 11;
                ctx.beginPath();
                ctx.arc(it.x, y, RING_R, 0, Math.PI * 2);
                ctx.stroke();

                ctx.strokeStyle = CORAL;
                ctx.lineWidth = 7;
                ctx.beginPath();
                ctx.arc(it.x, y, RING_R, 0, Math.PI * 2);
                ctx.stroke();

                // A lit arc on the upper left: the sun is up there.
                ctx.strokeStyle = 'rgba(255,255,255,0.7)';
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.arc(it.x, y, RING_R + 1, Math.PI * 1.05, Math.PI * 1.55);
                ctx.stroke();

                ctx.strokeStyle = GOLD;
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.arc(it.x, y, RING_R - 5, 0, Math.PI * 2);
                ctx.stroke();

                continue;
            }

            if (it.kind === 'mine') {
                const blink = 0.5 + Math.sin(this.t * 6 + it.x) * 0.5;

                ctx.fillStyle = INK;

                for (let i = 0; i < 8; i++) {
                    ctx.save();
                    ctx.translate(it.x, it.y);
                    ctx.rotate((i / 8) * Math.PI * 2 + 0.4);
                    ctx.beginPath();
                    ctx.moveTo(0, -MINE_R - 9);
                    ctx.lineTo(-4.5, -MINE_R + 3);
                    ctx.lineTo(4.5, -MINE_R + 3);
                    ctx.closePath();
                    ctx.fill();
                    ctx.restore();
                }

                const mg = ctx.createRadialGradient(it.x - 6, it.y - 7, 2, it.x, it.y, MINE_R);

                mg.addColorStop(0, '#37648f');
                mg.addColorStop(1, INK);
                ctx.fillStyle = mg;
                ctx.beginPath();
                ctx.arc(it.x, it.y, MINE_R, 0, Math.PI * 2);
                ctx.fill();

                ctx.strokeStyle = CORAL;
                ctx.lineWidth = 4;
                ctx.beginPath();
                ctx.arc(it.x, it.y, MINE_R, 0, Math.PI * 2);
                ctx.stroke();

                ctx.fillStyle = 'rgba(255,255,255,0.55)';
                ctx.beginPath();
                ctx.ellipse(it.x - 5, it.y - 6, 4.5, 3, -0.6, 0, Math.PI * 2);
                ctx.fill();

                ctx.globalAlpha = 0.45 + blink * 0.55;
                ctx.fillStyle = CORAL;
                ctx.beginPath();
                ctx.arc(it.x, it.y, 4.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.globalAlpha = 1;

                continue;
            }

            const bob = Math.sin(this.t * 2.4 + it.x * 0.02) * 4;

            if (it.kind === 'balloon') {
                ctx.strokeStyle = INK;
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(it.x, it.y + bob + 14);
                ctx.quadraticCurveTo(it.x + 5, it.y + bob + 22, it.x, it.y + bob + 30);
                ctx.stroke();

                const bg = ctx.createRadialGradient(it.x - 5, it.y + bob - 6, 2, it.x, it.y + bob, 16);

                bg.addColorStop(0, '#dff5ff');
                bg.addColorStop(1, '#8fd8f5');
                ctx.fillStyle = bg;
                ctx.strokeStyle = INK;
                ctx.lineWidth = 4;
                ctx.beginPath();
                ctx.ellipse(it.x, it.y + bob, 13, 15, 0, 0, Math.PI * 2);
                ctx.fill();
                ctx.stroke();

                ctx.fillStyle = INK;
                ctx.beginPath();
                ctx.moveTo(it.x - 4, it.y + bob + 14);
                ctx.lineTo(it.x + 4, it.y + bob + 14);
                ctx.lineTo(it.x, it.y + bob + 19);
                ctx.closePath();
                ctx.fill();

                continue;
            }

            // Magnet: a gold horseshoe with navy poles.
            ctx.strokeStyle = INK;
            ctx.lineWidth = 12;
            ctx.lineCap = 'butt';
            ctx.beginPath();
            ctx.arc(it.x, it.y + bob, 11, Math.PI, 0);
            ctx.stroke();

            ctx.strokeStyle = GOLD;
            ctx.lineWidth = 8;
            ctx.beginPath();
            ctx.arc(it.x, it.y + bob, 11, Math.PI, 0);
            ctx.stroke();

            ctx.strokeStyle = 'rgba(255,255,255,0.6)';
            ctx.lineWidth = 2.5;
            ctx.beginPath();
            ctx.arc(it.x, it.y + bob, 13, Math.PI * 1.15, Math.PI * 1.5);
            ctx.stroke();

            ctx.strokeStyle = INK;
            ctx.lineWidth = 8;
            ctx.beginPath();
            ctx.moveTo(it.x - 11, it.y + bob);
            ctx.lineTo(it.x - 11, it.y + bob + 9);
            ctx.moveTo(it.x + 11, it.y + bob);
            ctx.lineTo(it.x + 11, it.y + bob + 9);
            ctx.stroke();
            ctx.lineCap = 'round';
        }
    }

    drawTrail() {
        const ctx = this.ctx;

        for (const t of this.trail) {
            ctx.globalAlpha = clamp(t.life * 1.6, 0, 0.5);
            ctx.fillStyle = this.balloon > 0 ? '#8fd8f5' : GOLD;
            ctx.beginPath();
            ctx.arc(t.x, t.y, 4 * t.life * 2, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.globalAlpha = 1;
    }

    drawPuffs() {
        const ctx = this.ctx;

        for (const p of this.puffs) {
            ctx.globalAlpha = clamp(p.life * 2.4, 0, 1);
            ctx.fillStyle = ICE;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.globalAlpha = 1;
    }

    /**
     * The penguin — a chick, on purpose.
     *
     * Baby proportions do the charm: an oversized head about two thirds the
     * body, eyes far bigger than realism allows, a stubby beak, blushed
     * cheeks, and little round feet. Drawn back-to-front — tail, far flipper,
     * body, belly, near flipper, head, scarf — because flat-vector characters
     * only read as solid if the overlaps land in the right order.
     */
    drawPenguin() {
        const ctx = this.ctx;
        const speed = Math.hypot(this.vx, this.vy);
        const sweep = clamp(speed / 900, 0, 1);
        const R = PENGUIN_R;

        ctx.save();
        ctx.translate(this.x, this.y);
        ctx.rotate(this.spin);
        ctx.scale(1 + this.squash * 0.28, 1 - this.squash * 0.26);

        if (this.magnet > 0) {
            ctx.strokeStyle = 'rgba(255,209,102,0.6)';
            ctx.lineWidth = 3;

            for (let i = 0; i < 2; i++) {
                ctx.globalAlpha = 0.8 - i * 0.35;
                ctx.beginPath();
                ctx.arc(0, 0, 24 + i * 9 + Math.sin(this.t * 8 - i) * 3, 0, Math.PI * 2);
                ctx.stroke();
            }

            ctx.globalAlpha = 1;
        }

        const body = ctx.createLinearGradient(-R, -R, R * 0.8, R);

        body.addColorStop(0, '#2b5580');
        body.addColorStop(0.55, INK);
        body.addColorStop(1, '#0a2038');

        // Tail nub.
        ctx.fillStyle = INK;
        ctx.beginPath();
        ctx.moveTo(-R + 3, 0);
        ctx.lineTo(-R - 9, 6);
        ctx.lineTo(-R + 3, 8);
        ctx.closePath();
        ctx.fill();

        // Far flipper.
        ctx.save();
        ctx.rotate(0.8 + sweep * 0.35);
        ctx.fillStyle = '#0a2038';
        ctx.beginPath();
        ctx.ellipse(-6, 9, 10, 4.5, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        // Feet: two little rounded paddles tucked back.
        ctx.fillStyle = BEAK;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 2.5;

        for (const fy of [4, 9.5]) {
            ctx.beginPath();
            ctx.ellipse(-8, fy + 3, 7, 3.4, 0.3, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        }

        // Body: a plump teardrop, widest low down.
        ctx.fillStyle = body;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 5;
        ctx.beginPath();
        ctx.ellipse(-1, 2, R + 1, R - 1, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = ICE;
        ctx.beginPath();
        ctx.ellipse(0.5, 4.5, 8.5, 8.5, 0, 0, Math.PI * 2);
        ctx.fill();

        ctx.fillStyle = '#e8f5fd';
        ctx.beginPath();
        ctx.ellipse(2, 8, 5.5, 4.2, 0, 0, Math.PI * 2);
        ctx.fill();

        // Near flipper, swept back by speed.
        ctx.save();
        ctx.rotate(0.45 + sweep * 0.5);
        ctx.fillStyle = '#1b3f63';
        ctx.strokeStyle = INK;
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.ellipse(-7, 9, 11, 4.5, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.restore();

        // Scarf tail, streaming behind — the cheapest speedometer there is.
        const wag = Math.sin(this.t * 12) * (2 + sweep * 5);

        ctx.fillStyle = CORAL;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.moveTo(-2, -5);
        ctx.quadraticCurveTo(-15 - sweep * 12, -10 + wag, -27 - sweep * 20, -3 + wag * 1.4);
        ctx.quadraticCurveTo(-15 - sweep * 12, -2 + wag, -2, 3);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        // Head: big, round, tipped forward a little.
        ctx.fillStyle = body;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 4.5;
        ctx.beginPath();
        ctx.arc(6, -9, 10.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        // Face patch.
        ctx.fillStyle = ICE;
        ctx.beginPath();
        ctx.ellipse(9, -7.5, 7, 6.5, -0.15, 0, Math.PI * 2);
        ctx.fill();

        // Scarf knot over the neck seam.
        ctx.fillStyle = CORAL;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.roundRect(-3, -4, 13, 7, 3.5);
        ctx.fill();
        ctx.stroke();

        // Beak: short and soft, not a dart.
        ctx.fillStyle = BEAK;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.moveTo(14, -9);
        ctx.quadraticCurveTo(23, -6.5, 14, -3.5);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        // Eyes: big, with a highlight and a blink every few seconds.
        const blink = Math.sin(this.t * 1.1) > 0.985;

        for (const e of [[8.5, -12.5, 4.6], [14, -12, 3.4]]) {
            ctx.fillStyle = ICE;
            ctx.strokeStyle = INK;
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(e[0], e[1], e[2], 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();

            if (blink) {
                ctx.strokeStyle = INK;
                ctx.lineWidth = 2.2;
                ctx.beginPath();
                ctx.moveTo(e[0] - e[2], e[1]);
                ctx.lineTo(e[0] + e[2], e[1]);
                ctx.stroke();

                continue;
            }

            ctx.fillStyle = INK;
            ctx.beginPath();
            ctx.arc(e[0] + 0.8, e[1] + 0.4, e[2] * 0.55, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = ICE;
            ctx.beginPath();
            ctx.arc(e[0] + 1.8, e[1] - 1.4, e[2] * 0.24, 0, Math.PI * 2);
            ctx.fill();
        }

        // Blushed cheek.
        ctx.globalAlpha = 0.75;
        ctx.fillStyle = BLUSH;
        ctx.beginPath();
        ctx.ellipse(11.5, -5.5, 3.2, 2.2, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = 1;

        // Head tuft, because a chick needs one.
        ctx.strokeStyle = INK;
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(3, -18);
        ctx.quadraticCurveTo(1, -25 - sweep * 3, 6, -24 - sweep * 2);
        ctx.stroke();

        if (this.balloon > 0) {
            ctx.strokeStyle = INK;
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(0, -R - 8);
            ctx.lineTo(0, -R - 20);
            ctx.stroke();

            const g2 = ctx.createRadialGradient(-5, -R - 40, 2, 0, -R - 34, 17);

            g2.addColorStop(0, '#dff5ff');
            g2.addColorStop(1, '#8fd8f5');
            ctx.fillStyle = g2;
            ctx.lineWidth = 4;
            ctx.beginPath();
            ctx.ellipse(0, -R - 34, 13, 15, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        }

        ctx.restore();
    }

    /** HUD on cards, because thin type over bright ice is unreadable. */
    drawHud() {
        const ctx = this.ctx;

        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = 'rgba(255,255,255,0.86)';
        ctx.strokeStyle = INK;
        ctx.lineWidth = 3.5;
        ctx.beginPath();
        ctx.roundRect(13, 13, 130, 58, 14);
        ctx.fill();
        ctx.stroke();

        ctx.textAlign = 'left';
        ctx.font = '600 9px Outfit, system-ui, sans-serif';
        ctx.fillStyle = 'rgba(13,43,69,0.6)';
        ctx.fillText('DISTANCE', 26, 32);

        ctx.font = '600 30px Fredoka, "Baloo 2", system-ui, sans-serif';
        ctx.fillStyle = INK;
        ctx.fillText(String(this.metres), 26, 60);

        const wm = ctx.measureText(String(this.metres)).width;

        ctx.font = '600 14px Fredoka, "Baloo 2", system-ui, sans-serif';
        ctx.fillText('m', 28 + wm, 60);

        ctx.font = '600 10px Outfit, system-ui, sans-serif';

        const label = MILESTONES[this.milestone][1].toUpperCase();
        const lw = ctx.measureText(label).width;

        ctx.fillStyle = CORAL;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.roundRect(13, 77, lw + 22, 20, 10);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = ICE;
        ctx.fillText(label, 24, 91);

        if (this.best > 0) {
            const bt = 'BEST ' + this.best + 'm';
            const bw = ctx.measureText(bt).width;

            ctx.fillStyle = GOLD;
            ctx.strokeStyle = INK;
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.roundRect(W - bw - 35, 13, bw + 22, 20, 10);
            ctx.fill();
            ctx.stroke();

            ctx.fillStyle = INK;
            ctx.fillText(bt, W - bw - 24, 27);
        }

        let py = 46;

        for (const p of [[this.balloon, BALLOON_TIME, 'FLOAT', '#8fd8f5'], [this.magnet, MAGNET_TIME, 'MAGNET', GOLD]]) {
            if (p[0] <= 0) {
                continue;
            }

            ctx.fillStyle = 'rgba(255,255,255,0.86)';
            ctx.strokeStyle = INK;
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.roundRect(W - 105, py, 92, 30, 12);
            ctx.fill();
            ctx.stroke();

            ctx.font = '600 9px Outfit, system-ui, sans-serif';
            ctx.fillStyle = INK;
            ctx.fillText(p[2], W - 95, py + 13);

            ctx.fillStyle = '#dff0fb';
            ctx.beginPath();
            ctx.roundRect(W - 95, py + 18, 72, 6, 3);
            ctx.fill();

            ctx.fillStyle = p[3];
            ctx.beginPath();
            ctx.roundRect(W - 95, py + 18, 72 * (p[0] / p[1]), 6, 3);
            ctx.fill();

            py += 38;
        }

        if (this.flash) {
            ctx.textAlign = 'center';
            ctx.globalAlpha = clamp(this.flash.t * 2.4, 0, 1);
            ctx.font = '600 26px Fredoka, "Baloo 2", system-ui, sans-serif';
            ctx.lineWidth = 6;
            ctx.strokeStyle = ICE;
            ctx.strokeText(this.flash.text, W / 2, 140);
            ctx.fillStyle = CORAL;
            ctx.fillText(this.flash.text, W / 2, 140);
            ctx.globalAlpha = 1;
        }
    }

    /** Charge UI: a meter, and a dotted preview of the arc you have bought. */
    drawCharge() {
        const ctx = this.ctx;

        if (this.power > 0.02) {
            const len = Math.hypot(this.pull.x, this.pull.y) || 1;
            const speed = SPEED_MIN + this.power * (SPEED_MAX - SPEED_MIN);
            let px = this.x - this.cam.x;
            let py = this.y - this.cam.y;
            let vx = (-this.pull.x / len) * speed;
            let vy = (-this.pull.y / len) * speed;

            for (let i = 0; i < 30; i++) {
                for (let k = 0; k < 3; k++) {
                    vy += GRAVITY * 0.016;
                    px += vx * 0.016;
                    py += vy * 0.016;
                }

                if (i % 2 === 0) {
                    ctx.globalAlpha = clamp(1 - i / 30, 0.15, 0.55);
                    ctx.fillStyle = INK;
                    ctx.beginPath();
                    ctx.arc(px, py, 2.8, 0, Math.PI * 2);
                    ctx.fill();
                }
            }

            ctx.globalAlpha = 1;
        }

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.font = '600 9px Outfit, system-ui, sans-serif';
        ctx.fillStyle = 'rgba(13,43,69,0.62)';
        ctx.fillText('PULL BACK', 26, H - 58);

        ctx.fillStyle = ICE;
        ctx.strokeStyle = INK;
        ctx.lineWidth = 4;
        ctx.beginPath();
        ctx.roundRect(24, H - 50, 150, 16, 8);
        ctx.fill();
        ctx.stroke();

        if (this.power > 0.01) {
            const g = ctx.createLinearGradient(26, 0, 172, 0);

            g.addColorStop(0, GOLD);
            g.addColorStop(1, CORAL);
            ctx.fillStyle = g;
            ctx.beginPath();
            ctx.roundRect(26, H - 48, 146 * this.power, 12, 6);
            ctx.fill();
        }

        if (this.phase === 'title') {
            ctx.textAlign = 'center';
            // TWO LINES EDITED FROM THE BUNDLE. The game ships as "Penguin
            // Launch" and is called Westin's Whacky Game here, so this is the
            // one user-facing string that has to change — it is the largest
            // type on the start screen. 26px rather than the bundle's 30px
            // because the longer name overruns the 320px board at 30.
            // Re-apply both when replacing the file; ArcadePenguinTest fails
            // if either goes missing.
            ctx.font = '600 26px Fredoka, "Baloo 2", system-ui, sans-serif';
            ctx.lineWidth = 6;
            ctx.strokeStyle = 'rgba(255,255,255,0.9)';
            ctx.strokeText("Westin's Whacky Game", W / 2, 176);
            ctx.fillStyle = INK;
            ctx.fillText("Westin's Whacky Game", W / 2, 176);

            ctx.font = '600 13px Outfit, system-ui, sans-serif';
            ctx.lineWidth = 5;
            ctx.strokeText('Drag the penguin back. Let go.', W / 2, 202);
            ctx.fillStyle = 'rgba(13,43,69,0.7)';
            ctx.fillText('Drag the penguin back. Let go.', W / 2, 202);

            const pulse = 0.55 + Math.sin(performance.now() / 320) * 0.45;

            ctx.globalAlpha = pulse;
            ctx.font = '600 14px Fredoka, "Baloo 2", system-ui, sans-serif';
            ctx.lineWidth = 5;
            ctx.strokeText('No buttons once you fly \u2014 aim well', W / 2, 228);
            ctx.fillStyle = CORAL;
            ctx.fillText('No buttons once you fly \u2014 aim well', W / 2, 228);
            ctx.globalAlpha = 1;
        }
    }

    drawOver() {
        const ctx = this.ctx;

        ctx.fillStyle = 'rgba(226,245,255,0.9)';
        ctx.fillRect(0, 0, W, H);

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.font = '600 22px Fredoka, "Baloo 2", system-ui, sans-serif';
        ctx.fillStyle = CORAL;
        ctx.fillText('Slid to a stop', W / 2, H / 2 - 104);

        ctx.font = '600 66px Fredoka, "Baloo 2", system-ui, sans-serif';
        ctx.fillStyle = INK;
        ctx.fillText(this.metres + 'm', W / 2, H / 2 - 44);

        ctx.font = '600 15px Outfit, system-ui, sans-serif';
        ctx.fillText(MILESTONES[this.milestone][1], W / 2, H / 2 + 6);

        ctx.font = '600 11px Outfit, system-ui, sans-serif';
        ctx.fillStyle = 'rgba(13,43,69,0.62)';
        ctx.fillText(this.rings + ' RINGS \u00b7 ' + this.mines + ' MINES', W / 2, H / 2 + 44);

        if (this.best > this.metres) {
            ctx.fillText('BEST ' + this.best + 'm', W / 2, H / 2 + 66);
        } else {
            ctx.fillStyle = CORAL;
            ctx.fillText('NEW BEST', W / 2, H / 2 + 66);
        }

        const pulse = 0.55 + Math.sin(performance.now() / 320) * 0.45;

        ctx.globalAlpha = pulse;
        ctx.font = '600 15px Fredoka, "Baloo 2", system-ui, sans-serif';
        ctx.fillStyle = INK;
        ctx.fillText('TAP TO LAUNCH AGAIN', W / 2, H / 2 + 116);
        ctx.globalAlpha = 1;
    }
}

/* ------------------------------------------------------------------ *
 * The element
 * ------------------------------------------------------------------ */

class PenguinLaunchElement extends HTMLElement {
    connectedCallback() {
        if (this.game) {
            return;
        }

        this.style.display = 'block';
        this.style.position = 'relative';

        const wrap = document.createElement('div');

        wrap.style.cssText = 'width:100%;display:flex;flex-direction:column;gap:10px;align-items:center;';

        const canvas = document.createElement('canvas');

        // draw() scales by width alone, so the box has to keep 320:470.
        canvas.style.cssText = 'width:100%;aspect-ratio:320 / 470;height:auto;display:block;'
            + 'border-radius:18px;background:#9adcfb;touch-action:none;cursor:grab;';

        wrap.appendChild(canvas);
        this.appendChild(wrap);

        this.game = new PenguinLaunch(canvas);
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
            window.removeEventListener('keyup', this.onKeyUp);
            this.onKey = null;
        }
    }

    /** A hold-to-charge button, so the game plays without a drag at all. */
    buildPad() {
        const pad = document.createElement('div');

        pad.style.cssText = 'flex:none;display:flex;gap:6px;justify-content:center;width:100%;';

        const b = document.createElement('button');

        b.type = 'button';
        b.textContent = 'Hold to charge';
        b.style.cssText = 'height:44px;flex:1;max-width:224px;border:3px solid #0d2b45;'
            + 'background:#ffd166;color:#0d2b45;border-radius:13px;'
            + 'font:600 15px Fredoka,"Baloo 2",system-ui,sans-serif;cursor:pointer;touch-action:none;';

        b.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            this.game.hold(true);
        });
        b.addEventListener('pointerup', () => this.game.hold(false));
        b.addEventListener('pointerleave', () => this.game.hold(false));
        pad.appendChild(b);

        return pad;
    }

    wire(canvas) {
        canvas.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            this.game.down(e);
        });

        canvas.addEventListener('pointermove', (e) => this.game.move(e));
        canvas.addEventListener('pointerup', () => this.game.up());
        canvas.addEventListener('pointercancel', () => this.game.up());

        this.onKey = (e) => {
            if (e.key !== ' ' && e.key !== 'ArrowUp' && e.key !== 'w') {
                return;
            }

            // Only swallow the key when this cabinet is actually on screen.
            if (!this.isConnected || !this.offsetParent || e.repeat) {
                return;
            }

            e.preventDefault();
            this.game.hold(true);
        };

        this.onKeyUp = (e) => {
            if (e.key !== ' ' && e.key !== 'ArrowUp' && e.key !== 'w') {
                return;
            }

            this.game.hold(false);
        };

        window.addEventListener('keydown', this.onKey);
        window.addEventListener('keyup', this.onKeyUp);
    }
}

if (!customElements.get('penguin-launch')) {
    customElements.define('penguin-launch', PenguinLaunchElement);
}
