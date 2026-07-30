@props(['profile', 'active'])

@php
    $tabs = [
        'approvals' => ['label' => 'Approvals', 'route' => 'parent.approvals'],
        'chores' => ['label' => 'Quests', 'route' => 'parent.chores'],
        'loot' => ['label' => 'Loot Shop', 'route' => 'parent.loot'],
        'kids' => ['label' => 'Kids & Points', 'route' => 'parent.kids'],
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
    <div class="sticky top-0 z-20 pt-[14px] pb-[10px]">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[14px_16px]">
            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.22em] text-fq-cyan uppercase">Parent Console</p>
                <h1 class="font-baloo text-2xl font-extrabold">{{ config('app.name') }} HQ</h1>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-[10px] border border-fq-line-2 bg-fq-sunk px-3 py-2 font-mono-fq text-[11px] text-fq-gold">
                    {{ $pendingCount }} PENDING
                </span>
                <x-sound-toggle />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[7px] text-xs text-fq-text-4 transition hover:text-fq-text">Exit</button>
                </form>
            </div>
        </div>

        <x-quest-board>
            @foreach ($tabs as $key => $tab)
                <a
                    href="{{ route($tab['route']) }}"
                    wire:navigate
                    class="flex-1 rounded-[14px] border px-4 py-[10px] text-center text-sm font-semibold whitespace-nowrap {{ $active === $key ? 'border-2 border-fq-bg bg-fq-sky text-fq-bg' : 'border-fq-line bg-fq-sunk text-fq-text-3' }}"
                >{{ $tab['label'] }}</a>
            @endforeach
        </x-quest-board>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
