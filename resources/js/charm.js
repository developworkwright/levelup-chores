/*
 * ---------------------------------------------------------------------------
 * The Quest Charm, shown rather than announced
 * ---------------------------------------------------------------------------
 *
 * A charm spends a ticket, picks five chores at random and pays half again on
 * each. All of that used to happen between two paints: the board came back
 * with five violet marks on it and a toast saying "5 chores just went charmed
 * — find them!". Kids could not tell what they had bought. They knew a ticket
 * had gone and the screen looked different.
 *
 * So the charm is watched now. A wand comes out of the button that cast it,
 * flies to each chore it landed on, taps it, and the row's payout counts up to
 * its new number under the wand. Five rows, one at a time, in board order.
 *
 * The one thing that makes it teach rather than decorate: the row is put
 * *back* to its old number first. The server has already sent the charmed
 * board, so by the time this runs every row is violet and already paying more
 * — which is the answer with the working rubbed out. `rewind()` restores what
 * the row said a moment ago, and the wand is what changes it.
 *
 * Lives here rather than in an `x-data` for the usual reason (see
 * resources/js/app.js): there is no server value to interpolate, and it is far
 * too much geometry for an HTML attribute.
 *
 * Driven by one event, from the two places a charm can be cast over a board a
 * kid is looking at — the Quest Charm perk and the pet's Good Luck Charm, both
 * in pages/kid/quests.blade.php:
 *
 *   window.dispatchEvent(new CustomEvent('charm-cast', { detail: {
 *       rate: 100,                              // points per dollar
 *       message: '5 chores charmed!',           // the toast, once the wand is done
 *       chores: [{ id, from, to, tint }, ...],  // board order
 *   }}))
 *
 * `from` and `to` are the payout in points before and after the charm, and
 * `tint` is the colour the row wore before it — a wheel-boosted row keeps its
 * boost colour on the way up rather than flashing lime at us.
 */

/** How long each leg of the trip takes, in ms. */
const FLY = 560;
/** The tap at the end of a leg, and the count-up it sets off. */
const TAP = 260;
const COUNT = 460;
/** A beat between rows, so five of them read as five rather than as a blur. */
const BETWEEN = 190;
/** Time given to a smooth scroll before the wand sets off after it. */
const SCROLL = 340;

const VIOLET = '#a06bff';

/**
 * The wand, and where its star sits inside it.
 *
 * Everything aims the *star*, not the image: the wand is mostly handle, and
 * centring the picture on a row puts the tip somewhere off to one side of what
 * it is supposed to be pointing at. Drawn at 54 and scaled up, so the one
 * viewBox below stays the source of these numbers.
 */
const SIZE = 64;
const STAR = { x: (36 * SIZE) / 54, y: (18 * SIZE) / 54 };

/**
 * The wand. A tapered handle with a four-point star at the tip, drawn at the
 * size it is used at so nothing has to scale.
 *
 * The star is the charm's own glyph everywhere else in the app (✧ on the mark,
 * on the perk tile, in the shop), so a kid who has seen one knows what just
 * flew past.
 */
const WAND = `
<svg width="${SIZE}" height="${SIZE}" viewBox="0 0 54 54" fill="none" aria-hidden="true">
  <defs>
    <linearGradient id="fq-wand-stick" x1="10" y1="44" x2="34" y2="20" gradientUnits="userSpaceOnUse">
      <stop stop-color="#3a2360"/>
      <stop offset="1" stop-color="#8b6fd0"/>
    </linearGradient>
    <radialGradient id="fq-wand-glow" cx="0.5" cy="0.5" r="0.5">
      <stop stop-color="${VIOLET}" stop-opacity="0.55"/>
      <stop offset="1" stop-color="${VIOLET}" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <circle cx="36" cy="18" r="17" fill="url(#fq-wand-glow)"/>
  <path d="M9 45 L31 23 L34 26 L12 48 Z" fill="url(#fq-wand-stick)"/>
  <path d="M36 4 L39.6 14.4 L50 18 L39.6 21.6 L36 32 L32.4 21.6 L22 18 L32.4 14.4 Z"
        fill="#ffe14d" stroke="${VIOLET}" stroke-width="1.5" stroke-linejoin="round"/>
</svg>`;

