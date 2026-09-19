/*
 * The pet that lives on a kid's pages.
 *
 * One custom element, `<fq-pets>`, laid over the page. It draws the pet from the
 * eighteen-pose sprite sheet a grown-up uploaded (see App\Enums\CosmeticSlot, and
 * pet-sheet.js for the older twelve-pose one), and the pet gets on with its own
 * day: wanders, hops onto the top edge of a card, sits and blinks, sniffs about,
 * plays with its toy on a powered-up day, and sleeps after bedtime. It can be
 * petted, picked up and fed.
 *
 * Three things shape how this is written.
 *
 * **Livewire.** The layer is a shadow root, so a re-render can't strip the pet
 * mid-jump, and the element is never keyed to page content. Where the pet can
 * stand is re-read from the DOM each time it decides, rather than cached, so a
 * card that appeared or vanished in a round trip is simply the new world.
 *
 * **Taps have to keep working.** The layer never takes pointer events; only the
 * pet and its toy do. A pet sitting over a button is a pet you can pet, and the
 * button still works everywhere else.
 *
 * **It has to be testable without an animation frame.** requestAnimationFrame
 * does not fire while a browser is being driven by a tool, so the whole thing is
 * a `step(dt)` on a world object that the frame loop merely calls. Reach it as
 * `document.querySelector('fq-pets').world` and drive it by hand.
 */

import { posePosition, sheetLayout, sheetSize } from './pet-sheet.js';

/** How big the pet is drawn, in CSS pixels. One cell of the sheet. */
const PET_SIZE = 78;

/*
 * How much bigger everything is drawn on a big screen. A pet's size is set for
 * a phone (App\Enums\PetStage::pixels()), which is a speck on a desktop
 * monitor, so past a 900px-wide window the pet grows with the window, up to
 * 1.6 times as big. A phone or a tablet held upright never sees any of it.
 */
const ZOOM_FROM = 900;
const ZOOM_MAX = 1.6;

function screenZoom() {
    return Math.min(ZOOM_MAX, Math.max(1, (window.innerWidth || 0) / ZOOM_FROM));
}

const WALK_SPEED = 46;
const CHASE_SPEED = 96;
const GRAVITY = 2200;

/*
 * The swing of a pet held by the scruff: a damped pendulum hanging from the
 * finger. The length is roughly scruff to middle of body — it sets how fast the
 * swing is (about a second a swing), and the damping sets how soon it settles.
 */
const SWING_LENGTH = 55;
const SWING_DAMPING = 3.2;

/**
 * How much of the finger's acceleration reaches the body. Under one, because a
 * pet is not a point mass on a string and at full strength a quick flick slams
 * it straight to the limit.
 */
const SWING_DRIVE = 0.4;

/*
 * Past SWING_SOFT the swing is pushed back progressively rather than stopped:
 * a hard stop at a fixed angle reads as a hinge hitting its end. SWING_LIMIT is
 * only a backstop and should rarely be reached.
 */
const SWING_SOFT = 0.7;
const SWING_LIMIT = 1.25;

/**
 * Where a held pet is gripped, as a fraction of its height from the top.
 *
 * Right at the top, because the held pose is drawn dangling from a tuft at the
 * very top of its cell — the prompt asks for exactly that. Gripping any lower
 * put the finger on the pet's face.
 */
const SCRUFF = 0.04;

/**
 * How long a sibling's pet stays before letting itself out.
 *
 * Long enough to be a visit rather than a glimpse, short enough that a kid who
 * leaves Home open all afternoon does not end up with a lodger. Whether one
 * turns up at all is the server's call — see CosmeticService::visitingPet().
 */
const VISIT_SECONDS = 300;

/** How long a snack takes to eat, in seconds. */
const EAT_SECONDS = 1.8;

/*
 * The prize counter's pet gear, drawn with `window.FQPrizes` (prizes.js) at the
 * sizes the design gives: a toy 34px, a bed 96px wide. A bed's art stands on
 * the lower part of its box, so BED_FOOT is how far up the box its underside
 * is, and BED_LIFT how far above the floor a pet lying in it sits.
 */
const PRIZE_TOY_SIZE = 34;
const BED_SIZE = 96;
const BED_FOOT = 12;
const BED_LIFT = 16;

/**
 * The most of its cell the sheet's own toy may span, across or up. About a
 * prize-counter toy's size next to a grown pet (34px against 120px).
 */
const TOY_SPAN = 0.3;

/** How often an idle pet with a bed goes for a nap, per decision. */
const NAP_CHANCE = 0.12;

/** The drawn prize, or null when prizes.js has not loaded. */
function prizeSvg(kind, key) {
    return window.FQPrizes ? window.FQPrizes.draw(kind, key) : null;
}

/**
 * What a tap on the page lands on when it is *not* a request for a snack:
 * anything that does something when tapped. Livewire and Alpine handlers are
 * attributes, so an element carrying one counts even without a button's role.
 */
const TAPPABLE = [
    'a', 'button', 'input', 'select', 'textarea', 'label', 'summary', 'details', 'video', 'audio',
    '[role="button"]', '[role="link"]', '[role="tab"]', '[role="radio"]', '[role="checkbox"]', '[role="switch"]', '[role="menuitem"]',
    '[contenteditable]', '[tabindex]:not([tabindex="-1"])', '[wire\\:click]', '[x-on\\:click]', '[\\@click]',
    'fq-pets', 'fq-cosmetic', 'canvas', '[data-fq-no-feed]',
].join(',');

/**
 * A surprise egg, as an SVG: a dark mottled shell with glowing cracks, one
 * more for every chore — see App\Services\PetService. Drawn here rather than
 * uploaded, because every egg is the same egg: what hatches is the surprise.
 * Its bottom sits on the foot line, like a pet's feet.
 */
const EGG_CRACKS = [
    'M50 30 L46 38 L52 44 L47 52',
    'M47 52 L39 57 L42 64 L35 70',
    'M52 44 L60 49 L57 57 L65 62',
    'M47 52 L52 61 L48 69 L54 77',
    'M65 62 L62 71 L69 76 L64 84',
];

/**
 * Every egg in a shop is its own colour — App\Models\PetEgg::hueFor() — so
 * the shell, its spots and the glow of its cracks all come off one hue. The
 * default is the purple the first egg was.
 */
function eggSvg(cracks, hue) {
    const h = Number.isFinite(hue) ? hue : 275;
    const shown = EGG_CRACKS.slice(0, Math.max(0, Math.min(EGG_CRACKS.length, cracks)));
    const light = 'hsl(' + h + ',100%,72%)';
    const glow = cracks >= EGG_CRACKS.length ? '<ellipse cx="50" cy="60" rx="24" ry="30" fill="' + light + '" opacity=".2"/>' : '';
    const spot = 'hsl(' + h + ',42%,32%)';

    return 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        + '<path d="M50 20 C71 20 83 46 83 66 C83 85 68 96 50 96 C32 96 17 85 17 66 C17 46 29 20 50 20 Z" fill="hsl(' + h + ',48%,19%)" stroke="#0e0719" stroke-width="3"/>'
        + '<ellipse cx="38" cy="46" rx="6" ry="4" fill="' + spot + '"/><ellipse cx="62" cy="70" rx="7" ry="5" fill="' + spot + '"/>'
        + '<ellipse cx="44" cy="80" rx="4" ry="3" fill="' + spot + '"/><ellipse cx="66" cy="42" rx="3" ry="2.5" fill="' + spot + '"/>'
        + '<path d="M36 32 C40 26 46 24 50 24" stroke="hsl(' + h + ',38%,48%)" stroke-width="3" fill="none" stroke-linecap="round"/>'
        + glow
        + shown.map((d) => '<path d="' + d + '" stroke="' + light + '" stroke-width="3.2" fill="none" stroke-linejoin="round" stroke-linecap="round"/>').join('')
        + '</svg>'
    );
}

