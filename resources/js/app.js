/*
 * The boss artwork: 15 skins x 5 stages, as `window.FQMonsters`.
 *
 * Shipped verbatim from the design bundle in handoff/design_handoff_boss_battle
 * rather than ported into Blade partials. It is several hundred hand-tuned
 * coordinates, and hand-porting it would re-derive every one of them and then
 * drift from the design the first time either side changed. It is
 * framework-free, defines nothing but that one global, and is guarded against
 * being loaded twice. Update it by replacing the file from a new bundle.
 */
import './monsters.js';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

/**
 * How long the "+N" chip hangs over a tile before fading. Matches the
 * fq-rise keyframes in tokens.css.
 */
const TICKER_HOLD_MS = 1600;

/**
 * Makes a header tile announce its own change.
 *
 * A balance that silently swaps to a new number reads as nothing happening at
 * all — a kid opens a chest, is told they won a ticket, and the counter looks
 * the same as it did a moment ago because it moved while they weren't looking.
 * So the tile pops and floats the difference above itself.
 *
 * Livewire re-renders by morphing attributes in place rather than replacing
 * the element, which is exactly why this watches `data-fq-value` with a
 * MutationObserver: Alpine only evaluates `x-data` once, so the new server
 * value never reaches an expression, but it does reach the attribute.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqTicker', () => ({
        delta: 0,
        timer: null,

        init() {
            let last = this.value();

            this.observer = new MutationObserver(() => {
                const current = this.value();

                if (current === last) {
                    return;
                }

                this.announce(current - last);
                last = current;
            });

            this.observer.observe(this.$el, {
                attributes: true,
                attributeFilter: ['data-fq-value'],
            });
        },

        destroy() {
            this.observer?.disconnect();
            clearTimeout(this.timer);
        },

        value() {
            return Number(this.$el.dataset.fqValue ?? 0);
        },

        announce(delta) {
            // Reset first so a second change inside the window restarts the
            // animation rather than sitting on a chip that's already fading.
            this.delta = 0;
            clearTimeout(this.timer);

            requestAnimationFrame(() => {
                this.delta = delta;
                this.timer = setTimeout(() => (this.delta = 0), TICKER_HOLD_MS);
            });
        },

        /**
         * Blank rather than "0" at rest, so a chip that somehow survives being
         * hidden still has nothing to say.
         */
        get deltaLabel() {
            if (this.delta === 0) {
                return '';
            }

            return this.delta > 0 ? `+${this.delta.toLocaleString()}` : this.delta.toLocaleString();
        },
    }));
});

/*
 * ---------------------------------------------------------------------------
 * Celebrations
 * ---------------------------------------------------------------------------
 *
 * Everything the app throws on screen when something good happens goes through
 * one component, `fqCelebrations`, mounted once by <x-overlays>. Callers hand it
 * an options object — from PHP as `$this->dispatch('celebrate', message: ...)`,
 * from the client as a `celebrate` CustomEvent — and it owns the queue, the
 * particles, the sound and the pacing.
 *
 * It lives here rather than in the Blade component's x-data because none of it
 * is dynamic: there is no server value to interpolate, and a few hundred lines
 * of particle maths and WebAudio synthesis in an HTML attribute get no tooling,
 * no syntax highlighting and Blade's escaping rules for free.
 */

/**
 * Where the last tap landed, so a celebration can burst out of the thing that
 * caused it.
 *
 * The coordinates only ever exist on the client. A chore is claimed by a tap,
 * but the `celebrate` that answers it is dispatched by the server a round trip
 * later with no idea where the finger was — and threading x/y down through
 * Livewire and back would be a lot of plumbing for a fact the browser already
 * has. The last tap *is* the cause.
 */
let lastTap = null;

document.addEventListener(
    'pointerdown',
    (event) => (lastTap = { x: event.clientX, y: event.clientY }),
    { passive: true, capture: true },
);

/**
 * How loud each tier gets: how many pieces fall, and how long the toast holds
 * if the caller doesn't say.
 *
 * Three steps rather than the boolean this replaces, because a level-up and a
 * cleared chore were both merely "big" and so arrived looking identical.
 */