/**
 * Which cast is running. A kid holding two charms can tap Use twice before the
 * first trip is over, and the second cast owns the board from that moment —
 * every await in the older run checks this and bails rather than fighting the
 * new one for the same rows.
 */
let run = 0;

/** The wand in flight, so a new cast (or a page change) can take it away. */
let wand = null;

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** `number_format($points / $rate, 2)`, to the digit — see quests.blade.php. */
function money(points, rate) {
    const [whole, cents] = (points / rate).toFixed(2).split('.');

    return '$' + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + cents;
}

/** The row for a chore id, or null when a filter or a band is hiding it. */
function rowFor(id) {
    return document.querySelector(`[data-chore="${id}"]`);
}

/**
 * Puts a row back to what it said before the charm landed on it.
 *
 * Done to every row up front rather than one at a time: the board is already
 * on screen when the wand sets off, and a row that rewound as the wand reached
 * it would tick *down* in front of the kid we are explaining this to.
 */
function rewind(row, chore, rate) {
    const amount = row.querySelector('[data-chore-money]');
    const points = row.querySelector('[data-chore-pts]');
    const mark = row.querySelector('[data-charm-mark]');

    if (amount) {
        amount.textContent = money(chore.from, rate);
        amount.style.color = chore.tint;
    }

    if (points) {
        points.textContent = `${chore.from} PTS`;
    }

    if (mark) {
        // Kept in the layout, not display:none — the row must not change
        // height when the mark arrives, or five rows shuffling down the board
        // is the last thing the wand leaves behind.
        mark.style.visibility = 'hidden';
    }
}

/**
 * Where the wand should come to rest: beside the row's payout, pointing at it.
 *
 * Beside and not on. The number is the thing the kid is being asked to watch
 * change, and a wand parked over the top of it hides the only part of this
 * that was worth animating — which is exactly what the first cut did.
 */
function target(row) {
    const box = (row.querySelector('[data-chore-payout]') ?? row).getBoundingClientRect();

    return {
        x: box.left - 16 - STAR.x,
        y: box.top + box.height / 2 - STAR.y,
    };
}

function place(x, y, angle = 0) {
    wand.style.transform = `translate(${x}px, ${y}px) rotate(${angle}deg)`;
}

/**
 * One leg of the trip, arcing rather than sliding.
 *
 * A straight line between two rows on a vertical list is a wand sliding down a
 * wall. The arc bows it out to the left, which is also what lets the same
 * animation read at the top of the board and at the bottom of it.
 */
async function flyTo(from, to, mine) {
    const lift = Math.max(40, Math.abs(to.y - from.y) * 0.35);
    const mid = { x: (from.x + to.x) / 2 - lift, y: (from.y + to.y) / 2 };

    const flight = wand.animate(
        [
            { transform: `translate(${from.x}px, ${from.y}px) rotate(-18deg)` },
            { transform: `translate(${mid.x}px, ${mid.y}px) rotate(12deg)`, offset: 0.5 },
            { transform: `translate(${to.x}px, ${to.y}px) rotate(0deg)` },
        ],
        { duration: FLY, easing: 'cubic-bezier(.45,.05,.35,1)', fill: 'forwards' },
    );

    await flight.finished.catch(() => {});

    if (run !== mine) {
        return;
    }

    // Handed back to the inline transform so the next leg starts from a known
    // place rather than from a finished animation's fill.
    flight.cancel();
    place(to.x, to.y);
}

/** The flick at the end of a leg — the wand's own tap on the row. */
function flick(to) {
    wand.animate(
        [
            { transform: `translate(${to.x}px, ${to.y}px) rotate(0deg) scale(1)` },
            { transform: `translate(${to.x}px, ${to.y - 10}px) rotate(-22deg) scale(1.12)`, offset: 0.35 },
            { transform: `translate(${to.x}px, ${to.y}px) rotate(0deg) scale(1)` },
        ],
        { duration: TAP, easing: 'ease-out' },
    );
}

/**
 * Sparks off the tip, landing on the row.
 *
 * Deliberately not the app's confetti: that is what a *reward* looks like, and
 * the charm has not paid anything yet. These are the same four-point stars as
 * the mark, in the same violet, so the whole sequence says one word.
 */
