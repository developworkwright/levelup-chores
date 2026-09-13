/*
 * The finger pad — the family feed's drawing tool.
 *
 * Registers the `fqDrawPad` Alpine component and nothing else. Written here
 * rather than inline in the Blade page for the same reason the games are: it
 * owns a <canvas> with no DOM to reconcile, and it has to sit behind
 * `wire:ignore` so a Livewire round trip can never wipe a half-finished drawing
 * off the screen.
 *
 * Strokes are kept as points rather than as canvas snapshots, and undo redraws
 * from the list. A 640x380 snapshot is about a megabyte, and a six-year-old
 * taps undo a great many times.
 *
 * `paper`, the palette and the nib widths all arrive from PHP — see the
 * constants on App\Services\FeedDrawings — so the hexes exist in one place
 * rather than one here and one in the tray's markup.
 */

/** Where the chosen colour, nib and mixed colours are remembered between trays. */
const STORE = {
    color: 'fq-draw-color',
    size: 'fq-draw-size',
    recents: 'fq-draw-recents',
};

/** How many mixed colours are kept beside the presets. See pick(). */
const MAX_RECENTS = 3;

/**
 * localStorage, but it can never take the pad down with it.
 *
 * Reading it throws outright in a private window and wherever site data is
 * blocked, and the pad has to keep drawing in both — a remembered colour is a
 * convenience, not a feature anybody can lose.
 */
const remembered = (key, fallback) => {
    try {
        const value = localStorage.getItem(key);

        return value === null ? fallback : JSON.parse(value);
    } catch {
        return fallback;
    }
};

