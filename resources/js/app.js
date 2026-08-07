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
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqBossReplay', (steps) => ({
        steps,
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

        /** The blow that got us to the step now showing, as a "-320" chip. */
        get hitLabel() {
            const landed = this.current.landed;

            return landed > 0 ? `-${landed.toLocaleString()}` : '';
        },
    }));
});