/** A URL, safe inside a CSS url("..."). See cosmetic-elements.js for why not encodeURI. */
function cssUrl(src) {
    return String(src).replace(/["\\]/g, (character) => '\\' + character).replace(/[\r\n]/g, '');
}

function random(min, max) {
    return min + Math.random() * (max - min);
}

/**
 * One animal. Holds where it is and what it is doing; knows nothing about the
 * page, which is the world's job.
 */
class Pet {
    /**
     * A home and a roam distance pen a pet in around a point. The login door
     * uses them: every kid's pet stays by their own tile, so the row reads as
     * one animal per child rather than a scrum. Left out, it has the whole page.
     */
    constructor(world, sprite, options) {
        const settings = options ?? {};

        this.world = world;
        this.sprite = sprite;
        this.home = settings.home ?? null;
        this.roam = settings.roam ?? 120;
        this.visiting = settings.visiting ?? false;
        // Its own sheet, and whether its own toy is out — the login door holds
        // several pets, each with a toy of its own.
        this.src = settings.src ?? null;
        this.wantsToy = settings.toy ?? false;
        this.toy = null;
        // Which poses its sheet has, and where the toy goes in the ones with
        // empty paws — see pet-sheet.js and App\Models\Cosmetic::rig().
        this.layout = sheetLayout(settings.rig);
        this.anchors = settings.rig?.anchors ?? {};
        // Smaller while it is young — see App\Enums\PetStage.
        this.scale = settings.scale ?? 1;
        this.x = settings.home ?? random(60, Math.max(120, world.width - 60));
        this.y = world.floor();
        this.vx = 0;
        this.vy = 0;
        this.facing = 1;
        this.pose = 'idle';
        this.perch = null;
        this.held = false;
        this.think = 0;
        this.blinkIn = random(2, 6);
        this.state = 'idle';
    }

    /** Puts a pose up and holds it for a moment before the pet decides again. */
    act(state, pose, seconds) {
        this.state = state;
        this.pose = pose;
        this.think = seconds;
    }

    /**
     * Whether its sheet is the eighteen-pose one: a real walk cycle, and play
     * poses with empty paws. An older sheet has the toy drawn into play and
     * toss, and plays the old way.
     */
    hasFullSheet() {
        return ! this.layout.legacy;
    }

    /**
     * The walking frame for this moment: the two steps in turn, in time with
     * the bob the painter adds (see FqPets.paint()), so a foot lands on each
     * dip.
     */
    walkFrame() {
        if (! this.hasFullSheet()) {
            return 'walk';
        }

        const stride = (this.clock ?? 0) * (this.speed > WALK_SPEED ? 13 : 9);

        return Math.floor(stride / Math.PI) % 2 ? 'walk2' : 'walk';
    }

    /**
     * A point on its sheet — fractions of the cell, as the cutter measured
     * them — where it is on the page right now, allowing for its size and
     * which way it faces.
     */
    cellPoint(point) {
        const size = PET_SIZE * this.scale * screenZoom();

        return {
            x: this.x + (point[0] - 0.5) * size * this.facing,
            y: this.y + (point[1] - 1) * size,
        };
    }

    /**
     * Where the middle of its toy is while it holds it: resting on the paws
     * it holds up on its back, or just in front of the paws it pounces with.
     * Null when it is not holding the toy.
     */
    holdPoint(toy) {
        const paws = this.anchors[this.pose];

        if (this.state !== 'playing' || ! paws || (this.pose !== 'play' && this.pose !== 'back')) {
            return null;
        }

        const at = this.cellPoint(paws);
        const half = toy.halfSize();

        if (this.pose === 'back') {
            // Batted about between the paws.
            return { x: at.x + Math.sin(this.clock * 9) * 2.5, y: at.y - half.h * 0.55 + Math.abs(Math.sin(this.clock * 9)) * -2 };
        }

        return { x: at.x + this.facing * half.w * 0.9, y: at.y - half.h };
    }

    /**
     * Play with its toy, standing next to it. On a full sheet: a swipe that
     * bats it away, a pounce that pins it, a roll onto its back holding it
     * up, or a toss straight up — the same four for its own toy and a bought
     * one, because the paws are empty and the toy is drawn in.
     */
    playWith(toy) {
        this.facing = toy.x < this.x ? -1 : 1;
        this.state = 'playing';

        const roll = Math.random();

        if (roll < 0.3 || ! this.anchors.play) {
            this.pose = 'swipe';
            this.think = random(0.5, 0.9);
            toy.bat(this.facing);

            return;
        }

        if (roll < 0.55) {
            this.pose = 'play';
            this.think = random(0.8, 1.5);
            toy.carriedBy = this;

            return;
        }

        if (roll < 0.8 && this.anchors.back) {
            this.pose = 'back';
            this.think = random(1.4, 2.6);
            toy.carriedBy = this;

            return;
        }

        // Up it goes, from the paws held over its head, and it lands wherever
        // it lands — the next thing the pet does is go and get it.
        this.pose = 'toss';
        this.think = random(0.7, 1.1);

        const paws = this.cellPoint(this.anchors.toss ?? [0.5, 0.2]);
        const half = toy.halfSize();

        toy.carriedBy = null;
        toy.centerAt(paws.x, paws.y - half.h);
        toy.vy = -random(460, 620);
        toy.vx = this.facing * random(20, 70);
    }

    /** The surface under a point: the top of a card, or the floor. */
    settle() {
        const perch = this.world.perchUnder(this.x, this.y);

        this.perch = perch;
        this.y = perch ? perch.top : this.world.floor();
    }

    /** Picked up by a finger. */
    grab() {
        this.held = true;
        this.perch = null;
        this.vx = 0;
        this.vy = 0;
        this.act('held', 'held', 0);

        // Picked up hanging straight, and still.
        this.theta = 0;
        this.omega = 0;
        this.lastX = null;
        this.lastVx = 0;
        this.pivotAx = 0;
    }

    /**
     * The swing, while it dangles from a finger.
     *
     * A pendulum whose pivot is the finger. Gravity pulls it back to hanging
     * straight; the finger's sideways *acceleration* is what swings it — whip
     * the pet right and its body lags left, stop dead and it swings on past —
     * and damping bleeds the motion away. That is the whole difference from a
     * scripted sway, which looks the same however you move.
     *
     * theta is the angle from hanging straight down, positive when the body
     * has swung left of the finger.
     */
    swing(dt) {
        if (dt <= 0) {
            return;
        }

        // The finger's own motion, from where it put the pet this frame. The
        // acceleration is smoothed: pointer events arrive unevenly, and a raw
        // second difference of them is mostly noise.
        const vx = this.lastX === null ? 0 : (this.x - this.lastX) / dt;
        const ax = (vx - this.lastVx) / dt;

        this.lastX = this.x;
        this.lastVx = vx;
        this.pivotAx = this.pivotAx * 0.55 + ax * 0.45;

        const beyond = Math.max(0, Math.abs(this.theta) - SWING_SOFT);

        const alpha = -(GRAVITY / SWING_LENGTH) * Math.sin(this.theta)
            - SWING_DAMPING * this.omega
            + (this.pivotAx * SWING_DRIVE / SWING_LENGTH) * Math.cos(this.theta)
            - Math.sign(this.theta) * beyond * 160;

        this.omega += alpha * dt;
        this.theta = Math.max(-SWING_LIMIT, Math.min(SWING_LIMIT, this.theta + this.omega * dt));

        // Held still, it still wriggles now and then — a live animal, not a
        // weight on a string.
        if (Math.abs(this.omega) < 0.25 && Math.random() < dt * 1.1) {
            this.omega += (Math.random() < 0.5 ? -1 : 1) * random(1.2, 2.2);
        }
    }

    /**
     * Let go.
     *
     * Dropped over a card it lands on that card, even though the card's top
     * edge is above the point it was let go at — a kid dropping a pet onto a
     * card means "sit there", and falling straight past it to the floor reads
     * as the drop having failed. Dropped over nothing, it falls.
     */
    drop() {
        this.held = false;
        this.state = 'falling';
        this.pose = 'jump';
        this.vy = 0;

        // It was drawn scaled from the scruff while held, and is drawn scaled
        // from its feet from now on. Moved to match, so it lets go from
        // exactly where it hung instead of jumping, and falls from there.
        this.y += PET_SIZE * (1 - SCRUFF) * (this.scale * screenZoom() - 1);

        const onto = this.world.perches()
            .filter((perch) => this.x >= perch.left && this.x <= perch.right && perch.top - this.y > -40 && perch.top - this.y < 160)
            .sort((a, b) => Math.abs(a.top - this.y) - Math.abs(b.top - this.y))[0];

        this.landOn = onto ? onto.top : null;
        this.y = onto ? Math.min(this.y, onto.top - 12) : this.y;
    }

    /** Petted. */
    pet() {
        if (this.held) {
            return;
        }

        // Now and then it rolls over for a belly rub instead.
        const rollsOver = this.hasFullSheet() && ! this.perch && Math.random() < 0.3;

        this.act('happy', rollsOver ? 'back' : 'happy', rollsOver ? 1.8 : 1.4);
        this.world.emit('fq-pet-petted');
    }

    /** Sent somewhere: a celebration, the toy, a sibling's pet. */
    runTo(x, speed) {
        this.goal = x;
        this.speed = speed || WALK_SPEED;
        this.act('walking', 'walk', 0);
    }

    hop() {
        this.act('crouch', 'crouch', 0.18);
    }

    /** Keeps a penned pet near home, and everything else on the page. */
    pen(x) {
        if (this.home === null) {
            return this.world.clampX(x);
        }

        return Math.min(Math.max(this.home - this.roam, x), this.home + this.roam);
    }

    /**
     * Time to go home: off the nearest edge, and gone once it gets there.
     *
     * It comes down off whatever it was sitting on first, so a visitor never
     * exits along a card and vanishes halfway across the page.
     */
    leave() {
        const world = this.world;

        this.leaving = true;
        this.perch = null;
        this.runTo(this.x < world.width / 2 ? -(world.margin() + PET_SIZE) : world.width + world.margin() + PET_SIZE, WALK_SPEED * 1.3);
    }

    /** Standing right by a snack that has landed, and it is ours to eat. */
    canReachSnack() {
        const snack = this.world.snack;

        return Boolean(snack && ! this.visiting && snack.landed && Math.abs(snack.x - this.x) < 30);
    }

    /**
     * The page has stopped scrolling: come and find the kid.
     *
     * Still in view, it hops down to the bottom of the screen. Scrolled off
     * the top, it drops back in from the top edge; off the bottom, it pops up
     * from under it. A pet sitting on a card that is still on screen stays
     * put, one mid-air or in a hand is left to finish, and one asleep is just
     * quietly moved.
     */
    comeBack() {
        const world = this.world;

        // A nap keeps itself on the bed, and the bed on the floor — see step().
        if (this.held || this.leaving || ['falling', 'jumping', 'crouch', 'napping'].includes(this.state)) {
            return;
        }

        const floor = world.floor();
        const top = world.ceiling();
        const inView = this.y >= top + PET_SIZE * 0.5 && this.y <= floor + 2;

        if (this.perch && inView) {
            return;
        }

        if (world.asleep) {
            if (! inView) {
                this.perch = null;
                this.y = floor;
            }

            return;
        }

        if (! this.perch && Math.abs(this.y - floor) < 3) {
            return;
        }

        this.perch = null;
        this.vx = 0;
        this.vy = 0;

        if (inView) {
            this.jumpTo = { x: this.x, y: floor };
            this.hop();

            return;
        }

        if (this.y < floor) {
            // Off the top: it drops in.
            this.y = top - 10;
            this.landOn = floor;
            this.state = 'falling';
            this.pose = 'jump';

            return;
        }

        // Off the bottom: it springs up from under the edge.
        this.y = floor + PET_SIZE;
        this.jumpTo = { x: this.x, y: floor };
        this.hop();
    }

    /** Says hello to whoever it has just bumped into. */
    greet(other) {
        this.facing = other.x < this.x ? -1 : 1;
        this.act('happy', 'happy', random(0.9, 1.6));
    }

    step(dt) {
        const world = this.world;

        // Still in its egg — see FqPets.hatch().
        if (this.inEgg) {
            return;
        }

        // Its own clock, for the gait — see FqPets.paint().
        this.clock = (this.clock ?? 0) + dt;

        if (this.held) {
            this.swing(dt);

            return;
        }

        // Let go mid-swing, it carries the angle into the fall and straightens
        // out on the way down instead of snapping upright the instant it drops.
        if (this.theta) {
            this.theta *= Math.pow(0.02, dt);

            if (Math.abs(this.theta) < 0.01) {
                this.theta = 0;
            }
        }

        // A visitor sees itself out. The clock only runs while it is loose, so
        // a pet being carried about is never whisked away mid-drag.
        if (this.visiting && ! this.leaving) {
            this.visit = (this.visit ?? VISIT_SECONDS) - dt;

            if (this.visit <= 0) {
                this.leave();
            }
        }

        if (world.asleep) {
            if (this.state !== 'sleeping' && this.state !== 'happy') {
                // Bedtime in its own bed, when it has one; anywhere, when not.
                if (world.bed && ! this.visiting) {
                    this.perch = null;
                    this.x = world.bed.x;
                    this.y = world.floor() - BED_LIFT * screenZoom();
                } else {
                    this.settle();
                }

                this.act('sleeping', 'sleep', 0);
            }

            if (this.state === 'happy' && (this.think -= dt) <= 0) {
                this.act('sleeping', 'sleep', 0);
            }

            return;
        }

        if (this.state === 'falling') {
            this.vy += GRAVITY * dt;
            this.y += this.vy * dt;
            this.x += this.vx * dt;

            const ground = this.landOn ?? world.groundUnder(this.x, this.y);

            if (this.y >= ground) {
                this.y = ground;
                this.landOn = null;
                this.settle();
                this.vx = 0;
                this.act('landed', 'landed', 0.7);
            }

            return;
        }

        if (this.state === 'jumping') {
            this.vy += GRAVITY * dt;
            this.y += this.vy * dt;
            this.x += this.vx * dt;

            // The top of what the kid can see is a ceiling. Without it a hop at
            // a card high up the page carries the pet clean off the screen, and
            // a pet you cannot see reads as one that broke.
            if (this.y < world.ceiling()) {
                this.y = world.ceiling();
                this.vy = Math.max(this.vy, 0);
            }

            if (this.vy > 0 && this.y >= this.target) {
                this.y = this.target;
                this.settle();
                this.vx = 0;
                this.act('idle', 'idle', random(0.6, 1.8));
            }

            return;
        }

        this.think -= dt;

        if (this.state === 'walking') {
            const to = this.goal;
            const distance = to - this.x;

            this.facing = distance < 0 ? -1 : 1;
            this.x += Math.sign(distance) * Math.min(Math.abs(distance), this.speed * dt);
            this.pose = this.walkFrame();

            // On the floor. Not while the page is scrolling: then the pet
            // stays where it is on the page and scrolls away with it, and
            // comes back once the scrolling stops — see comeBack().
            if (! this.perch && ! world.scrolling()) {
                this.y = world.floor();
            }

            if (Math.abs(distance) < 4) {
                if (this.leaving) {
                    this.gone = true;

                    return;
                }

                if (this.canReachSnack()) {
                    // A quick sniff first, then it tucks in — see decide().
                    if (this.hasFullSheet()) {
                        this.act('sniffing', 'sniff', 0.45);
                    } else {
                        this.act('eating', 'crouch', EAT_SECONDS);
                    }

                    return;
                }

                // Got to its bed: in, and a nap.
                if (this.toBed && world.bed && Math.abs(world.bed.x - this.x) < 8) {
                    this.toBed = false;
                    this.act('napping', 'sleep', random(4, 8));

                    return;
                }

                this.toBed = false;
                this.act('idle', 'idle', random(0.4, 1.4));
            }

            return;
        }

        if (this.state === 'napping') {
            if (! world.bed) {
                this.act('idle', 'idle', 0.4);

                return;
            }

            this.x = world.bed.x;

            if (! world.scrolling()) {
                this.y = world.floor() - BED_LIFT * screenZoom();
            }

            // A snack wakes it up. Otherwise it sleeps the nap out.
            if (world.snack || this.think <= 0) {
                this.y = world.floor();
                this.act('landed', 'landed', 0.5);
            }

            return;
        }

        if (this.state === 'eating') {
            if (! this.perch && ! world.scrolling()) {
                this.y = world.floor();
            }

            // Chomping: down to the snack and back up, a few times a second.
            this.pose = Math.floor(this.clock * 6) % 2 ? 'crouch' : 'happy';

            if (world.snack) {
                world.snack.bite = 1 - Math.max(0, this.think) / EAT_SECONDS;
            }

            if (this.think <= 0) {
                world.finishSnack();
                this.act('happy', 'happy', 1.2);
                world.emit('fq-pet-fed');
            }

            return;
        }

        if (this.state === 'crouch' && this.think <= 0) {
            // Up and over to wherever it was aiming.
            const target = this.jumpTo ?? { x: this.x, y: world.floor() };
            const rise = Math.max(160, (this.y - target.y) + 150);

            this.vy = -Math.sqrt(2 * GRAVITY * rise);
            this.vx = (target.x - this.x) / (2 * Math.abs(this.vy) / GRAVITY);
            this.target = target.y;
            this.facing = this.vx < 0 ? -1 : 1;
            this.state = 'jumping';
            this.pose = 'jump';

            return;
        }

        if (['idle', 'happy', 'landed', 'playing', 'sitting', 'sniffing', 'surprised'].includes(this.state)) {
            if (! this.perch && ! world.scrolling()) {
                this.y = world.floor();
            }

            this.blinkIn -= dt;

            if (this.state === 'idle' && this.blinkIn <= 0) {
                this.pose = this.pose === 'blink' ? 'idle' : 'blink';
                this.blinkIn = this.pose === 'blink' ? 0.16 : random(2, 6);
            }

            if (this.think <= 0) {
                // Surprise always gives way to delight.
                if (this.state === 'surprised') {
                    this.act('happy', 'happy', 1.2);
                } else {
                    this.decide();
                }
            }
        }
    }

    /** What to do next, when nothing is already happening. */
    decide() {
        const world = this.world;
        const toy = this.toy;

        // Food beats everything, and a visitor does not eat somebody else's.
        if (world.snack && ! this.visiting) {
            if (this.canReachSnack()) {
                this.act('eating', 'crouch', EAT_SECONDS);
            } else {
                this.runTo(world.snack.x, CHASE_SPEED);
            }

            return;
        }

        // The toy, when there is one and it is not already being sat next to.
        if (toy && Math.random() < 0.45) {
            if (Math.abs(toy.x - this.x) > 40) {
                this.runTo(toy.x + random(-24, 24), CHASE_SPEED);

                return;
            }

            if (this.hasFullSheet()) {
                this.playWith(toy);

                return;
            }

            // An old twelve-pose sheet. A toy from the prize counter can't use
            // its play and toss poses — those have the sheet's own toy drawn
            // in the paws, so the pet would be playing with a different toy
            // from the one on the floor. It plays with the real one instead: a
            // pounce in a pose with empty paws, and the toy batted away across
            // the floor, which the pet then chases on its next decision.
            if (toy.prizeKey) {
                this.facing = toy.x < this.x ? -1 : 1;
                this.pose = Math.random() < 0.5 ? 'crouch' : 'happy';
                this.state = 'playing';
                this.think = random(0.5, 0.9);
                toy.bat(this.facing);

                return;
            }

            this.pose = Math.random() < 0.5 ? 'play' : 'toss';
            this.state = 'playing';
            this.think = random(0.8, 1.6);

            return;
        }

        // A nap in its bed, now and then — its own pet only, and never on a
        // card: the bed is on the floor.
        if (world.bed && ! this.visiting && ! this.perch && Math.random() < NAP_CHANCE) {
            this.toBed = true;
            this.runTo(world.bed.x);

            return;
        }

        const roll = Math.random();

        if (roll < 0.36) {
            const perch = this.home === null ? world.randomPerch(this.y) : null;

            if (perch) {
                this.jumpTo = { x: Math.min(Math.max(perch.left + 30, perch.left), perch.right - 30), y: perch.top };
                this.hop();

                return;
            }
        }

        if (roll < 0.78) {
            this.runTo(this.pen(this.x + random(-260, 260)));

            return;
        }

        // A sit, or a nose round the floor, when its sheet has them.
        if (this.hasFullSheet() && roll < 0.86) {
            this.act('sitting', 'sit', random(2, 4.5));

            return;
        }

        if (this.hasFullSheet() && roll < 0.91) {
            this.act('sniffing', 'sniff', random(0.8, 1.6));

            return;
        }

        this.act('idle', 'idle', random(1.2, 3.4));
    }
}

/**
 * The page as the pet sees it: how wide it is, where the floor is, and which
 * card edges can be stood on. Everything here is measured fresh, because
 * Livewire rewrites the page underneath it.
 */
class World {
    constructor(host) {
        this.host = host;
        this.pets = [];
        this.toys = [];
        this.eggs = [];
        this.snack = null;
        // The pet's own bed from the prize counter, standing on the floor.
        this.bed = null;
        this.asleep = false;
        this.width = 0;
    }

    measure() {
        const box = this.host.getBoundingClientRect();

        this.width = box.width;
        this.top = box.top + window.scrollY;
        this.height = box.height;
    }

    /**
     * How far outside the page's column the pet may wander, in pixels.
     *
     * On a phone the column is the whole screen and this is nothing. On a
     * desktop there is a wide margin either side of it, and a pet that turned
     * back at the column edge looked like it had hit an invisible wall — or
     * worse, walked behind a curtain, which is what the clipping used to do.
     */
    margin() {
        return Math.max(0, (window.innerWidth - this.width) / 2 - PET_SIZE * 0.35);
    }

    /** Keeps a wandering pet inside the page and its margins. */
    clampX(x) {
        const margin = this.margin();

        return Math.min(Math.max(-margin + PET_SIZE * 0.2, x), this.width + margin - PET_SIZE * 0.2);
    }

    /** The top of what the kid can see, in the layer's own coordinates. */
    ceiling() {
        return Math.max(PET_SIZE * 0.3, window.scrollY - this.top + PET_SIZE * 0.3);
    }

    /** The bottom of what the kid can see, in the layer's own coordinates. */
    floor() {
        const bottom = window.scrollY + window.innerHeight - this.top - 8;

        return Math.max(PET_SIZE, Math.min(bottom, this.height - 4));
    }

    /**
     * The card edges a pet can stand on, read from the page every time it is
     * asked — a Livewire round trip may have replaced all of them.
     *
     * Found by shape rather than by marking up every card on twelve pages: a
     * card is a rounded box of a certain size inside the page area. An element
     * can insist with `data-fq-perch`, or refuse with `data-fq-no-perch`.
     *
     * Only what is on screen counts, so the pet stays where the kid is looking
     * and the walk never costs more than a handful of rectangles.
     */
    perches() {
        const page = document.querySelector('[data-fq-page]') ?? document.body;
        const found = [];
        const left = this.hostLeft();

        const consider = (element, depth) => {
            if (depth > 3 || ! (element instanceof HTMLElement)) {
                return;
            }

            if (element.hasAttribute('data-fq-no-perch') || element.closest('[data-fq-no-perch]')) {
                return;
            }

            const box = element.getBoundingClientRect();
            // Room above it for the animal, or the pet perches with its head off
            // the top of the screen.
            const onScreen = box.bottom > 0 && box.top < window.innerHeight - 20 && box.top > PET_SIZE * screenZoom();
            const radius = parseFloat(getComputedStyle(element).borderTopLeftRadius) || 0;
            const isCard = element.hasAttribute('data-fq-perch') || (radius >= 10 && box.height >= 48);

            if (onScreen && isCard && box.width >= 140) {
                found.push({
                    left: box.left - left,
                    right: box.right - left,
                    top: box.top + window.scrollY - this.top,
                });

                // The card itself, not the cards inside it — a pet perching on
                // every nested box would have nowhere to walk.
                return;
            }

            Array.from(element.children).forEach((child) => consider(child, depth + 1));
        };

        Array.from(page.children).forEach((child) => consider(child, 1));

        return found;
    }

    hostLeft() {
        return this.host.getBoundingClientRect().left;
    }

    /**
     * Somewhere to hop to from where the pet is standing.
     *
     * Within reach, deliberately: a pet that tried for a card near the top of a
     * long page would launch itself off the screen, and a pet you cannot see is
     * a pet that broke. Out of reach, it walks instead and tries again from
     * wherever it ends up — which is how it gets up a page of cards.
     *
     * A screen's worth of reach, because the gap from the floor to the lowest
     * card is most of one: any tighter and the pet never leaves the floor.
     */
    randomPerch(fromY) {
        const reach = Math.max(260, window.innerHeight * 0.55);
        const perches = this.perches().filter((perch) => perch.right - perch.left > 90
            && (fromY === undefined || Math.abs(perch.top - fromY) < reach));

        return perches.length ? perches[Math.floor(Math.random() * perches.length)] : null;
    }

    /** The perch a pet standing at this point is on, if any. */
    perchUnder(x, y) {
        return this.perches().find((perch) => x >= perch.left && x <= perch.right && Math.abs(perch.top - y) < 26) ?? null;
    }

    /** What a falling pet will land on: the nearest card top below it, or the floor. */
    groundUnder(x, y) {
        const floor = this.floor();
        const tops = this.perches()
            .filter((perch) => x >= perch.left && x <= perch.right && perch.top > y)
            .map((perch) => perch.top);

        return tops.length ? Math.min(floor, Math.min(...tops)) : floor;
    }

    /**
     * Two pets standing next to each other say hello.
     *
     * Only when both are loafing, so a greeting never interrupts a jump or a
     * drag, and on a cooldown — without one they stand nose to nose grinning at
     * each other forever, which is funny exactly once.
     */
    introduce() {
        this.met = Math.max(0, (this.met ?? 0) - 1);

        if (this.pets.length < 2 || this.met > 0 || this.asleep) {
            return;
        }

        const loafing = (one) => one.state === 'idle' || one.state === 'walking';

        for (const pet of this.pets) {
            for (const other of this.pets) {
                if (pet === other || pet.held || other.held) {
                    continue;
                }

                if (loafing(pet) && loafing(other) && Math.abs(pet.x - other.x) < 56 && Math.abs(pet.y - other.y) < 30) {
                    pet.greet(other);
                    other.greet(pet);
                    this.met = 240;

                    return;
                }
            }
        }
    }

    /** The snack is eaten: gone from the page. */
    finishSnack() {
        if (this.snack) {
            this.snack.sprite.remove();
            this.snack = null;
        }
    }

    emit(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail ?? {} }));
    }

    /**
     * Whether the page scrolled a moment ago. The pets and toys stay where
     * they are on the page while it does, rather than chasing the bottom of
     * the screen frame by frame.
     */
    scrolling() {
        return performance.now() - (this.scrolledAt ?? -Infinity) < 350;
    }

    step(dt) {
        this.measure();

        const scrolling = this.scrolling();

        if (this.wasScrolling && ! scrolling) {
            this.pets.forEach((pet) => pet.comeBack());
            this.eggs.forEach((egg) => {
                egg.y = this.floor();
            });
        }

        if (this.bed && ! scrolling) {
            this.bed.y = this.floor();
        }

        this.wasScrolling = scrolling;
        this.pets.forEach((pet) => pet.step(dt));
        this.eggs.forEach((egg) => egg.step(dt));

        // A visitor that has walked off the edge takes its sprite with it.
        this.pets = this.pets.filter((pet) => {
            if (! pet.gone) {
                return true;
            }

            pet.sprite.remove();

            return false;
        });

        this.introduce();

        this.toys.forEach((toy) => toy.step(dt, this));

        if (this.snack) {
            this.snack.step(dt);
        }
    }
}

