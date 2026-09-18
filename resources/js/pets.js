/*
 * The pet that lives on a kid's pages.
 *
 * One custom element, `<fq-pets>`, laid over the page. It draws the pet from the
 * twelve-pose sprite sheet a grown-up uploaded (see App\Enums\CosmeticSlot), and
 * the pet gets on with its own day: wanders, hops onto the top edge of a card,
 * sits and blinks, looks at whatever was tapped last, plays with its toy on a
 * powered-up day, and sleeps after bedtime. It can be petted, picked up and fed.
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

/** How big the pet is drawn, in CSS pixels. One cell of the sheet. */
const PET_SIZE = 78;

/** The poses, in the order the sheet draws them. Mirrors CosmeticSlot::PET_POSES. */
const POSES = ['idle', 'blink', 'crouch', 'jump', 'walk', 'happy', 'held', 'landed', 'play', 'toss', 'sleep', 'toy'];

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

/** Where one pose sits in the sheet, as a background-position pair. */
function posePosition(pose) {
    const index = Math.max(0, POSES.indexOf(pose));

    return ((index % 4) * 100 / 3) + '% ' + (Math.floor(index / 4) * 100 / 2) + '%';
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

        this.act('happy', 'happy', 1.4);
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

    /** Says hello to whoever it has just bumped into. */
    greet(other) {
        this.facing = other.x < this.x ? -1 : 1;
        this.act('happy', 'happy', random(0.9, 1.6));
    }

    step(dt) {
        const world = this.world;

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
                this.settle();
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
            this.pose = 'walk';

            // Following the floor as the page scrolls under it.
            if (! this.perch) {
                this.y = world.floor();
            }

            if (Math.abs(distance) < 4) {
                if (this.leaving) {
                    this.gone = true;

                    return;
                }

                if (this.canReachSnack()) {
                    this.act('eating', 'crouch', EAT_SECONDS);

                    return;
                }

                this.act('idle', 'idle', random(0.4, 1.4));
            }

            return;
        }

        if (this.state === 'eating') {
            if (! this.perch) {
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

        if (this.state === 'idle' || this.state === 'happy' || this.state === 'landed' || this.state === 'playing') {
            if (! this.perch) {
                this.y = world.floor();
            }

            this.blinkIn -= dt;

            if (this.state === 'idle' && this.blinkIn <= 0) {
                this.pose = this.pose === 'blink' ? 'idle' : 'blink';
                this.blinkIn = this.pose === 'blink' ? 0.16 : random(2, 6);
            }

            if (this.think <= 0) {
                this.decide();
            }
        }
    }

    /** What to do next, when nothing is already happening. */
    decide() {
        const world = this.world;
        const toy = world.toy;

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

            this.pose = Math.random() < 0.5 ? 'play' : 'toss';
            this.state = 'playing';
            this.think = random(0.8, 1.6);

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
        this.toy = null;
        this.snack = null;
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
            const onScreen = box.bottom > 0 && box.top < window.innerHeight - 20 && box.top > PET_SIZE;
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

    step(dt) {
        this.measure();
        this.pets.forEach((pet) => pet.step(dt));

        // A visitor that has walked off the edge takes its sprite with it.
        this.pets = this.pets.filter((pet) => {
            if (! pet.gone) {
                return true;
            }

            pet.sprite.remove();

            return false;
        });

        this.introduce();

        if (this.toy) {
            this.toy.step(dt, this);
        }

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

/** The toy: a sprite that falls, sits, and can be dragged about. */
class Toy {
    constructor(world) {
        this.x = random(80, Math.max(140, world.width - 80));
        this.y = -40;
        this.vy = 0;
        this.held = false;
    }

    step(dt, world) {
        if (this.held) {
            return;
        }

        const ground = world.groundUnder(this.x, this.y);

        if (this.y < ground) {
            this.vy += GRAVITY * dt;
            this.y = Math.min(ground, this.y + this.vy * dt);
        } else {
            this.y = ground;
            this.vy = 0;
        }
    }
}

class FqPets extends HTMLElement {
    static get observedAttributes() {
        return ['sheet', 'scale', 'effect', 'toy', 'asleep', 'drag', 'sheets', 'visitor'];
    }

    connectedCallback() {
        this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.render();

        this.onCelebrate = () => this.cheer();
        this.onFeed = () => this.feed();
        this.onTap = (event) => this.feedAt(event);
        this.onVisible = () => (document.hidden ? this.pause() : this.play());

        window.addEventListener('celebrate', this.onCelebrate);
        window.addEventListener('fq-pet-feed', this.onFeed);
        document.addEventListener('click', this.onTap);
        document.addEventListener('visibilitychange', this.onVisible);
    }

    disconnectedCallback() {
        this.pause();
        window.removeEventListener('celebrate', this.onCelebrate);
        window.removeEventListener('fq-pet-feed', this.onFeed);
        document.removeEventListener('click', this.onTap);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    attributeChangedCallback(name) {
        if (! this.isConnected) {
            return;
        }

        // The sheet changing is a different pet; everything else is the same
        // pet in a different mood, and must not restart it mid-jump.
        if (name === 'sheet' || name === 'scale' || name === 'effect' || name === 'sheets' || name === 'visitor') {
            this.render();

            return;
        }

        if (this.world) {
            this.world.asleep = this.hasAttribute('asleep');
            this.syncToy();
        }
    }

    /**
     * Every animal this layer holds.
     *
     * One kid's own pet from `sheet`, a sibling's from `visitor`, or a whole
     * row of them from `sheets` — which is the login door, where each pet is
     * penned around its own kid's tile.
     *
     * @return array<int, {src: string, effect: ?string, scale: ?number, home: ?number, roam: ?number, visiting: ?boolean}>
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
            return many.filter((entry) => entry && entry.src);
        }

        const mine = this.getAttribute('sheet');
        const visitor = read('visitor');

        return [
            mine ? { src: mine, effect: this.getAttribute('effect'), scale: parseFloat(this.getAttribute('scale')) || 1 } : null,
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
                background-image: var(--sheet); background-size: 400% 300%;
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
            .toy { pointer-events: none; transform-origin: 50% 100%; }
            .toy-grab {
                position: absolute; left: 22%; right: 22%; bottom: 4%; height: 46%;
                pointer-events: auto; cursor: grab; touch-action: none;
            }
            .snack {
                position: absolute; width: 30px; height: 30px; font-size: 26px; line-height: 30px;
                text-align: center; pointer-events: none; transform-origin: 50% 100%;
                filter: drop-shadow(0 2px 0 rgba(0,0,0,.45));
            }
        `;

        root.append(style);

        this.world = new World(this);
        this.world.asleep = this.hasAttribute('asleep');
        this.world.measure();

        cast.forEach((entry) => {
            const sprite = document.createElement('div');
            sprite.className = 'pet ' + (entry.effect || '');
            sprite.style.setProperty('--sheet', 'url("' + cssUrl(entry.src) + '")');

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
            });

            this.world.pets.push(pet);
            this.bindDrag(sprite, pet);
        });

        this.syncToy();
        this.paint();

        if (! this.reduced) {
            this.play();
        }
    }

    /** The toy comes and goes with the powered-up day. */
    syncToy() {
        if (! this.world || ! this.shadowRoot) {
            return;
        }

        const wanted = this.hasAttribute('toy') && ! this.reduced;

        if (wanted && ! this.world.toy) {
            const sprite = document.createElement('div');
            sprite.className = 'toy';
            sprite.style.setProperty('--sheet', 'url("' + cssUrl(this.cast()[0].src) + '")');
            sprite.style.backgroundPosition = posePosition('toy');

            const grab = document.createElement('div');
            grab.className = 'toy-grab';
            sprite.append(grab);
            this.shadowRoot.append(sprite);

            this.world.toy = new Toy(this.world);
            this.world.toy.sprite = sprite;
            this.bindDrag(grab, this.world.toy);
        }

        if (! wanted && this.world.toy) {
            this.world.toy.sprite.remove();
            this.world.toy = null;
        }
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

        const sprite = document.createElement('div');
        sprite.className = 'snack';
        sprite.textContent = '🍖';
        this.shadowRoot.append(sprite);

        this.world.snack = new Snack(x, rest, at ? at.y : undefined);
        this.world.snack.sprite = sprite;

        // Straight over, unless it is busy in the air or in a hand — then it
        // finds the food the next time it decides what to do.
        if (! pet.held && ! pet.leaving && ['idle', 'happy', 'landed', 'playing', 'walking'].includes(pet.state)) {
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

        this.world.pets.forEach((pet) => {
            pet.sprite.style.backgroundPosition = posePosition(pet.pose);

            /*
             * The sheet has one walking frame, so walking it across the page
             * slides it like a sticker. A gait instead: the body rises and dips
             * twice a stride and squashes on the down beat, which is what a
             * two-frame walk cycle is really doing. Cheap, and it reads as legs
             * even though the legs never move.
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
                origin = '50% ' + (SCRUFF * 100) + '%';
            }

            pet.sprite.style.transformOrigin = origin;
            // Scaled about the same origin as everything else — the feet on the
            // ground, the scruff in a hand — so a small pet still stands on
            // the card and still hangs from the finger.
            const size = pet.scale === 1 ? '' : ' scale(' + pet.scale + ')';

            pet.sprite.style.transform = 'translate(' + (pet.x - PET_SIZE / 2) + 'px,' + (pet.y - PET_SIZE) + 'px)' + size + swing + ' scaleX(' + pet.facing + ')' + gait;
        });

        const toy = this.world.toy;

        if (toy) {
            // Shrunk with the kid's own pet, so a baby's toy stays in proportion.
            const owner = this.world.pets.find((one) => ! one.visiting);
            const size = owner && owner.scale !== 1 ? ' scale(' + owner.scale + ')' : '';

            toy.sprite.style.transform = 'translate(' + (toy.x - PET_SIZE / 2) + 'px,' + (toy.y - PET_SIZE) + 'px)' + size;
        }

        const snack = this.world.snack;

        if (snack) {
            snack.sprite.style.transform = 'translate(' + (snack.x - 15) + 'px,' + (snack.y - 30) + 'px) scale(' + (1 - snack.bite * 0.8).toFixed(3) + ')';
        }
    }
}

if (! customElements.get('fq-pets')) {
    customElements.define('fq-pets', FqPets);
}
