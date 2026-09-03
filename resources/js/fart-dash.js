/*
 * Bean Dash — the arcade's second cabinet.
 *
 * A dog crosses an endless run of lanes: roads with traffic, water it can't
 * swim. Every hop is powered by a fart, which is decoration — until it isn't.
 * Tins of beans sit on the safe lanes, and a tin buys one SUPER hop: three
 * lanes in one go, with a blast that shoves everything in the landing zone out
 * of the way. That is the whole game. The gas is a joke you can spend.
 *
 * Written to the same three rules as `resources/js/arcade.js`, so the two
 * cabinets behave like siblings rather than cousins:
 *
 * 1. Everything is drawn in a fixed 320x460 coordinate system and scaled to
 *    whatever the element is actually sized at, so physics is identical on a
 *    phone and a desktop. Both play the same board.
 *
 * 2. The milestone ladder belongs to PHP (`ArcadeService`), not here — the
 *    leaderboard has to label a score with the same words the banner does.
 *    MILESTONES below is the design's copy of it and `SCENES` is keyed to it
 *    by index, exactly as arcade.js keys its scenery.
 *
 * 3. Sound is synthesised rather than loaded, and reads the same `fq-muted`
 *    key the arcade's speaker button writes.
 *
 * Registers <fart-dash>. Self-contained: builds its own canvas and its own
 * d-pad, so the page around it only has to give it a box.
 */

const W = 320;
const H = 460;

/** One lane. Also the hop distance, and the height of every vehicle. */
const LANE_H = 44;

/** Screen y of world y=0 with the camera on the ground. */
const FOOT = 372;

/** How far ahead of the dog the camera settles. */
const LEAD = 62;

const DOG_W = 26;
const SIDE = 14;

/** Lanes a super hop covers, and how far past it the blast reaches. */
const SUPER_LANES = 3;
const BLAST_LANES = 4;
const MAX_CHARGE = 3;

/** How long a biome lasts before the scenery changes. */
const BIOME_LANES = 14;

const rnd = (s) => {
    const x = Math.sin(s * 127.1) * 43758.5453;

    return x - Math.floor(x);
};

const pick = (list, seed) => list[Math.floor(rnd(seed) * list.length) % list.length];

const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);

function shade(hex, k) {
    const n = parseInt(hex.slice(1), 16);

    return 'rgb(' + Math.round(((n >> 16) & 255) * k) + ',' +
        Math.round(((n >> 8) & 255) * k) + ',' +
        Math.round((n & 255) * k) + ')';
}

/** Rounded rect by hand, so the game doesn't stop drawing on an older tablet. */
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
 * The three places it goes
 * ------------------------------------------------------------------ */

/*
 * A biome is a palette and a cast, not a ruleset — every one of them has safe
 * ground, a lane with something deadly moving along it, and a lane you can only
 * cross by riding. Keeping the rules identical is what lets the scenery change
 * every fourteen lanes without teaching the kid a new game each time.
 *
 * Water is the SAME blue in all three. It was tinted per biome — brown for the
 * farmyard, grey-blue for the shop — which looked better and read worse: the
 * one lane that kills you for standing on it has to be the lane you can name at
 * a glance, before you have read anything else on screen.
 */
const WATER = '#1d5fa8';

const BIOMES = [
    {
        name: 'roadside',
        safe: '#1d3a24',
        safeAlt: '#24472c',
        road: '#241f2e',
        water: WATER,
        vehicle: 'car',
        float: 'log',
    },
    {
        name: 'farm',
        safe: '#3a3018',
        safeAlt: '#463a1d',
        road: '#2e2519',
        water: WATER,
        vehicle: 'tractor',
        float: 'plank',
    },
    {
        name: 'shop',
        safe: '#2a2438',
        safeAlt: '#322b42',
        road: '#211c2e',
        water: WATER,
        vehicle: 'trolley',
        float: 'box',
    },
];

const biomeAt = (lane) => BIOMES[Math.floor(Math.max(0, lane) / BIOME_LANES) % BIOMES.length];

const CAR_COLS = ['#e0365b', '#2f8fd6', '#3fae6a', '#a06bff', '#ff8a3d', '#ffd23d'];

/**
 * The ladder, and the design's copy of `ArcadeService::MILESTONES`.
 *
 * Named for places rather than numbers for the same reason the tower's are: a
 * number tells a six-year-old nothing, and "past the tractors" tells them
 * exactly how far they got.
 *
 * @type {Array<[number, string]>}
 */
const MILESTONES = [
    [0, 'Off the kerb'],
    [4, 'Across the road'],
    [8, 'Over the water'],
    [14, 'Down the lane'],
    [20, 'In the farmyard'],
    [27, 'Past the tractors'],
    [34, 'Through the doors'],
    [42, 'Frozen aisle'],
    [50, 'Out the back'],
    [60, 'Open country'],
    [72, 'Long gone'],
    [90, 'Legendary guff'],
];

/* ------------------------------------------------------------------ *
 * Sound
 * ------------------------------------------------------------------ */