/**
 * Something to eat, dropped beside the pet. It falls to the level the pet is
 * standing on rather than to whatever is under it, so a pet on a card is never
 * handed food it would have to jump down to.
 */
class Snack {
    constructor(x, rest, from) {
        this.x = x;
        // From where it was dropped — a finger's tap — or from just above.
        this.y = Math.min(from ?? rest - 220, rest);
        this.rest = rest;
        this.vy = 0;
        this.bite = 0;
        this.landed = false;
    }

    step(dt) {
        if (this.y < this.rest) {
            this.vy += GRAVITY * dt;
            this.y = Math.min(this.rest, this.y + this.vy * dt);
        }

        this.landed = this.y >= this.rest;
    }
}

/**
 * A surprise egg out on the page in place of a pet. It sits on the floor,
 * wobbles now and then — and when tapped — and scrolls with the page like a
 * pet does, settling back on the floor once the scrolling stops.
 *
 * Given `hatchIn`, it is the hatching: it shakes hard for that long and then
 * calls `onHatch`, which bursts it and lets the new pet out.
 */
class Egg {
    constructor(world, sprite, options) {
        const settings = options ?? {};

        this.world = world;
        this.sprite = sprite;
        this.cracks = settings.cracks ?? 0;
        this.hue = settings.hue;
        this.scale = settings.scale ?? 1;
        this.x = settings.x ?? (settings.home ?? random(80, Math.max(140, world.width - 80)));
        this.y = world.floor();
        this.clock = 0;
        this.wobble = 0;
        this.nextWobble = random(2, 5);
        this.hatchIn = settings.hatchIn ?? null;
        this.onHatch = settings.onHatch ?? null;
    }