const CELEBRATION_TIERS = {
    small: { pieces: 210, side: 80, hold: 3400 },
    big: { pieces: 320, side: 120, hold: 5200 },
    epic: { pieces: 420, side: 150, hold: 6400 },
};

/**
 * How long a queued reward holds — shorter than the tier's own default, since
 * these arrive several at a time and a kid back after a busy weekend shouldn't
 * have to sit through the full length of each.
 */
const REWARD_HOLDS = { small: 3000, big: 4200, epic: 5000 };

/** Which voice a style speaks in when the caller doesn't pick one. */
const CELEBRATION_VOICES = {
    money: 'cash',
    confetti: 'chime',
    star: 'sparkle',
    heart: 'soft',
    ticket: 'paper',
};

/**
 * One AudioContext for the whole session.
 *
 * Every celebration used to build its own and never close it. Chrome caps a
 * document at six, and the seventh constructor throw was swallowed by the
 * try/catch around it — so sound stopped partway through a session, silently,
 * and only for the kids who were having the best day.
 */
let audioContext = null;

function audio() {
    if (audioContext === null) {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        audioContext = Ctor ? new Ctor() : false;
    }

    // A context built before the browser has seen a gesture starts suspended.
    if (audioContext && audioContext.state === 'suspended') {
        audioContext.resume().catch(() => {});
    }

    return audioContext || null;
}

/** One note. `sweepTo` bends the pitch across the note's life. */
function tone(ctx, { freq, at, type = 'triangle', gain = 0.16, decay = 0.24, sweepTo = null }) {
    const osc = ctx.createOscillator();
    const amp = ctx.createGain();

    osc.type = type;
    osc.frequency.setValueAtTime(freq, at);

    if (sweepTo) {
        osc.frequency.exponentialRampToValueAtTime(sweepTo, at + decay);
    }

    osc.connect(amp);
    amp.connect(ctx.destination);

    amp.gain.setValueAtTime(0.0001, at);
    amp.gain.exponentialRampToValueAtTime(gain, at + 0.02);
    amp.gain.exponentialRampToValueAtTime(0.0001, at + decay);

    osc.start(at);
    osc.stop(at + decay + 0.02);
}

/**
 * A band-passed burst of noise — the part of a sound that isn't a pitch. Paper
 * rustle, the thud under an impact, the hiss on a coin landing.
 */
function noise(ctx, { at, duration = 0.18, gain = 0.12, frequency = 1800, q = 1 }) {
    const frames = Math.max(1, Math.floor(ctx.sampleRate * duration));
    const buffer = ctx.createBuffer(1, frames, ctx.sampleRate);
    const data = buffer.getChannelData(0);

    for (let i = 0; i < frames; i++) {
        // Decays across the buffer, so it reads as a hit rather than as static.
        data[i] = (Math.random() * 2 - 1) * (1 - i / frames);
    }

    const source = ctx.createBufferSource();
    source.buffer = buffer;

    const filter = ctx.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.value = frequency;
    filter.Q.value = q;

    const amp = ctx.createGain();
    amp.gain.value = gain;

    source.connect(filter);
    filter.connect(amp);
    amp.connect(ctx.destination);

    source.start(at);
}

/**
 * The voices, one per kind of good news.
 *
 * Every celebration used to play the same three notes, which meant a level-up
 * and a redeemed reward sounded like the same event with different words on it.
 * These are synthesised rather than sampled: no assets, no loading, and they
 * can't be late to their own celebration.
 */
