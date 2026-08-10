@props(['profile', 'active'])

@php
    $tabs = [
        'approvals' => ['label' => 'Approvals', 'route' => 'parent.approvals'],
        'chores' => ['label' => 'Quests', 'route' => 'parent.chores'],
        'loot' => ['label' => 'Loot Shop', 'route' => 'parent.loot'],
        'monsters' => ['label' => 'Monsters', 'route' => 'parent.monsters'],
        'kids' => ['label' => 'Kids & Points', 'route' => 'parent.kids'],
        'standings' => ['label' => 'Standings', 'route' => 'parent.standings'],
        'activity' => ['label' => 'Activity', 'route' => 'parent.activity'],
    ];

    $pendingCount = \App\Models\ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $profile->household_id))
        ->where('status', \App\Enums\CompletionStatus::Pending)
        ->count()
        + \App\Models\Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $profile->household_id))
            ->where('status', \App\Enums\RedemptionStatus::Pending)
            ->count();
@endphp

<div class="mx-auto max-w-[1080px] px-[14px] pb-10">
    <div class="pt-[14px] pb-[10px]">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[12px_14px]">
            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.22em] text-fq-cyan uppercase">Parent Console</p>
                <h1 class="font-baloo text-2xl font-extrabold">{{ config('app.name') }} HQ</h1>
            </div>

            {{-- Same 52px bank as the kid header, minus the two tiles a parent
                 has no use for. --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex h-[52px] w-[92px] flex-col items-end justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk px-3">
                    <span class="font-baloo text-[19px] leading-none font-extrabold text-fq-gold">{{ $pendingCount }}</span>
                    <span class="font-mono-fq text-[9px] text-fq-text-4">PENDING</span>
                </div>

                <x-sound-toggle />

                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button
                        type="submit"
                        title="Exit"
                        aria-label="Exit"
                        class="flex h-[52px] w-[52px] items-center justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk text-fq-text-4 transition hover:text-fq-text"
                    ><x-power-icon /></button>
                </form>
            </div>
        </div>

        <x-quest-board>
            @foreach ($tabs as $key => $tab)
                <a
                    href="{{ route($tab['route']) }}"
                    wire:navigate
                    class="flex-1 rounded-[14px] border border-transparent px-4 py-[10px] text-center text-sm font-semibold whitespace-nowrap"
                    style="{{ $active === $key ? 'background: var(--fq-tab-active); color: var(--fq-lime)' : 'background:transparent; color: var(--fq-ink)' }}"
                >{{ $tab['label'] }}</a>
            @endforeach
        </x-quest-board>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