/*
 * The fart is the reason this file has an audio section at all, and it is
 * synthesised for the same reason the tower's thud is: the login page stays one
 * request. Filtered noise plus a falling saw — the noise is the air, the saw is
 * the pitch, and the wobble on the way down is what makes it funny rather than
 * just rude.
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

    fart(dur, vol, hi, lo) {
        const ac = this.open();

        if (!ac) {
            return;
        }

        const t0 = ac.currentTime;
        const len = Math.max(1, Math.floor(ac.sampleRate * dur));
        const buf = ac.createBuffer(1, len, ac.sampleRate);
        const data = buf.getChannelData(0);

        for (let i = 0; i < len; i++) {
            data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 0.8);
        }

        const src = ac.createBufferSource();
        const band = ac.createBiquadFilter();
        const noiseGain = ac.createGain();

        src.buffer = buf;
        band.type = 'lowpass';
        band.frequency.setValueAtTime(hi, t0);
        band.frequency.exponentialRampToValueAtTime(lo, t0 + dur);
        band.Q.value = 7;
        noiseGain.gain.setValueAtTime(vol, t0);
        noiseGain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);

        src.connect(band);
        band.connect(noiseGain);
        noiseGain.connect(ac.destination);
        src.start();

        const saw = ac.createOscillator();
        const sawGain = ac.createGain();

        saw.type = 'sawtooth';
        saw.frequency.setValueAtTime(hi * 0.55, t0);
        saw.frequency.exponentialRampToValueAtTime(Math.max(28, lo * 0.6), t0 + dur);

        // The wobble. Without it this is a raspberry; with it, it's a fart.
        const wob = ac.createOscillator();
        const wobDepth = ac.createGain();

        wob.type = 'sine';
        wob.frequency.value = 17 + Math.random() * 13;
        wobDepth.gain.value = hi * 0.16;
        wob.connect(wobDepth);
        wobDepth.connect(saw.frequency);

        sawGain.gain.setValueAtTime(vol * 0.5, t0);
        sawGain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);

        saw.connect(sawGain);
        sawGain.connect(ac.destination);
        saw.start();
        wob.start();
        saw.stop(t0 + dur);
        wob.stop(t0 + dur);
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

    splat(dur, vol, cutoff) {
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

class BeanDash {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.scale = 1;
        this.raf = null;
        this.last = 0;
        this.lanes = new Map();
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
        this.score = 0;
        this.maxLane = 0;
        this.charge = 0;
        this.milestone = 0;
        this.cam = 0;
        this.shake = 0;
        this.banner = null;
        this.puffs = [];
        this.effects = [];
        this.lanes.clear();

        this.dog = {
            lane: 0,
            x: W / 2,
            face: 0,
            hop: 0,
            from: { lane: 0, x: W / 2 },
            to: { lane: 0, x: W / 2 },
            super: false,
            dead: null,
        };
    }

    start() {
        this.reset();
        this.phase = 'playing';
        this.emit('fd-score', { score: 0, altitude: MILESTONES[0][1], charge: 0 });
    }

    emit(name, detail) {
        this.canvas.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
    }

    /* -------------------------------------------------------------- *
     * The board
     * -------------------------------------------------------------- */

    /**
     * The lane at index n, built the first time anybody asks for it.
     *
     * Generated forward rather than up front because the run is endless, and
     * kept in a Map rather than an array so the first few lanes behind the dog
     * can stay addressable without the index arithmetic going negative.
     */
    lane(n) {
        if (this.lanes.has(n)) {
            return this.lanes.get(n);
        }

        const biome = biomeAt(n);
        const built = { kind: 'safe', biome, items: [], bean: null, seed: n * 3.7 + 1.3 };

        // Three safe lanes to stand on before anything can kill you, and the
        // three after a biome change are safe too — a kid should never meet new
        // scenery and new traffic in the same hop.
        const settling = n < 3 || n % BIOME_LANES < 1;

        if (!settling) {
            const roll = rnd(n * 9.17 + 0.31);
            const prev = this.lanes.get(n - 1);
            const twoBack = this.lanes.get(n - 2);
            const streak = prev && prev.kind !== 'safe' && twoBack && twoBack.kind !== 'safe';

            // Never three deadly lanes in a row: with the camera where it is, a
            // fourth would be off the top of the screen when you commit to the
            // first, which is a guess rather than a decision.
            built.kind = streak ? 'safe' : roll < 0.46 ? 'road' : roll < 0.72 ? 'water' : 'safe';
        }

        if (built.kind === 'safe') {
            // Beans sit on safe ground on purpose. A pickup you have to stand
            // in traffic for is a dare; one on the grass is a detour, and a
            // detour is the decision worth having.
            if (n > 2 && rnd(n * 4.41 + 2.7) < 0.3) {
                built.bean = { x: 40 + rnd(n * 7.7) * (W - 80), got: false, bob: rnd(n) * 6.28 };
            }

            this.lanes.set(n, built);

            return built;
        }

        const dir = rnd(n * 2.13 + 5.9) < 0.5 ? -1 : 1;
        const fast = built.kind === 'road';

        built.dir = dir;
        built.speed = (fast ? 42 + rnd(n * 3.3) * 46 : 26 + rnd(n * 3.3) * 22)
            // Everything speeds up with distance, and stops speeding up before
            // it becomes a reflex test rather than a game.
            + Math.min(46, this.maxLane * 0.7);

        const count = fast ? 2 + Math.floor(rnd(n * 6.1) * 2) : 2 + Math.floor(rnd(n * 6.1) * 2);
        const gap = (W + 190) / count;

        for (let i = 0; i < count; i++) {
            const seed = n * 13.1 + i * 5.3;
            const wide = fast
                ? (built.biome.vehicle === 'tractor' ? 52 : 40 + rnd(seed) * 24)
                : 62 + rnd(seed) * 40;

            built.items.push({
                x: -90 + i * gap + rnd(seed + 1.1) * (gap * 0.4),
                w: wide,
                seed,
                col: pick(CAR_COLS, seed),
                push: 0,
            });
        }

        this.lanes.set(n, built);

        return built;
    }

    /* -------------------------------------------------------------- *
     * Input
     * -------------------------------------------------------------- */

    tap() {
        if (this.phase === 'idle') {
            this.start();

            return;
        }

        if (this.phase === 'over') {
            // A beat of dead air after a squash, so the last thing that happened
            // registers before the next run starts under their thumb.
            if (this.dog.dead && this.dog.dead.t > 0.7) {
                this.start();
            }

            return;
        }

        this.move(0, 1);
    }

    /** dx/dy in lanes. dy of 1 is forward. */
    move(dx, dy) {
        if (this.phase !== 'playing' || this.dog.hop > 0) {
            return;
        }

        const dog = this.dog;

        if (dx !== 0) {
            const to = clamp(dog.x + dx * LANE_H, SIDE + DOG_W / 2, W - SIDE - DOG_W / 2);

            if (Math.abs(to - dog.x) < 1) {
                return;
            }

            this.launch(dog.lane, to, dx > 0 ? 1 : 3, false);

            return;
        }

        if (dy < 0) {
            const lane = dog.lane - 1;

            // No going back off the bottom of the screen — there is nothing
            // chasing, so the edge is the only thing that has to say no.
            if (lane < 0 || this.laneScreenY(lane) > 436) {
                return;
            }

            this.launch(lane, dog.x, 2, false);

            return;
        }

        const superHop = this.charge > 0;
        const lane = dog.lane + (superHop ? SUPER_LANES : 1);

        if (superHop) {
            this.charge -= 1;
        }

        this.launch(lane, dog.x, 0, superHop);
    }

    launch(lane, x, face, superHop) {
        const dog = this.dog;

        dog.from = { lane: dog.lane, x: dog.x };
        dog.to = { lane, x };
        dog.face = face;
        dog.hop = 1;
        dog.super = superHop;

        // Every lane the hop passes over, plus one, so a super lands in a gap
        // it made rather than on the bonnet of the car it just shoved.
        for (let n = dog.from.lane; n <= lane + (superHop ? 1 : 0); n++) {
            this.lane(n);
        }

        if (superHop) {
            this.blast(lane, x);
            Sfx.fart(0.5, 0.3, 620, 44);
            this.effects.push({ kind: 'ring', x, y: 0, lane, t: 0, life: 0.55 });
        } else {
            Sfx.fart(0.17, 0.15, 420 + Math.random() * 180, 70);
        }

        this.puff(dog.from.lane, dog.from.x, superHop);
    }

    /**
     * The blast. Shoves traffic near the landing zone away from the dog's line
     * and leaves it wobbling.
     *
     * **Roads only.** Blowing the logs away took away the thing you land on,
     * so a super fart over water drowned you — the reward killed you for using
     * it. Deliberately does not make anything harmless either: a shoved car is
     * still a car. It buys a gap you have to use.
     */
    blast(lane, x) {
        for (let n = Math.max(0, lane - BLAST_LANES); n <= lane + BLAST_LANES; n++) {
            const row = this.lanes.get(n);

            if (!row || row.kind !== 'road') {
                continue;
            }

            const near = 1 - Math.min(1, Math.abs(n - lane) / (BLAST_LANES + 1));

            for (const item of row.items) {
                const away = item.x + item.w / 2 < x ? -1 : 1;

                item.push = away * (150 + near * 210);
            }
        }

        this.shake = 11;
    }

    puff(lane, x, big) {
        const n = big ? 16 : 6;

        for (let i = 0; i < n; i++) {
            const spread = big ? 2.6 : 1.5;

            this.puffs.push({
                lane,
                x: x + (Math.random() - 0.5) * 12,
                off: 6 + Math.random() * 10,
                vx: (Math.random() - 0.5) * 40 * spread,
                vy: (18 + Math.random() * 46) * (big ? 1.5 : 1),
                r: (big ? 7 : 4) + Math.random() * (big ? 9 : 4),
                t: 0,
                life: (big ? 0.95 : 0.5) + Math.random() * 0.3,
                big,
            });
        }
    }

    /* -------------------------------------------------------------- *
     * Geometry
     * -------------------------------------------------------------- */

    laneWorldY(n) {
        return n * LANE_H;
    }

    laneScreenY(n) {
        return FOOT - (this.laneWorldY(n) - this.cam);
    }

    /* -------------------------------------------------------------- *
     * Frame
     * -------------------------------------------------------------- */

    frame(now) {
        this.raf = requestAnimationFrame((t) => this.frame(t));

        // Clamped so a backgrounded tab doesn't resume with one enormous step
        // that teleports a car straight through the dog.
        const dt = Math.min(0.05, (now - this.last) / 1000);
        this.last = now;

        this.update(dt);
        this.draw();
    }

    update(dt) {
        const dog = this.dog;

        if (dog.hop > 0) {
            dog.hop = Math.max(0, dog.hop - dt * (dog.super ? 4.4 : 8.5));

            const k = 1 - dog.hop;

            dog.lane = dog.from.lane + (dog.to.lane - dog.from.lane) * k;
            dog.x = dog.from.x + (dog.to.x - dog.from.x) * k;

            if (dog.hop === 0) {
                dog.lane = dog.to.lane;
                dog.x = dog.to.x;
                this.land();
            }
        }

        const here = Math.round(dog.lane);

        // Build a screen and a half ahead, so a lane is never generated in the
        // frame it first becomes visible.
        for (let n = Math.max(0, here - 2); n < here + 14; n++) {
            this.lane(n);
        }

        const from = Math.max(0, here - 3);

        for (let n = from; n < here + 14; n++) {
            const row = this.lanes.get(n);

            if (!row || row.kind === 'safe') {
                continue;
            }

            for (const item of row.items) {
                item.x += (row.dir * row.speed + item.push) * dt;

                if (item.push !== 0) {
                    item.push -= item.push * Math.min(1, dt * 3.4);

                    if (Math.abs(item.push) < 3) {
                        item.push = 0;
                    }
                }

                if (row.dir > 0 && item.x > W + 100) {
                    item.x = -item.w - 60 - Math.random() * 70;
                } else if (row.dir < 0 && item.x + item.w < -100) {
                    item.x = W + 60 + Math.random() * 70;
                }
            }
        }

        if (this.phase === 'playing' && dog.hop === 0) {
            this.ride(dt);
            this.checkDeath();
        }

        const target = this.phase === 'idle'
            ? 0
            : Math.max(0, this.laneWorldY(this.maxLane) - LEAD);

        this.cam += (target - this.cam) * Math.min(1, dt * 6);

        this.shake = Math.max(0, this.shake - dt * 34);

        this.puffs = this.puffs.filter((p) => {
            p.t += dt;
            p.x += p.vx * dt;
            p.off += p.vy * dt;
            p.vy -= p.vy * Math.min(1, dt * 1.5);
            p.r += dt * (p.big ? 26 : 12);

            return p.t < p.life;
        });

        this.effects = this.effects.filter((e) => {
            e.t += dt;

            return e.t < e.life;
        });

        if (this.banner) {
            this.banner.t += dt;

            if (this.banner.t > 1.9) {
                this.banner = null;
            }
        }

        if (dog.dead) {
            dog.dead.t += dt;
        }
    }

    /** Carried along by whatever it is standing on. */
    ride(dt) {
        const row = this.lanes.get(this.dog.lane);

        if (!row || row.kind !== 'water') {
            return;
        }

        const float = this.floatUnder(row);

        if (float) {
            this.dog.x += (row.dir * row.speed + float.push) * dt;
        }
    }

    floatUnder(row) {
        const x = this.dog.x;

        return row.items.find((item) => x > item.x + 4 && x < item.x + item.w - 4) || null;
    }

    land() {
        const dog = this.dog;
        const row = this.lane(dog.lane);

        if (dog.lane > this.maxLane) {
            this.maxLane = dog.lane;
            this.score = dog.lane;
            this.checkMilestone();
        }

        if (row.bean && !row.bean.got && Math.abs(row.bean.x - dog.x) < 24) {
            row.bean.got = true;

            if (this.charge < MAX_CHARGE) {
                this.charge += 1;
            }

            Sfx.blip(430, 0.14, 0.07, 940);
            this.effects.push({
                kind: 'text',
                lane: dog.lane,
                x: dog.x,
                t: 0,
                life: 0.95,
                text: this.charge >= MAX_CHARGE ? 'FULL TANK' : 'BEANS!',
            });
        }

        this.emit('fd-score', {
            score: this.score,
            altitude: MILESTONES[this.milestone][1],
            charge: this.charge,
        });
    }

    checkMilestone() {
        let reached = this.milestone;

        for (let i = 0; i < MILESTONES.length; i++) {
            if (this.score >= MILESTONES[i][0]) {
                reached = i;
            }
        }

        if (reached > this.milestone) {
            this.milestone = reached;
            this.banner = { text: MILESTONES[reached][1], t: 0 };
            Sfx.blip(300, 0.45, 0.07, 880);
        }
    }

    checkDeath() {
        const dog = this.dog;
        const row = this.lanes.get(dog.lane);

        if (!row) {
            return;
        }

        if (dog.x < SIDE - 4 || dog.x > W - SIDE + 4) {
            this.die('carried off');

            return;
        }

        if (row.kind === 'water') {
            if (!this.floatUnder(row)) {
                this.die('in the drink');
            }

            return;
        }

        if (row.kind !== 'road') {
            return;
        }

        for (const item of row.items) {
            if (dog.x > item.x - DOG_W * 0.4 && dog.x < item.x + item.w + DOG_W * 0.4) {
                this.die('squashed');

                return;
            }
        }
    }

    die(how) {
        this.phase = 'over';
        this.dog.dead = { how, t: 0 };
        this.shake = 14;

        if (how === 'in the drink') {
            Sfx.splat(0.4, 0.2, 700);
            Sfx.blip(340, 0.4, 0.07, 90);
        } else {
            Sfx.splat(0.22, 0.26, 1100);
            // The last word on a squashed dog is one more fart. It is the joke
            // the whole game is built on and this is the moment it has to land.
            Sfx.fart(0.6, 0.22, 300, 40);
        }

        this.puff(Math.round(this.dog.lane), this.dog.x, true);
        this.emit('fd-over', { score: this.score, how });
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

        ctx.save();

        if (this.shake > 0) {
            ctx.translate(
                (Math.random() - 0.5) * this.shake * 0.4,
                (Math.random() - 0.5) * this.shake * 0.3,
            );
        }

        this.drawLanes(ctx);
        this.drawPuffs(ctx, false);

        // The dog is skipped here on the title screen and drawn again after the
        // overlay instead — at 0.68 alpha over the top of it, it was dimmed to
        // nearly nothing on the one screen where it is the only thing to look at.
        if (this.phase === 'playing' || (this.dog.dead && this.dog.dead.t < 0.35)) {
            this.drawDog(ctx);
        }

        this.drawPuffs(ctx, true);
        this.drawEffects(ctx);
        ctx.restore();

        this.drawHud(ctx);
        this.drawBanner(ctx);
        this.drawOverlay(ctx);

        if (this.phase === 'idle') {
            this.drawDog(ctx);
        }
    }

    drawLanes(ctx) {
        // Ground under everything. Lane 0 sits at the bottom of the board and
        // nothing is drawn below it, so without this the strip under the kerb
        // is bare canvas.
        ctx.fillStyle = biomeAt(0).safe;
        ctx.fillRect(0, 0, W, H);

        const first = Math.floor((this.cam - 100) / LANE_H);
        const last = Math.ceil((this.cam + FOOT + LANE_H) / LANE_H);

        for (let n = Math.max(0, first); n <= last; n++) {
            const row = this.lane(n);
            const y = this.laneScreenY(n) - LANE_H;

            if (y > H || y + LANE_H < -LANE_H) {
                continue;
            }

            this.drawLane(ctx, row, n, y);
        }

        // Kerbs, drawn over every lane so the playfield has hard edges rather
        // than fading out into the bezel.
        ctx.fillStyle = 'rgba(7,3,13,0.55)';
        ctx.fillRect(0, 0, SIDE - 4, H);
        ctx.fillRect(W - SIDE + 4, 0, SIDE - 4, H);
    }

    drawLane(ctx, row, n, y) {
        const b = row.biome;

        if (row.kind === 'safe') {
            ctx.fillStyle = n % 2 === 0 ? b.safe : b.safeAlt;
            ctx.fillRect(0, y, W, LANE_H);
            this.drawGround(ctx, row, n, y);
        } else if (row.kind === 'road') {
            ctx.fillStyle = b.road;
            ctx.fillRect(0, y, W, LANE_H);

            ctx.strokeStyle = 'rgba(255,225,77,0.22)';
            ctx.lineWidth = 2;
            ctx.setLineDash([13, 11]);
            ctx.beginPath();
            ctx.moveTo(0, y + LANE_H / 2);
            ctx.lineTo(W, y + LANE_H / 2);
            ctx.stroke();
            ctx.setLineDash([]);
        } else {
            ctx.fillStyle = b.water;
            ctx.fillRect(0, y, W, LANE_H);

            // A bright lip on the near edge, so a water lane announces itself as
            // you come up on it rather than once you are already level with it.
            ctx.fillStyle = 'rgba(150,215,255,0.3)';
            ctx.fillRect(0, y + LANE_H - 3, W, 3);

            ctx.strokeStyle = 'rgba(255,255,255,0.16)';
            ctx.lineWidth = 1.4;

            for (let i = 0; i < 3; i++) {
                const wy = y + 10 + i * 12;
                const drift = (performance.now() / 1000) * row.dir * 12;

                ctx.beginPath();

                for (let x = -20; x < W + 20; x += 20) {
                    const px = x + ((drift + i * 7) % 20);
                    ctx.moveTo(px, wy);
                    ctx.quadraticCurveTo(px + 5, wy - 2.4, px + 10, wy);
                }

                ctx.stroke();
            }
        }

        if (row.bean && !row.bean.got) {
            this.drawBean(ctx, row.bean, y);
        }

        for (const item of row.items) {
            if (row.kind === 'road') {
                this.drawVehicle(ctx, row, item, y);
            } else {
                this.drawFloat(ctx, row, item, y);
            }
        }

        // The landing marker for a charged hop. Same job as the tower's aim
        // ticks: it turns a super fart from a gamble into a decision.
        if (this.charge > 0 && this.phase === 'playing' && n === Math.round(this.dog.lane) + SUPER_LANES) {
            ctx.strokeStyle = 'rgba(168,240,138,0.5)';
            ctx.lineWidth = 2;
            ctx.setLineDash([6, 5]);
            rr(ctx, this.dog.x - 20, y + 5, 40, LANE_H - 10, 8);
            ctx.stroke();
            ctx.setLineDash([]);
        }
    }

    drawGround(ctx, row, n, y) {
        const b = row.biome;

        if (b.name === 'shop') {
            ctx.strokeStyle = 'rgba(255,255,255,0.05)';
            ctx.lineWidth = 1;

            for (let i = 0; i < 7; i++) {
                ctx.beginPath();
                ctx.moveTo(i * 46 + 12, y);
                ctx.lineTo(i * 46 + 12, y + LANE_H);
                ctx.stroke();
            }

            return;
        }

        if (b.name === 'farm') {
            ctx.strokeStyle = 'rgba(255,225,77,0.11)';
            ctx.lineWidth = 2;

            for (let i = 0; i < 9; i++) {
                const hx = 14 + rnd(row.seed + i * 2.3) * (W - 28);
                const hy = y + 8 + rnd(row.seed + i * 5.1) * (LANE_H - 16);

                ctx.beginPath();
                ctx.moveTo(hx - 5, hy);
                ctx.lineTo(hx + 5, hy - 2);
                ctx.stroke();
            }

            return;
        }

        ctx.fillStyle = 'rgba(168,240,138,0.14)';

        for (let i = 0; i < 11; i++) {
            const tx = 14 + rnd(row.seed + i * 1.7) * (W - 28);
            const ty = y + 9 + rnd(row.seed + i * 4.3) * (LANE_H - 18);

            ctx.beginPath();
            ctx.moveTo(tx, ty + 5);
            ctx.lineTo(tx + 2.6, ty - 5);
            ctx.lineTo(tx + 5.2, ty + 5);
            ctx.closePath();
            ctx.fill();
        }
    }

    drawBean(ctx, bean, y) {
        const bob = Math.sin(performance.now() / 320 + bean.bob) * 2.6;
        const cx = bean.x;
        const cy = y + LANE_H / 2 + bob;

        ctx.fillStyle = 'rgba(168,240,138,0.16)';
        ctx.beginPath();
        ctx.arc(cx, cy, 15, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = '#d8d2c0';
        rr(ctx, cx - 8, cy - 10, 16, 20, 3);
        ctx.fill();

        ctx.fillStyle = '#e0365b';
        ctx.fillRect(cx - 8, cy - 5, 16, 11);

        ctx.fillStyle = '#ffd23d';
        ctx.fillRect(cx - 8, cy - 3, 16, 2);

        ctx.fillStyle = 'rgba(255,255,255,0.5)';
        ctx.fillRect(cx - 6, cy - 9, 3, 17);

        ctx.fillStyle = '#a8f08a';

        for (let i = 0; i < 3; i++) {
            ctx.beginPath();
            ctx.ellipse(cx - 3 + i * 3, cy - 12 - (i % 2) * 2, 2.6, 1.9, 0.4, 0, 6.29);
            ctx.fill();
        }
    }

    drawVehicle(ctx, row, item, y) {
        const kind = row.biome.vehicle;
        const facing = row.dir;
        const x = item.x;
        const h = LANE_H - 12;
        const top = y + 6;
        const tilt = item.push !== 0 ? clamp(item.push / 900, -0.16, 0.16) : 0;

        ctx.save();

        if (tilt !== 0) {
            ctx.translate(x + item.w / 2, top + h / 2);
            ctx.rotate(tilt);
            ctx.translate(-(x + item.w / 2), -(top + h / 2));
        }

        ctx.fillStyle = 'rgba(7,3,13,0.4)';
        rr(ctx, x + 2, top + h - 5, item.w, 7, 4);
        ctx.fill();

        if (kind === 'trolley') {
            ctx.strokeStyle = '#cfc6ea';
            ctx.lineWidth = 2;
            rr(ctx, x + 3, top + 2, item.w - 6, h - 8, 3);
            ctx.stroke();

            ctx.strokeStyle = 'rgba(207,198,234,0.55)';
            ctx.lineWidth = 1;

            for (let i = 1; i < 4; i++) {
                ctx.beginPath();
                ctx.moveTo(x + 3 + ((item.w - 6) / 4) * i, top + 2);
                ctx.lineTo(x + 3 + ((item.w - 6) / 4) * i, top + h - 6);
                ctx.stroke();
            }

            ctx.fillStyle = item.col;
            rr(ctx, x + 9, top + 4, item.w - 20, h * 0.4, 2);
            ctx.fill();
            ctx.fillStyle = '#ffd23d';
            ctx.beginPath();
            ctx.arc(x + item.w * 0.6, top + 7, 4, 0, 6.29);
            ctx.fill();

            ctx.fillStyle = '#0f0a1a';
            ctx.beginPath();
            ctx.arc(x + 9, top + h - 4, 3, 0, 6.29);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(x + item.w - 9, top + h - 4, 3, 0, 6.29);
            ctx.fill();
            ctx.restore();

            return;
        }

        if (kind === 'tractor') {
            ctx.fillStyle = item.col;
            rr(ctx, x + (facing > 0 ? 4 : 14), top + 4, item.w - 18, h - 12, 3);
            ctx.fill();

            ctx.fillStyle = shade(item.col, 0.7);
            rr(ctx, x + (facing > 0 ? item.w - 24 : 6), top - 2, 18, h - 8, 3);
            ctx.fill();

            ctx.fillStyle = '#1a2a3a';
            rr(ctx, x + (facing > 0 ? item.w - 21 : 9), top, 12, 9, 2);
            ctx.fill();

            ctx.fillStyle = '#0f0a1a';
            ctx.beginPath();
            ctx.arc(x + (facing > 0 ? 12 : item.w - 12), top + h - 6, 8, 0, 6.29);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(x + (facing > 0 ? item.w - 14 : 14), top + h - 7, 5, 0, 6.29);
            ctx.fill();

            ctx.fillStyle = '#4a3a2a';
            ctx.beginPath();
            ctx.arc(x + (facing > 0 ? 12 : item.w - 12), top + h - 6, 3.4, 0, 6.29);
            ctx.fill();
            ctx.restore();

            return;
        }

        ctx.fillStyle = item.col;
        rr(ctx, x, top, item.w, h - 4, 6);
        ctx.fill();

        ctx.fillStyle = shade(item.col, 0.74);
        rr(ctx, x, top + (h - 4) * 0.58, item.w, (h - 4) * 0.42, 6);
        ctx.fill();

        ctx.fillStyle = 'rgba(190,230,255,0.85)';
        const wx = facing > 0 ? x + item.w * 0.5 : x + item.w * 0.16;
        rr(ctx, wx, top + 3, item.w * 0.34, (h - 4) * 0.42, 3);
        ctx.fill();

        ctx.fillStyle = 'rgba(255,255,255,0.3)';
        rr(ctx, x + item.w * 0.18, top + 2.5, item.w * 0.26, 3, 2);
        ctx.fill();

        ctx.fillStyle = facing > 0 ? '#fff6c8' : '#ff8a6b';
        ctx.beginPath();
        ctx.arc(facing > 0 ? x + item.w - 4 : x + 4, top + h * 0.42, 3, 0, 6.29);
        ctx.fill();

        ctx.fillStyle = '#0f0a1a';
        ctx.beginPath();
        ctx.arc(x + 10, top + h - 5, 4, 0, 6.29);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(x + item.w - 10, top + h - 5, 4, 0, 6.29);
        ctx.fill();

        ctx.restore();
    }

    drawFloat(ctx, row, item, y) {
        const kind = row.biome.float;
        const top = y + 8;
        const h = LANE_H - 16;

        if (kind === 'box') {
            ctx.fillStyle = '#8a6a44';
            rr(ctx, item.x, top, item.w, h, 3);
            ctx.fill();
            ctx.fillStyle = 'rgba(0,0,0,0.22)';
            ctx.fillRect(item.x, top + h * 0.45, item.w, 3);
            ctx.strokeStyle = 'rgba(255,255,255,0.16)';
            ctx.lineWidth = 1;
            rr(ctx, item.x + 3, top + 2.5, item.w - 6, h - 5, 2);
            ctx.stroke();

            return;
        }

        if (kind === 'plank') {
            ctx.fillStyle = '#6b4f30';
            rr(ctx, item.x, top, item.w, h, 2);
            ctx.fill();

            ctx.strokeStyle = 'rgba(0,0,0,0.26)';
            ctx.lineWidth = 1;

            for (let i = 1; i < 4; i++) {
                ctx.beginPath();
                ctx.moveTo(item.x + (item.w / 4) * i, top);
                ctx.lineTo(item.x + (item.w / 4) * i, top + h);
                ctx.stroke();
            }

            return;
        }

        ctx.fillStyle = '#5a3f27';
        rr(ctx, item.x, top, item.w, h, h / 2);
        ctx.fill();

        ctx.fillStyle = '#6f4f31';
        rr(ctx, item.x + 2, top + 1.5, item.w - 4, h * 0.5, h / 3);
        ctx.fill();

        ctx.fillStyle = '#3f2b1a';
        ctx.beginPath();
        ctx.ellipse(item.x + 5, top + h / 2, 3.4, h / 2 - 1.5, 0, 0, 6.29);
        ctx.fill();
        ctx.beginPath();
        ctx.ellipse(item.x + item.w - 5, top + h / 2, 3.4, h / 2 - 1.5, 0, 0, 6.29);
        ctx.fill();
    }

    /**
     * The dog, seen from behind and above.
     *
     * Drawn rather than sprited so it can squash into the hop — the arc is the
     * only animation in the game that has to feel good, because it is the one
     * the player makes happen forty times a run.
     */
    drawDog(ctx) {
        const dog = this.dog;
        const y = this.laneScreenY(dog.lane) - LANE_H / 2;
        const k = dog.hop > 0 ? Math.sin((1 - dog.hop) * Math.PI) : 0;
        const lift = k * (dog.super ? 34 : 18);
        const squash = dog.hop > 0 ? 1 - k * 0.16 : 1;

        // A dog-coloured dog on dark asphalt is a smudge. The glow is the only
        // thing that makes it findable at a glance while the lanes are moving.
        const halo = ctx.createRadialGradient(dog.x, y - lift - 4, 4, dog.x, y - lift - 4, 34);
        halo.addColorStop(0, 'rgba(255,225,77,0.20)');
        halo.addColorStop(1, 'rgba(255,225,77,0)');
        ctx.fillStyle = halo;
        ctx.fillRect(dog.x - 40, y - lift - 44, 80, 80);

        ctx.fillStyle = 'rgba(7,3,13,' + (0.42 - k * 0.2).toFixed(3) + ')';
        ctx.beginPath();
        ctx.ellipse(dog.x, y + 11, 12 - k * 3, 4.6 - k * 1.4, 0, 0, 6.29);
        ctx.fill();

        ctx.save();
        ctx.translate(dog.x, y - lift);
        ctx.rotate([0, 1.571, 3.142, -1.571][dog.face] * 0.12);
        ctx.scale(squash, 2 - squash);

        const tan = '#c8762e';
        const dark = '#9c5620';

        // The Sausage. Seen from above and behind, facing UP the board — the
        // direction of travel, so the fart comes out the end pointing back at
        // the player. Long body, small head, ears hanging either side: nothing
        // else on the board is this shape, which is what makes it findable in
        // traffic at 26px.
        ctx.strokeStyle = dark;
        ctx.lineWidth = 3.5;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(0, 9);
        ctx.quadraticCurveTo(5 + k * 4, 15, 4, 21 + k * 3);
        ctx.stroke();

        ctx.fillStyle = dark;
        [[-7, 6], [7, 6], [-6, -8], [6, -8]].forEach(([px, py]) => {
            ctx.beginPath();
            ctx.ellipse(px, py + k * 2, 3.4, 4.2, 0, 0, 6.29);
            ctx.fill();
        });

        ctx.fillStyle = tan;
        rr(ctx, -8, -12, 16, 24, 7);
        ctx.fill();

        ctx.strokeStyle = 'rgba(7,3,13,0.55)';
        ctx.lineWidth = 1.6;
        rr(ctx, -8, -12, 16, 24, 7);
        ctx.stroke();

        ctx.fillStyle = 'rgba(255,255,255,0.16)';
        rr(ctx, -5.5, -10, 5, 19, 2.5);
        ctx.fill();

        ctx.fillStyle = tan;
        ctx.beginPath();
        ctx.arc(0, -15, 7.2, 0, 6.29);
        ctx.fill();

        ctx.strokeStyle = 'rgba(7,3,13,0.55)';
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        ctx.arc(0, -15, 7.2, 0, 6.29);
        ctx.stroke();

        // Long ears hanging down either side of the head.
        ctx.fillStyle = dark;
        ctx.beginPath();
        ctx.ellipse(-7, -14, 3.2, 6.4, -0.25, 0, 6.29);
        ctx.fill();
        ctx.beginPath();
        ctx.ellipse(7, -14, 3.2, 6.4, 0.25, 0, 6.29);
        ctx.fill();

        // Back of the head: a lighter crown patch, never eyes or a nose.
        ctx.fillStyle = 'rgba(255,255,255,0.13)';
        ctx.beginPath();
        ctx.ellipse(0, -16, 4, 3, 0, 0, 6.29);
        ctx.fill();

        // Muzzle just peeking past the far edge — a bump on the horizon.
        ctx.fillStyle = '#f2e2cc';
        ctx.beginPath();
        ctx.ellipse(0, -21, 3.2, 2.2, 0, 0, 6.29);
        ctx.fill();

        // Tongue lolls out to one side on the way up.
        if (k > 0.25) {
            ctx.fillStyle = '#ff8ac7';
            rr(ctx, 2.2, -22, 2.8, 4.4 + k * 2, 1.4);
            ctx.fill();
        }

        ctx.restore();
    }

    drawPuffs(ctx, big) {
        for (const p of this.puffs) {
            if (!!p.big !== big) {
                continue;
            }

            const k = p.t / p.life;
            const y = this.laneScreenY(p.lane) - LANE_H / 2 + p.off;

            ctx.fillStyle = 'rgba(168,240,138,' + ((1 - k) * (p.big ? 0.4 : 0.3)).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(p.x, y, p.r * (0.6 + k * 0.8), 0, 6.29);
            ctx.fill();

            if (p.big) {
                ctx.fillStyle = 'rgba(220,255,190,' + ((1 - k) * 0.22).toFixed(3) + ')';
                ctx.beginPath();
                ctx.arc(p.x + p.r * 0.3, y - p.r * 0.2, p.r * 0.45, 0, 6.29);
                ctx.fill();
            }
        }
    }

    drawEffects(ctx) {
        for (const e of this.effects) {
            const k = e.t / e.life;
            const y = this.laneScreenY(e.lane) - LANE_H / 2;

            if (e.kind === 'ring') {
                ctx.strokeStyle = 'rgba(168,240,138,' + ((1 - k) * 0.7).toFixed(3) + ')';
                ctx.lineWidth = 3 * (1 - k) + 0.5;
                ctx.beginPath();
                ctx.ellipse(e.x, y, 12 + k * 80, (12 + k * 80) * 0.45, 0, 0, 6.29);
                ctx.stroke();
            } else {
                ctx.font = '800 15px Outfit, ui-sans-serif, system-ui, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = 'rgba(168,240,138,' + ((1 - k) * 0.95).toFixed(3) + ')';
                ctx.fillText(e.text, e.x, y - 14 - k * 24);
            }
        }
    }

    /* -------------------------------------------------------------- *
     * HUD — drawn in the canvas, so the cabinet round it stays chrome
     * -------------------------------------------------------------- */

    drawHud(ctx) {
        ctx.save();

        const g = ctx.createLinearGradient(0, 0, 0, 62);
        g.addColorStop(0, 'rgba(7,3,13,0.72)');
        g.addColorStop(1, 'rgba(7,3,13,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, 62);

        ctx.textAlign = 'left';
        ctx.font = '800 30px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ffe14d';
        ctx.fillText(String(this.score), 14, 34);

        ctx.font = '600 9px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText('LANES', 14, 45);

        if (this.phase !== 'idle') {
            ctx.textAlign = 'center';
            ctx.font = '600 10px "JetBrains Mono", ui-monospace, monospace';
            ctx.fillStyle = '#b0a3cc';
            ctx.fillText(MILESTONES[this.milestone][1].toUpperCase(), W / 2, 20);
        }

        // Charge pips, top right — three tins of beans is a full tank.
        for (let i = 0; i < MAX_CHARGE; i++) {
            const px = W - 20 - i * 15;
            const on = i < this.charge;

            ctx.fillStyle = on ? '#a8f08a' : 'rgba(140,123,171,0.35)';
            rr(ctx, px - 5, 14, 10, 13, 2.5);
            ctx.fill();

            if (on) {
                ctx.fillStyle = '#e0365b';
                ctx.fillRect(px - 5, 18, 10, 6);
            }
        }

        ctx.textAlign = 'right';
        ctx.font = '600 9px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText('BEANS', W - 14, 38);

        ctx.restore();
    }

    drawBanner(ctx) {
        if (!this.banner) {
            return;
        }

        const t = this.banner.t;
        const alpha = t < 0.25 ? t / 0.25 : clamp((1.9 - t) / 0.6, 0, 1);

        ctx.textAlign = 'center';
        ctx.font = '800 26px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = 'rgba(7,3,13,' + (alpha * 0.7).toFixed(3) + ')';
        ctx.fillText(this.banner.text.toUpperCase(), W / 2 + 2, 104 + 2);
        ctx.fillStyle = 'rgba(255,225,77,' + alpha.toFixed(3) + ')';
        ctx.fillText(this.banner.text.toUpperCase(), W / 2, 104);
    }

    drawOverlay(ctx) {
        if (this.phase === 'playing') {
            return;
        }

        ctx.fillStyle = 'rgba(7,3,13,0.68)';
        ctx.fillRect(0, 0, W, H);

        ctx.textAlign = 'center';

        if (this.phase === 'idle') {
            // The only edit to this file from the design bundle, and the only
            // one worth making: the game shipped under its working title, and
            // this is the one place that title was drawn where a player could
            // read it. 30px rather than 34 because the new name is four
            // characters longer and 34 runs into the kerbs.
            ctx.font = '800 30px "Baloo 2", Outfit, system-ui, sans-serif';
            ctx.fillStyle = '#ffe14d';
            ctx.fillText('WINDY WALKIES', W / 2, H / 2 - 46);

            ctx.font = '400 13px Outfit, system-ui, sans-serif';
            ctx.fillStyle = '#e6dcf5';
            ctx.fillText('Every hop is a fart. Cross the road.', W / 2, H / 2 - 20);

            ctx.fillStyle = '#a8f08a';
            ctx.fillText('Eat beans for a SUPER fart.', W / 2, H / 2 + 2);

            ctx.font = '600 11px "JetBrains Mono", ui-monospace, monospace';
            ctx.fillStyle = '#8c7bab';
            ctx.fillText('SWIPE, ARROWS OR WASD', W / 2, H / 2 + 34);

            ctx.font = '800 17px "Baloo 2", Outfit, system-ui, sans-serif';
            ctx.fillStyle = '#ffe14d';
            ctx.fillText('TAP TO GO', W / 2, H / 2 + 52);

            return;
        }

        const how = this.dog.dead ? this.dog.dead.how : '';

        ctx.font = '800 27px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ff8ac7';
        ctx.fillText(how.toUpperCase(), W / 2, H / 2 - 44);

        ctx.font = '800 58px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ffe14d';
        ctx.fillText(String(this.score), W / 2, H / 2 + 12);

        ctx.font = '600 10px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText('LANES CROSSED', W / 2, H / 2 + 30);

        ctx.font = '400 14px Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#e6dcf5';
        ctx.fillText(MILESTONES[this.milestone][1], W / 2, H / 2 + 56);

        if (this.dog.dead && this.dog.dead.t > 0.7) {
            ctx.font = '800 16px "Baloo 2", Outfit, system-ui, sans-serif';
            ctx.fillStyle = '#ffe14d';
            ctx.fillText('TAP TO GO AGAIN', W / 2, H / 2 + 92);
        }
    }
}

/* ------------------------------------------------------------------ *
 * The element
 * ------------------------------------------------------------------ */

/**
 * Is the keystroke going somewhere that wants the letter?
 *
 * The game listens on the window so a player never has to click the canvas
 * first, which was harmless while it only claimed the arrow keys. WASD is not:
 * an `a` belongs to whatever field has the caret.
 */
function isTyping(node) {
    if (!(node instanceof HTMLElement)) {
        return false;
    }

    return node.isContentEditable
        || ['INPUT', 'TEXTAREA', 'SELECT'].includes(node.tagName);
}

class FartDashElement extends HTMLElement {
    connectedCallback() {
        if (this.game) {
            return;
        }

        this.style.display = 'block';
        this.style.position = 'relative';

        const wrap = document.createElement('div');
        wrap.style.cssText = 'width:100%;display:flex;flex-direction:column;gap:10px;align-items:center;';

        const canvas = document.createElement('canvas');

        // The aspect ratio is not decoration: draw() scales by width alone, so a
        // box that isn't 320:460 renders the bottom of the board off the bottom
        // of the canvas — which silently loses the dog and the title screen.
        canvas.style.cssText = 'width:100%;aspect-ratio:320 / 460;height:auto;display:block;'
            + 'border-radius:18px;background:#0a0512;touch-action:none;cursor:pointer;';

        wrap.appendChild(canvas);
        wrap.appendChild(this.buildPad());
        this.appendChild(wrap);

        this.game = new BeanDash(canvas);
        this.game.mount();

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

    /**
     * The d-pad, for the desktop half of the house.
     *
     * Arrow keys do the same thing and are what a keyboard player will reach
     * for, but a mouse needs somewhere to click that isn't "swipe" — and the
     * buttons double as the only place the controls are written down.
     */
    buildPad() {
        const pad = document.createElement('div');
        pad.style.cssText = 'flex:none;display:grid;grid-template-columns:repeat(3,52px);'
            + 'grid-template-rows:repeat(2,44px);gap:6px;justify-content:center;';

        const button = (label, col, row, dx, dy) => {
            const b = document.createElement('button');

            b.type = 'button';
            b.textContent = label;
            b.style.cssText = 'grid-column:' + col + ';grid-row:' + row + ';'
                + 'border:1px solid #3a2360;background:#150c26;color:#c9a0ff;'
                + 'border-radius:13px;font:800 19px "Baloo 2",system-ui,sans-serif;'
                + 'cursor:pointer;font-family:inherit;';

            b.addEventListener('click', () => {
                if (this.game.phase !== 'playing') {
                    this.game.tap();

                    return;
                }

                this.game.move(dx, dy);
            });

            pad.appendChild(b);

            return b;
        };

        button('◀', 1, 2, -1, 0);
        button('▲', 2, 1, 0, 1);
        button('▼', 2, 2, 0, -1);
        button('▶', 3, 2, 1, 0);

        return pad;
    }

    wire(canvas) {
        let sx = 0;
        let sy = 0;
        let moved = false;

        const down = (x, y) => {
            sx = x;
            sy = y;
            moved = false;
        };

        const up = (x, y) => {
            const dx = x - sx;
            const dy = y - sy;

            if (Math.abs(dx) < 22 && Math.abs(dy) < 22) {
                this.game.tap();

                return;
            }

            if (this.game.phase !== 'playing') {
                this.game.tap();

                return;
            }

            if (Math.abs(dx) > Math.abs(dy)) {
                this.game.move(dx > 0 ? 1 : -1, 0);
            } else {
                this.game.move(0, dy < 0 ? 1 : -1);
            }
        };

        canvas.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            down(e.clientX, e.clientY);
        });

        canvas.addEventListener('pointermove', () => {
            moved = true;
        });

        canvas.addEventListener('pointerup', (e) => {
            e.preventDefault();
            up(e.clientX, e.clientY);
        });

        this.onKey = (e) => {
            // WASD beside the arrows: a laptop player's left hand is already
            // there, and it is the same four directions rather than a second
            // control scheme anybody has to be told about.
            const map = {
                ArrowUp: [0, 1],
                ArrowDown: [0, -1],
                ArrowLeft: [-1, 0],
                ArrowRight: [1, 0],
                w: [0, 1],
                s: [0, -1],
                a: [-1, 0],
                d: [1, 0],
            };

            if (isTyping(e.target)) {
                return;
            }

            const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;

            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                this.game.tap();

                return;
            }

            if (!map[key]) {
                return;
            }

            e.preventDefault();

            if (this.game.phase !== 'playing') {
                this.game.tap();

                return;
            }

            this.game.move(map[key][0], map[key][1]);
        };

        window.addEventListener('keydown', this.onKey);
    }
}

if (!customElements.get('fart-dash')) {
    customElements.define('fart-dash', FartDashElement);
}