const CELEBRATION_SOUNDS = {
    /** The old arpeggio, still the default for anything unclassified. */
    chime(ctx, now, tier) {
        const notes = tier === 'small' ? [523, 659, 880] : [523, 659, 880, 1046];

        notes.forEach((freq, i) => tone(ctx, { freq, at: now + i * 0.09 }));
    },

    /** Ka-ching. Two bright strikes and a bell left ringing behind them. */
    cash(ctx, now) {
        tone(ctx, { freq: 1318, at: now, type: 'square', gain: 0.1, decay: 0.09 });
        tone(ctx, { freq: 1760, at: now + 0.05, type: 'square', gain: 0.1, decay: 0.09 });
        tone(ctx, { freq: 880, at: now + 0.08, type: 'triangle', gain: 0.14, decay: 0.42 });
        noise(ctx, { at: now, duration: 0.1, gain: 0.05, frequency: 5200, q: 0.7 });
    },

    /** A ladder climbed. For levels and anything else that goes *up*. */
    sparkle(ctx, now, tier) {
        const notes = tier === 'epic'
            ? [523, 659, 784, 1046, 1318, 1568]
            : [523, 659, 784, 1046, 1318];

        notes.forEach((freq, i) => tone(ctx, {
            freq,
            at: now + i * 0.07,
            type: 'sine',
            gain: 0.13,
            decay: 0.3,
        }));

        tone(ctx, { freq: 2093, at: now + notes.length * 0.07, type: 'sine', gain: 0.08, decay: 0.6 });
    },

    /** Warm and unhurried — the one celebration that isn't about winning. */
    soft(ctx, now) {
        tone(ctx, { freq: 392, at: now, type: 'sine', gain: 0.12, decay: 0.5 });
        tone(ctx, { freq: 523, at: now + 0.16, type: 'sine', gain: 0.12, decay: 0.62 });
    },

    /** Torn stub, flicked twice. Almost all noise, barely any pitch. */
    paper(ctx, now) {
        noise(ctx, { at: now, duration: 0.14, gain: 0.1, frequency: 2600, q: 0.6 });
        noise(ctx, { at: now + 0.11, duration: 0.1, gain: 0.07, frequency: 3400, q: 0.6 });
        tone(ctx, { freq: 784, at: now + 0.06, type: 'triangle', gain: 0.07, decay: 0.16 });
    },

    /** Something large hitting the floor. Reserved for the boss. */
    impact(ctx, now) {
        tone(ctx, { freq: 160, at: now, type: 'sine', gain: 0.26, decay: 0.5, sweepTo: 42 });
        noise(ctx, { at: now, duration: 0.34, gain: 0.16, frequency: 220, q: 0.5 });
        tone(ctx, { freq: 98, at: now + 0.14, type: 'square', gain: 0.1, decay: 0.34, sweepTo: 55 });
    },
};

/** How long each stage of a catch-up replay holds before the next blow lands. */
const BOSS_STEP_MS = 1500;

/** Matches the fq-boss-hit keyframes in app.css. */
const BOSS_HIT_MS = 520;

/**
 * Replays the damage a kid missed while they were away.
 *
 * A family goal moves whenever anyone's chore is approved, so a kid who logs in
 * after a busy afternoon would otherwise find the monster simply *already*
 * beaten up — the part that makes it worth watching having happened to somebody
 * else. So the server hands over every stage between what this kid last saw and
 * where the boss stands now, and the arena walks through them one blow at a
 * time before settling on the truth.
 *
 * The steps are rendered server-side and stacked; this only decides which one
 * is visible. That keeps six monsters' worth of artwork out of JavaScript.
 */
/**
 * How dread-soaked the arena's floor glow and card wash are, 0-1. The design
 * bundle's gallery exposes this as a slider; the arena is pinned to 0.7 and the
 * sidebar thumbnail sits lower, where a heavy glow would just be murk.
 */
const BOSS_DREAD = 0.7;

const BOSS_DREAD_MINI = 0.55;

/** Draws a monster, or nothing at all if the artwork failed to load. */
function monsterSvg(skin, stage, dread) {
    return window.FQMonsters ? window.FQMonsters.svg(skin, stage, { dread }) : '';
}

function monsterCardBg(skin, dread) {
    return window.FQMonsters ? window.FQMonsters.cardBg(skin, dread) : '';
}

