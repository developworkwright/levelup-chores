<?php

use App\Enums\CompletionStatus;
use App\Enums\RedemptionStatus;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Models\Redemption;
use App\Services\ChoreService;
use App\Services\StoreService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    public function approve(int $completionId): void
    {
        $completion = ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', CompletionStatus::Pending)
            ->find($completionId);

        if ($completion) {
            $completion->loadMissing('profile', 'chore');
            app(ChoreService::class)->approve($completion, $this->profile);
            $this->dispatch('celebrate', message: "{$completion->profile->name} earned +{$completion->points_awarded} for {$completion->chore->name}!");
        }
    }

    public function sendBack(int $completionId): void
    {
        $completion = ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', CompletionStatus::Pending)
            ->find($completionId);

        if ($completion) {
            app(ChoreService::class)->sendBack($completion, $this->profile);
        }
    }

    public function fulfill(int $redemptionId): void
    {
        $redemption = Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', RedemptionStatus::Pending)
            ->find($redemptionId);

        if ($redemption) {
            app(StoreService::class)->fulfill($redemption, $this->profile);
        }
    }

    public function subscribeToPush(string $endpoint, ?string $key, ?string $token): void
    {
        $this->profile->updatePushSubscription($endpoint, $key, $token);
    }

    public function unsubscribeFromPush(string $endpoint): void
    {
        $this->profile->deletePushSubscription($endpoint);
    }

    public function with(): array
    {
        return [
            'completions' => ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
                ->where('status', CompletionStatus::Pending)
                ->with(['profile', 'chore'])
                ->oldest('submitted_at')
                ->get(),
            'redemptions' => Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
                ->where('status', RedemptionStatus::Pending)
                ->with(['profile', 'storeItem'])
                ->oldest('requested_at')
                ->get(),
            'pushSubscribed' => $this->profile->pushSubscriptions()->exists(),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="approvals">
    {{--
        Every way this can fail is silent by nature: a denied permission, a
        browser without push, an unconfigured server, a subscription the
        database has forgotten. All of them look identical from the sofa —
        no notification — so the button states each one out loud rather than
        quietly doing nothing.
    --}}
    <div
        x-data="{
            state: @js($vapidPublicKey ? 'off' : 'unconfigured'),
            busy: false,
            labels: {
                off: 'Enable approval alerts',
                on: 'Approval alerts on — tap to turn off',
                blocked: 'Approval alerts are blocked',
                unsupported: 'Approval alerts unavailable here',
                unconfigured: 'Approval alerts not set up',
                error: 'Could not turn alerts on — tap to retry',
            },
            notes: {
                blocked: 'This browser is blocking notifications for the site. Re-allow them in your browser settings, then reload this page.',
                unsupported: 'This browser cannot receive push notifications. On an iPhone or iPad, add the app to your Home Screen and open it from there.',
                unconfigured: 'The server has no VAPID keys. Run `php artisan webpush:vapid`, then redeploy.',
                error: 'The browser refused the subscription. Reload and try again.',
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
                this.state = subscription ? 'on' : 'off';

                // The browser and the database drift apart easily — the
                // subscription outlives the row that backs it. Showing 'on'
                // for alerts that can never arrive is the worst outcome, so
                // re-register instead.
                if (subscription && ! @js($pushSubscribed)) {
                    const json = subscription.toJSON();
                    await $wire.subscribeToPush(json.endpoint, json.keys.p256dh, json.keys.auth);
                }
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
                // nothing surfaces. Keys change whenever they're regenerated,
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
                if (this.busy || ['unsupported', 'unconfigured', 'blocked'].includes(this.state)) return;

                this.busy = true;

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
        class="mb-4"
    >
        <button
            type="button"
            @click="toggle()"
            :disabled="busy || ['unsupported', 'unconfigured', 'blocked'].includes(state)"
            class="rounded-[13px] border px-4 py-[10px] text-sm font-semibold disabled:opacity-60"
            :style="state === 'on'
                ? 'border-color: var(--fq-success-border); background: oklch(0.7 0.16 140 / 0.15); color: var(--fq-lime)'
                : (['blocked', 'error'].includes(state)
                    ? 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-gold)'
                    : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)')"
            x-text="busy ? 'Working…' : labels[state]"
        ></button>

        <p x-show="notes[state]" x-cloak x-text="notes[state]" class="mt-2 max-w-[52ch] text-xs text-fq-text-5"></p>
    </div>

    <h2 class="font-baloo text-xl font-bold">Chore Approvals</h2>

    @if ($completions->isEmpty())
        <div class="mt-3 rounded-[20px] border border-dashed border-[#2f3960] bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
            Queue's clear. Nothing to approve.
        </div>
    @else
        <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-3">
            @foreach ($completions as $completion)
                <div wire:key="completion-{{ $completion->id }}" class="flex flex-col gap-[13px] rounded-[20px] border border-fq-line bg-fq-panel p-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px] font-baloo text-sm font-extrabold text-fq-bg"
                            style="background:{{ $completion->profile->color->cssVar() }}"
                        >{{ mb_substr($completion->profile->name, 0, 1) }}</div>
                        <div class="flex-1">
                            <p class="text-[15px] font-semibold">{{ $completion->chore->name }}</p>
                            <p class="font-mono-fq text-[10px] text-fq-text-4">{{ $completion->profile->name }} · {{ $completion->submitted_at->diffForHumans() }}</p>
                        </div>
                        <span class="font-baloo text-lg font-extrabold text-fq-lime">+{{ $completion->points_awarded }}</span>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="approve({{ $completion->id }})" class="flex-1 rounded-[13px] py-[11px] text-sm font-bold text-fq-bg" style="background:var(--fq-lime)">Approve</button>
                        <button type="button" wire:click="sendBack({{ $completion->id }})" class="rounded-[13px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[11px] text-sm text-fq-text-3">Send back</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="mt-6 font-baloo text-xl font-bold">Redemption Requests</h2>

    @if ($redemptions->isEmpty())
        <div class="mt-3 rounded-[20px] border border-dashed border-[#2f3960] bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
            No redemptions waiting.
        </div>
    @else
        <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-3">
            @foreach ($redemptions as $redemption)
                <div wire:key="redemption-{{ $redemption->id }}" class="flex flex-col gap-[13px] rounded-[20px] border border-fq-line bg-fq-panel p-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px] font-baloo text-sm font-extrabold text-fq-bg"
                            style="background:{{ $redemption->profile->color->cssVar() }}"
                        >{{ mb_substr($redemption->profile->name, 0, 1) }}</div>
                        <div class="flex-1">
                            <p class="text-[15px] font-semibold">{{ $redemption->storeItem->name }}</p>
                            <p class="font-mono-fq text-[10px] text-fq-text-4">{{ $redemption->profile->name }} · {{ $redemption->requested_at->diffForHumans() }}</p>
                        </div>
                        <span class="font-baloo text-lg font-extrabold text-fq-gold">-{{ $redemption->cost_snapshot }}</span>
                    </div>
                    <button type="button" wire:click="fulfill({{ $redemption->id }})" class="w-full rounded-[13px] py-[11px] text-sm font-bold text-fq-bg" style="background:var(--fq-cyan)">Mark fulfilled</button>
                </div>
            @endforeach
        </div>
    @endif
</x-parent.shell>
