{{-- Shooting stars, on the days somebody has done a chore.

     Deliberately background rather than a card. Every other reward in the app
     is a thing that appears somewhere and asks to be read; this one is just
     *how the day looks* — the page is livelier than it was this morning
     without a single word about it.

     Five, staggered, on a long loop. The temptation is more and faster, and the
     reason not to is that this sits behind pages that get read — including the
     one where a kid writes down how they are feeling. It has to be noticeable
     at a glance and ignorable at a paragraph.

     Shared by the kid shell and the login door so the two never drift apart.
     Off entirely under prefers-reduced-motion — see app.css. --}}
<div class="fq-shooting-sky" aria-hidden="true">
    @foreach ([[8, 12, 0], [62, 4, 3.1], [31, 38, 6.4], [78, 26, 9.2], [46, 60, 12.7]] as [$left, $top, $delay])
        <span
            class="fq-shooting-star"
            style="left:{{ $left }}%; top:{{ $top }}%; animation-delay:{{ $delay }}s"
        ></span>
    @endforeach
</div>