const remember = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Nothing to do and nothing worth saying: the pad works either way.
    }
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqDrawPad', (width, height, paper, brushes) => ({
        /** @type {Array<{color: string, size: number, points: Array<[number, number]>}>} */
        strokes: [],
        current: null,
        color: '#ffe14d',
        size: 6,
        /** Whether the next stroke rubs out instead of drawing. */
        erasing: false,
        /**
         * The last few colours mixed in the picker, newest first.
         *
         * The picker is the operating system's own, which on a phone is a
         * full-screen sheet over the top of the drawing. Going back into it
         * every time you want your green again is the whole cost of having a
         * custom colour at all, so the colours you mixed stay on the pad.
         *
         * @type {Array<string>}
         */
        recents: [],
        /** Whether anything has been drawn — the page reads this to enable Send. */
        drawn: false,

        init() {
            this.canvas = this.$refs.pad;
            this.canvas.width = width;
            this.canvas.height = height;
            this.ctx = this.canvas.getContext('2d');
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';

            // The tray lives inside a server-rendered @if, so Livewire destroys
            // and rebuilds this component every time it is opened. Without this
            // the pad forgot the colour, the nib and every mixed colour on each
            // visit, which for a kid mid-picture is the tool resetting itself.
            this.color = remembered(STORE.color, this.color);
            this.size = remembered(STORE.size, brushes[1] ?? this.size);
            this.recents = (remembered(STORE.recents, []) || []).slice(0, MAX_RECENTS);

            this.paint();

            // Pointer events rather than touch + mouse: one code path covers a
            // finger, a stylus and a trackpad, and `setPointerCapture` is what
            // keeps a stroke going when a finger slides off the edge of the pad
            // mid-line, which on a 390px phone happens constantly.
            this.canvas.addEventListener('pointerdown', (e) => this.down(e));
            this.canvas.addEventListener('pointermove', (e) => this.move(e));
            this.canvas.addEventListener('pointerup', () => this.up());
            this.canvas.addEventListener('pointercancel', () => this.up());
        },

        /** Canvas coordinates from a pointer event, through whatever CSS scaled the pad to. */
        at(event) {
            const box = this.canvas.getBoundingClientRect();

            return [
                ((event.clientX - box.left) / box.width) * width,
                ((event.clientY - box.top) / box.height) * height,
            ];
        },

        down(event) {
            event.preventDefault();
            this.canvas.setPointerCapture(event.pointerId);
            this.current = { color: this.ink(), size: this.size, points: [this.at(event)] };
            this.strokes.push(this.current);
            this.drawn = true;
            this.paint();
        },

        move(event) {
            if (!this.current) {
                return;
            }

            event.preventDefault();
            this.current.points.push(this.at(event));
            this.paint();
        },

        up() {
            this.current = null;
        },

        undo() {
            this.strokes.pop();
            this.drawn = this.strokes.length > 0;
            this.paint();
        },

        clear() {
            this.strokes = [];
            this.current = null;
            this.drawn = false;
            this.paint();
        },

        /**
         * What the next stroke is laid down in.
         *
         * The eraser is a stroke in the paper colour rather than a
         * `destination-out` composite, and that is a deliberate choice rather
         * than the lazy one. paint() fills the paper and then replays every
         * stroke over it, so a paper-coloured stroke looks identical, keeps
         * `strokes` a single homogeneous list — which is what leaves undo() as
         * one line that undoes rubbing out exactly like it undoes drawing —
         * and leaves the exported PNG opaque. Compositing would punch through
         * the paper fill as well, and a drawing with transparent holes in it
         * shows the dark room panel through them, which is the invisible-ink
         * problem the opaque paper exists to prevent in the first place.
         */
        ink() {
            return this.erasing ? paper : this.color;
        },

        /**
         * Draw in this colour from now on, without deciding it is worth keeping.
         *
         * The native picker fires `input` continuously while a finger is
         * dragging across the spectrum, so this is called dozens of times for
         * one choice. It sets the pen and nothing else — see pick() for the
         * half that has to happen once.
         *
         * Choosing a colour is also how you stop erasing. A kid who taps a
         * colour while the eraser is armed means "draw in this", every time;
         * leaving it armed would make the next stroke silently rub out, which
         * reads as the pad being broken.
         */
        preview(color) {
            this.color = color;
            this.erasing = false;

            remember(STORE.color, color);
        },

        /**
         * Commit to a colour, and keep it if it is one we mixed.
         *
         * Bound to `change` rather than `input`, which is what makes the
         * recents row a list of colours somebody chose instead of a smear of
         * every shade their finger passed over on the way there.
         */
        pick(color) {
            this.preview(color);

            // Only colours that aren't already on the pad are worth keeping,
            // and only the newest few: this row sits beside six presets on a
            // 390px screen, and a fourth would wrap it.
            if (this.presets().includes(color)) {
                return;
            }

            this.recents = [color, ...this.recents.filter((hex) => hex !== color)].slice(0, MAX_RECENTS);

            remember(STORE.recents, this.recents);
        },

        /** The six built-in colours, read off the swatch buttons the page drew. */
        presets() {
            return Array.from(this.$el.querySelectorAll('[data-fq-swatch]')).map(
                (button) => button.dataset.fqSwatch,
            );
        },

        setSize(size) {
            this.size = size;

            remember(STORE.size, size);
        },

        /** Arms or disarms the eraser. The nib buttons set how wide it rubs. */
        toggleEraser() {
            this.erasing = !this.erasing;
        },

        /**
         * Redraws everything. The paper is painted opaque rather than left
         * transparent, because the PNG is shown on a dark panel in the room and
         * a transparent background would make every drawing look like it was
         * done in invisible ink on whatever happened to be behind it.
         */
        paint() {
            this.ctx.fillStyle = paper;
            this.ctx.fillRect(0, 0, width, height);

            for (const stroke of this.strokes) {
                this.ctx.strokeStyle = stroke.color;
                this.ctx.lineWidth = stroke.size;
                this.ctx.beginPath();

                // A single tap is a dot, not a zero-length line, which `arc` is
                // the only way to get out of a stroked path.
                if (stroke.points.length === 1) {
                    const [x, y] = stroke.points[0];
                    this.ctx.fillStyle = stroke.color;
                    this.ctx.beginPath();
                    this.ctx.arc(x, y, stroke.size / 2, 0, Math.PI * 2);
                    this.ctx.fill();
                    continue;
                }

                stroke.points.forEach(([x, y], i) => {
                    i === 0 ? this.ctx.moveTo(x, y) : this.ctx.lineTo(x, y);
                });

                this.ctx.stroke();
            }
        },

        /** Hands the flattened PNG to the Livewire component and lets it post. */
        send() {
            if (!this.drawn) {
                return;
            }

            this.$wire.postDrawing(this.canvas.toDataURL('image/png'));
            this.clear();
        },
    }));
});
