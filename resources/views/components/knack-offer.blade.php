{{-- A pet's knack, offered where it helps — see App\Services\KnackService.

     The pet layer (resources/js/pets.js) looks for `data-fq-knack-offer`,
     walks the pet over to it and shows a 🐾 bubble over its head; a tap on the
     bubble opens the confirm here. So does the line below, which is always on
     the page: a kid with reduced motion, or a pet busy asleep, still gets to
     use the knack.

     Yes plays the pet's part first (`fq-pet-act`, the steps in `act`) and then
     calls the Livewire action — the order the chests use, so the result lands
     after the pet has done the thing. "Save it" puts the bubble away for this
     visit only.

     `offer` is the line, `question` the confirm, `yes` its button. `choices`
     swaps the one yes for several — [label, argument] pairs, each calling the
     action with its argument — which is how a grown Paw Nudge asks which way. --}}
@props([
    'knack',
    'pet',
    'offer',
    'question',
    'yes',
    'action',
    'left' => null,
    'uses' => null,
    'act' => [['happy', 1.2]],
    'choices' => null,
])

@php $key = $knack->value; @endphp

<div
    data-fq-knack-offer="{{ $key }}"
    data-fq-knack-label="{{ $knack->label() }}?"
    x-data="{ open: false }"
    x-on:fq-knack.window="if ($event.detail.knack === '{{ $key }}') open = true"
    {{ $attributes->class('w-full') }}
>
    <button
        type="button"
        x-show="! open"
        x-on:click="window.dispatchEvent(new CustomEvent('fq-knack', { detail: { knack: '{{ $key }}' } }))"
        class="flex w-full items-center gap-[8px] rounded-[12px] border border-dashed px-[12px] py-[8px] text-left text-[12.5px] text-fq-text-2"
        style="border-color: color-mix(in srgb, var(--fq-green) 55%, transparent); background: color-mix(in srgb, var(--fq-green) 8%, transparent)"
        data-knack-line="{{ $key }}"
    >
        <i class="fa-solid {{ $knack->icon() }} text-[12px] text-fq-green"></i>
        <span class="flex-1"><strong class="text-fq-green">{{ $pet }}</strong> {{ $offer }}</span>
        <span class="font-mono-fq text-[9px] tracking-[0.1em] text-fq-green uppercase">🐾 {{ $knack->label() }}</span>
    </button>

    <div
        x-show="open"
        x-cloak
        class="flex flex-col gap-[8px] rounded-[14px] border-2 border-fq-green px-[13px] py-[11px] text-left"
        style="background: color-mix(in srgb, var(--fq-green) 10%, var(--fq-panel)); animation: fq-pop .25s ease both"
        data-knack-confirm="{{ $key }}"
    >
        <p class="font-baloo text-[16px] leading-tight font-extrabold">🐾 {{ $question }}</p>

        @if ($left !== null && $uses !== null)
            <p class="text-[11.5px] text-fq-text-4">You have {{ $left }} of {{ $uses }} left — it comes back on its own.</p>
        @endif

        <div class="flex flex-wrap gap-[8px]">
            @foreach ($choices ?? [[$yes, null]] as [$choiceLabel, $argument])
                <button
                    type="button"
                    x-on:click="
                        open = false;
                        window.dispatchEvent(new CustomEvent('fq-pet-act', { detail: { knack: '{{ $key }}', steps: {{ Js::from($act) }} } }));
                        setTimeout(() => $wire.{{ $action }}({{ $argument === null ? '' : Js::from($argument) }}), 900);
                    "
                    class="rounded-[10px] px-[14px] py-[8px] font-baloo text-[14px] font-extrabold text-fq-ink"
                    style="background: linear-gradient(150deg,#b8ffd9,#54e8d0)"
                    data-knack-choice="{{ $argument ?? 'yes' }}"
                >{{ $choiceLabel }}</button>
            @endforeach
            <button
                type="button"
                x-on:click="open = false; window.dispatchEvent(new CustomEvent('fq-knack-dismiss', { detail: { knack: '{{ $key }}' } }))"
                class="rounded-[10px] border border-fq-line-3 px-[12px] py-[8px] font-baloo text-[14px] font-extrabold text-fq-text-3"
            >Save it</button>
        </div>
    </div>
</div>