function sparkle(to) {
    for (let i = 0; i < 9; i++) {
        const spark = document.createElement('span');

        spark.textContent = '✧';
        spark.style.cssText = [
            'position:fixed',
            'left:0',
            'top:0',
            'z-index:55',
            'pointer-events:none',
            `color:${i % 3 === 0 ? '#ffe14d' : VIOLET}`,
            `font-size:${9 + Math.random() * 9}px`,
            'line-height:1',
        ].join(';');

        document.body.appendChild(spark);

        const angle = (Math.PI * 2 * i) / 9 + Math.random() * 0.5;
        const reach = 26 + Math.random() * 34;

        spark
            .animate(
                [
                    { transform: `translate(${to.x + STAR.x}px, ${to.y + STAR.y}px) scale(0.4)`, opacity: 1 },
                    {
                        transform: `translate(${to.x + STAR.x + Math.cos(angle) * reach}px, ${to.y + STAR.y + Math.sin(angle) * reach}px) scale(1.1)`,
                        opacity: 0,
                    },
                ],
                { duration: 520 + Math.random() * 260, easing: 'cubic-bezier(.2,.7,.4,1)' },
            )
            .finished.catch(() => {})
            .finally(() => spark.remove());
    }
}

/**
 * The number going up, under the wand that is holding still over it.
 *
 * This is the part the whole animation exists for, so it is the slowest thing
 * in the sequence and it counts in points rather than jumping: the row says
 * 240, then 250, then 260, and the dollars keep pace underneath.
 */
function countUp(row, chore, rate, mine) {
    const amount = row.querySelector('[data-chore-money]');
    const points = row.querySelector('[data-chore-pts]');
    const mark = row.querySelector('[data-charm-mark]');

    if (mark) {
        mark.style.visibility = '';
        mark.animate(
            [
                { transform: 'scale(0.4)', opacity: 0 },
                { transform: 'scale(1.18)', opacity: 1, offset: 0.6 },
                { transform: 'scale(1)', opacity: 1 },
            ],
            { duration: 420, easing: 'cubic-bezier(.2,.9,.3,1.2)' },
        );
    }

    row.animate(
        [
            { boxShadow: `0 0 0 0 ${VIOLET}00`, transform: 'scale(1)' },
            { boxShadow: `0 0 0 5px ${VIOLET}66`, transform: 'scale(1.025)', offset: 0.4 },
            { boxShadow: `0 0 0 0 ${VIOLET}00`, transform: 'scale(1)' },
        ],
        { duration: 700, easing: 'ease-out' },
    );

    return new Promise((resolve) => {
        const started = performance.now();

        const step = (now) => {
            if (run !== mine) {
                return resolve();
            }

            // easeOutCubic: most of the climb is over early, so the number is
            // readable for longer than it is moving.
            const t = Math.min(1, (now - started) / COUNT);
            const eased = 1 - Math.pow(1 - t, 3);
            const at = Math.round(chore.from + (chore.to - chore.from) * eased);

            if (amount) {
                amount.textContent = money(at, rate);
                amount.style.color = VIOLET;
            }

            if (points) {
                points.textContent = `${at} PTS`;
            }

            t < 1 ? requestAnimationFrame(step) : resolve();
        };

        requestAnimationFrame(step);
    });
}

/** Whatever the server sent, applied at once — the end of the trip, skipped to. */
function settle(chores, rate) {
    for (const chore of chores) {
        const row = rowFor(chore.id);

        if (! row) {
            continue;
        }

        const amount = row.querySelector('[data-chore-money]');
        const points = row.querySelector('[data-chore-pts]');
        const mark = row.querySelector('[data-charm-mark]');

        if (amount) {
            amount.textContent = money(chore.to, rate);
            amount.style.color = VIOLET;
        }

        if (points) {
            points.textContent = `${chore.to} PTS`;
        }

        if (mark) {
            mark.style.visibility = '';
        }
    }
}

function clearWand() {
    wand?.remove();
    wand = null;
}

/** The toast the sequence ends on, in the app's own celebration queue. */
function toast(message) {
    window.dispatchEvent(
        new CustomEvent('celebrate', {
            detail: { message, style: 'star', motion: 'burst', origin: 'tap' },
        }),
    );
}

