<div
    x-data="{
        toast: null,
        toastTimer: null,
        treat: null,
        mode: 'money',
        big: false,
        celebrating: false,
        burst: 0,
        celebrate(message, treat, style, big) {
            this.toast = message;
            this.treat = treat || null;
            this.mode = style || 'money';
            this.big = !!big;
            this.celebrating = true;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toast = null; this.celebrating = false }, this.big ? 5200 : 3400);
            this.burst++;
            this.playSound();
        },
        pieceCount() {
            return this.big ? 320 : 210;
        },
        sideCount() {
            return this.big ? 120 : 80;
        },
        confettiPieceStyle(i) {
            const colors = ['var(--fq-lime)', 'var(--fq-cyan)', 'var(--fq-gold)', 'var(--fq-magenta)', 'var(--fq-coral)', 'var(--fq-violet)', 'var(--fq-sky)'];
            const square = i % 2 === 0;

            return {
                position: 'fixed',
                left: (2 + (i * 53) % 96) + 'vw',
                top: '10vh',
                width: square ? '10px' : '6px',
                height: square ? '10px' : '14px',
                borderRadius: '2px',
                background: colors[i % colors.length],
                boxShadow: '0 1px 2px rgba(0,0,0,.35)',
                animation: 'fq-fall ' + (1.4 + (i % 12) / 8) + 's ease-in forwards',
                animationDelay: ((i % 24) * 0.09) + 's',
            };
        },
        pieceStyle(i) {
            if (this.mode === 'confetti') {
                return this.confettiPieceStyle(i);
            }

            const base = {
                position: 'fixed',
                left: (2 + (i * 53) % 96) + 'vw',
                top: '10vh',
                animation: 'fq-fall ' + (1.4 + (i % 12) / 8) + 's ease-in forwards',
                animationDelay: ((i % 24) * 0.09) + 's',
            };

            if (this.treat === 'cookie' && i % 3 === 0) {
                // Chocolate chip cookie.
                return {
                    ...base,
                    width: '15px',
                    height: '15px',
                    borderRadius: '50%',
                    border: '1px solid #a8692a',
                    background: 'radial-gradient(circle at 30% 30%, #6b4423 6%, transparent 7%), radial-gradient(circle at 65% 55%, #6b4423 6%, transparent 7%), radial-gradient(circle at 40% 78%, #6b4423 6%, transparent 7%), radial-gradient(circle at 72% 22%, #6b4423 5%, transparent 6%), #c9873f',
                    boxShadow: '0 1px 2px rgba(0,0,0,.4)',
                };
            }

            if (i % 2 === 0) {
                // Dollar bill: small green rectangle with a lighter seal mark.
                return {
                    ...base,
                    width: '18px',
                    height: '10px',
                    borderRadius: '2px',
                    border: '1px solid #2f6b3c',
                    background: 'radial-gradient(circle at 50% 50%, #d7f0da 22%, transparent 23%), linear-gradient(135deg, #8fd19e, #4c9a5b)',
                    boxShadow: '0 1px 2px rgba(0,0,0,.4)',
                };
            }

            // Coin: shiny gold circle.
            return {
                ...base,
                width: '14px',
                height: '14px',
                borderRadius: '50%',
                border: '1px solid #8a6a1f',
                background: 'radial-gradient(circle at 35% 30%, #ffe89b, #d4af37 60%, #a8791f 100%)',
                boxShadow: 'inset 0 -2px 2px rgba(0,0,0,.35), 0 1px 2px rgba(0,0,0,.4)',
            };
        },
        sidePieceStyle(i, edgeVw) {
            // Same rain as pieceStyle(), just anchored near a screen edge instead of spread across the top.
            const style = this.pieceStyle(i);
            style.left = (edgeVw + (i * 17) % 14) + 'vw';

            return style;
        },
        playSound() {
            if (localStorage.getItem('fq-muted') === '1') return;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const notes = this.big ? [523, 659, 880, 1046] : [523, 659, 880];
                notes.forEach((freq, i) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.value = freq;
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    const start = ctx.currentTime + i * 0.09;
                    gain.gain.setValueAtTime(0.0001, start);
                    gain.gain.exponentialRampToValueAtTime(0.16, start + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.24);
                    osc.start(start);
                    osc.stop(start + 0.26);
                });
            } catch (e) {}
        },
    }"
    x-on:celebrate.window="celebrate($event.detail.message, $event.detail.treat, $event.detail.style, $event.detail.big)"
