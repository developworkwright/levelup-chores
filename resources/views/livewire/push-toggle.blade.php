<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use NotificationChannels\WebPush\PushSubscription;

/**
 * The switch that turns push notifications on for whoever is signed in.
 *
 * One component for both consoles, because everything hard about push is the
 * same on both sides — the browser's permission, the subscription drifting
 * apart from the row that backs it, a VAPID key that was regenerated, a
 * browser that can't do any of it. Only the words and the shape differ, and
 * those come off `audience`.
 */
new class extends Component
{
    /** 'parent' — a labelled button with room to explain itself. 'kid' — a bell in the header. */
    #[Locked]
    public string $audience = 'kid';

    public function mount(string $audience = 'kid'): void
    {
        $this->audience = $audience === 'parent' ? 'parent' : 'kid';
    }

    private function profile(): Profile
    {
        $profile = Auth::guard('profile')->user();

        abort_unless($profile instanceof Profile, 403);

        return $profile;
    }

    public function subscribeToPush(string $endpoint, ?string $key, ?string $token): void
    {
        $this->profile()->updatePushSubscription($endpoint, $key, $token);
    }

    public function unsubscribeFromPush(string $endpoint): void
    {
        $this->profile()->deletePushSubscription($endpoint);
    }

    /**
     * Who, if anyone, this browser's subscription currently belongs to.
     *
     * The browser and the database drift apart easily — a subscription outlives
     * the row that backs it — and the two ways that happens need opposite
     * answers. Nobody owns it: adopt it silently, because showing 'off' for
     * alerts the browser is already holding open leaves a kid tapping a button
     * that does nothing. Somebody else owns it: say so and wait to be told,
     * because a subscription belongs to one browser, so claiming it is *taking*
     * it — and doing that on page load is how a kid signing in on a parent's
     * phone silently killed the approval alerts that phone was there for.
     *
     * @return array{owner: 'mine'|'none'|'other', name: string|null}
     */
    public function describeSubscription(string $endpoint): array
    {
        $profile = $this->profile();
        $subscription = app(config('webpush.model'))->findByEndpoint($endpoint);

        if (! $subscription instanceof PushSubscription) {
            return ['owner' => 'none', 'name' => null];
        }

        if ($profile->ownsPushSubscription($subscription)) {
            return ['owner' => 'mine', 'name' => null];
        }

        // Named only when they're family. The endpoint arrives from the
        // browser, so answering is answering a question somebody asked about a
        // string they handed us — fine for "your sister has these", not for
        // turning opaque endpoints into names across households.
        $owner = $subscription->subscribable;
        $family = $owner instanceof Profile && $owner->household_id === $profile->household_id;

        return ['owner' => 'other', 'name' => $family ? $owner->name : null];
    }

    public function with(): array
    {
        $kid = $this->audience === 'kid';

        return [
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            /*
             * Every way this can fail is silent by nature: a denied permission,
             * a browser without push, an unconfigured server, a subscription
             * the database has forgotten, one another profile is holding. All
             * of them look identical from the sofa — no notification — so each
             * one is stated out loud rather than quietly doing nothing.
             */
            'labels' => $kid ? [
                'off' => 'Alerts are off — tap to turn them on',
                'on' => 'Alerts are on — tap to turn them off',
                'blocked' => 'Alerts are blocked',
                'unsupported' => 'Alerts do not work here',
                'unconfigured' => 'Alerts are not set up',
                'error' => 'Alerts did not turn on — tap to try again',
                'taken' => 'Alerts on this device belong to someone else',
            ] : [
                'off' => 'Enable approval alerts',
                'on' => 'Approval alerts on — tap to turn off',
                'blocked' => 'Approval alerts are blocked',
                'unsupported' => 'Approval alerts unavailable here',
                'unconfigured' => 'Approval alerts not set up',
                'error' => 'Could not turn alerts on — tap to retry',
                'taken' => 'Approval alerts here go to someone else',
            ],
            'notes' => $kid ? [
                'blocked' => 'This browser is blocking alerts. A grown-up can allow them again in the browser settings, then reload the page.',
                'unsupported' => 'This browser cannot do alerts. On an iPhone or iPad, add the app to the Home Screen and open it from there.',
                'unconfigured' => 'Alerts are not switched on for the app yet. A grown-up will need to sort that out.',
                'error' => 'The browser said no. Reload the page and try again.',
                'taken' => 'This device can only alert one person, and right now that is :name. Tap again to move the alerts to you — :name will stop getting theirs here.',
            ] : [
                'blocked' => 'This browser is blocking notifications for the site. Re-allow them in your browser settings, then reload this page.',
                'unsupported' => 'This browser cannot receive push notifications. On an iPhone or iPad, add the app to your Home Screen and open it from there.',
                'unconfigured' => 'The server has no VAPID keys. Run `php artisan webpush:vapid`, then redeploy.',
                'error' => 'The browser refused the subscription. Reload and try again.',
                'taken' => 'A subscription belongs to one browser, and this one is currently :name\'s. Tap again to take it over — :name will stop getting alerts on this device.',
            ],
        ];
    }
}; ?>