async function cast(detail) {
    const rate = Number(detail.rate) || 100;
    const message = detail.message ?? '';
    const all = Array.isArray(detail.chores) ? detail.chores : [];

    // A charm cast from the Bonus Shop, or one whose chores are all behind a
    // band or a chip: there is nothing to fly to, so the toast does the whole
    // job exactly as it did before.
    const visible = all.filter((chore) => rowFor(chore.id));

    if (visible.length === 0 || reduced()) {
        settle(all, rate);

        return toast(message);
    }

    const mine = ++run;

    clearWand();

    for (const chore of visible) {
        rewind(rowFor(chore.id), chore, rate);
    }

    wand = document.createElement('div');
    wand.innerHTML = WAND;
    wand.setAttribute('aria-hidden', 'true');
    wand.style.cssText =
        'position:fixed;left:0;top:0;z-index:55;pointer-events:none;will-change:transform;filter:drop-shadow(0 6px 14px rgba(0,0,0,.55))';
    document.body.appendChild(wand);

    // Out of the button that cast it, or up from the bottom of the screen
    // when there has been no tap at all (a keyboard press, or a charm cast on
    // one device and answered on another).
    let at = lastTap ?? { x: window.innerWidth / 2 - STAR.x, y: window.innerHeight - 120 };

    place(at.x, at.y, -18);
    wand.animate([{ opacity: 0, scale: 0.4 }, { opacity: 1, scale: 1 }], {
        duration: 200,
        easing: 'ease-out',
    });

    await wait(160);

    for (const chore of visible) {
        if (run !== mine) {
            return;
        }

        const row = rowFor(chore.id);

        if (! row) {
            continue;
        }

        // Scrolled to before the wand sets off rather than while it flies: the
        // target moves under a smooth scroll, and a wand that chases it lands
        // a row late on a long board.
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        await wait(SCROLL);

        if (run !== mine) {
            return;
        }

        const to = target(row);

        await flyTo(at, to, mine);

        if (run !== mine) {
            return;
        }

        at = to;

        flick(to);
        await wait(TAP * 0.5);

        if (run !== mine) {
            return;
        }

        sparkle(to);
        await countUp(row, chore, rate, mine);
        await wait(BETWEEN);
    }

    if (run !== mine) {
        return;
    }

    // Anything the wand could not reach — a chore behind the active filter —
    // still has to end up saying the truth.
    settle(all, rate);

    await wand
        .animate([{ opacity: 1, scale: 1 }, { opacity: 0, scale: 0.5 }], {
            duration: 260,
            easing: 'ease-in',
            fill: 'forwards',
        })
        .finished.catch(() => {});

    if (run === mine) {
        clearWand();
        toast(message);
    }
}

/**
 * Where the charm was cast from.
 *
 * The same trick, and the same reasoning, as `lastTap` in app.js: the wand is
 * drawn a round trip after the tap that caused it, by which time the button
 * may have been morphed away and on a phone there is no pointer to ask. A
 * charm is always cast by a tap, so the last tap is where the wand comes from.
 * Kept here rather than imported because app.js does not export it.
 */
let lastTap = null;

document.addEventListener(
    'pointerdown',
    (event) => (lastTap = { x: event.clientX - STAR.x, y: event.clientY - STAR.y }),
    { passive: true, capture: true },
);

/**
 * Two frames before anything is touched.
 *
 * Livewire applies the morph and fires the events a response carries in the
 * same pass, and this one has to run against the board *after* the morph: the
 * rows it rewinds are the ones the response just repainted, and rewinding them
 * first would simply be undone. Waiting for a painted frame is the cheap way
 * to be behind it without hooking into Livewire's internals.
 */
window.addEventListener('charm-cast', (event) => {
    const detail = event.detail ?? {};

    requestAnimationFrame(() =>
        requestAnimationFrame(() => cast(detail).catch(() => clearWand())),
    );
});

// A wand mid-flight when the kid navigates away would outlive the board it was
// pointing at — wire:navigate keeps the document.
document.addEventListener('livewire:navigating', () => {
    run++;
    clearWand();
});
