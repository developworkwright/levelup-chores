/*
 * `<fq-prize>`: the prize counter's artwork, drawn into a shadow root.
 *
 * `prizes.js` is the art and is shipped verbatim from
 * handoff/design_handoff_arcade_tokens; this is the app's glue around it, for
 * the same reason `<fq-cosmetic>` exists — the server's markup for a prize is
 * an empty tag, so SVG written into the light DOM would be morphed away on the
 * next Livewire round trip. The morph does update attributes, so a changed
 * `key` re-renders.
 *
 *   <fq-prize kind="snack" key="burger">   a snack, toy or bed
 *   <fq-prize kind="candy" key="330">      a sweet, in that hue
 *   <fq-prize kind="token">                the coin
 *   <fq-prize prize="toy:ball">            both at once, for an Alpine binding —
 *                                          Alpine treats a bound `key` as its own
 *
 * The element fills whatever box it is given; size it from outside.
 */
class FqPrize extends HTMLElement {
    static get observedAttributes() {
        return ['kind', 'key', 'prize'];
    }

    connectedCallback() {
        this.render();
    }

    attributeChangedCallback() {
        if (this.isConnected) {
            this.render();
        }
    }

    render() {
        const root = this.shadowRoot || this.attachShadow({ mode: 'open' });
        const art = window.FQPrizes;
        const [kind, key] = this.hasAttribute('prize')
            ? String(this.getAttribute('prize')).split(':')
            : [this.getAttribute('kind'), this.getAttribute('key')];

        root.innerHTML = '<style>:host{display:block;position:relative}svg{position:absolute;inset:0;display:block}</style>'
            + (art ? art.draw(kind || 'token', key || '') : '');
    }
}

if (! customElements.get('fq-prize')) {
    customElements.define('fq-prize', FqPrize);
}
