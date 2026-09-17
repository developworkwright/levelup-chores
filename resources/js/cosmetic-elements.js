/*
 * The elements the app draws cosmetics with. `cosmetics.js` is the artwork and
 * is shipped verbatim; this file is the app's own glue around it.
 *
 * Everything renders into a shadow root, and that is the point of them being
 * custom elements at all. Livewire morphs the light DOM against the server's
 * markup on every round trip, and the server's markup for a frame is an empty
 * tag — so SVG written into the light DOM would be stripped the moment a kid
 * bought anything. A shadow root is invisible to the morph. The morph *does*
 * update attributes, so a changed `recipe` re-renders through
 * attributeChangedCallback.
 *
 *   <fq-cosmetic kind="frame" recipe="orbit">          generated art
 *   <fq-cosmetic kind="frame" src="/cosmetics/art/9">  an uploaded picture
 *     mode="fill"   the art edge to edge, for a face; default is a shop tile
 *     still         no motion — feed rows and boards
 *     label="COLTON" the name drawn on a plate's tile
 *   <fq-plate recipe="tape">Colton</fq-plate>          a name on its plate
 *   <fq-cabinet recipe="chrome">…</fq-cabinet>         the arcade machine's bezel
 *   <fq-spark recipe="bats">                           bursts on every celebration
 */

const REDUCED = '@media (prefers-reduced-motion: reduce) { *, :host { animation: none !important; } }';

function C() {
    return window.FQCosmetics;
}

function keyframes() {
    return C() ? C().KEYFRAMES : '';
}

/** The CSS `animation` a motion word stands for, or ''. */
function motionCss(word) {
    return (word && C() && C().MOTION[word]) || '';
}

/**
 * A URL, safe to drop inside a CSS url("...").
 *
 * Never encodeURI: that escapes the percent signs in an already-encoded URL, so
 * a presigned S3 link — which is most of what Livewire hands back for an upload
 * preview in production — turns its %2F into %252F and 404s. The URL arrives
 * encoded already; all this has to do is survive being inside quotes.
 */
