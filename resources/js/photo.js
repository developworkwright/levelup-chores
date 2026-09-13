/*
 * The camera button — picking a photo for the family feed.
 *
 * This drives `$wire.upload()` by hand rather than letting `wire:model` start
 * the upload on its own, for one reason: it has to be able to *refuse* a file
 * before a byte of it is sent.
 *
 * Past PHP's `post_max_size` the request body is discarded wholesale, CSRF
 * token included, so Laravel answers the upload endpoint with a 419 HTML page.
 * Livewire's uploader tries to JSON.parse that and throws — which reaches a kid
 * as a console error and a button that appears to do nothing at all. There is
 * no hook to catch it after the fact, so the only fix is not to start.
 *
 * The ceiling is handed in from PHP (see FeedPhotos::uploadCeilingKb) rather
 * than hardcoded, so it is the real limit of the running server and not a
 * number this file hopes is true.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fqPhotoPicker', (ceilingKb) => ({
        /** Whether an upload is in flight — the button shows progress instead of a camera. */
        busy: false,
        percent: 0,

        choose(event) {
            const file = event.target.files[0];

            // Cleared immediately so that picking the *same* file again still
            // fires a change event. Without this, a kid whose first try failed
            // taps the same photo and nothing happens.
            event.target.value = '';

            if (!file) {
                return;
            }

            if (file.size > ceilingKb * 1024) {
                this.say(`That photo is over ${this.readableCeiling()}. Try a smaller one.`);

                return;
            }

            this.busy = true;
            this.percent = 0;

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
