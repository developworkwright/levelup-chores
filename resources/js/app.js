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