>
    <div
        x-show="toast"
        x-transition
        :style="big
            ? 'animation: fq-pop .26s ease both; box-shadow: 0 20px 50px -14px var(--fq-gold); background:#1d2440; border-color: var(--fq-gold)'
            : 'animation: fq-pop .26s ease both; box-shadow: 0 20px 40px -18px #000; background:#1d2440; border-color: oklch(0.7 0.16 140 / 0.6)'"
        class="fixed bottom-6 left-1/2 z-[60] flex max-w-[92vw] -translate-x-1/2 items-center gap-2 rounded-[18px] border px-5 py-[14px]"
    >
        <div
            x-show="big"
            x-cloak
            class="pointer-events-none absolute -z-10"
            style="inset:-16px; border-radius:26px; background: radial-gradient(circle, var(--fq-gold) 0%, transparent 70%); filter:blur(9px); animation: fq-pulse 1s ease-in-out infinite"
        ></div>

        <span x-show="treat === 'cookie'" x-cloak><x-cookie-icon class="h-[18px] w-[18px] shrink-0" /></span>
        <span x-show="treat !== 'cookie' && !big" class="h-[9px] w-[9px] shrink-0 rounded-full bg-fq-lime"></span>
        <span x-show="treat !== 'cookie' && big" x-cloak class="h-[9px] w-[9px] shrink-0 rounded-full" style="background:var(--fq-gold)"></span>
        <span class="font-semibold" :class="big ? 'text-[16px]' : 'text-[15px]'" x-text="toast"></span>
    </div>

    {{-- Big spinning coin with a glow behind it — money celebrations only; chests/wheel use plain confetti. --}}
    <div
        x-show="celebrating && mode !== 'confetti'"
        x-transition.opacity.duration.400ms
        class="pointer-events-none fixed top-[36vh] left-1/2 z-[56]"
        style="transform: translate(-50%, -50%); perspective: 700px"
    >
        <div
            style="
                position:absolute; top:50%; left:50%; width:440px; height:440px; border-radius:50%;
                background: radial-gradient(circle, oklch(0.86 0.18 130 / .55) 0%, oklch(0.84 0.16 85 / .35) 35%, transparent 70%);
                filter: blur(6px);
                animation: fq-glow-pulse 1.6s ease-in-out infinite;
            "
        ></div>

        <div
            class="relative"
            style="
                width:180px; height:180px; border-radius:50%;
                animation: fq-coin-spin 1.8s linear infinite;
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
                    class="absolute h-[6px] w-[6px] rounded-full bg-white"
                    style="left:{{ $sx }}%; top:{{ $sy }}%; box-shadow:0 0 8px 2px rgba(255,255,255,.9); animation: fq-sparkle 1.4s ease-in-out {{ $i * 0.25 }}s infinite;"
                ></span>
            @endforeach
        </div>
    </div>

    <div class="pointer-events-none fixed inset-x-0 top-0 z-[57]" style="height:0">
        <template x-for="i in pieceCount()" :key="i + '-' + burst">
            <span x-show="burst > 0" :style="pieceStyle(i)"></span>
        </template>

        <template x-for="i in sideCount()" :key="'left-' + i + '-' + burst">
            <span x-show="burst > 0" :style="sidePieceStyle(i, 1)"></span>
        </template>

        <template x-for="i in sideCount()" :key="'right-' + i + '-' + burst">
            <span x-show="burst > 0" :style="sidePieceStyle(i, 85)"></span>
        </template>
    </div>
</div>
