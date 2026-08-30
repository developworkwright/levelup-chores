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
 * The newest song this browser has been shown, as a unix timestamp.
 *
 * The whole of "is there new music": the server sends the library's high-water
 * mark, and anything higher than what is stored here means songs arrived since
 * the picker was last opened. Per browser rather than per kid, which is the
 * honest thing for a marker whose only job is to say "you have not looked in
 * here yet" — and it costs no column, no round trip and no migration.
 */
const KEY_SEEN = 'fq-music-seen';

/**
 * Which tab is allowed to be making noise.
 *
 * Two tabs — or the installed app and a browser tab, which is the easy way for
 * a kid to end up with two — each build their own player, and each resumes on
 * load because the "music is on" flag is shared between them. The result is two
 * songs at once, and a stop button that only silences the one you are looking
 * at. Whoever presses play last writes their id here; every other tab sees the
 * storage event and pauses.
 */
const KEY_OWNER = 'fq-music-owner';

/** This tab, for the lifetime of this page. */
const SESSION = Math.random().toString(36).slice(2);

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
    /*
     * Registering twice would replace this object with a fresh one while the
     * old one's Audio carried on playing, unreachable — an orphan nothing can
     * pause, overlapping whatever the new store goes on to play. Cheap to rule
     * out, and impossible to debug from a screenshot.
     */
    if (window.Alpine.store('music')) {
        return;
    }

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

        /** The library's newest song, as a unix timestamp, from the server. */
        latestAt: 0,

        /** The newest this browser has been shown. Null until it has looked. */
        seenAt: null,

        /**
         * Handed the catalogue by whichever header drew itself. Runs again on
         * every navigation, so it must not disturb a song already playing —
         * only the list and a selection that has gone stale.
         */
        load(tracks, latestAt = 0) {
            this.tracks = tracks;
            this.latestAt = latestAt;

            if (this.seenAt === null) {
                const stored = read(KEY_SEEN);

                /*
                 * A browser that has never looked is caught up, not a hundred
                 * songs behind. Marking the whole existing library as new the
                 * first time anybody opens the app would put a permanent dot on
                 * the header of every kid who has been listening for weeks.
                 */
                if (stored === null) {
                    write(KEY_SEEN, String(latestAt));
                    this.seenAt = latestAt;
                } else {
                    this.seenAt = Number(stored) || 0;
                }
            }

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
                this.listenForOtherTabs();
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
            // Claim the sound. Every other tab is listening for this and will
            // pause itself, so a house never has two songs going at once.
            write(KEY_OWNER, SESSION);

            audio.play().then(() => {
                /*
                 * Stopped while this was still starting.
                 *
                 * play() resolves a beat after it is called, and a stop in that
                 * gap pauses an element that has not begun — so the playback
                 * starts anyway, a moment after being told not to, and the
                 * music is on with the button saying off.
                 */
                if (! this.playing) {
                    audio.pause();
                }
            }).catch(() => {
                // Only reachable from resume() in practice — a click has
                // already satisfied the autoplay policy everywhere else.
                this.blocked = true;
            });
        },

        stop() {
            this.playing = false;
            this.blocked = false;
            write(KEY_ON, '0');
            write(KEY_OWNER, '');
            this.el?.pause();
        },

        /**
         * Go quiet when another tab takes over, or when the music is switched
         * off anywhere.
         *
         * The storage event only fires in the tabs that did *not* make the
         * change, which is exactly the audience. Nothing in here writes back:
         * two tabs answering each other's writes would ping-pong forever.
         */
        listenForOtherTabs() {
            window.addEventListener('storage', (event) => {
                const takenOver = event.key === KEY_OWNER
                    && event.newValue
                    && event.newValue !== SESSION;

                const switchedOff = event.key === KEY_ON && event.newValue === '0';

                if (takenOver || switchedOff) {
                    this.playing = false;
                    this.el?.pause();
                }
            });
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

        /** True while songs have arrived that this browser has not been shown. */
        get hasNew() {
            return this.seenAt !== null && this.latestAt > this.seenAt;
        },

        /** Opening the picker is looking, so the marker comes off. */
        markSeen() {
            this.seenAt = this.latestAt;
            write(KEY_SEEN, String(this.latestAt));
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
    window.Alpine.data('fqMusic', (tracks = [], latestAt = 0) => ({
        open: false,

        /**
         * Which album is expanded, if any. One at a time: a soundtrack is a
         * hundred songs, and two open at once is a scroll with no landmarks.
         */
        openAlbum: null,

        init() {
            this.$store.music.load(tracks, latestAt);
        },

        get music() {
            return this.$store.music;
        },

        /** Songs sitting loose at the top of the library, outside any album. */
        get loose() {
            return this.music.tracks.filter((track) => ! track.album);
        },

        /** Album names, in the order the server sorted the songs into. */
        get albums() {
            const names = [];

            for (const track of this.music.tracks) {
                if (track.album && ! names.includes(track.album)) {
                    names.push(track.album);
                }
            }

            return names;
        },

        songsIn(album) {
            return this.music.tracks.filter((track) => track.album === album);
        },

        /**
         * Opening the picker jumps to wherever the current song lives, so a kid
         * playing track 60 of a soundtrack is not dropped at the top of a list
         * of a hundred with no idea where they were.
         */
        togglePanel() {
            this.open = ! this.open;

            if (this.open) {
                this.openAlbum = this.music.current()?.album ?? null;
                this.music.markSeen();
            }
        },

        /**
         * Click only. Hover was tried and taken out: it opens an album as the
         * pointer crosses it on the way somewhere else, and does nothing at all
         * on the phones this is mostly used from.
         */
        toggleAlbum(album) {
            this.openAlbum = this.openAlbum === album ? null : album;
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
