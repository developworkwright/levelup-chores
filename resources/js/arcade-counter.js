/*
 * The prize counter's Alpine side: which prize is picked, what the keeper says
 * about it, and the tray that slides up to buy it. Everything it spends goes
 * through the Livewire component — this only ever decides what to *say*.
 *
 * A prize arrives whole in the tap that picks it (`pick(item)`), balance and
 * all, rather than from a table baked into x-data: Livewire re-renders the
 * shelves after every purchase and Alpine never re-reads x-data, so a lookup
 * built at first paint would describe a counter that no longer exists. See the
 * Alpine + Livewire morph notes in the arcade component.
 *
 * Buying anything closes the tray, which is what keeps a picked prize from
 * going stale: the next tap carries the new balance and the new "yours".
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqCounter', () => ({
        /** The prize on the tray, as the shelf described it — or null. */
        sel: null,

        /** Whether the picked prize is on the pet's floor for a look. */
        trying: false,

        pick(item) {
            if (this.sel && this.sel.id === item.id) {
                this.close();

                return;
            }

            this.stopTrying();
            this.sel = item;
        },

        close() {
            this.stopTrying();
            this.sel = null;
        },

        get afford() {
            return this.sel !== null && this.sel.bank >= this.sel.cost;
        },

        get short() {
            return this.sel === null ? 0 : Math.max(0, this.sel.cost - this.sel.bank);
        },

        /** Whether the tray's button does anything. */
        get can() {
            const item = this.sel;

            return item !== null && ! item.out && ! item.soldOut && (item.owned || this.afford);
        },

        /** What the keeper says, with a prize on the tray. */
        get line() {
            const item = this.sel;

            if (item === null) {
                return '';
            }

            if (item.out) {
                return 'That one’s already out.';
            }

            if (item.owned) {
                return 'Yours already. Want it out?';
            }

            if (item.soldOut) {
                return 'All gone. Ask a grown-up for more.';
            }

            return this.afford ? item.cost + ' tokens. Sure?' : this.short + ' short. Go play.';
        },

        get cta() {
            const item = this.sel;

            if (item === null) {
                return '';
            }

            if (item.out) {
                return 'Already out';
            }

            if (item.owned) {
                return 'Put it out';
            }

            if (item.soldOut) {
                return 'None left';
            }

            return this.afford ? 'Buy it · ' + item.cost + ' ✦' : this.short + ' more tokens';
        },

        get note() {
            const item = this.sel;

            if (item === null) {
                return '';
            }

            if (item.owned) {
                return 'You own this — swapping is free.';
            }

            if (item.soldOut) {
                return 'A grown-up puts more in the cupboard.';
            }

            if (this.afford) {
                return 'You’ll have ' + (item.bank - item.cost) + ' left.';
            }

            // Three a run is what a decent run pays on the way up a ladder.
            const runs = Math.ceil(this.short / 3);

            return 'About ' + runs + (runs === 1 ? ' run' : ' runs') + ' — or one chore refills the machine by 15.';
        },

        /** Only a pet prize can be tried, and only before it is yours. */
        get canTry() {
            return this.sel !== null && ['snack', 'toy', 'bed'].includes(this.sel.kind) && ! this.sel.owned;
        },

        /** Puts the picked prize on the pet's own floor, or takes it off again. */
        tryIt() {
            this.trying = ! this.trying;
            window.dispatchEvent(new CustomEvent('fq-pet-try', {
                detail: this.trying ? { kind: this.sel.kind, key: this.sel.key } : null,
            }));
        },

        stopTrying() {
            if (this.trying) {
                this.trying = false;
                window.dispatchEvent(new CustomEvent('fq-pet-try', { detail: null }));
            }
        },

        /** The tray's one button: buy it, or put it out if it is yours. */
        act() {
            const item = this.sel;

            if (! this.can) {
                return;
            }

            this.close();

            if (item.kind === 'ticket') {
                this.$wire.buyTicket();
            } else if (item.kind === 'candy') {
                this.$wire.buyCandy(item.candyId);
            } else if (item.owned) {
                this.$wire.putOutPrize(item.kind, item.key);
            } else {
                this.$wire.buyPrize(item.kind, item.key);
            }
        },
    }));
});
