/*
 * Background music.
 *
 * The one piece of state in the app that has to outlive the page it was
 * started on: a kid turns music on from Home, walks to the Loot Shop, and the
 * song is meant to keep going. `wire:navigate` swaps the body out from under
 * us, so the audio deliberately lives in a plain `Audio` object held by an
 * Alpine store rather than in an `<audio>` element in the markup — nothing in
 * the DOM to be swapped, and no `@persist` block to keep matched across every
 * page in both consoles. The header control is just a view onto that store,
 * and re-attaches to it on each navigation.
 *
 * Three keys in localStorage, so the choice survives a real page load too:
 * `fq-music-on`, `fq-music-track` and `fq-music-volume`.
 */

const KEY_ON = 'fq-music-on';
const KEY_TRACK = 'fq-music-track';
const KEY_VOLUME = 'fq-music-volume';

/**
 * Quiet by default. This plays *underneath* a kid doing chores on a phone
 * speaker, and mastered mp3s at 1.0 are loud enough that the first thing
 * anyone does is turn it off and never come back.
 */
const DEFAULT_VOLUME = 0.35;

/** Private-mode Safari throws on both of these rather than returning null. */
function read(key, fallback = null) {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch (e) {
        return fallback;
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (e) {}
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('music', {
        /** @type {Array<{id: string, title: string, url: string}>} */
        tracks: [],
        trackId: read(KEY_TRACK),
        playing: false,
        volume: Number(read(KEY_VOLUME, DEFAULT_VOLUME)) || DEFAULT_VOLUME,

        /**
         * True when the browser refused to resume a remembered song without a
         * tap first. The header button says so rather than sitting there lit
         * and silent, which is the state that reads as "the app is broken".
         */
        blocked: false,

        /** @type {HTMLAudioElement|null} Built on first use, never in the DOM. */
        el: null,

        /** Guards resume() to the first header that mounts, not every one. */
        resumed: false,

        /**
         * Handed the catalogue by whichever header drew itself. Runs again on
         * every navigation, so it must not disturb a song already playing —
         * only the list and a selection that has gone stale.
         */
        load(tracks) {
            this.tracks = tracks;

            if (! tracks.length) {
                return;
            }

            // A remembered song whose file has since been deleted or renamed
            // would otherwise leave the picker with nothing marked and the
            // play button pointing at a 404.
            if (! this.current()) {
                this.trackId = tracks[0].id;
            }

            if (! this.resumed) {
                this.resumed = true;
                this.resume();
            }
        },

        current() {
            return this.tracks.find((track) => track.id === this.trackId) ?? null;
        },

        audio() {
            if (! this.el) {
                this.el = new Audio();
                this.el.loop = true;
                this.el.volume = this.volume;
                // Nothing loads until a kid actually asks for a song; these
                // files are megabytes each and most page loads never play one.
                this.el.preload = 'none';
            }

            return this.el;
        },

        toggle() {
            this.playing ? this.stop() : this.play();
        },

        play() {
            const track = this.current();

            if (! track) {
                return;
            }

            const audio = this.audio();

            if (audio.src !== track.url) {
                audio.src = track.url;
            }

            this.playing = true;
            this.blocked = false;
            write(KEY_ON, '1');

            audio.play().catch(() => {
                // Only reachable from resume() in practice — a click has
                // already satisfied the autoplay policy everywhere else.
                this.blocked = true;
            });
        },

        stop() {
            this.playing = false;
            this.blocked = false;
            write(KEY_ON, '0');
            this.el?.pause();
        },

        /**
         * Switching songs while the music is off selects without starting
         * anything: the picker is also how you choose what *will* play.
         */
        select(id) {
            const changed = id !== this.trackId;

            this.trackId = id;
            write(KEY_TRACK, id);

            if (this.playing && changed) {
                if (this.el) {
                    this.el.pause();
                    this.el.currentTime = 0;
                }

                this.play();
            }
        },

        setVolume(value) {
            this.volume = Number(value);
            write(KEY_VOLUME, String(this.volume));

            if (this.el) {
                this.el.volume = this.volume;
            }
        },

        /**
         * After a full page load, pick the music back up where it was.
         *
         * Browsers will not start audio without a gesture, so this attempt is
         * expected to fail on a cold load and is retried once on the first tap
         * anywhere — which is how a kid who left music on gets it back by
         * touching the screen, without hunting for the button again.
         */
        resume() {
            if (read(KEY_ON) !== '1') {
                return;
            }

            this.play();

            document.addEventListener('pointerdown', () => {
                if (this.blocked) {
                    this.play();
                }
            }, { once: true });
        },
    });

    /**
     * The header control. Owns nothing but the picker panel — every piece of
     * state a song has is on the store, so the control can be destroyed and
     * rebuilt by a navigation without the music noticing.
     */
    window.Alpine.data('fqMusic', (tracks = []) => ({
        open: false,

        init() {
            this.$store.music.load(tracks);
        },

        get music() {
            return this.$store.music;
        },

        get label() {
            if (this.music.blocked) {
                return 'Tap anywhere to start the music';
            }

            const title = this.music.current()?.title ?? 'No songs yet';

            return this.music.playing ? 'Music on — ' + title : 'Music off — ' + title;
        },
    }));
});
