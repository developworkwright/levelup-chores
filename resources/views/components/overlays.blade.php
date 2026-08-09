{{-- The one place anything good gets announced.

     The behaviour — the queue, the particles, the sound, the pacing — lives in
     `fqCelebrations` in resources/js/app.js. None of it needs a server value, and
     a few hundred lines of particle maths in an x-data attribute get no tooling
     and Blade's escaping rules for free. This file is the markup it drives. --}}
<div
    x-data="fqCelebrations"
    x-on:celebrate.window="celebrate($event.detail)"
    x-on:rewards-earned.window="queueRewards($event.detail.rewards)"
>
    {{-- x-show and :style must not share an element. x-show hides by writing
         style.display, and a :style binding re-renders the whole attribute the
         next time anything it reads changes — wiping the display and stranding
         the element on screen. That never bit while `big` only ever moved as a
         toast appeared; a queued card now flips it while the toast is hidden,
         which used to strand an empty one. So the wrapper owns visibility and
         the box inside owns its looks. --}}
    <div
        x-show="toast"
        x-transition
        class="fixed bottom-6 left-1/2 z-[60] max-w-[92vw] -translate-x-1/2"
    >
        <div
            :style="big
                ? 'animation: fq-pop .26s ease both; box-shadow: 0 20px 50px -14px var(--fq-gold); background: var(--fq-sunk); border-color: var(--fq-gold)'
                : 'animation: fq-pop .26s ease both; box-shadow: var(--fq-shadow-toast); background: var(--fq-sunk); border-color: var(--fq-success-border)'"
            class="relative flex items-center gap-2 rounded-[18px] border px-5 py-[14px]"
        >
            <div
                x-show="big"
                x-cloak
                class="fq-toast-halo pointer-events-none absolute -z-10"
                style="inset:-16px; border-radius:26px; background: radial-gradient(circle, var(--fq-gold) 0%, transparent 70%); filter:blur(9px)"
            ></div>

            <span x-show="treat === 'cookie'" x-cloak><x-cookie-icon class="h-[18px] w-[18px] shrink-0" /></span>
            <span x-show="treat !== 'cookie' && !big" class="h-[9px] w-[9px] shrink-0 rounded-full bg-fq-lime"></span>
            <span x-show="treat !== 'cookie' && big" x-cloak class="h-[9px] w-[9px] shrink-0 rounded-full" style="background:var(--fq-gold)"></span>
            <span class="font-semibold" :class="big ? 'text-[16px]' : 'text-[15px]'" x-text="toast"></span>
        </div>
    </div>

    {{-- The chest's reveal card, lifted out so anything worth that much noise
         can borrow it. A badge or a level is exactly the kind of thing a kid
         needs shoved in front of them — a toast along the bottom edge is what
         they were already missing. --}}
    <template x-if="card">
        <div class="pointer-events-none fixed inset-0 z-[58] flex items-center justify-center px-4">
            {{-- One wash of the accent over the whole screen. Only the set
                 pieces get it: a badge unlock lighting the room up would leave
                 nothing bigger to reach for. --}}
            <template x-if="hero">
                <div
                    class="fq-hero-flash absolute inset-0"
                    :style="`background: radial-gradient(circle at 50% 45%, ${card.accent} 0%, transparent 62%)`"
                ></div>
            </template>

            <div class="relative flex flex-col items-center">
                <div
                    class="fq-card-halo absolute"
                    :style="`top:50%; left:50%; width:420px; height:420px; border-radius:50%; background: radial-gradient(circle, ${card.accent} 0%, transparent 70%); opacity:.45; filter:blur(4px)`"
                ></div>

                {{-- The ring the impact throws off, delayed to expand as the
                     card lands rather than ahead of it. --}}
                <template x-if="hero === 'level'">
                    <div class="fq-shockwave absolute" :style="`border-color: ${card.accent}`"></div>
                </template>

                {{-- The monster, face down, above its own obituary. Drawn from
                     the skin the defeat was stamped with — see bossArt. --}}
                <template x-if="hero === 'boss'">
                    <div class="relative mb-[-18px] flex items-center justify-center">
                        <div class="fq-ko-monster h-[168px] w-[168px]" x-html="bossArt"></div>

                        <span
                            class="fq-ko-stamp absolute font-mono-fq text-[26px] leading-none font-extrabold tracking-[0.16em] uppercase sm:text-[32px]"
                            style="color: var(--fq-coral); border: 3px solid var(--fq-coral); border-radius: 10px; padding: 6px 14px; background: rgba(10,5,18,.62); text-shadow: 0 2px 6px #000"
                        >Defeated</span>
                    </div>
                </template>

                <div
                    class="relative rounded-[22px] border px-10 py-8 text-center"
                    :style="cardStyle"
                >
                    <p
                        class="font-mono-fq text-[11px] tracking-[0.2em] uppercase"
                        :style="`color: ${card.accent}`"
                        x-text="card.sub"
                    ></p>
                    <p class="mt-2 max-w-[70vw] font-baloo text-[28px] leading-tight font-extrabold" x-text="card.label"></p>
                    <p class="mt-2 font-mono-fq text-[12px] text-fq-text-3" x-text="card.note"></p>
                </div>
            </div>
        </div>
    </template>

    {{-- Big spinning coin with a glow behind it — money celebrations only.
         Everything else (chests, the wheel, levels) has its own look, and a
         dollar sign hanging over a badge unlock says the wrong thing. --}}
    <div
        x-show="celebrating && mode === 'money'"
        x-transition.opacity.duration.400ms
        class="pointer-events-none fixed top-[36vh] left-1/2 z-[56]"
        style="transform: translate(-50%, -50%); perspective: 700px"
    >
        <div
            class="fq-coin-halo"
            style="
                position:absolute; top:50%; left:50%; width:440px; height:440px; border-radius:50%;
                background: radial-gradient(circle, rgba(255,225,77,.55) 0%, rgba(255,201,61,.35) 35%, transparent 70%);
                filter: blur(6px);
            "
        ></div>

        <div
            class="fq-coin relative"
            style="
                width:180px; height:180px; border-radius:50%;
                background: radial-gradient(circle at 35% 30%, #fff3c4, #f0c75e 45%, #c9962c 80%, #a8791f 100%);
                border: 4px solid #8a6a1f;
                box-shadow: 0 0 40px 6px rgba(255,214,100,.6), inset 0 -6px 12px rgba(0,0,0,.25), inset 0 6px 10px rgba(255,255,255,.35);
            "
        >
            <div class="absolute inset-[10px] flex items-center justify-center rounded-full" style="border: 2px dashed rgba(138,106,31,.5)">
                <span class="font-baloo font-extrabold" style="font-size:64px; color:#8a6a1f; text-shadow: 0 2px 2px rgba(255,255,255,.4)">$</span>
            </div>

            @foreach ([[20, 18], [76, 14], [14, 68], [80, 66], [50, 8]] as $i => [$sx, $sy])
                <span
                    class="fq-coin-sparkle absolute h-[6px] w-[6px] rounded-full bg-white"
                    style="left:{{ $sx }}%; top:{{ $sy }}%; box-shadow:0 0 8px 2px rgba(255,255,255,.9); animation-delay: {{ $i * 0.25 }}s"
                ></span>
            @endforeach
        </div>
    </div>

    {{-- The particles. The counts return 0 before the first burst and whenever
         the OS has asked for less movement, so there is no x-show here to fight
         the :style binding for the display property. --}}
    <div class="pointer-events-none fixed inset-x-0 top-0 z-[57]" style="height:0">
        <template x-for="i in pieceCount()" :key="i + '-' + burst">
            <span :style="pieceStyle(i)"></span>
        </template>

        <template x-for="i in sideCount()" :key="'left-' + i + '-' + burst">
            <span :style="sidePieceStyle(i, 1)"></span>
        </template>

        <template x-for="i in sideCount()" :key="'right-' + i + '-' + burst">
            <span :style="sidePieceStyle(i, 85)"></span>
        </template>
    </div>
</div>
