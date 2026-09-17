{{-- A bought face and frame, laid over whatever tile the caller already draws.

     The caller keeps its own tile — the login door's accent block, the header's
     rounded square, the feed's letter — because each of those is a decision
     that surface already made, and a kid who has bought nothing must look
     exactly as they always did. The caller hides its letter when `avatar` is
     set; this draws the rest.

     `motion` follows the design's rule: full on the locker, the mirror and the
     login fan; frames only in the header; nothing in feed rows or board rows.

     `glow` is a colour when the kid is powered up. With a frame on, the frame
     *is* the status light — see .fq-frame-lit in app.css — so the caller skips
     its own powered-up ring. --}}
@props([
    'avatar' => null,
    'frame' => null,
    'avatarInset' => '13%',
    'frameInset' => '0px',
    'motion' => 'full',
    'glow' => null,
])

@if ($avatar)
    <x-cosmetic.art
        :item="$avatar"
        mode="fill"
        :still="$motion !== 'full'"
        class="pointer-events-none absolute z-[1]"
        style="inset: {{ $avatarInset }}"
    />
@endif

@if ($frame)
    @if ($glow)
        <x-cosmetic.art
            :item="$frame"
            mode="fill"
            :still="$motion === 'none'"
            class="fq-frame-halo absolute z-[2]"
            style="inset: {{ $frameInset }}; --fq-glow: {{ $glow }}"
        />
    @endif

    <x-cosmetic.art
        :item="$frame"
        mode="fill"
        :still="$motion === 'none'"
        :class="'pointer-events-none absolute z-[3]'.($glow ? ' fq-frame-lit' : '')"
        :style="'inset: '.$frameInset.($glow ? '; --fq-glow: '.$glow : '')"
    />
@endif
