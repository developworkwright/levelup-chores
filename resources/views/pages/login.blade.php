<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        $profile = Auth::guard('profile')->user();

        if ($profile) {
            $this->redirect($profile->isParent() ? '/parent' : '/kid', navigate: true);
        }
    }

    public function with(): array
    {
        return [
            'profiles' => Profile::query()
                ->orderBy('role')
                ->orderByDesc('age')
                ->get(),
        ];
    }
}; ?>

<div class="mx-auto flex max-w-[520px] flex-col gap-7 px-5 pt-16 pb-10">
    <div class="flex flex-col items-center gap-7 text-center">
        <p class="font-mono-fq text-[11px] tracking-[0.34em] text-fq-cyan uppercase">Family Operations</p>
        <h1 class="fq-wordmark font-baloo text-[54px] leading-none font-extrabold">{{ config('app.name') }}</h1>
        <p class="max-w-[320px] text-[15px] text-fq-text-3">
            Clear your daily quest, stack points, cash out for loot.
        </p>
    </div>

    <div class="flex flex-col gap-[18px] rounded-[22px] border border-fq-line bg-fq-panel p-[22px] shadow-[0_24px_60px_-30px_#000]">
        <h2 class="font-baloo text-[19px] font-bold">Who's playing?</h2>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(96px,1fr))] gap-3">
            @foreach ($profiles as $profile)
                <a
                    href="{{ route('pin', $profile) }}"
                    wire:navigate
                    class="group flex flex-col items-center gap-[9px] rounded-[20px] border-2 border-fq-line-2 bg-fq-sunk p-4 px-2 transition hover:-translate-y-[3px] hover:border-fq-line-focus"
                >
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-[18px] font-baloo text-2xl font-extrabold text-fq-bg"
                        style="background:{{ $profile->color->cssVar() }}"
                    >
                        {{ mb_substr($profile->name, 0, 1) }}
                    </div>
                    <span class="text-sm font-semibold">{{ $profile->name }}</span>
                    <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase">
                        {{ $profile->isParent() ? 'Console' : 'Level '.$profile->level() }}
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</div>