/**
 * A single monster, for the Quests sidebar. The arena has its own component
 * because it also owns the replay.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqMonster', (skin, stage, dread = BOSS_DREAD_MINI) => ({
        get svg() {
            return monsterSvg(skin, stage, dread);
        },
        get cardBg() {
            return monsterCardBg(skin, dread);
        },
    }));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqBossReplay', (steps, skin) => ({
        steps,
        skin,
        index: 0,
        hit: false,
        timer: null,
        hitTimer: null,

        init() {
            if (this.steps.length < 2) {
                return;
            }

            // Someone who has asked the OS for less movement gets the outcome
            // without the show.
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                this.finish();

                return;
            }

            this.queue();
        },

        destroy() {
            clearTimeout(this.timer);
            clearTimeout(this.hitTimer);
        },

        queue() {
            this.timer = setTimeout(() => this.advance(), BOSS_STEP_MS);
        },

        advance() {
            if (! this.replaying) {
                return;
            }

            this.index++;
            this.hit = true;
            clearTimeout(this.hitTimer);
            this.hitTimer = setTimeout(() => (this.hit = false), BOSS_HIT_MS);

            if (this.replaying) {
                this.queue();
            }
        },

        /** Tapping the arena skips to the end — patience is not universal. */
        finish() {
            clearTimeout(this.timer);
            clearTimeout(this.hitTimer);
            this.index = this.steps.length - 1;
            this.hit = false;
        },

        get current() {
            return this.steps[this.index];
        },

        get replaying() {
            return this.index < this.steps.length - 1;
        },

        /** The artwork for one step of the replay. */
        monster(stage) {
            return monsterSvg(this.skin, stage, BOSS_DREAD);
        },

        get cardBg() {
            return monsterCardBg(this.skin, BOSS_DREAD);
        },

        /** The blow that got us to the step now showing, as a "-320" chip. */
        get hitLabel() {
            const landed = this.current.landed;

            return landed > 0 ? `-${landed.toLocaleString()}` : '';
        },
    }));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqCelebrations', () => ({
        toast: null,
        toastTimer: null,
        treat: null,
        mode: 'money',
        motion: 'fall',
        origin: null,
        tier: 'small',
        sound: null,
        /**
         * A set piece that replaces the card's ordinary arrival: 'level' for
         * the slam, 'boss' for the knockout. Null for everything else, which
         * is most things — a hero is what makes the rare ones rare.
         */
        hero: null,
        celebrating: false,
        burst: 0,
        queue: [],
        showing: false,
        reduced: false,
        /**
         * Set when a queued celebration is big enough to deserve the chest's
         * full-screen reveal rather than a toast along the bottom edge.
         */
        card: null,

        init() {
            this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        destroy() {
            clearTimeout(this.toastTimer);
        },

        /**
         * Kept because a good deal of the template still asks the old question.
         * `tier` is the truth; this is "louder than an ordinary one".
         */
        get big() {
            return this.tier !== 'small';
        },

        /**
         * Queued rather than overwritten. One action routinely pays out more
         * than once — a chest hands over tickets and the badges it just
         * unlocked hand over more — and a single toast that gets replaced
         * mid-flight leaves the extra tickets looking like they appeared from
         * nowhere.
         */
        celebrate(options) {
            const o = options || {};

            this.queue.push({
                message: o.message ?? null,
                treat: o.treat ?? null,
                style: o.style ?? 'money',
                motion: o.motion ?? 'fall',
                // Resolved now, not at advance(): the tap that caused this is
                // the last one that happened, and by the time this reaches the
                // front of the queue there may well have been another.
                origin: o.origin === 'tap' ? lastTap : (o.origin ?? null),
                sound: o.sound ?? null,
                // `big: true` is what every existing caller sends, and there is
                // no reason to go and rewrite them all to say 'big'.
                tier: o.tier ?? (o.big ? 'big' : 'small'),
                hero: o.hero ?? null,
                hold: o.hold ?? null,
                card: o.card ?? null,
            });

            if (! this.showing) {
                this.advance();
            }
        },

        advance() {
            const next = this.queue.shift();

            if (! next) {
                this.showing = false;
                this.toast = null;
                this.card = null;
                this.celebrating = false;

                return;
            }

            this.showing = true;
            this.card = next.card;
            // A card carries its own headline, so a toast repeating it along
            // the bottom would just be the same news said twice.
            this.toast = next.card ? null : next.message;
            this.treat = next.treat;
            this.mode = next.style;
            this.motion = next.motion;
            this.origin = next.origin;
            this.tier = CELEBRATION_TIERS[next.tier] ? next.tier : 'small';
            this.sound = next.sound;
            this.hero = next.hero;
            this.celebrating = true;
            this.burst++;
            this.playSound();

            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.advance(), next.hold ?? CELEBRATION_TIERS[this.tier].hold);
        },

        /**
         * Held back a tick on purpose. Livewire fires its browser events while
         * the response is still being applied, which is before a chest or wheel
         * resolves its own await and announces the prize — so without the gap
         * these would jump the reveal that earned them.
         */
        queueRewards(rewards) {
            setTimeout(() => {
                (rewards || []).forEach((reward) => {
                    const tier = reward.tier ?? (reward.big ? 'big' : 'small');

                    this.celebrate({
                        message: reward.message,
                        style: reward.style ?? 'confetti',
                        motion: reward.motion,
                        sound: reward.sound,
                        tier,
                        hero: reward.hero,
                        // Shorter than a tier's own default, because these
                        // arrive in a queue: a kid coming back to three days of
                        // news should not be held at the door for each item.
                        // An epic still gets its length — fireworks go off in
                        // shells, and the last one lands a good second in.
                        hold: reward.hold ?? REWARD_HOLDS[tier],
                        card: reward.card,
                    });
                });
            }, 60);
        },

        /**
         * The monster, face down, for the knockout card.
         *
         * The skin rides on the card because the celebration can be days old by
         * the time a kid opens the app, and the household has very likely
         * rotated to the next monster since — drawing whatever is current would
         * stamp DEFEATED on something nobody has hit yet.
         *
         * Empty rather than broken if the artwork failed to load, same as the
         * arena: a card with no monster still says the boss is down.
         */
        /**
         * How the card itself arrives. A hero doesn't add decoration around an
         * ordinary card — it changes the way the card lands, which is the part
         * a kid actually reads as "this one is different".
         */
        get cardStyle() {
            const arrival = {
                level: 'fq-slam .55s cubic-bezier(.18,1.5,.4,1) both',
                boss: 'fq-ko-shake .6s ease-out both',
            }[this.hero] ?? 'fq-pop .4s ease both';

            return `animation: ${arrival}; background: var(--fq-sunk); border-color: ${this.card.accent}; box-shadow: 0 26px 60px -20px #000`;
        },

        get bossArt() {
            return this.card?.skin && window.FQMonsters
                ? window.FQMonsters.svg(this.card.skin, 'defeated', { dread: 0.7 })
                : '';
        },

        /**
         * A phone gets roughly half the pieces. The count was written for a
         * desktop viewport and never asked: 560 absolutely positioned spans,
         * each with its own inline style object, is a lot to hand a tablet in
         * the middle of an animation.
         */
        density() {
            return window.innerWidth < 640 ? 0.45 : 1;
        },

        pieceCount() {
            if (this.burst === 0 || this.reduced) {
                return 0;
            }

            return Math.round(CELEBRATION_TIERS[this.tier].pieces * this.density());
        },

        /**
         * The two columns hugging the screen edges belong to the falling rain
         * only — a burst or a cannon already decides where its pieces go, and
         * bolting edge columns onto one just puts confetti somewhere the
         * explosion didn't throw it.
         */
        sideCount() {
            if (this.burst === 0 || this.reduced || this.motion !== 'fall') {
                return 0;
            }

            return Math.round(CELEBRATION_TIERS[this.tier].side * this.density());
        },

        /**
         * What a piece looks like. Split from where it goes so a new treat is a
         * shape and nothing else — the money rain, the cookies and the confetti
         * all used to carry their own copy of the falling animation.
         */
        shapeStyle(i) {
            switch (this.mode) {
                case 'confetti':
                    return this.confettiShape(i);
                case 'star':
                    return this.starShape(i);
                case 'heart':
                    return this.heartShape(i);
                case 'ticket':
                    return this.ticketShape(i);
                default:
                    return this.moneyShape(i);
            }
        },

        confettiShape(i) {
            const colors = ['var(--fq-lime)', 'var(--fq-cyan)', 'var(--fq-gold)', 'var(--fq-magenta)', 'var(--fq-coral)', 'var(--fq-violet)', 'var(--fq-sky)'];
            const square = i % 2 === 0;

            return {
                width: square ? '10px' : '6px',
                height: square ? '10px' : '14px',
                borderRadius: '2px',
                background: colors[i % colors.length],
                boxShadow: '0 1px 2px rgba(0,0,0,.35)',
            };
        },

        /**
         * Four-point sparkles for a level. Clip-path rather than a drawn glyph
         * because the points are percentages, so one polygon covers both sizes.
         */
        starShape(i) {
            const colors = ['var(--fq-gold)', 'var(--fq-violet)', '#fff6c4', 'var(--fq-sky)'];
            const size = i % 3 === 0 ? 17 : 11;

            return {
                width: size + 'px',
                height: size + 'px',
                background: colors[i % colors.length],
                clipPath: 'polygon(50% 0%, 61% 39%, 100% 50%, 61% 61%, 50% 100%, 39% 61%, 0% 50%, 39% 39%)',
                filter: 'drop-shadow(0 0 3px rgba(255,228,130,.7))',
            };
        },

        /**
         * A heart has curves a clip-path polygon can only approximate into
         * something spiky, and this is the one celebration that is meant to be
         * soft — so it's drawn, as an inline SVG the piece carries as its own
         * background. No request, nothing to load late.
         */
        heartShape(i) {
            const fills = ['%23ff6b8a', '%23ff9ab0', '%23ffd1dc', '%23e0365b'];
            const size = i % 3 === 0 ? 18 : 12;
            const path = 'M16 28.7C16 28.7 0 18.6 0 8.9C0 4 4 0 8.9 0C12.4 0 15.1 2 16 4.4C16.9 2 19.6 0 23.1 0C28 0 32 4 32 8.9C32 18.6 16 28.7 16 28.7Z';

            return {
                width: size + 'px',
                height: size + 'px',
                background: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 29'%3E%3Cpath fill='"
                    + fills[i % fills.length] + "' d='" + path + "'/%3E%3C/svg%3E\") center/contain no-repeat",
                filter: 'drop-shadow(0 1px 1px rgba(0,0,0,.35))',
            };
        },

        /**
         * Torn stubs, notched down both sides. Tickets used to fall as coins,
         * which said the wrong currency in the one place the app has two.
         */
        ticketShape(i) {
            const warm = i % 2 === 0;
            const stub = 'linear-gradient(90deg, transparent 0 13px, rgba(0,0,0,.3) 13px 14px, transparent 14px)';

            return {
                width: '21px',
                height: '12px',
                background: warm
                    ? stub + ', linear-gradient(160deg, #ffe9a3, #f0c75e 60%, #d9a72f)'
                    : stub + ', linear-gradient(160deg, #bdf5c8, #7fd99a 60%, #4c9a5b)',
                clipPath: 'polygon(0 0, 100% 0, 100% 32%, 93% 50%, 100% 68%, 100% 100%, 0 100%, 0 68%, 7% 50%, 0 32%)',
                filter: 'drop-shadow(0 1px 1px rgba(0,0,0,.45))',
            };
        },

        moneyShape(i) {
            if (this.treat === 'cookie' && i % 3 === 0) {
                // Chocolate chip cookie.
                return {
                    width: '15px',
                    height: '15px',
                    borderRadius: '50%',
                    border: '1px solid #a8692a',
                    background: 'radial-gradient(circle at 30% 30%, #6b4423 6%, transparent 7%), radial-gradient(circle at 65% 55%, #6b4423 6%, transparent 7%), radial-gradient(circle at 40% 78%, #6b4423 6%, transparent 7%), radial-gradient(circle at 72% 22%, #6b4423 5%, transparent 6%), #c9873f',
                    boxShadow: '0 1px 2px rgba(0,0,0,.4)',
                };
            }

            if (i % 2 === 0) {
                // Dollar bill: small green rectangle with a lighter seal mark.
                return {
                    width: '18px',
                    height: '10px',
                    borderRadius: '2px',
                    border: '1px solid #2f6b3c',
                    background: 'radial-gradient(circle at 50% 50%, #d7f0da 22%, transparent 23%), linear-gradient(135deg, #8fd19e, #4c9a5b)',
                    boxShadow: '0 1px 2px rgba(0,0,0,.4)',
                };
            }

            // Coin: shiny gold circle.
            return {
                width: '14px',
                height: '14px',
                borderRadius: '50%',
                border: '1px solid #8a6a1f',
                background: 'radial-gradient(circle at 35% 30%, #ffe89b, #d4af37 60%, #a8791f 100%)',
                boxShadow: 'inset 0 -2px 2px rgba(0,0,0,.35), 0 1px 2px rgba(0,0,0,.4)',
            };
        },

        /**
         * Where a piece starts and how it travels.
         *
         * Anything that isn't a straight fall hands its direction to CSS as
         * `--fq-dx`/`--fq-dy` custom properties, because a keyframe can't be
         * written per piece but it can read one.
         */
        motionStyle(i) {
            if (this.motion === 'cannon') {
                return this.cannonStyle(i);
            }

            if (this.motion === 'burst' || this.motion === 'fireworks') {
                return this.burstStyle(i);
            }

            return {
                left: (2 + (i * 53) % 96) + 'vw',
                top: '10vh',
                animation: 'fq-fall ' + (1.4 + (i % 12) / 8) + 's ease-in forwards',
                animationDelay: ((i % 24) * 0.09) + 's',
            };
        },

        /** Launched from the two bottom corners, arcing up and inward. */
        cannonStyle(i) {
            const fromLeft = i % 2 === 0;
            const reach = 18 + (i * 31) % 52;
            const lift = 46 + (i * 17) % 38;

            return {
                left: (fromLeft ? 3 : 97) + 'vw',
                top: '94vh',
                '--fq-dx': (fromLeft ? reach : -reach) + 'vw',
                '--fq-dy': '-' + lift + 'vh',
                '--fq-drop': (10 + (i % 9) * 4) + 'vh',
                '--fq-spin': (((i % 5) - 2) * 220) + 'deg',
                animation: 'fq-arc ' + (1.7 + (i % 10) / 7) + 's cubic-bezier(.2,.62,.4,1) forwards',
                animationDelay: ((i % 12) * 0.035) + 's',
            };
        },

        /**
         * Thrown outward from a point — the tile that was tapped, or, for
         * fireworks, a handful of points going off one after another.
         */
        burstStyle(i) {
            const shells = [
                { x: 0.22, y: 0.3 },
                { x: 0.76, y: 0.24 },
                { x: 0.42, y: 0.5 },
                { x: 0.82, y: 0.58 },
            ];

            let x;
            let y;
            let delay;

            if (this.motion === 'fireworks') {
                const shell = shells[i % shells.length];
                x = shell.x * window.innerWidth;
                y = shell.y * window.innerHeight;
                delay = (i % shells.length) * 0.36 + ((i % 7) * 0.012);
            } else {
                x = this.origin?.x ?? window.innerWidth / 2;
                y = this.origin?.y ?? window.innerHeight / 2;
                delay = (i % 6) * 0.02;
            }

            // The golden angle, so successive pieces never line up into spokes.
            const angle = i * 137.507 * (Math.PI / 180);
            const spread = Math.min(window.innerWidth, window.innerHeight) * 0.55;
            const distance = spread * (0.35 + ((i * 29) % 65) / 100);

            return {
                left: x + 'px',
                top: y + 'px',
                '--fq-dx': Math.cos(angle) * distance + 'px',
                // Biased downward so the far side of the burst falls away
                // rather than hanging in the air.
                '--fq-dy': (Math.sin(angle) * distance + distance * 0.45) + 'px',
                '--fq-spin': (((i % 5) - 2) * 260) + 'deg',
                animation: 'fq-burst ' + (1.1 + (i % 9) / 9) + 's cubic-bezier(.16,.75,.35,1) forwards',
                animationDelay: delay + 's',
            };
        },

        pieceStyle(i) {
            return {
                position: 'fixed',
                ...this.shapeStyle(i),
                ...this.motionStyle(i),
            };
        },

        /** Same rain as pieceStyle(), anchored near a screen edge instead. */
        sidePieceStyle(i, edgeVw) {
            const style = this.pieceStyle(i);
            style.left = (edgeVw + (i * 17) % 14) + 'vw';

            return style;
        },

        playSound() {
            if (localStorage.getItem('fq-muted') === '1') {
                return;
            }

            try {
                const ctx = audio();

                if (! ctx) {
                    return;
                }

                const voice = CELEBRATION_SOUNDS[this.sound ?? CELEBRATION_VOICES[this.mode] ?? 'chime']
                    ?? CELEBRATION_SOUNDS.chime;

                voice(ctx, ctx.currentTime, this.tier);
            } catch (e) {}
        },
    }));
});