    /** Tapped: a wobble, and the egg says so to the page. */
    poke() {
        this.wobble = 0.7;
        this.world.emit('fq-egg-poked', { cracks: this.cracks });
    }

    step(dt) {
        const world = this.world;

        this.clock += dt;

        if (! world.scrolling()) {
            this.y = world.floor();
        }

        if (this.hatchIn !== null) {
            this.wobble = 0.7;
            this.hatchIn -= dt;

            if (this.hatchIn <= 0) {
                this.hatchIn = null;
                this.onHatch?.(this);
            }

            return;
        }

        this.nextWobble -= dt;

        if (this.nextWobble <= 0) {
            this.wobble = 0.6;
            // The more it is cracked, the more it moves: something in there
            // is nearly out.
            this.nextWobble = random(2, 7) / (1 + this.cracks * 0.3);
        }

        this.wobble = Math.max(0, this.wobble - dt);
    }

    /** How far over it leans this frame, in degrees. */
    angle() {
        return this.wobble > 0 ? Math.sin(this.clock * 26) * 10 * Math.min(1, this.wobble / 0.6) : 0;
    }
}

/** The toy: a sprite that falls, sits, and can be dragged about. */
class Toy {
    constructor(world, x) {
        this.world = world;
        this.x = x ?? random(80, Math.max(140, world.width - 80));
        this.y = -40;
        this.vx = 0;
        this.vy = 0;
        // How far it has rolled, in degrees — only a prize-counter toy is drawn
        // turning. See FqPets.paint().
        this.spin = 0;
        this.held = false;
        // The pet holding it in its paws, if one is — see Pet.holdPoint().
        this.carriedBy = null;
    }