function cssUrl(src) {
    return String(src).replace(/["\\]/g, (character) => '\\' + character).replace(/[\r\n]/g, '');
}

function el(tag, style) {
    const node = document.createElement(tag);

    if (style) {
        node.setAttribute('style', style);
    }

    return node;
}

/** An uploaded picture, moving if its row says so. */
function picture(src, motion, fit) {
    const img = el('img', 'position:absolute;inset:0;width:100%;height:100%;object-fit:' + (fit || 'contain') + ';display:block' +
        (motion ? ';animation:' + motion + ';transform-origin:50% 50%' : ''));
    img.alt = '';
    img.src = src;
    img.draggable = false;

    return img;
}

class FqCosmetic extends HTMLElement {
    static get observedAttributes() {
        return ['kind', 'recipe', 'src', 'motion', 'mode', 'still', 'label', 'pose'];
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
        const kind = this.getAttribute('kind');
        const recipe = this.getAttribute('recipe');
        const src = this.getAttribute('src');
        const still = this.hasAttribute('still');
        const tile = this.getAttribute('mode') !== 'fill';

        root.replaceChildren();

        const style = document.createElement('style');
        style.textContent = ':host{display:block;position:relative}' +
            '.box{position:absolute;inset:0;container-type:size;overflow:visible}' + keyframes() + REDUCED;
        root.append(style);

        if (! C() || (! recipe && ! src)) {
            return;
        }

        const padding = tile ? ({ frame: '9%', avatar: '6%', spark: '4%' }[kind] || '0') : '0';
        const box = el('div', 'padding:' + padding);
        box.className = 'box';
        root.append(box);

        const inner = el('div', 'position:relative;width:100%;height:100%');
        box.append(inner);

        const uploadMotion = still ? '' : motionCss(this.getAttribute('motion'));

        if (kind === 'frame' || kind === 'avatar' || kind === 'spark') {
            if (src) {
                inner.append(picture(src, uploadMotion));

                return;
            }

            const opts = still ? { anim: null } : {};
            const svg = kind === 'frame'
                ? C().frameSvg(recipe, opts)
                : (kind === 'avatar' ? C().avatarSvg(recipe, opts) : C().sparkSvg(recipe, opts));

            // The recipes are the bundle's own code over the bundle's own
            // palettes; nothing a user typed ever reaches this string.
            inner.innerHTML = svg;

            return;
        }

        /*
         * A pet is a 4x3 sprite sheet, so a still one is a window onto one cell
         * of it — `pose` names which, defaulting to the idle pose. The sheet is
         * scaled to 400% by 300% so one cell fills the box exactly, which is
         * how the engine will show a pet too.
         */
        if (kind === 'pet') {
            if (! src) {
                return;
            }

            const poses = ['idle', 'blink', 'crouch', 'jump', 'walk', 'happy', 'held', 'landed', 'play', 'toss', 'sleep', 'toy'];
            const index = Math.max(0, poses.indexOf(this.getAttribute('pose') || 'idle'));
            const column = index % 4;
            const row = Math.floor(index / 4);

            box.style.background = 'url("' + cssUrl(src) + '") no-repeat';
            box.style.backgroundSize = '400% 300%';
            // Thirds and halves of the leftover space, which is what a
            // percentage background-position means: column 1 of 4 is 33.3%.
            box.style.backgroundPosition = (column * 100 / 3) + '% ' + (row * 100 / 2) + '%';

            return;
        }

        if (kind === 'pattern') {
            const anim = still ? '' : (src ? uploadMotion : (C().patternAnim ? C().patternAnim(recipe) : ''));
            box.style.background = src ? 'url("' + cssUrl(src) + '") 0 0 / 128px 128px repeat' : C().pattern(recipe);

            if (anim) {
                box.style.animation = anim;
            }

            return;
        }

        if (kind === 'theme') {
            const t = C().theme(recipe);
            box.style.background = t.bg;
            [
                'inset:18%;border-radius:14%;background:' + t.panel + ';border:1px solid ' + t.line,
                'left:26%;top:30%;width:30%;height:9%;border-radius:99px;background:' + t.ink,
                'left:26%;top:46%;width:46%;height:7%;border-radius:99px;background:' + t.muted,
                'left:26%;bottom:26%;width:16%;height:16%;border-radius:99px;background:' + t.accent,
                'left:46%;bottom:26%;width:16%;height:16%;border-radius:99px;background:' + t.accent2,
            ].forEach((css) => box.append(el('div', 'position:absolute;' + css)));

            return;
        }

        if (kind === 'plate') {
            box.style.background = '#0a0512';
            const name = el('div', 'position:absolute;left:8%;right:8%;top:38%;padding:6% 4%;text-align:center;font-family:"Baloo 2",cursive;font-weight:800;font-size:15cqw;line-height:1;overflow:hidden;white-space:nowrap');
            name.textContent = this.getAttribute('label') || 'NAME';

            if (src) {
                name.style.background = 'url("' + cssUrl(src) + '") center / 100% 100% no-repeat';
                name.style.color = '#fff';
                name.style.textShadow = '0 1px 3px #000';
            } else {
                const p = C().plate(recipe);
                name.style.background = p.background;
                name.style.border = p.border || 'none';
                name.style.color = p.ink;
                name.style.borderRadius = p.radius;
                name.style.clipPath = p.clip || 'none';
                name.style.boxShadow = p.shadow || 'none';
                name.style.transform = p.skew ? 'skewY(' + p.skew + ')' : 'none';
            }

            box.append(name);

            return;
        }

        if (kind === 'cabinet') {
            box.style.background = '#07030f';

            if (src) {
                inner.append(picture(src, ''));

                return;
            }

            const c = C().cabinet(recipe);
            [
                'inset:14%;border-radius:10%;background:' + c.bezel + ';border:1px solid ' + c.edge,
                'left:22%;right:22%;top:20%;height:13%;border-radius:4px;background:' + c.marquee,
                'left:24%;right:24%;top:38%;height:34%;border-radius:4px;background:#05030a;box-shadow:inset 0 0 12px ' + c.glow,
                'left:34%;right:34%;bottom:20%;height:6%;border-radius:99px;background:' + c.edge,
            ].forEach((css) => box.append(el('div', 'position:absolute;' + css)));
        }
    }
}

/**
 * A name on its plate. The name is light DOM — a <slot> — so it is readable
 * before the script runs and a morph can change it; the plate itself is a
 * :host rule, which a morph can't strip the way it strips a style attribute.
 */
class FqPlate extends HTMLElement {
    static get observedAttributes() {
        return ['recipe', 'src'];
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
        const src = this.getAttribute('src');
        const style = document.createElement('style');

        if (src) {
            style.textContent = ':host{display:inline-block;background:url("' + cssUrl(src) + '") center / 100% 100% no-repeat;color:#fff;text-shadow:0 1px 3px #000}';
        } else if (C()) {
            const p = C().plate(this.getAttribute('recipe'));
            // `!important` on the border only because Tailwind's preflight
            // (`* { border: 0 solid }`) is an outer style, and an outer style
            // beats a :host rule unless the :host rule is important.
            style.textContent = ':host{display:inline-block;background:' + p.background + ';border:' + (p.border || 'none') + ' !important' +
                ';color:' + p.ink + ';border-radius:' + p.radius + ';clip-path:' + (p.clip || 'none') +
                ';box-shadow:' + (p.shadow || 'none') + ';transform:' + (p.skew ? 'skewY(' + p.skew + ')' : 'none') + '}';
        }

        root.replaceChildren(style, document.createElement('slot'));
    }
}

/** The arcade machine's bezel, around whatever game is inside it. */
class FqCabinet extends HTMLElement {
    static get observedAttributes() {
        return ['recipe', 'src'];
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
        const src = this.getAttribute('src');
        const style = document.createElement('style');
        // Padding and border are `!important` for the reason FqPlate's border
        // is: Tailwind's preflight zeroes both from outside the shadow root.
        const base = ':host{display:flex;flex-direction:column;gap:11px;border-radius:24px;padding:12px !important;border-width:1px !important;border-style:solid !important;';

        if (src) {
            style.textContent = base + 'border-color:#3a2360 !important;background:linear-gradient(rgba(10,5,18,.55),rgba(10,5,18,.55)),url("' + cssUrl(src) + '") center / cover no-repeat}';
        } else if (C()) {
            const c = C().cabinet(this.getAttribute('recipe'));
            style.textContent = base + 'border-color:' + c.edge + ' !important;background:' + c.bezel + ';box-shadow:0 0 28px ' + c.glow + '}';
        }

        root.replaceChildren(style, document.createElement('slot'));
    }
}

/**
 * A kid's tap effect. Sits invisibly in their shell and, whenever the app
 * celebrates anything, throws the art out from wherever the last tap landed.
 * The celebration itself is untouched — this rides on top of it.
 */
let lastPoint = null;

document.addEventListener('pointerdown', (event) => {
    lastPoint = { x: event.clientX, y: event.clientY };
}, { capture: true, passive: true });

class FqSpark extends HTMLElement {
    /*
     * `trigger` names the window event it bursts on. The worn one listens for
     * `celebrate`; the locker's try-on preview listens for its own event, so
     * seeing a tap effect you haven't bought doesn't also fire the one you have.
     */
    connectedCallback() {
        // The worn one also answers a petted pet: stroking the animal throws
        // the kid's own tap effect out of it, which is two bought things
        // meeting and the cheapest joke in the app.
        this.triggers = this.hasAttribute('trigger')
            ? [this.getAttribute('trigger')]
            : ['celebrate', 'fq-pet-petted'];

        this.onCelebrate = () => this.fire();
        this.triggers.forEach((name) => window.addEventListener(name, this.onCelebrate));
    }

    disconnectedCallback() {
        this.triggers.forEach((name) => window.removeEventListener(name, this.onCelebrate));
    }

    fire() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! C()) {
            return;
        }

        const size = 170;
        const x = lastPoint ? lastPoint.x : window.innerWidth / 2;
        const y = lastPoint ? lastPoint.y : window.innerHeight / 2;
        const burst = document.createElement('fq-cosmetic');

        burst.setAttribute('kind', 'spark');
        burst.setAttribute('mode', 'fill');
        burst.setAttribute('still', '');
        ['recipe', 'src'].forEach((name) => this.hasAttribute(name) && burst.setAttribute(name, this.getAttribute(name)));
        burst.setAttribute('style', 'position:fixed;z-index:59;pointer-events:none;width:' + size + 'px;height:' + size + 'px;left:' +
            (x - size / 2) + 'px;top:' + (y - size / 2) + 'px;animation:fqradiate .9s ease-out forwards');

        document.body.append(burst);
        setTimeout(() => burst.remove(), 1000);
    }
}

if (! customElements.get('fq-cosmetic')) {
    customElements.define('fq-cosmetic', FqCosmetic);
    customElements.define('fq-plate', FqPlate);
    customElements.define('fq-cabinet', FqCabinet);
    customElements.define('fq-spark', FqSpark);
}
