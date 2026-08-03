<?php

use App\Models\Badge;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function with(): array
    {
        $earned = $this->profile->badges->keyBy('key');

        $badges = Badge::orderBy('id')->get()->map(fn (Badge $badge) => [
            'badge' => $badge,
            'earned' => $earned->has($badge->key),
            'earnedAt' => $earned->get($badge->key)?->pivot->earned_at,
            // A hidden badge keeps its name and description back until it's
            // won — revealing them here would defeat the point of hiding it.
            'secret' => $badge->hidden && ! $earned->has($badge->key),
        ]);

        return [
            'badges' => $badges,
            'earnedCount' => $badges->where('earned', true)->count(),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="badges">
    <div class="flex flex-col gap-[14px]">
        <div class="rounded-[24px] border border-fq-line bg-fq-panel p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-gold uppercase">Trophy Case</p>
                    <h2 class="mt-1 font-baloo text-2xl font-extrabold">Badges</h2>
                </div>
                <span class="font-mono-fq text-[11px] text-fq-text-4">
                    {{ $earnedCount }} / {{ $badges->count() }} EARNED
                </span>
            </div>
            <p class="mt-2 max-w-[520px] text-sm text-fq-text-2">
                Every badge you can earn, and exactly how to get it. Some are secret — you'll only
                find out what they were once you've unlocked them.
            </p>
        </div>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-3">
            @foreach ($badges as $entry)
                @php
                    $badge = $entry['badge'];
                    // The same tier x-badge-star strikes the metal from, so the
                    // label under a name always names what's on screen.
                    $tier = $badge->tier();
                    // The animated tier can't take an inline colour anywhere:
                    // it would outrank the keyframes and freeze on one hue.
                    $drifting = $entry['earned'] && $tier->isAnimated();
                @endphp

                <div
                    wire:key="badge-{{ $badge->id }}"
                    @class([
                        'flex gap-3 rounded-[20px] border p-4',
                        'opacity-60' => ! $entry['earned'],
                        'fq-rainbow-ring' => $drifting,
                    ])
                    style="background: var(--fq-panel); @if (! $drifting) border-color: {{ $entry['earned'] ? $tier->ringVar() : 'var(--fq-line)' }}; @endif"
                >
                    <x-badge-star :badge="$badge" :earned="$entry['earned']" :secret="$entry['secret']" size="lg" />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="text-[15px] font-semibold">
                                {{ $entry['secret'] ? '???' : $badge->name }}
                            </p>
                            @if ($entry['earned'])
                                <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-lime uppercase">Earned</span>
                            @endif
                        </div>

                        <p
                            @class([
                                'mt-[2px] font-mono-fq text-[9px] tracking-[0.14em]',
                                'fq-rainbow-ink' => $drifting,
                            ])
                            @if (! $drifting)
                                style="color: {{ $entry['earned'] ? $tier->ringVar() : 'var(--fq-text-5)' }}"
                            @endif
                        >{{ $tier->label() }} · {{ $badge->xp_reward }} XP</p>

                        <p class="mt-1 text-sm text-fq-text-3">
                            @if ($entry['secret'])
                                A secret badge. Keep playing to find out what unlocks it.
                            @else
                                {{ $badge->description }}
                            @endif
                        </p>

                        @if ($entry['earnedAt'])
                            <p class="mt-2 font-mono-fq text-[10px] text-fq-text-5">
                                {{ \Illuminate\Support\Carbon::parse($entry['earnedAt'])->toFormattedDateString() }}
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-kid.shell>
