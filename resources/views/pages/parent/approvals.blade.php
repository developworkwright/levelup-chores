<?php

use App\Enums\BountyKind;
use App\Enums\CompletionStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\BountyUnavailableException;
use App\Models\Bounty;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Models\Redemption;
use App\Services\BountyService;
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
            $this->dispatch(
                'celebrate',
                message: "{$completion->profile->name} earned +{$completion->points_awarded} for {$completion->chore->name}!",
                motion: 'burst',
                origin: 'tap',
            );
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

    /**
     * Why a redemption was turned down, keyed by redemption id. Optional —
     * "you already have one" is worth saying, and a form that demands a reason
     * before a parent can undo a misclick is not.
     *
     * @var array<int, string>
     */
    public array $rejectReasons = [];

    /** What just happened to a redemption, so a card vanishing is explained. */
    public ?string $redemptionMessage = null;

    public function reject(int $redemptionId): void
    {
        $this->redemptionMessage = null;

        $redemption = Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', RedemptionStatus::Pending)
            ->find($redemptionId);

        if (! $redemption) {
            return;
        }

        $name = $redemption->storeItem->name;
        $kid = $redemption->profile->name;

        if (app(StoreService::class)->reject($redemption, $this->profile, $this->rejectReasons[$redemptionId] ?? null)) {
            unset($this->rejectReasons[$redemptionId]);
            // Names the refund, because that is the part a parent is trusting
            // happened — the card disappearing on its own says nothing.
            $this->redemptionMessage = "{$name} turned down — {$redemption->cost_snapshot} points back to {$kid}.";
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

    /**
     * What a parent will actually pay for a job a kid has offered to do,
     * keyed by bounty id. Seeded from the asking price so the field is never
     * empty, and editable because a slightly-too-high ask should get met in
     * the middle rather than quietly ignored.
     *
     * @var array<int, int|string>
     */
    public array $hirePrices = [];

    public ?string $hireMessage = null;

    public function hire(int $bountyId): void
    {
        $this->hireMessage = null;

        $bounty = Bounty::where('household_id', $this->profile->household_id)->find($bountyId);

        if (! $bounty) {
            $this->hireMessage = 'That job is no longer on the board.';

            return;
        }

        try {
            // Hiring pays nothing here. It creates a one-time chore already
            // claimed by the kid, which then runs the ordinary approval path —
            // so the points only exist once the work is signed off below.
            app(BountyService::class)->hire($bounty, $this->profile, (int) ($this->hirePrices[$bountyId] ?? $bounty->reward_amount));

            $this->hireMessage = "Hired {$bounty->poster->name}. It's in the list below once they've done it.";
            unset($this->hirePrices[$bountyId]);
        } catch (BountyUnavailableException|InvalidArgumentException $e) {
            $this->hireMessage = $e->getMessage();
        }
    }

    public function with(): array
    {
        $jobOffers = Bounty::where('household_id', $this->profile->household_id)
            // Offers of work only. A sale runs the same machinery but can't be
            // hired — turning "my blue Lego set" into a one-time chore would
            // mint a chore named after the thing being sold.
            ->whereIn('kind', BountyKind::hireableCases())
            // Aimed at a sibling, so it is theirs to answer — a grown-up
            // taking it would be hijacking a deal between two kids.
            ->whereNull('target_profile_id')
            ->takeable()
            ->with('poster')
            ->oldest('expires_at')
            ->get();

        foreach ($jobOffers as $offer) {
            $this->hirePrices[$offer->id] ??= $offer->reward_amount;
        }

        return [
            'jobOffers' => $jobOffers,
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
                ? 'border-color: var(--fq-success-border); background: color-mix(in srgb, var(--fq-lime) 15%, transparent); color: var(--fq-lime)'
                : (['blocked', 'error'].includes(state)
                    ? 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-gold)'
                    : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)')"
            x-text="busy ? 'Working…' : labels[state]"
        ></button>

        <p x-show="notes[state]" x-cloak x-text="notes[state]" class="mt-2 max-w-[52ch] text-xs text-fq-text-5"></p>
    </div>

    {{-- Jobs the kids have offered to do, above the approvals queue: this is
         the only thing on the page nobody else can action, and a job offer
         goes stale in a way an approval doesn't. Hiring one doesn't pay
         anything — it drops a one-time chore into the queue below, which is
         where it gets signed off and paid like any other work. --}}
    {{-- Outside the section below, because hiring the last offer on the board
         empties it — and a confirmation that disappears with the thing it is
         confirming leaves the parent wondering whether the tap landed. --}}
    @if ($hireMessage)
        <p class="mb-3 text-sm font-semibold text-fq-lime">{{ $hireMessage }}</p>
    @endif

    @if ($jobOffers->isNotEmpty())
        <h2 class="font-baloo text-xl font-bold">Jobs On Offer</h2>

        <div class="mt-3 mb-6 grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-3">
            @foreach ($jobOffers as $offer)
                <div wire:key="job-offer-{{ $offer->id }}" class="flex flex-col gap-[13px] rounded-[20px] border border-fq-line bg-fq-panel p-4">
                    <div class="flex items-center gap-3">
                        <span
                            class="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-[9px] font-baloo text-[13px] font-extrabold text-fq-bg"
                            style="background: {{ $offer->poster->color->cssVar() }}"
                        >{{ mb_substr($offer->poster->name, 0, 1) }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold">{{ $offer->description }}</p>
                            <p class="font-mono-fq text-[10px] text-fq-text-5">
                                {{ $offer->poster->name }} asks {{ $offer->rewardText() }} &middot;
                                {{ $offer->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase" for="hire-{{ $offer->id }}">
                            Pay
                        </label>
                        <input
                            id="hire-{{ $offer->id }}"
                            type="number"
                            wire:model="hirePrices.{{ $offer->id }}"
                            min="1"
                            max="1000"
                            class="w-[100px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                        >
                        <span class="font-mono-fq text-[10px] text-fq-text-5">PTS</span>

                        <button
                            type="button"
                            wire:click="hire({{ $offer->id }})"
                            class="ml-auto rounded-[12px] px-4 py-2 text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                            style="background: var(--fq-lime)"
                        >Hire</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="font-baloo text-xl font-bold">Chore Approvals</h2>

    @if ($completions->isEmpty())
        <div class="mt-3 rounded-[20px] border border-dashed border-fq-line bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
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
        <div class="mt-3 rounded-[20px] border border-dashed border-fq-line bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
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
                    {{-- Optional, and above the buttons so it is obviously
                         attached to the refusal rather than to the reward. --}}
                    <input
                        type="text"
                        wire:model="rejectReasons.{{ $redemption->id }}"
                        maxlength="160"
                        placeholder="Why not? (optional)"
                        class="w-full rounded-[12px] border border-dashed border-fq-line-2 bg-fq-sunk px-3 py-2 text-[13px] outline-none focus:border-fq-coral"
                    >

                    <div class="flex gap-2">
                        <button type="button" wire:click="fulfill({{ $redemption->id }})" class="flex-1 rounded-[13px] py-[11px] text-sm font-bold text-fq-bg" style="background:var(--fq-cyan)">Mark fulfilled</button>

                        {{-- Points leave a kid's balance the moment they ask,
                             so a request nobody meant to grant has already
                             been paid for. This is what hands it back. --}}
                        <button
                            type="button"
                            wire:click="reject({{ $redemption->id }})"
                            wire:confirm="Turn down '{{ $redemption->storeItem->name }}' and give {{ $redemption->profile->name }} their {{ $redemption->cost_snapshot }} points back?"
                            class="rounded-[13px] border px-[14px] py-[11px] text-sm text-fq-danger"
                            style="border-color: var(--fq-danger-border)"
                        >Reject</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($redemptionMessage)
        <p class="mt-3 text-sm text-fq-lime">{{ $redemptionMessage }}</p>
    @endif
</x-parent.shell>
