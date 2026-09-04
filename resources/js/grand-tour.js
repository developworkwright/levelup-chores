/**
 * Grand Tour — a one-button flier, and the arcade's fourth cabinet.
 *
 * A self-registering `<grand-tour>` custom element, plain browser JS, no
 * build step, mounted exactly like `slime.js`.
 *
 * A paper plane crosses Europe. Tap to climb, and thread the gaps. That is
 * the whole game.
 *
 * Why this shape: difficulty here is a CURVE, not a rule. The only three knobs
 * are how fast the room scrolls, how wide the gaps are, and how hard the vents
 * blow — all continuous, all monotonic, all tunable by moving one number
 * without changing what the player is allowed to do. There is no placement rule
 * to exploit, so there is no "tap anywhere and win": the gap either lines up
 * with the plane or it doesn't, and the player can see which from a second
 * away.
 *
 * Score is KILOMETRES FLOWN plus a bonus per gap threaded, so it rises smoothly
 * with skill instead of jumping on lucky events. Dying costs nothing beyond
 * ending the run — a kid having a bad go is never charged for playing.
 * `MILESTONES` is the design's copy of `ArcadeService::MILESTONES` for this
 * game; the leaderboard has to label a score with the same words the banner
 * does, so PHP owns the real ladder.
 *
 * Kindness, deliberately: the ceiling bonks instead of killing, the first
 * gap is enormous, gusts do not start until the player has clearly got the
 * hang of flapping, and one forgiving frame of coyote time covers the
 * "but I pressed it!" case. The ground is the only thing that ends a run.
 *
 * Events: `gt-score` on every change, `gt-over` once per run. The page posts
 * the run; the game never does.
 */

const W = 320;
const H = 460;

/** The sky. The plane's centre never leaves these. */
const SKY_TOP = 64;
const FLOOR = H - 34;

/** The plane sits at a fixed x and Europe comes to it. */
const PLANE_X = 96;
const PLANE_R = 11;

const GRAVITY = 1560;
const FLAP = -412;

/** Terminal velocity, so a long fall stays survivable-looking. */
const FALL_MAX = 620;

/** A flap inside this long after a press still counts. "But I pressed it!" */
const COYOTE = 0.09;

/**
 * The curve.
 *
 * Everything the player experiences as "getting harder" is one of these three
 * lerps against `ramp` (0 → 1 over RAMP_TIME seconds of flying). Tuning the
 * game means moving these six numbers and nothing else.
 */
const RAMP_TIME = 75;

const SPEED_0 = 104;
const SPEED_1 = 208;

const GAP_0 = 176;
const GAP_1 = 104;

const SPACING_0 = 224;
const SPACING_1 = 172;

/** Crosswind stays off until climbing is second nature. */
const GUST_AFTER = 26;
const GUST_MAX = 340;

const CLEAN = 26;

/** Points. Kilometres are the honest part; the bonus is the flourish. */
const PER_KM = 1;
const PER_MARK = 4;
const PER_CLEAN = 6;

/** The ladder is the itinerary: every promotion is a city further east. */
const MILESTONES = [
    [0, 'On the runway'],
    [40, 'Over the Channel'],
    [110, 'Paris'],
    [220, 'The Alps'],
    [360, 'Venice'],
    [540, 'Athens'],
    [780, 'All the way round'],
];

const clamp = (v, lo, hi) => (v < lo ? lo : (v > hi ? hi : v));
const lerp = (a, b, t) => a + (b - a) * t;

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
        // newer bundle; ArcadeFlightTest fails if it goes missing.
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

    climb() {
        this.tone(380, 0.1, 0.1, 620, 'triangle');
    },

    pass(clean) {
        this.tone(clean ? 880 : 620, 0.12, 0.1, clean ? 1320 : 780);
    },

    bonk() {
        this.tone(180, 0.14, 0.11, 90, 'square');
    },

    over() {
        this.tone(300, 0.5, 0.13, 70, 'sawtooth');
    },
};

/* ------------------------------------------------------------------ *
 * The game
 * ------------------------------------------------------------------ */