    /**
     * Half its drawn width and height, on the page. A bought toy is its own
     * little picture; the sheet's toy is one cell of the pet's sheet, sized
     * with its pet, and the cutter measured the toy inside that cell.
     */
    halfSize() {
        const zoom = screenZoom();

        if (this.prizeKey) {
            return { w: PRIZE_TOY_SIZE / 2 * zoom, h: PRIZE_TOY_SIZE / 2 * zoom };
        }

        const size = this.cellSize();
        const box = this.owner?.anchors?.toy;

        return { w: (box?.[2] ?? 0.3) / 2 * size, h: (box?.[3] ?? 0.3) / 2 * size };
    }

    /** Puts the middle of the toy at a point on the page. */
    centerAt(x, y) {
        const zoom = screenZoom();

        if (this.prizeKey) {
            this.x = x;
            this.y = y + PRIZE_TOY_SIZE / 2 * zoom;

            return;
        }

        // The sheet's toy is drawn somewhere inside its cell, and the cell is
        // what is positioned: from its feet, like a pet.
        const size = this.cellSize();
        const box = this.owner?.anchors?.toy ?? [0.5, 0.8];

        this.x = x - (box[0] - 0.5) * size;
        this.y = y - (box[1] - 1) * size;
    }

    /**
     * How big the sheet's toy cell is drawn, on the page: with its pet, and
     * shrunk when the generator drew the toy big. A toy is something a pet
     * holds in its paws, and a bone half as long as the animal looked like
     * a plank — so it is never more than TOY_SPAN of the cell across, about
     * the size of a toy from the prize counter.
     */
    cellSize() {
        const box = this.owner?.anchors?.toy;
        const span = box ? Math.max(box[2], box[3]) : 0;
        const shrink = span > TOY_SPAN ? TOY_SPAN / span : 1;

        return PET_SIZE * (this.owner?.scale ?? 1) * screenZoom() * shrink;
    }

    /** Whether a pet has it in its paws this frame. */
    carried() {
        return Boolean(this.carriedBy && ! this.held && this.carriedBy.holdPoint(this));
    }

    /**
     * Batted by its pet: a hop and a roll away in the direction it was hit.
     * On an old twelve-pose sheet the sheet's own toy is never batted — it is
     * played with in the paws, where that sheet draws it.
     */
    bat(direction) {
        if (this.held) {
            return;
        }

        this.vx = direction * random(140, 260);
        this.vy = -random(260, 420);
    }

    step(dt, world) {
        if (this.held || world.scrolling()) {
            return;
        }

        // In its pet's paws: it goes where they go. Let go of — the pet has
        // moved on to something else — it drops from wherever it was.
        if (this.carriedBy) {
            const hold = this.carriedBy.holdPoint(this);

            if (hold) {
                this.centerAt(hold.x, hold.y);
                this.vx = 0;
                this.vy = 0;

                return;
            }

            this.carriedBy = null;
        }

        // Rolling along after a bat, slowing as it goes, and never off the page.
        if (this.vx !== 0) {
            const before = this.x;

            this.x = world.clampX(this.x + this.vx * dt);
            this.spin += (this.x - before) * 4;

            // Hit an edge: it stops there rather than pressing into it.
            this.vx = this.x === before ? 0 : this.vx * Math.pow(0.25, dt);

            if (Math.abs(this.vx) < 8) {
                this.vx = 0;
            }
        }

        const ground = world.groundUnder(this.x, this.y);

        if (this.y < ground || this.vy < 0) {
            this.vy += GRAVITY * dt;
            this.y = Math.min(ground, this.y + this.vy * dt);

            // Landing hard enough: one small bounce.
            if (this.y >= ground && this.vy > 380) {
                this.vy = -this.vy * 0.3;
            } else if (this.y >= ground) {
                this.vy = 0;
            }
        } else {
            this.y = ground;
            this.vy = 0;
        }
    }
}

class FqPets extends HTMLElement {
    /*
     * `snack`, `toy-prize` and `bed` are the gear a kid bought at the arcade's
     * prize counter (App\Services\PrizeCounterService::gearFor()), as prize
     * keys. A bought toy is out every day, not only on a powered-up one.
     */
    static get observedAttributes() {
        return ['sheet', 'rig', 'scale', 'effect', 'toy', 'asleep', 'drag', 'sheets', 'visitor', 'egg', 'egg-hue', 'snack', 'toy-prize', 'bed'];
    }

    connectedCallback() {
        this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        // A prize being tried on at the counter, standing in for the real gear
        // until the tray closes: {kind, key}, or null.
        this.trial = null;
        this.render();

        this.onCelebrate = () => this.cheer();
        this.onFeed = () => this.feed();
        this.onTap = (event) => this.feedAt(event);
        this.onGear = (event) => this.takeGear(event.detail?.gear);
        this.onTry = (event) => this.tryOn(event.detail ?? null);
        this.onScroll = () => {
            if (this.world) {
                this.world.scrolledAt = performance.now();
            }
        };
        this.onVisible = () => (document.hidden ? this.pause() : this.play());

        window.addEventListener('celebrate', this.onCelebrate);
        window.addEventListener('fq-pet-feed', this.onFeed);
        window.addEventListener('fq-pet-gear', this.onGear);
        window.addEventListener('fq-pet-try', this.onTry);
        document.addEventListener('click', this.onTap);
        window.addEventListener('scroll', this.onScroll, { passive: true });
        document.addEventListener('visibilitychange', this.onVisible);
    }

