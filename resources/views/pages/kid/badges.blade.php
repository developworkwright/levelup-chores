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
                    $accent = $entry['secret'] ? 'var(--fq-line-3)' : $badge->color->cssVar();
                @endphp

                <div
                    wire:key="badge-{{ $badge->id }}"
                    class="flex gap-3 rounded-[20px] border p-4 {{ $entry['earned'] ? '' : 'opacity-60' }}"
                    style="background: var(--fq-panel); border-color: {{ $entry['earned'] ? $accent : 'var(--fq-line)' }}"
                >
                    <div
                        class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-[14px] font-baloo text-lg font-extrabold"
                        style="
                            background: {{ $entry['earned'] ? $accent : 'var(--fq-sunk)' }};
                            color: {{ $entry['earned'] ? 'var(--fq-bg)' : 'var(--fq-text-4)' }};
                            border: 1px solid {{ $entry['earned'] ? $accent : 'var(--fq-line-3)' }};
                        "
                    >{{ $entry['secret'] ? '?' : $badge->glyph }}</div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="text-[15px] font-semibold">
                                {{ $entry['secret'] ? '???' : $badge->name }}
                            </p>
                            @if ($entry['earned'])
                                <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-lime uppercase">Earned</span>
                            @endif
                        </div>

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