class GrandTour {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.scale = 1;
        this.last = 0;
        this.phase = 'title';
        this.reset();
    }

    reset() {
        this.y = (SKY_TOP + FLOOR) / 2;
        this.vy = 0;
        this.spin = 0;
        this.squash = 0;
        this.dist = 0;
        this.score = 0;
        this.km = 0;
        this.marks = 0;
        this.cleans = 0;
        this.milestone = 0;
        this.ramp = 0;
        this.t = 0;
        this.press = -1;
        this.shake = 0;
        this.lines = [];
        this.birds = [];
        this.pops = [];
        this.nextAt = 260;

        for (let i = 0; i < 26; i++) {
            this.birds.push({
                x: Math.random() * W,
                y: SKY_TOP + Math.random() * (FLOOR - SKY_TOP),
                r: 0.6 + Math.random() * 1.6,
                sp: 0.3 + Math.random() * 0.8,
            });
        }
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
     * Input — one button, three ways to press it
     * -------------------------------------------------------------- */

    tap() {
        Sfx.wake();

        if (this.phase === 'playing') {
            this.press = 0;

            return;
        }

        this.start();
    }

    start() {
        this.reset();
        this.phase = 'playing';
        this.emit('gt-score', { score: 0, label: MILESTONES[0][1], metres: 0 });
    }

    /* -------------------------------------------------------------- *
     * Step
     * -------------------------------------------------------------- */

    step(dt) {
        this.t += dt;
        this.shake = Math.max(0, this.shake - dt * 3);

        for (const p of this.pops) {
            p.life -= dt;
            p.y += p.vy * dt;
            p.x += p.vx * dt;
        }

        this.pops = this.pops.filter((p) => p.life > 0);

        if (this.phase !== 'playing') {
            this.drift(dt, 40);

            return;
        }

        this.ramp = clamp(this.t / RAMP_TIME, 0, 1);

        const speed = lerp(SPEED_0, SPEED_1, this.ramp);

        this.dist += speed * dt;
        this.drift(dt, speed);

        const m = Math.floor(this.dist / 24);

        if (m > this.km) {
            this.km = m;
            this.bump();
        }

        if (this.press >= 0) {
            this.press += dt;

            if (this.press <= COYOTE) {
                this.vy = FLAP;
                this.squash = 0.7;
                this.press = -1;
                Sfx.climb();

                for (let i = 0; i < 4; i++) {
                    this.pops.push({
                        x: PLANE_X - 6,
                        y: this.y + 6,
                        vx: -60 - Math.random() * 70,
                        vy: 20 + Math.random() * 60,
                        r: 1 + Math.random() * 2,
                        life: 0.3 + Math.random() * 0.2,
                    });
                }
            } else {
                this.press = -1;
            }
        }

        this.vy = clamp(this.vy + (GRAVITY + this.gust()) * dt, -900, FALL_MAX);
        this.y += this.vy * dt;

        // The ceiling bonks. Killing a kid for flapping too much is a bad
        // lesson to teach with the only button in the game.
        if (this.y < SKY_TOP + PLANE_R) {
            this.y = SKY_TOP + PLANE_R;

            if (this.vy < -80) {
                Sfx.bonk();
                this.shake = Math.max(this.shake, 0.25);
            }

            this.vy = Math.max(this.vy, 30);
        }

        this.squash = Math.max(0, this.squash - dt * 3.4);
        this.spin = clamp(this.vy / 900, -0.5, 0.9);

        this.spawn(speed, dt);
        this.hit();

        if (this.y > FLOOR - PLANE_R) {
            this.y = FLOOR - PLANE_R;
            this.over();
        }
    }

    /** Lint in the air, moving with the room. Free sense of speed. */
    drift(dt, speed) {
        for (const p of this.birds) {
            p.x -= speed * p.sp * dt;

            if (p.x < -4) {
                p.x = W + 4;
                p.y = SKY_TOP + Math.random() * (FLOOR - SKY_TOP);
            }
        }
    }

    /**
     * The vents.
     *
     * A gust is a smooth vertical push that reverses on a slow sine, so it is
     * readable rather than random — the lint shows which way it is blowing a
     * beat before the sock feels it, and it never fully overpowers a flap.
     */
    gust() {
        if (this.t < GUST_AFTER) {
            return 0;
        }

        const ease = clamp((this.t - GUST_AFTER) / 20, 0, 1);

        return Math.sin(this.t * 0.55) * GUST_MAX * ease;
    }

    spawn(speed, dt) {
        for (const l of this.lines) {
            l.x -= speed * dt;

            if (!l.done && l.x + 18 < PLANE_X - PLANE_R) {
                l.done = true;
                this.marks += 1;

                const off = Math.abs(this.y - l.gapY);
                const clean = off < CLEAN;

                if (clean) {
                    this.cleans += 1;
                }

                this.score += clean ? PER_MARK + PER_CLEAN : PER_MARK;
                Sfx.pass(clean);
                this.bump(clean ? 'clean' : 'gap');
            }
        }

        this.lines = this.lines.filter((l) => l.x > -60);

        this.nextAt -= speed * dt;

        if (this.nextAt > 0) {
            return;
        }

        const gap = lerp(GAP_0, GAP_1, this.ramp);
        const pad = gap / 2 + 26;
        const last = this.lines.length ? this.lines[this.lines.length - 1].gapY : (SKY_TOP + FLOOR) / 2;

        // The next gap is never more than a comfortable flap away from the
        // last one, so a wall of gaps is always physically threadable.
        const lo = Math.max(SKY_TOP + pad, last - 120);
        const hi = Math.min(FLOOR - pad, last + 120);

        this.lines.push({
            x: W + 30,
            gapY: lo + Math.random() * Math.max(1, hi - lo),
            gap,
            done: false,
            sway: Math.random() * Math.PI * 2,
        });

        this.nextAt = lerp(SPACING_0, SPACING_1, this.ramp);
    }

    /** Circle vs the two cloth rects. Cloth is 18 wide, walls are hard. */
    hit() {
        for (const l of this.lines) {
            if (l.x > PLANE_X + PLANE_R || l.x + 18 < PLANE_X - PLANE_R) {
                continue;
            }

            const top = l.gapY - l.gap / 2;
            const bot = l.gapY + l.gap / 2;

            if (this.y - PLANE_R < top || this.y + PLANE_R > bot) {
                this.over();

                return;
            }
        }
    }

    bump(kind) {
        this.score = this.km * PER_KM
            + this.marks * PER_MARK
            + this.cleans * PER_CLEAN;

        let m = this.milestone;

        while (m + 1 < MILESTONES.length && this.score >= MILESTONES[m + 1][0]) {
            m += 1;
        }

        this.milestone = m;
        this.emit('gt-score', {
            score: this.score,
            label: MILESTONES[m][1],
            km: this.km,
            marks: this.marks,
            clean: kind === 'clean',
        });
    }

    over() {
        if (this.phase !== 'playing') {
            return;
        }

        this.phase = 'over';
        this.shake = 0.5;
        Sfx.over();

        for (let i = 0; i < 14; i++) {
            this.pops.push({
                x: PLANE_X,
                y: this.y,
                vx: (Math.random() - 0.5) * 260,
                vy: (Math.random() - 0.5) * 260,
                r: 1 + Math.random() * 2.6,
                life: 0.4 + Math.random() * 0.4,
            });
        }

        this.emit('gt-over', {
            score: this.score,
            label: MILESTONES[this.milestone][1],
            km: this.km,
            marks: this.marks,
            cleans: this.cleans,
        });
    }

    /* -------------------------------------------------------------- *
     * Draw
     * -------------------------------------------------------------- */

    draw() {
        const ctx = this.ctx;

        ctx.setTransform(this.scale, 0, 0, this.scale, 0, 0);
        ctx.clearRect(0, 0, W, H);

        if (this.shake > 0) {
            ctx.translate((Math.random() - 0.5) * this.shake * 9, (Math.random() - 0.5) * this.shake * 9);
        }

        ctx.fillStyle = '#0a0512';
        ctx.fillRect(0, 0, W, H);

        this.drawSky();

        for (const l of this.lines) {
            this.drawLine(l);
        }

        for (const p of this.pops) {
            ctx.globalAlpha = clamp(p.life * 2.2, 0, 1);
            ctx.fillStyle = '#e6dcf5';
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.globalAlpha = 1;

        if (this.phase !== 'over') {
            this.drawPlane();
        }

        this.drawHud();

        if (this.phase === 'title') {
            this.drawTitle();
        }

        if (this.phase === 'over') {
            this.drawOver();
        }
    }

    drawSky() {
        const ctx = this.ctx;

        ctx.fillStyle = '#12081f';
        ctx.fillRect(0, SKY_TOP, W, FLOOR - SKY_TOP);

        // Birds, drawn as two strokes each. Free sense of speed.
        ctx.strokeStyle = '#8c7bab';
        ctx.lineWidth = 1.2;
        ctx.globalAlpha = 0.5;

        for (const p of this.birds) {
            const w = p.r * 2.4;

            ctx.beginPath();
            ctx.moveTo(p.x - w, p.y);
            ctx.lineTo(p.x, p.y - w * 0.5);
            ctx.lineTo(p.x + w, p.y);
            ctx.stroke();
        }

        ctx.globalAlpha = 1;

        // Cloud ceiling and ground, drawn as themselves: one is soft, one ends
        // the run.
        ctx.fillStyle = '#1c1030';
        ctx.fillRect(0, SKY_TOP - 6, W, 6);

        ctx.fillStyle = '#3a2360';
        ctx.fillRect(0, FLOOR, W, H - FLOOR);

        ctx.strokeStyle = '#ff8ac7';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(0, FLOOR);
        ctx.lineTo(W, FLOOR);
        ctx.stroke();

        if (this.phase === 'playing' && this.t >= GUST_AFTER) {
            const g = this.gust();

            ctx.globalAlpha = clamp(Math.abs(g) / GUST_MAX, 0, 1) * 0.5;
            ctx.fillStyle = g < 0 ? '#8af0d2' : '#ffb26b';
            ctx.fillRect(0, g < 0 ? SKY_TOP : FLOOR - 26, W, 26);
            ctx.globalAlpha = 1;
        }
    }

    /**
     * A column down, a column up, and the gap between.
     *
     * Deliberately undecorated: silhouettes on the columns pulled the eye away
     * from the only thing that matters, which is where the gap is. The hems are
     * the one flourish, and they exist to make the gap edge unmistakable.
     */
    drawLine(l) {
        const ctx = this.ctx;
        const top = l.gapY - l.gap / 2;
        const bot = l.gapY + l.gap / 2;

        ctx.fillStyle = '#5c3c96';
        ctx.fillRect(l.x, SKY_TOP, 18, top - SKY_TOP);

        ctx.fillStyle = '#3a2360';
        ctx.fillRect(l.x, bot, 18, FLOOR - bot);

        ctx.fillStyle = 'rgba(10,5,18,0.3)';
        ctx.fillRect(l.x + 12, SKY_TOP, 6, top - SKY_TOP);
        ctx.fillRect(l.x + 12, bot, 6, FLOOR - bot);

        ctx.fillStyle = '#f7f0ff';
        ctx.fillRect(l.x - 1, top - 3, 20, 3);
        ctx.fillRect(l.x - 1, bot, 20, 3);
    }

    /** A folded paper dart. Two triangles and a crease. */
    drawPlane() {
        const ctx = this.ctx;
        const sq = 1 - this.squash * 0.25;

        ctx.save();
        ctx.translate(PLANE_X, this.y);
        ctx.rotate(this.spin * 0.8);
        ctx.scale(1, sq);

        ctx.fillStyle = '#f7f0ff';
        ctx.beginPath();
        ctx.moveTo(PLANE_R + 3, 0);
        ctx.lineTo(-PLANE_R - 2, -PLANE_R + 1);
        ctx.lineTo(-PLANE_R + 3, 0);
        ctx.lineTo(-PLANE_R - 2, PLANE_R - 1);
        ctx.closePath();
        ctx.fill();

        ctx.fillStyle = '#b0a3cc';
        ctx.beginPath();
        ctx.moveTo(PLANE_R + 3, 0);
        ctx.lineTo(-PLANE_R - 2, PLANE_R - 1);
        ctx.lineTo(-PLANE_R + 3, 0);
        ctx.closePath();
        ctx.fill();

        ctx.strokeStyle = '#ffe14d';
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.moveTo(PLANE_R + 3, 0);
        ctx.lineTo(-PLANE_R + 3, 0);
        ctx.stroke();

        ctx.restore();
    }

    drawHud() {
        const ctx = this.ctx;

        ctx.fillStyle = '#0a0512';
        ctx.fillRect(0, 0, W, SKY_TOP - 6);

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.font = '600 9px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#6f6288';
        ctx.fillText('POINTS', 18, 24);

        ctx.font = '800 30px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ffe14d';
        ctx.fillText(String(this.score), 18, 52);

        ctx.textAlign = 'right';
        ctx.font = '600 9px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#6f6288';
        ctx.fillText('KM', W - 18, 24);

        ctx.font = '800 20px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#c9a0ff';
        ctx.fillText(String(this.km), W - 18, 46);

        ctx.font = '400 10px Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText(MILESTONES[this.milestone][1], W - 18, 58);
    }

    drawTitle() {
        const ctx = this.ctx;

        ctx.fillStyle = 'rgba(10,5,18,0.9)';
        ctx.fillRect(0, 0, W, H);

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.font = '800 38px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ffe14d';
        ctx.fillText('Grand Tour', W / 2, 116);

        ctx.font = '400 14px Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#b0a3cc';
        ctx.fillText('One button. Tap to climb.', W / 2, 152);

        // The rule, drawn instead of written.
        const y = 240;

        ctx.fillStyle = '#5c3c96';
        ctx.fillRect(196, y - 92, 18, 55);
        ctx.fillStyle = '#3a2360';
        ctx.fillRect(196, y + 33, 18, 59);

        ctx.fillStyle = '#f7f0ff';
        ctx.fillRect(195, y - 37, 20, 3);
        ctx.fillRect(195, y + 30, 20, 3);

        const bob = Math.sin(performance.now() / 420) * 14;

        const keep = this.y;

        this.y = y + bob;
        ctx.save();
        ctx.translate(112 - PLANE_X, 0);
        this.drawPlane();
        ctx.restore();
        this.y = keep;

        ctx.strokeStyle = 'rgba(201,160,255,0.4)';
        ctx.lineWidth = 2;
        ctx.setLineDash([4, 5]);
        ctx.beginPath();
        ctx.moveTo(126, y + bob);
        ctx.lineTo(190, y);
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.font = '400 13px Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText('Thread the gaps. Don’t hit the ground.', W / 2, 330);

        const pulse = 0.6 + Math.sin(performance.now() / 320) * 0.4;

        ctx.globalAlpha = pulse;
        ctx.font = '800 16px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#f7f0ff';
        ctx.fillText('TAP TO FLY', W / 2, 386);
        ctx.globalAlpha = 1;
    }

    drawOver() {
        const ctx = this.ctx;

        ctx.fillStyle = 'rgba(10,5,18,0.86)';
        ctx.fillRect(0, 0, W, H);

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.font = '800 26px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ff8ac7';
        ctx.fillText('Grounded', W / 2, H / 2 - 96);

        ctx.font = '800 64px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#ffe14d';
        ctx.fillText(String(this.score), W / 2, H / 2 - 34);

        ctx.font = '600 10px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#6f6288';
        ctx.fillText('POINTS', W / 2, H / 2 + 4);

        ctx.font = '400 14px Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#e6dcf5';
        ctx.fillText(MILESTONES[this.milestone][1], W / 2, H / 2 + 36);

        ctx.font = '600 9px "JetBrains Mono", ui-monospace, monospace';
        ctx.fillStyle = '#8c7bab';
        ctx.fillText(this.km + 'KM FLOWN', W / 2, H / 2 + 74);
        ctx.fillText(this.marks + ' GAPS · ' + this.cleans + ' CLEAN', W / 2, H / 2 + 92);

        const pulse = 0.55 + Math.sin(performance.now() / 320) * 0.45;

        ctx.globalAlpha = pulse;
        ctx.font = '800 15px "Baloo 2", Outfit, system-ui, sans-serif';
        ctx.fillStyle = '#f7f0ff';
        ctx.fillText('TAP TO FLY AGAIN', W / 2, H / 2 + 140);
        ctx.globalAlpha = 1;
    }
}

/* ------------------------------------------------------------------ *
 * The element
 * ------------------------------------------------------------------ */

class GrandTourElement extends HTMLElement {
    connectedCallback() {
        if (this.game) {
            return;
        }

        this.style.display = 'block';
        this.style.position = 'relative';

        const wrap = document.createElement('div');

        wrap.style.cssText = 'width:100%;display:flex;flex-direction:column;gap:10px;align-items:center;';

        const canvas = document.createElement('canvas');

        // draw() scales by width alone, so a box that isn't 320:460 renders the
        // floor off the bottom of the canvas.
        canvas.style.cssText = 'width:100%;aspect-ratio:320 / 460;height:auto;display:block;'
            + 'border-radius:18px;background:#0a0512;touch-action:none;cursor:pointer;';

        wrap.appendChild(canvas);
        this.appendChild(wrap);

        this.game = new GrandTour(canvas);
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

    /** One button, because the game is one button. */
    buildPad() {
        const pad = document.createElement('div');

        pad.style.cssText = 'flex:none;display:flex;gap:6px;justify-content:center;width:100%;';

        const b = document.createElement('button');

        b.type = 'button';
        b.textContent = 'Climb';
        b.style.cssText = 'height:44px;flex:1;max-width:224px;border:1px solid #3a2360;'
            + 'background:#150c26;color:#ffe14d;border-radius:13px;'
            + 'font:800 16px "Baloo 2",system-ui,sans-serif;cursor:pointer;';

        b.addEventListener('click', () => this.game.tap());
        pad.appendChild(b);

        return pad;
    }

    wire(canvas) {
        canvas.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            this.game.tap();
        });

        this.onKey = (e) => {
            if (e.key !== ' ' && e.key !== 'ArrowUp' && e.key !== 'w') {
                return;
            }

            // Only swallow the key when this cabinet is actually on screen.
            if (!this.isConnected || !this.offsetParent) {
                return;
            }

            e.preventDefault();
            this.game.tap();
        };

        window.addEventListener('keydown', this.onKey);
    }
}

if (!customElements.get('grand-tour')) {
    customElements.define('grand-tour', GrandTourElement);
}