    disconnectedCallback() {
        this.pause();
        window.removeEventListener('celebrate', this.onCelebrate);
        window.removeEventListener('fq-pet-feed', this.onFeed);
        window.removeEventListener('fq-pet-gear', this.onGear);
        window.removeEventListener('fq-pet-try', this.onTry);
        document.removeEventListener('click', this.onTap);
        window.removeEventListener('scroll', this.onScroll);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    attributeChangedCallback(name) {
        if (! this.isConnected) {
            return;
        }

        // The sheet changing is a different pet; everything else is the same
        // pet in a different mood, and must not restart it mid-jump.
        if (['sheet', 'rig', 'scale', 'effect', 'sheets', 'visitor', 'egg', 'egg-hue'].includes(name)) {
            this.render();

            return;
        }

        if (this.world) {
            this.world.asleep = this.hasAttribute('asleep');
            this.syncToy();
            this.syncBed();
        }
    }

    /**
     * The counter bought or swapped something. The page's own markup would
     * say so on the next load; this says so now, by setting the same
     * attributes the kid shell renders. Only on a kid's own layer — the login
     * door's row and a grown-up's pet have no gear.
     */
    takeGear(gear) {
        if (! gear || this.sheets() || ! this.hasAttribute('feed-on-tap')) {
            return;
        }

        [['snack', gear.snack], ['toy-prize', gear.toy], ['bed', gear.bed]].forEach(([name, value]) => {
            if (value) {
                this.setAttribute(name, value);
            } else {
                this.removeAttribute(name);
            }
        });
    }

    /**
     * A prize tried on at the counter: a snack drops to be eaten, a toy or bed
     * takes the place of the real one until the tray closes (`null`).
     */
    tryOn(trial) {
        if (this.sheets() || ! this.hasAttribute('feed-on-tap')) {
            return;
        }

        this.trial = trial && ['snack', 'toy', 'bed'].includes(trial.kind) ? trial : null;
        this.syncToy();
        this.syncBed();

        if (this.trial && this.trial.kind === 'snack') {
            this.world?.finishSnack();
            this.feed();
        }
    }

    /** The snack that drops: the one being tried, else the one out. */
    snackKey() {
        return this.trial?.kind === 'snack' ? this.trial.key : (this.getAttribute('snack') || 'meat');
    }

    /** The bought toy that is out, if any — the one being tried first. */
    toyKey() {
        return this.trial?.kind === 'toy' ? this.trial.key : this.getAttribute('toy-prize');
    }

    /** The bed that is out, if any — the one being tried first. */
    bedKey() {
        return this.trial?.kind === 'bed' ? this.trial.key : this.getAttribute('bed');
    }

    /**
     * Every animal this layer holds.
     *
     * One kid's own pet from `sheet`, a sibling's from `visitor`, or a whole
     * row of them from `sheets` — which is the login door, where each pet is
     * penned around its own kid's tile.
     *
     * `rig` says how each sheet is laid out (App\Models\Cosmetic::rig()); a
     * pet without one has the old twelve-pose sheet.
     *
     * @return array<int, {src: string, rig: ?object, effect: ?string, scale: ?number, home: ?number, roam: ?number, visiting: ?boolean, toy: ?boolean}>
     */
    cast() {
        const read = (name) => {
            try {
                return JSON.parse(this.getAttribute(name) || 'null');
            } catch (error) {
                return null;
            }
        };

        const many = read('sheets');

        if (Array.isArray(many)) {
            return many.filter((entry) => entry && (entry.src || Number.isInteger(entry.egg)));
        }

        const mine = this.getAttribute('sheet');
        const visitor = read('visitor');
        const egg = this.hasAttribute('egg') ? parseInt(this.getAttribute('egg'), 10) || 0 : null;

        return [
            egg !== null ? { egg, hue: parseInt(this.getAttribute('egg-hue'), 10), scale: parseFloat(this.getAttribute('scale')) || 1 } : null,
            egg === null && mine ? { src: mine, rig: read('rig'), effect: this.getAttribute('effect'), scale: parseFloat(this.getAttribute('scale')) || 1 } : null,
            visitor && visitor.src ? { ...visitor, visiting: true } : null,
        ].filter(Boolean);
    }

    render() {
        const root = this.shadowRoot || this.attachShadow({ mode: 'open' });
        const cast = this.cast();

        this.pause();
        root.replaceChildren();

        if (cast.length === 0) {
            return;
        }

        const style = document.createElement('style');
        style.textContent = `
            /*
             * No overflow clipping, deliberately. The layer is the page's own
             * 1080px column, and hiding what leaves it made the pet disappear
             * into a curtain at each edge on a wide screen. Nothing above this
             * clips either, so the pet can walk out over the margins and stay
             * on screen — see World.margin() for how far.
             */
            :host { position: absolute; inset: 0; pointer-events: none; z-index: 30; }
            .pet, .toy {
                position: absolute; width: ${PET_SIZE}px; height: ${PET_SIZE}px;
                background-image: var(--sheet); background-size: 600% 300%;
                background-repeat: no-repeat; pointer-events: auto; cursor: grab;
                touch-action: none; will-change: transform;
            }
            /*
             * The toy's cell at the pet's own size: the sheet draws the toy at
             * the scale it is in the pet's paws, so this is how big it really
             * is next to the animal. The cell is mostly empty, so only the
             * patch where the toy sits takes a finger — the rest of the box
             * must not swallow taps meant for buttons under it.
             */
            .toy { pointer-events: none; transform-origin: 50% 100%; z-index: 1; }
            .egg {
                position: absolute; width: ${PET_SIZE}px; height: ${PET_SIZE}px;
                background-size: contain; background-repeat: no-repeat;
                transform-origin: 50% 100%; pointer-events: auto; cursor: pointer;
                z-index: 2; will-change: transform;
            }
            .shell {
                position: absolute; background-size: contain; background-repeat: no-repeat;
                pointer-events: none; z-index: 3;
            }
            .shell-top { clip-path: inset(0 0 52% 0); animation: fq-shell-top .9s ease-out forwards; }
            .shell-bottom { clip-path: inset(48% 0 0 0); animation: fq-shell-bottom .9s ease-in forwards; }
            @keyframes fq-shell-top { to { transform: translate(-30%, -70%) rotate(-70deg); opacity: 0; } }
            @keyframes fq-shell-bottom { to { transform: translate(25%, 10%) rotate(35deg); opacity: 0; } }
            /* Animals in front of toys: a toy lying on the ground never covers the pet. */
            .pet { z-index: 2; }
            .toy-grab {
                position: absolute; left: 22%; right: 22%; bottom: 4%; height: 46%;
                pointer-events: auto; cursor: grab; touch-action: none;
            }
            .snack {
                position: absolute; width: 30px; height: 30px; font-size: 26px; line-height: 30px;
                text-align: center; pointer-events: none; transform-origin: 50% 100%;
                filter: drop-shadow(0 2px 0 rgba(0,0,0,.45));
            }
            /* The prize counter's gear: drawn art rather than a cell of the sheet. */
            .toy.prize {
                width: ${PRIZE_TOY_SIZE}px; height: ${PRIZE_TOY_SIZE}px; background: none;
                filter: drop-shadow(0 2px 0 rgba(0,0,0,.45));
            }
            .toy.prize .toy-grab { inset: 0; height: auto; }
            .toy.prize svg { transform-origin: 50% 50%; }
            .bed {
                position: absolute; width: ${BED_SIZE}px; height: ${BED_SIZE}px;
                pointer-events: none; transform-origin: 50% 100%; z-index: 0;
            }
            .snack svg, .toy svg, .bed svg { display: block; width: 100%; height: 100%; }
        `;

        root.append(style);

        this.world = new World(this);
        this.world.asleep = this.hasAttribute('asleep');
        this.world.measure();

        cast.forEach((entry) => {
            // A surprise egg out in place of a pet.
            if (Number.isInteger(entry.egg)) {
                const shell = document.createElement('div');
                shell.className = 'egg';
                shell.style.backgroundImage = 'url("' + eggSvg(entry.egg, entry.hue) + '")';
                root.append(shell);

                const egg = new Egg(this.world, shell, {
                    cracks: entry.egg,
                    hue: entry.hue,
                    scale: entry.scale ?? 1,
                    home: entry.home === undefined || entry.home === null ? undefined : entry.home * this.world.width,
                });

                this.world.eggs.push(egg);
                shell.addEventListener('click', () => egg.poke());

                return;
            }

            const sprite = document.createElement('div');
            sprite.className = 'pet ' + (entry.effect || '');
            sprite.style.setProperty('--sheet', 'url("' + cssUrl(entry.src) + '")');
            sprite.style.backgroundSize = sheetSize(sheetLayout(entry.rig));

            if (! this.hasAttribute('drag')) {
                sprite.style.cursor = 'pointer';
            }

            root.append(sprite);

            const pet = new Pet(this.world, sprite, {
                // A fraction of the layer's width, so a pet's patch of the
                // login row survives the row being laid out differently on a
                // phone — see the login page.
                home: entry.home === undefined || entry.home === null ? null : entry.home * this.world.width,
                roam: entry.roam ?? Math.max(70, this.world.width * 0.12),
                visiting: entry.visiting ?? false,
                scale: entry.scale ?? 1,
                src: entry.src,
                rig: entry.rig ?? null,
                // A kid's own pages say so with the `toy` attribute; the
                // login door says so per pet.
                toy: entry.visiting ? false : (entry.toy ?? this.hasAttribute('toy')),
            });

            this.world.pets.push(pet);
            this.bindDrag(sprite, pet);
        });

        this.syncToy();
        this.syncBed();

        // Just hatched: the first time the kid sees their new pet, it comes
        // out of its egg in front of them.
        if (this.hasAttribute('hatch') && ! this.reduced) {
            const pet = this.world.pets.find((one) => ! one.visiting);

            if (pet) {
                this.hatch(pet);
            }
        }

        this.paint();

        if (! this.reduced) {
            this.play();
        }
    }

    /**
     * The hatching: the new pet hidden inside a fully cracked egg where it
     * stands, the egg shaking for a moment, then bursting — two halves of
     * shell flying off — and the pet out, delighted with itself.
     */
    hatch(pet) {
        pet.inEgg = true;
        pet.sprite.style.visibility = 'hidden';

        if (pet.toy) {
            pet.toy.sprite.style.visibility = 'hidden';
        }

        const shell = document.createElement('div');
        shell.className = 'egg';
        const hue = parseInt(this.getAttribute('hatch'), 10);
        shell.style.backgroundImage = 'url("' + eggSvg(EGG_CRACKS.length, hue) + '")';
        this.shadowRoot.append(shell);

        const egg = new Egg(this.world, shell, {
            cracks: EGG_CRACKS.length,
            hue,
            scale: pet.scale,
            x: pet.x,
            hatchIn: 1.6,
            onHatch: (done) => {
                this.world.eggs = this.world.eggs.filter((one) => one !== done);
                done.sprite.remove();
                this.burst(done);

                pet.inEgg = false;
                pet.sprite.style.visibility = '';

                if (pet.toy) {
                    pet.toy.sprite.style.visibility = '';
                }

                // Out, amazed at the world, and then delighted with itself.
                if (pet.hasFullSheet()) {
                    pet.act('surprised', 'surprised', 0.9);
                } else {
                    pet.act('happy', 'happy', 1.8);
                }

                this.world.emit('fq-pet-hatched');
            },
        });

        this.world.eggs.push(egg);
    }

    /** Two halves of shell flying off where an egg burst. */
    burst(egg) {
        const size = PET_SIZE * egg.scale * screenZoom();

        ['top', 'bottom'].forEach((half) => {
            const piece = document.createElement('div');
            piece.className = 'shell shell-' + half;
            piece.style.backgroundImage = 'url("' + eggSvg(EGG_CRACKS.length, egg.hue) + '")';
            piece.style.width = size + 'px';
            piece.style.height = size + 'px';
            piece.style.left = (egg.x - size / 2) + 'px';
            piece.style.top = (egg.y - size) + 'px';
            piece.addEventListener('animationend', () => piece.remove());
            this.shadowRoot.append(piece);
        });
    }

    /**
     * Each pet's own toy, dropped beside it: on a kid's pages it comes and goes
     * with the powered-up day, and on the login door every pet has one.
     */
    syncToy() {
        if (! this.world || ! this.shadowRoot) {
            return;
        }

        this.world.pets.forEach((pet) => {
            // A toy bought at the prize counter is out every day; the sheet's
            // own toy only comes with a powered-up day. Visitors bring neither.
            const prize = ! pet.visiting && ! this.sheets() ? this.toyKey() : null;

            if (! pet.visiting && ! this.sheets()) {
                pet.wantsToy = Boolean(prize) || this.hasAttribute('toy');
            }

            const wanted = pet.wantsToy && ! this.reduced && pet.src;

            // A different toy than the one out: this one goes, and the new one
            // drops in below.
            if (pet.toy && (pet.toy.prizeKey ?? null) !== (prize || null)) {
                pet.toy.sprite.remove();
                this.world.toys = this.world.toys.filter((toy) => toy !== pet.toy);
                pet.toy = null;
            }

            if (wanted && ! pet.toy) {
                const sprite = document.createElement('div');
                const art = prize ? prizeSvg('toy', prize) : null;

                if (art) {
                    sprite.className = 'toy prize';
                    sprite.innerHTML = art;
                } else {
                    sprite.className = 'toy';
                    sprite.style.setProperty('--sheet', 'url("' + cssUrl(pet.src) + '")');
                    sprite.style.backgroundSize = sheetSize(pet.layout);
                    sprite.style.backgroundPosition = posePosition('toy', pet.layout);
                }

                const grab = document.createElement('div');
                grab.className = 'toy-grab';

                // Where nothing can be carried about, the toy is only to look
                // at — and must not sit over a kid's tile catching its taps.
                if (! this.hasAttribute('drag')) {
                    grab.style.pointerEvents = 'none';
                }

                sprite.append(grab);
                this.shadowRoot.append(sprite);

                const near = pet.home === null ? undefined : pet.home + random(-pet.roam * 0.6, pet.roam * 0.6);

                pet.toy = new Toy(this.world, near);
                pet.toy.sprite = sprite;
                pet.toy.owner = pet;
                pet.toy.prizeKey = art ? prize : null;
                this.world.toys.push(pet.toy);
                this.bindDrag(grab, pet.toy);
            }

            if (! wanted && pet.toy) {
                pet.toy.sprite.remove();
                this.world.toys = this.world.toys.filter((toy) => toy !== pet.toy);
                pet.toy = null;
            }
        });
    }

    /**
     * The pet's bed from the prize counter, standing on the floor near the
     * left of the page — where a pet naps now and then, and sleeps at bedtime.
     * A kid's own layer only.
     */
    syncBed() {
        if (! this.world || ! this.shadowRoot) {
            return;
        }

        const key = this.sheets() || ! this.world.pets.some((pet) => ! pet.visiting) ? null : this.bedKey();
        const bed = this.world.bed;

        if (bed && bed.key !== key) {
            bed.sprite.remove();
            this.world.bed = null;
        }

        const art = key ? prizeSvg('bed', key) : null;

        if (art && ! this.world.bed) {
            const sprite = document.createElement('div');
            sprite.className = 'bed';
            sprite.innerHTML = art;
            this.shadowRoot.append(sprite);

            this.world.bed = {
                key,
                sprite,
                x: this.world.clampX(Math.min(this.world.width * 0.14, 140) + BED_SIZE / 2),
                y: this.world.floor(),
            };
        }

        // Out of a bed that has gone: back on the floor.
        if (! this.world.bed) {
            this.world.pets.forEach((pet) => {
                if (pet.state === 'napping') {
                    pet.y = this.world.floor();
                    pet.act('idle', 'idle', 0.4);
                }
            });
        }

        this.paint();
    }

    /** Whether this layer is the login door's row of pets. */
    sheets() {
        return this.hasAttribute('sheets');
    }

    /** Petting, and picking up. A tap is a drag that never went anywhere. */
    bindDrag(sprite, thing) {
        let from = null;
        let moved = false;

        sprite.addEventListener('pointerdown', (event) => {
            from = { x: event.clientX, y: event.clientY };
            moved = false;
            sprite.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        sprite.addEventListener('pointermove', (event) => {
            if (! from) {
                return;
            }

            if (! moved && Math.hypot(event.clientX - from.x, event.clientY - from.y) > 7) {
                moved = true;

                if (this.hasAttribute('drag')) {
                    thing.grab ? thing.grab() : (thing.held = true);
                }
            }

            if (moved && thing.held) {
                const box = this.getBoundingClientRect();

                // Kept inside what the kid can see. A finger dragged up into
                // the header would otherwise carry the pet off the top of the
                // layer, where it is still being held and no longer anywhere.
                // A pet is held by the scruff, so the finger sits near the top
                // of its head and the body hangs below — that is the pivot the
                // swing turns on. The toy sits low in its cell, on the foot
                // line, so it is held a quarter of the way up.
                const grip = thing instanceof Pet ? PET_SIZE * (1 - SCRUFF) : PET_SIZE * 0.25;

                thing.x = this.world.clampX(event.clientX - box.left);
                thing.y = Math.min(
                    Math.max(event.clientY - box.top + grip, this.world.ceiling()),
                    this.world.floor(),
                );

                this.paint();
            }
        });

        const release = () => {
            if (! from) {
                return;
            }

            from = null;

            if (! moved) {
                thing.pet ? thing.pet() : null;
            } else if (thing.held) {
                thing.drop ? thing.drop() : (thing.held = false);
            }

            this.paint();
        };

        sprite.addEventListener('pointerup', release);
        sprite.addEventListener('pointercancel', release);
    }

    /**
     * Snack time: something to eat drops beside the kid's own pet, and it goes
     * and eats it. Play and nothing else — it grows nothing and is never owed.
     * One snack at a time, and none while it sleeps.
     */
    feed(at) {
        if (! this.world || this.reduced || this.world.asleep || this.world.snack) {
            return;
        }

        const pet = this.world.pets.find((one) => ! one.visiting);

        if (! pet) {
            return;
        }

        const perch = pet.perch;
        let x = at ? at.x : pet.x + (Math.random() < 0.5 ? -1 : 1) * random(40, 80);
        let rest = pet.y;

        if (perch && x >= perch.left + 20 && x <= perch.right - 20) {
            // On the card the pet is sitting on: it lands there, beside it.
        } else if (perch && ! at) {
            x = Math.min(Math.max(perch.left + 20, x), perch.right - 20);
        } else {
            // Somewhere off its card: the food goes to the floor, and so does
            // the pet — it hops down after it rather than walking on air.
            x = this.world.clampX(x);
            rest = this.world.floor();

            if (perch && ! pet.held) {
                pet.perch = null;
                pet.vy = 0;
                pet.landOn = rest;
                pet.state = 'falling';
                pet.pose = 'jump';
            }
        }

        // The snack out, from the prize counter — the meat block when none
        // has been bought, and the old emoji if the art has not loaded.
        const sprite = document.createElement('div');
        const art = prizeSvg('snack', this.snackKey());
        sprite.className = 'snack';

        if (art) {
            sprite.innerHTML = art;
        } else {
            sprite.textContent = '🍖';
        }

        this.shadowRoot.append(sprite);

        this.world.snack = new Snack(x, rest, at ? at.y : undefined);
        this.world.snack.sprite = sprite;

        // Straight over, unless it is busy in the air or in a hand — then it
        // finds the food the next time it decides what to do.
        if (! pet.held && ! pet.leaving && ['idle', 'happy', 'landed', 'playing', 'walking', 'sitting', 'sniffing', 'surprised'].includes(pet.state)) {
            pet.runTo(x, CHASE_SPEED);
        }

        this.paint();
    }

    /**
     * A tap on nothing in particular drops a snack right where it landed.
     *
     * Only on a kid's own pages (`feed-on-tap`), and only for a tap that
     * would otherwise do nothing: anything tappable keeps its tap — see
     * TAPPABLE — and so does a tap that ends a text selection.
     */
    feedAt(event) {
        if (! this.hasAttribute('feed-on-tap') || event.defaultPrevented || event.button !== 0) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;

        if (! target || target.closest(TAPPABLE) || String(window.getSelection?.() ?? '').length > 0) {
            return;
        }

        const box = this.getBoundingClientRect();

        this.feed({ x: event.clientX - box.left, y: event.clientY - box.top });
    }

    /** Something good happened: run over to it and jump about. */
    cheer() {
        if (! this.world || this.reduced || this.world.asleep) {
            return;
        }

        const pet = this.world.pets[0];

        if (! pet || pet.held) {
            return;
        }

        pet.runTo(this.world.clampX(pet.x + random(-150, 150)), CHASE_SPEED);
        setTimeout(() => pet.held || pet.hop(), 400);
    }

    play() {
        if (this.frame || this.reduced || ! this.world) {
            return;
        }

        let last = performance.now();

        const tick = (now) => {
            // Capped, so coming back to a tab left open for an hour does not
            // fling the pet across the page in one step.
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;

            this.world.step(dt);
            this.paint();
            this.frame = requestAnimationFrame(tick);
        };

        this.frame = requestAnimationFrame(tick);
    }

    pause() {
        if (this.frame) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    }

    /** Puts what the world says on screen. Nothing here decides anything. */
    paint() {
        if (! this.world) {
            return;
        }

        // Every sprite is scaled about its feet (or the scruff, when held),
        // so a bigger pet still stands on the card and hangs from the finger.
        const zoom = screenZoom();

        this.world.pets.forEach((pet) => {
            pet.sprite.style.backgroundPosition = posePosition(pet.pose, pet.layout);

            /*
             * A gait: the body rises and dips twice a stride and squashes on
             * the down beat. On a full sheet the two walking frames change on
             * the same beat (Pet.walkFrame()), so a foot lands on each dip. An
             * old sheet has one walking frame, and the bob alone is what keeps
             * it from sliding along like a sticker.
             */
            let gait = '';
            // Feet for anything standing on the ground; the scruff for a pet
            // dangling from a finger, so it swings from where it is held.
            let origin = '50% 100%';

            if (pet.state === 'walking') {
                const stride = pet.clock * (pet.speed > WALK_SPEED ? 13 : 9);
                const bob = Math.abs(Math.sin(stride)) * -3.2;
                const squash = 1 - Math.abs(Math.cos(stride)) * 0.035;

                gait = ' translateY(' + bob.toFixed(2) + 'px) scaleY(' + squash.toFixed(3) + ')';
            }

            /*
             * The swing — see Pet.swing(). Pivoting on the scruff, which is
             * where the finger is holding it. Written before the facing flip
             * in the transform, so a pet facing left swings the same way in
             * the world as one facing right; after it, the mirror would reverse
             * every swing.
             */
            let swing = '';

            if (pet.theta) {
                swing = ' rotate(' + (pet.theta * 180 / Math.PI).toFixed(2) + 'deg)';
            }

            // Only while it is actually in a hand. Scaled from the scruff, a
            // big pet stretches downwards — right for one dangling from a
            // finger, and wrong for one let go: it sank below the floor until
            // its swing died away and then jumped back up onto its feet.
            if (pet.held) {
                origin = '50% ' + (SCRUFF * 100) + '%';
            }

            pet.sprite.style.transformOrigin = origin;
            // Scaled about the same origin as everything else — the feet on the
            // ground, the scruff in a hand — so a small pet still stands on
            // the card and still hangs from the finger.
            const scale = pet.scale * zoom;
            const size = scale === 1 ? '' : ' scale(' + scale.toFixed(3) + ')';

            pet.sprite.style.transform = 'translate(' + (pet.x - PET_SIZE / 2) + 'px,' + (pet.y - PET_SIZE) + 'px)' + size + swing + ' scaleX(' + pet.facing + ')' + gait;
        });

        this.world.toys.forEach((toy) => {
            // In the paws, it is in front of the pet holding it.
            toy.sprite.style.zIndex = toy.carried() ? '3' : '';

            // A bought toy is drawn at its own true size (design 2 of
            // handoff/design_handoff_arcade_tokens).
            if (toy.prizeKey) {
                // Rolled about its middle, standing on its foot.
                toy.sprite.style.transform = 'translate(' + (toy.x - PRIZE_TOY_SIZE / 2) + 'px,' + (toy.y - PRIZE_TOY_SIZE) + 'px) scale(' + zoom.toFixed(3) + ')';
                toy.sprite.firstElementChild?.style.setProperty('transform', 'rotate(' + (toy.spin % 360).toFixed(1) + 'deg)');

                return;
            }

            // Shrunk with its own pet, so a baby's toy stays in proportion —
            // and never drawn bigger than a toy should be. See Toy.cellSize().
            const scale = toy.cellSize() / PET_SIZE;
            const size = scale !== 1 ? ' scale(' + scale.toFixed(3) + ')' : '';

            toy.sprite.style.transform = 'translate(' + (toy.x - PET_SIZE / 2) + 'px,' + (toy.y - PET_SIZE) + 'px)' + size;

            // An old sheet's play and toss poses have the toy drawn in the
            // pet's paws, so while its own pet is playing the loose one is put
            // away — two of the same toy side by side reads as a glitch. A
            // full sheet's paws are empty, and this toy is the one in them.
            const drawnInPaws = toy.owner && ! toy.owner.hasFullSheet() && toy.owner.state === 'playing' && ! toy.held;

            toy.sprite.style.visibility = drawnInPaws ? 'hidden' : '';
        });

        const bed = this.world.bed;

        if (bed) {
            // Its underside on the floor, scaled up from there with the pets.
            bed.sprite.style.transform = 'translate(' + (bed.x - BED_SIZE / 2) + 'px,' + (bed.y - BED_SIZE + BED_FOOT) + 'px) scale(' + zoom.toFixed(3) + ')';
        }

        this.world.eggs.forEach((egg) => {
            const scale = egg.scale * zoom;

            egg.sprite.style.transform = 'translate(' + (egg.x - PET_SIZE / 2) + 'px,' + (egg.y - PET_SIZE) + 'px) scale(' + scale.toFixed(3) + ') rotate(' + egg.angle().toFixed(2) + 'deg)';
        });

        const snack = this.world.snack;

        if (snack) {
            snack.sprite.style.transform = 'translate(' + (snack.x - 15) + 'px,' + (snack.y - 30) + 'px) scale(' + ((1 - snack.bite * 0.8) * zoom).toFixed(3) + ')';
        }
    }
}

// The same egg on the Locker's card as out on the page.
window.fqEggSvg = eggSvg;

if (! customElements.get('fq-pets')) {
    customElements.define('fq-pets', FqPets);
}
