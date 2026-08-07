{{-- The boss, small enough to sit in the Quests sidebar.

     No replay and no `markSeen()` here, deliberately. This is a status glance
     on the page kids live on; the catch-up walk through the stages they missed
     belongs to the arena on the Goal page, and marking them seen from here
     would spend that moment on a thumbnail. --}}
@props(['state'])

<div {{ $attributes }}>
    <div class="flex items-center gap-3">
        <div class="w-[74px] shrink-0">
            <x-dynamic-component
                :component="$state['skin']->component()"
                :skin="$state['skin']"
                :stage="$state['stage']"
            />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <h3 class="font-baloo text-lg font-bold">{{ $state['name'] }}</h3>
                <span class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-coral uppercase">
                    {{ $state['stage']->label() }}
                </span>
            </div>

            <div class="mt-2 h-[14px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                <div
                    class="h-full rounded-full transition-[width] duration-500"
                    style="width:{{ $state['healthPercent'] }}%;background:linear-gradient(90deg, #7a1030, var(--fq-streak) 60%, #ff8ac7)"
                ></div>
            </div>

            <p class="mt-[6px] font-mono-fq text-[10px] text-fq-text-4">
                {{ number_format($state['health']) }} HP LEFT ·
                <span class="text-fq-coral">{{ $state['damagePercent'] }}% BEATEN</span>
            </p>
        </div>
    </div>
</div>
