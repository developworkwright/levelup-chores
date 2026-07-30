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
    <div
        x-data="{
            supported: 'serviceWorker' in navigator && 'PushManager' in window,
            subscribed: @js($pushSubscribed),
            busy: false,
            vapidPublicKey: @js($vapidPublicKey),
            urlBase64ToUint8Array(base64String) {
                const padding = '='.repeat((4 - base64String.length % 4) % 4);
                const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                const rawData = window.atob(base64);
                return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
            },
            async enable() {
                if (!this.supported || this.busy || !this.vapidPublicKey) return;
                this.busy = true;
                try {
                    const permission = await Notification.requestPermission();
                    if (permission !== 'granted') return;

                    const registration = await navigator.serviceWorker.ready;
                    const sub = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey),
                    });
                    const json = sub.toJSON();
                    await $wire.subscribeToPush(json.endpoint, json.keys.p256dh, json.keys.auth);
                    this.subscribed = true;
                } finally {
                    this.busy = false;
                }
            },
            async disable() {
                if (this.busy) return;
                this.busy = true;
                try {
                    const registration = await navigator.serviceWorker.ready;
                    const sub = await registration.pushManager.getSubscription();
                    if (sub) {
                        await $wire.unsubscribeFromPush(sub.endpoint);
                        await sub.unsubscribe();
                    }
                    this.subscribed = false;
                } finally {
                    this.busy = false;
                }
            },
        }"
        class="mb-4"
    >
        <button
            type="button"
            x-show="supported"
            x-cloak
            @click="subscribed ? disable() : enable()"
            :disabled="busy"
            class="rounded-[13px] border px-4 py-[10px] text-sm font-semibold disabled:opacity-50"
            :style="subscribed ? 'border-color: var(--fq-success-border); background: oklch(0.7 0.16 140 / 0.15); color: var(--fq-lime)' : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)'"
        >
            <span x-show="!subscribed" x-cloak>Enable approval alerts</span>
            <span x-show="subscribed" x-cloak>Approval alerts on — tap to turn off</span>
        </button>
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
