/*
 * The camera button — picking a photo for the family feed.
 *
 * It does two things `wire:model` cannot, and both exist because of the same
 * problem: the request has to be small before it is sent.
 *
 * ## It shrinks the photo first
 *
 * The server downscales every upload to FeedPhotos::MAX_EDGE and re-encodes it
 * anyway, so the twelve megapixels a phone puts in a file are thrown away the
 * moment they arrive. Sending them was always waste. Doing the same resize here
 * turns a 12MB photo into a few hundred kilobytes before it goes near the wire,
 * which makes an upload from a phone on cellular quick and — far more usefully —
 * puts it under every size limit in the way.
 *
 * There are three of those and the app controls none of them: PHP's
 * `upload_max_filesize` and `post_max_size`, which are PHP_INI_PERDIR, and
 * nginx's `client_max_body_size`, which defaults to one megabyte and lives in
 * the web server. On a managed host there may be no way to raise any of them.
 * A small request needs none of them raised.
 *
 * This is a convenience, never a control: the server re-reads, re-checks and
 * re-encodes whatever arrives, because anything sent from a browser is a claim
 * rather than a fact. See App\Services\FeedPhotos.
 *
 * ## It refuses what it cannot shrink
 *
 * Past `post_max_size` PHP discards the entire request body, CSRF token
 * included, so Laravel answers Livewire's upload endpoint with a 419 HTML page,
 * the uploader JSON.parses it, and it throws — reaching a kid as a console
 * error and a button that did nothing. There is no hook to catch that after the
 * fact, so anything still too big after shrinking is turned down here instead.
 */

/**
 * Quality for this pass — higher than the server's, on purpose.
 *
 * A photo is now encoded twice: here, and again by FeedPhotos when it lands.
 * JPEG loses a little every time, and those losses compound, so matching the
 * server's 82 here would mean the stored photo had been through 82 twice. At
 * 0.92 this pass is close to lossless and the server's is the only one that
 * really costs anything — which matters, because what it stores is the copy
 * the family keeps.
 *
 * The extra weight is affordable: it is the difference between roughly 0.4MB
 * and 0.6MB on a 12-megapixel photo, both far under every limit in the way.
 */
const QUALITY = 0.92;

/** Below this, shrinking costs more than it saves. */
const SHRINK_ABOVE_BYTES = 256 * 1024;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqPhotoPicker', (ceilingKb, maxEdge) => ({
        /** Whether an upload is in flight — the button shows progress instead of a camera. */
        busy: false,
        percent: 0,

        async choose(event) {
            const picked = event.target.files[0];

            // Cleared immediately so that picking the *same* file again still
            // fires a change event. Without this, a kid whose first try failed
            // taps the same photo and nothing happens.
            event.target.value = '';

            if (!picked) {
                return;
            }

            this.busy = true;
            this.percent = 0;

            // A failure here is not fatal: the original still goes, and the
            // ceiling check below catches it if it is too big to send.
            const file = (await this.shrink(picked, ceilingKb * 1024)) ?? picked;

            if (file.size > ceilingKb * 1024) {
                this.busy = false;
                this.say(`That photo is too big to send — over ${this.readableCeiling()}.`);

                return;
            }

            this.$wire.upload(
                'photo',
                file,
                () => {
                    this.busy = false;
                    this.percent = 100;
                },
                () => {
                    // Livewire's own failure path: a rejected file, or a server
                    // that answered with something the uploader could read.
                    this.busy = false;
                    this.say('That photo did not upload. Try again?');
                },
                (event) => {
                    this.percent = event.detail.progress;
                },
            );
        },

        /**
         * The same photo, no bigger than `maxEdge` on its longest side.
         *
         * Returns null — meaning "send the original" — whenever anything at all
         * goes wrong, or when the result would not actually be smaller. A
         * browser that cannot do this is a browser that uploads a big file, not
         * a browser that cannot post a photo.
         */
        async shrink(file, ceilingBytes) {
            if (!file.type.startsWith('image/') || file.size <= SHRINK_ABOVE_BYTES) {
                return null;
            }

            try {
                const source = await this.decode(file);

                // Clamped at 1 rather than bailing out when the photo is
                // already narrower than maxEdge. Pixel count is not the only
                // thing that makes a file heavy: a 1920x1080 PNG screenshot is
                // comfortably under the edge and comfortably over 2MB, and
                // re-encoding it to JPEG is what fixes that. Returning early on
                // those sent the original untouched and then refused it.
                const fit = Math.min(1, maxEdge / Math.max(source.width, source.height));

                /*
                 * Down a ladder until it fits, rather than one pass and hope.
                 *
                 * The first rung is the one that should nearly always win:
                 * full permitted size, near-lossless. The rest exist for the
                 * genuinely enormous — a panorama, a 100-megapixel phone — and
                 * trade quality for getting there at all, because a photo that
                 * arrives slightly softer beats one that cannot be sent.
                 */
                let best = null;

                for (const [scale, quality] of [
                    [fit, QUALITY],
                    [fit, 0.82],
                    [fit * 0.75, 0.82],
                    [fit * 0.5, 0.78],
                ]) {
                    const blob = await this.encode(source, scale, quality);

                    if (!blob) {
                        break;
                    }

                    if (!best || blob.size < best.size) {
                        best = blob;
                    }

                    if (blob.size <= ceilingBytes) {
                        break;
                    }
                }

                source.close?.();

                // Re-encoding does not always help — an already-optimised JPEG
                // can come back bigger. Sending the original is then the better
                // of the two, and the ceiling check still has the last word.
                if (!best || best.size >= file.size) {
                    return null;
                }

                return new File([best], 'photo.jpg', { type: 'image/jpeg' });
            } catch {
                return null;
            }
        },

        /** One pass: draw the source at `scale` and encode it at `quality`. */
        async encode(source, scale, quality) {
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(source.width * scale));
            canvas.height = Math.max(1, Math.round(source.height * scale));

            const ctx = canvas.getContext('2d');

            // White underneath, because the output is a JPEG and JPEG has no
            // alpha — a transparent PNG would otherwise come out on black.
            // The server does the same thing for the same reason.
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(source, 0, 0, canvas.width, canvas.height);

            return await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        },

        /**
         * The picked file as something drawable, the right way up.
         *
         * `imageOrientation: 'from-image'` is the whole reason this prefers
         * createImageBitmap. Rotating here drops the EXIF tag that said to
         * rotate, so a decoder that ignored it would upload a sideways photo
         * with nothing left on it for the server to correct — and every
         * portrait phone photo would arrive on its side. An <img> is the
         * fallback, and applies orientation by default in current browsers.
         */
        async decode(file) {
            if (window.createImageBitmap) {
                return await createImageBitmap(file, { imageOrientation: 'from-image' });
            }

            const url = URL.createObjectURL(file);

            try {
                const image = new Image();

                await new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = reject;
                    image.src = url;
                });

                return image;
            } finally {
                URL.revokeObjectURL(url);
            }
        },

        /** Rounded for reading, so 12288KB is "12MB" and 2048KB is "2MB". */
        readableCeiling() {
            return ceilingKb >= 1024
                ? `${Math.round(ceilingKb / 1024)}MB`
                : `${ceilingKb}KB`;
        },

        /** Puts a line where the room's other refusals already appear. */
        say(message) {
            this.$wire.set('notice', message);
        },
    }));
});
