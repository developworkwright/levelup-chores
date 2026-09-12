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
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqDrawPad', (width, height) => ({
        /** @type {Array<{color: string, size: number, points: Array<[number, number]>}>} */
        strokes: [],
        current: null,
        color: '#ffe14d',
        size: 6,
        /** Whether anything has been drawn — the page reads this to enable Send. */
        drawn: false,

        init() {
            this.canvas = this.$refs.pad;
            this.canvas.width = width;
            this.canvas.height = height;
            this.ctx = this.canvas.getContext('2d');
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';

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
            this.current = { color: this.color, size: this.size, points: [this.at(event)] };
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

        pick(color) {
            this.color = color;
        },

        /**
         * Redraws everything. The paper is painted opaque rather than left
         * transparent, because the PNG is shown on a dark panel in the room and
         * a transparent background would make every drawing look like it was
         * done in invisible ink on whatever happened to be behind it.
         */
        paint() {
            this.ctx.fillStyle = '#150c26';
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
