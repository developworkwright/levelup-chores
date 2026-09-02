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
 * Five keys in localStorage, so the choice survives a real page load too:
 * `fq-music-on`, `fq-music-track`, `fq-music-volume`, `fq-music-playlist` and
 * `fq-music-shuffle`.
 */

const KEY_ON = 'fq-music-on';
const KEY_TRACK = 'fq-music-track';
const KEY_VOLUME = 'fq-music-volume';

/**
 * Which playlist is on, and whether it is shuffled.
 *
 * The playlists themselves belong to the kid and live in the database, because
 * a list they made has to follow them to any device. Which one is *currently
 * playing* is the opposite kind of fact — it belongs to this browser, exactly
 * like the remembered song beside it — so it stays here, and a kid can have
 * one playlist going on the tablet and another on the phone.
 */
const KEY_PLAYLIST = 'fq-music-playlist';
const KEY_SHUFFLE = 'fq-music-shuffle';

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
         * Where the song is and how long it is, in whole seconds.
         *
         * Both zero until a file has actually loaded, which is the honest
         * answer from a player that fetches nothing until somebody presses
         * play: there is no length to report about a song the browser has never
         * seen. Whole seconds because the display is m:ss and this is written
         * from a handler that fires four times a second — rounding here means
         * the panel's bindings re-run once a second instead.
         *
         * Deliberately not remembered anywhere. A kid coming back tomorrow
         * wants the song from the top, not from four minutes in.
         */
        position: 0,
        duration: 0,

        /**
         * Where the scrubber is being dragged to, or null when nobody is
         * dragging it.
         *
         * Without this the bar fights the thumb: `timeupdate` writes the
         * playing position back into the input four times a second, and the
         * thumb snaps out from under the finger on the way to the middle of the
         * song. While this is set the clock reads from it instead, and the
         * audio is only moved on release.
         */
        scrubAt: null,

        /** @type {Array<{id: number, name: string, trackIds: Array<string>}>} */
        playlists: [],

        /** Which playlist is playing, or null for a single song on repeat. */
        playlistId: Number(read(KEY_PLAYLIST)) || null,

        shuffle: read(KEY_SHUFFLE) === '1',

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
        load(tracks, latestAt = 0, playlists = []) {
            this.tracks = tracks;
            this.latestAt = latestAt;
            this.loadPlaylists(playlists);

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

        /**
         * Handed the kid's playlists, at load and again whenever they edit one.
         *
         * The second case is the reason this is a method rather than part of
         * `load`. The music page and the header control are on the same screen,
         * and Livewire morphs the page around an Alpine component it does not
         * rebuild — so a playlist made on that page never reaches an `x-data`
         * expression that was evaluated once, on first render. The page
         * dispatches instead, and the header listens. Without it a kid makes a
         * playlist, walks up to the header and cannot find it.
         */
        loadPlaylists(playlists) {
            this.playlists = playlists ?? [];

            // A playlist deleted — on this device or another one — must not
            // leave the player pointed at it, silently refusing to advance
            // because its queue comes back empty.
            if (this.playlistId !== null && ! this.playlist()) {
                this.leavePlaylist();
            }
        },

        playlist() {
            return this.playlists.find((list) => list.id === this.playlistId) ?? null;
        },

        /**
         * The songs of the playing playlist, in order, as real tracks.
         *
         * Ids the library no longer has are already gone before this arrives —
         * the server drops them — so this is a map rather than a filter in all
         * but the moment between a parent deleting a song and the next render.
         */
        queue() {
            const playlist = this.playlist();

            if (! playlist) {
                return [];
            }

            return playlist.trackIds
                .map((id) => this.tracks.find((track) => track.id === id))
                .filter(Boolean);
        },

        /** True while a playlist is what is playing, rather than one song. */
        get inPlaylist() {
            return this.playlist() !== null;
        },

        /**
         * Start a playlist from the top — or from anywhere, when shuffled.
         *
         * Tapping the one that is already playing turns it off rather than
         * restarting it, which is the same "tap it again" the album headings
         * and the play button already answer to.
         */
        playPlaylist(id) {
            if (this.playlistId === id) {
                this.leavePlaylist();

                return;
            }

            this.playlistId = id;
            write(KEY_PLAYLIST, String(id));

            const queue = this.queue();

            if (! queue.length) {
                return;
            }

            const first = this.shuffle ? Math.floor(Math.random() * queue.length) : 0;

            this.select(queue[first].id, true);
            this.play();
        },

        /**
         * Back to one song on repeat. The song keeps playing: leaving a
         * playlist is a change of what happens *next*, not a stop button.
         */
        leavePlaylist() {
            this.playlistId = null;
            write(KEY_PLAYLIST, '');

            if (this.el) {
                this.el.loop = true;
            }
        },

        toggleShuffle() {
            this.shuffle = ! this.shuffle;
            write(KEY_SHUFFLE, this.shuffle ? '1' : '0');
        },

        /**
         * The next song in the playlist, wrapping at the end.
         *
         * Shuffle picks any *other* song rather than any song, so a two-song
         * playlist alternates instead of occasionally playing one of them three
         * times in a row — which reads as the shuffle being broken, and on a
         * playlist that short it is indistinguishable from it.
         */
        advance() {
            const queue = this.queue();

            if (queue.length === 0) {
                return;
            }

            const index = queue.findIndex((track) => track.id === this.trackId);

            let next = (index + 1) % queue.length;

            if (this.shuffle && queue.length > 1) {
                do {
                    next = Math.floor(Math.random() * queue.length);
                } while (next === index);
            }

            this.select(queue[next].id, true);
            this.play();
        },

        audio() {
            if (! this.el) {
                this.el = new Audio();
                this.el.loop = true;
                this.el.volume = this.volume;
                // Only ever reached in a playlist: a looping element never
                // ends. play() sets `loop` from that, so this listener is the
                // playlist's whole advance mechanism.
                this.el.addEventListener('ended', () => this.advance());

                /**
                 * How long the song is, as best as the browser currently knows.
                 *
                 * Deliberately not trusting `duration` alone. A file served
                 * without a content length — chunked, or through a proxy that
                 * re-encodes on the way out — reports Infinity until the last
                 * byte lands, and a VBR mp3 with no Xing header revises its
                 * guess as it goes. What the browser will actually let us jump
                 * to is the seekable range, which is real from the first chunk,
                 * so that is the fallback rather than giving up on a bar.
                 */
                const measure = () => {
                    let length = this.el.duration;

                    if (! Number.isFinite(length) && this.el.seekable.length > 0) {
                        length = this.el.seekable.end(this.el.seekable.length - 1);
                    }

                    const whole = Number.isFinite(length) && length > 0 ? Math.floor(length) : 0;

                    // Guarded because this runs on `timeupdate` as well: writing
                    // the same number back four times a second would re-run
                    // every binding in the panel for nothing.
                    if (whole !== this.duration) {
                        this.duration = whole;
                    }
                };

                this.el.addEventListener('loadedmetadata', measure);
                this.el.addEventListener('durationchange', measure);
                // Buffering grows the seekable range, which is the length when
                // the file did not come with one.
                this.el.addEventListener('progress', measure);

                // Fires about four times a second, and also on every seek,
                // which is what moves the clock back to where a kid dropped
                // the thumb. Nothing to do while they are still holding it.
                this.el.addEventListener('timeupdate', () => {
                    // Measuring here too so a length that arrives by a route
                    // none of the events above covered still turns the bar on
                    // within a quarter second of the song playing, rather than
                    // leaving it dead for the whole track with no way back.
                    measure();

                    if (this.scrubAt === null) {
                        this.position = Math.floor(this.el.currentTime);
                    }
                });

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

                // The old song's numbers would otherwise sit under the new
                // song's title until its metadata lands — a four-minute bar on
                // a thirty-second jingle, and a scrubber that can be dragged
                // somewhere the file does not go.
                this.position = 0;
                this.duration = 0;
                this.scrubAt = null;
            }

            /*
             * A single song repeats itself; a playlist moves on.
             *
             * Set here rather than once at construction because it changes
             * whenever a kid joins or leaves a playlist — and it has to be
             * false for `ended` to fire at all, since a looping element simply
             * starts again and never reports the end of anything.
             *
             * A playlist of one still loops. There is nothing to advance to,
             * and the alternative is a song that stops dead.
             */
            audio.loop = this.queue().length < 2;

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
         *
         * `fromQueue` is how the player tells its own advancing apart from a
         * kid reaching past the playlist for a particular song. Reaching past
         * it ends it — they asked for that song, and a playlist that quietly
         * took over again two minutes later would look like the app changing
         * songs on its own.
         */
        select(id, fromQueue = false) {
            const changed = id !== this.trackId;

            if (! fromQueue) {
                this.leavePlaylist();
            }

            this.trackId = id;
            write(KEY_TRACK, id);

            // play() clears these too, but it is not reached when the music is
            // off — and a picker that swaps the title while leaving the last
            // song's length under it is reporting on a song nobody chose.
            if (changed) {
                this.position = 0;
                this.duration = 0;
                this.scrubAt = null;
            }

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
         * True once the browser knows how long the song is, which is the whole
         * of whether it can be seeked. Until the first play there is no file
         * loaded to seek through.
         */
        get seekable() {
            return this.duration > 0;
        },

        /**
         * Where the scrubber should sit: under the finger while it is being
         * dragged, on the song the rest of the time.
         */
        get elapsed() {
            return this.scrubAt ?? this.position;
        },

        /** Dragging. Moves the clock and nothing else — see scrubAt. */
        scrubTo(value) {
            this.scrubAt = Math.floor(Number(value)) || 0;
        },

        /**
         * Let go. This is the jump.
         *
         * The song carries on doing whatever it was doing: seeking a paused
         * song is a kid lining up where to start from, and starting the music
         * for them would be the panel making noise on its own.
         */
        seek(value) {
            const seconds = Math.min(Math.max(Math.floor(Number(value)) || 0, 0), this.duration);

            this.scrubAt = null;

            if (! this.el || ! this.seekable) {
                return;
            }

            /*
             * Throws rather than clamping in one case: a file the browser has
             * decided it cannot seek in at all. Nothing to do about it, and it
             * must not take the rest of the panel down with it.
             */
            try {
                this.el.currentTime = seconds;
                this.position = seconds;
            } catch (e) {}
        },

        /**
         * Seconds as m:ss, for both ends of the bar. Em dashes rather than
         * 0:00 for a song the browser has not loaded, because a zero length is
         * a claim about the song and this is an admission about the player.
         */
        clock(seconds) {
            if (! Number.isFinite(seconds) || seconds < 0) {
                return '--:--';
            }

            const whole = Math.floor(seconds);

            return Math.floor(whole / 60) + ':' + String(whole % 60).padStart(2, '0');
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

    /*
     * Edits made on the kid's music page, straight into the store.
     *
     * On the window rather than on the header's own markup, because the two are
     * different Livewire renders of the same screen and the header's `x-data`
     * expression is evaluated exactly once — see loadPlaylists().
     */
    window.addEventListener('playlists-updated', (event) => {
        window.Alpine.store('music').loadPlaylists(event.detail?.playlists ?? []);
    });

    /**
     * The header control. Owns nothing but the picker panel — every piece of
     * state a song has is on the store, so the control can be destroyed and
     * rebuilt by a navigation without the music noticing.
     */
    window.Alpine.data('fqMusic', (tracks = [], latestAt = 0, playlists = []) => ({
        open: false,

        /**
         * Which album is expanded, if any. One at a time: a soundtrack is a
         * hundred songs, and two open at once is a scroll with no landmarks.
         */
        openAlbum: null,

        init() {
            this.$store.music.load(tracks, latestAt, playlists);
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

        /** How many of a playlist's songs are actually in the library today. */
        countIn(playlist) {
            return playlist.trackIds.length;
        },

        get label() {
            if (this.music.blocked) {
                return 'Tap anywhere to start the music';
            }

            const title = this.music.current()?.title ?? 'No songs yet';
            // The playlist first when there is one: it is the bigger fact about
            // what is happening, and the song under it changes on its own.
            const what = this.music.playlist()
                ? this.music.playlist().name + ' — ' + title
                : title;

            return this.music.playing ? 'Music on — ' + what : 'Music off — ' + what;
        },
    }));
});