<div
    x-data="{
        state: @js($vapidPublicKey ? 'off' : 'unconfigured'),
        busy: false,
        /** Whose alerts this device is holding, once we know they are not ours. */
        holder: null,
        /** Tapping a button that cannot do anything has to say why. */
        showNote: false,
        labels: @js($labels),
        rawNotes: @js($notes),
        get label() {
            return this.labels[this.state];
        },
        get note() {
            return (this.rawNotes[this.state] ?? '').replaceAll(':name', this.holder ?? 'someone else');
        },
        supported() {
            return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        },
        applicationServerKey() {
            const raw = @js($vapidPublicKey) ?? '';
            const padded = (raw + '='.repeat((4 - raw.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');

            return Uint8Array.from([...atob(padded)].map((c) => c.charCodeAt(0)));
        },
        async existing() {
            const registration = await navigator.serviceWorker.getRegistration();

            return registration ? await registration.pushManager.getSubscription() : null;
        },
        async sync() {
            if (this.state === 'unconfigured') return;
            if (!this.supported()) { this.state = 'unsupported'; return; }
            if (Notification.permission === 'denied') { this.state = 'blocked'; return; }

            const subscription = await this.existing();

            if (!subscription) { this.state = 'off'; return; }

            const { owner, name } = await $wire.describeSubscription(subscription.endpoint);

            if (owner === 'other') {
                this.holder = name;
                this.state = 'taken';

                return;
            }

            // Ours already, or nobody's — see describeSubscription(). Showing
            // 'on' for alerts that can never arrive is the worst outcome, so
            // the forgotten row is written back rather than trusted.
            if (owner === 'none') {
                const json = subscription.toJSON();
                await $wire.subscribeToPush(json.endpoint, json.keys.p256dh, json.keys.auth);
            }

            this.state = 'on';
        },
        async enable() {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                this.state = permission === 'denied' ? 'blocked' : 'off';

                return;
            }

            const registration = await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();

            // A subscription minted under a different VAPID key is dead on
            // arrival — the push service rejects every send against it, and
            // nothing surfaces. Keys change whenever they are regenerated,
            // so re-subscribe rather than trusting the old one.
            if (subscription) {
                const current = new Uint8Array(subscription.options?.applicationServerKey ?? []);
                const wanted = this.applicationServerKey();

                if (current.length !== wanted.length || !current.every((b, i) => b === wanted[i])) {
                    await subscription.unsubscribe();
                    subscription = null;
                }
            }

            subscription ??= await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.applicationServerKey(),
            });

            const json = subscription.toJSON();
            await $wire.subscribeToPush(json.endpoint, json.keys.p256dh, json.keys.auth);
            this.holder = null;
            this.state = 'on';
        },
        async disable() {
            const subscription = await this.existing();

            if (subscription) {
                // Server first — a row left behind here keeps a dead
                // endpoint on file with no way to clear it.
                await $wire.unsubscribeFromPush(subscription.endpoint);
                await subscription.unsubscribe();
            }

            this.state = 'off';
        },
        async toggle() {
            if (this.busy) return;

            // Nothing a tap can fix, so the tap explains itself instead.
            if (['unsupported', 'unconfigured', 'blocked'].includes(this.state)) {
                this.showNote = !this.showNote;

                return;
            }

            // Taking the device off another profile is a real consequence, so
            // the first tap says what it costs and the second one does it.
            if (this.state === 'taken' && !this.showNote) {
                this.showNote = true;

                return;
            }

            this.busy = true;
            this.showNote = false;

            try {
                await (this.state === 'on' ? this.disable() : this.enable());
            } catch (e) {
                this.state = 'error';
            } finally {
                this.busy = false;
            }
        },
    }"
    x-init="sync()"
    {{-- On the wrapper rather than on the note panel itself, so a tap on the
         button counts as inside. Hung off the panel it raced its own opening:
         the tap that set showNote bubbled on to a handler that cleared it. --}}
    @click.outside="showNote = false"
    class="{{ $audience === 'kid' ? 'relative' : 'mb-4' }}"
>
    @if ($audience === 'kid')
        {{-- One of the 52px icon squares the header ends with. Like the mute
             button beside it the glyph never changes — only its weight does —
             so the row cannot reflow as the state settles after load. --}}
        <button
            type="button"
            @click="toggle()"
            :disabled="busy"
            :title="label"
            :aria-label="label"
            :aria-pressed="state === 'on'"
            :style="state === 'on' ? '' : 'opacity:0.35'"
            :class="['blocked', 'error', 'taken'].includes(state) ? 'text-fq-gold' : (state === 'on' ? 'text-fq-lime' : 'text-fq-text-4')"
            class="flex h-[52px] w-[52px] items-center justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk text-[16px] transition hover:text-fq-text disabled:opacity-60"
        >&#128276;</button>

        {{-- A tooltip is no use on the devices this runs on, so the explanation
             is a panel the button opens. Anchored right so it cannot run off
             the edge of a phone from the last control in the row. --}}
        <div
            x-show="showNote && note"
            x-cloak
            class="absolute top-[58px] right-0 z-30 w-[248px] rounded-[14px] border border-fq-line-2 bg-fq-panel p-3 text-xs leading-relaxed text-fq-text-3 shadow-lg"
            x-text="note"
        ></div>
    @else
        <button
            type="button"
            @click="toggle()"
            :disabled="busy"
            class="rounded-[13px] border px-4 py-[10px] text-sm font-semibold disabled:opacity-60"
            :style="state === 'on'
                ? 'border-color: var(--fq-success-border); background: color-mix(in srgb, var(--fq-lime) 15%, transparent); color: var(--fq-lime)'
                : (['blocked', 'error', 'taken'].includes(state)
                    ? 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-gold)'
                    : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)')"
            {{-- The note below is always on show here, so an armed confirm has
                 to say so on the button itself — otherwise the first of the
                 two taps a takeover needs looks like a tap that did nothing. --}}
            x-text="busy ? 'Working…' : (showNote && state === 'taken' ? 'Take this device over?' : label)"
        ></button>

        {{-- Shown as soon as there is one to show, rather than waiting for a
             tap: a parent reading the page is the audience, and the states it
             explains are all ones where the button itself is a dead end. --}}
        <p x-show="note" x-cloak x-text="note" class="mt-2 max-w-[52ch] text-xs text-fq-text-5"></p>
    @endif
</div>
