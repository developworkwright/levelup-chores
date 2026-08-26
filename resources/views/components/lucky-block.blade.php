{{-- The Lucky Block, pinned at the top of the Loot Shop.

     Three tickets, one hit, one of the pool at random. It lives in the shop
     rather than on Home for two reasons: Home already runs seven sections and
     two openable chests, and the block needs room for its rules — the full
     prize list, the ticket state, and the four ways to earn more. Home gets a
     one-line pointer instead; see <x-lucky-strip>.

     Nothing renders when the pool is empty. A block with nothing in it is a
     button that can only disappoint.

     The reveal (S3) replaces this card in place rather than opening an
     overlay: the prize is the answer to the tap, and it belongs where the tap
     was. --}}
@props([
    'pool',
    'tickets',
    'journalDone' => false,
    'hit' => null,
    'cost' => \App\Services\LuckyBlockService::TICKET_COST,
    'hitAction' => 'hitLuckyBlock',
    'dismissAction' => 'dismissLuckyBlock',
])

@if ($pool->isNotEmpty())
    @php $canHit = $tickets >= $cost; @endphp

    <div
        wire:key="lucky-block"
        class="mt-4 flex flex-col gap-[13px]"
        x-data="{
            hitting: false,
            {{-- The suspense runs *before* the server call, the same ordering
                 <x-chest> uses and for the same reason: the response swaps
                 this card for the reveal, so calling first would show the
                 prize while the block was still mid-bounce.

                 520ms is the block's 420ms bounce plus the tail of the prize
                 rise that overlaps it — keep it in step with .fq-lucky-hit and
                 .fq-lucky-rise in app.css.

                 What rises out of the block is the block's own `?`, not the
                 prize. The prize is drawn server-side and must stay that way:
                 the same handoff that specs this animation also says the
                 result must not be inspectable, and anything the client can
                 animate is something the client already knows. --}}
            async hit() {
                if (this.hitting) return;
                this.hitting = true;
                await new Promise(resolve => setTimeout(resolve, 520));
                await $wire.{{ $hitAction }}();
                this.hitting = false;
            },
        }"
    >
        @if ($hit)
            {{-- S3 — the payoff. --}}
            <div
                class="fq-lucky-reveal flex flex-col gap-[13px] rounded-[20px] border-2 p-[16px_15px]"
                style="border-color: var(--fq-gold); background: var(--fq-lucky-panel)"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="font-baloo text-[21px] leading-none font-extrabold">You got</h2>
                    <span
                        class="rounded-full px-[9px] py-[5px] font-mono-fq text-[9px] font-semibold tracking-[0.14em] uppercase"
                        style="background: var(--fq-lime); color: var(--fq-bg)"
                    >Block hit</span>
                </div>

                <div
                    class="flex flex-col items-center gap-[10px] rounded-[22px] border-2 px-[18px] py-[26px] text-center"
                    style="border-color: var(--fq-gold); background: linear-gradient(165deg, #2a2405, #0e0719 74%)"
                >
                    <x-chore-icon :icon="$hit->iconClass()" class="text-[64px]" style="color: var(--fq-lime)" />

                    <h3 class="font-baloo text-[29px] leading-[1.05] font-extrabold" style="color: var(--fq-lime)">
                        {{ $hit->prize_name }}
                    </h3>

                    <p class="max-w-[280px] text-[13.5px] text-fq-notice-text" style="text-wrap: pretty">
                        {{ $hit->luckyPrize?->flavor ?: 'Yours. Go and tell somebody.' }}
                    </p>
                </div>

                {{-- The honest bit. A won prize is a promise until a grown-up
                     keeps it, exactly like a cash-out, and saying so here is
                     what stops the kid thinking the app owes them something it
                     can't hand over. --}}
                <div class="flex items-center gap-[10px] rounded-[16px] border border-fq-line bg-fq-panel px-[13px] py-3">
                    <i aria-hidden="true" class="fa-fw fa-solid fa-user-check text-[15px]" style="color: var(--fq-green)"></i>
                    <span class="flex-1 text-[12.5px] text-fq-text-3">Sent to a grown-up to tick off.</span>
                    <span class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-4 uppercase">Pending</span>
                </div>

                {{-- Every prize it wasn't, grayed. The flat odds are the whole
                     design, and this is where a kid can see that the thing
                     they wanted was in there with the same chance as the thing
                     they got. --}}
                @php $others = $pool->reject(fn ($prize) => $prize->id === $hit->lucky_prize_id); @endphp

                @if ($others->isNotEmpty())
                    <div class="border-t border-dashed border-fq-line-2 pt-[11px]">
                        <p class="font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-ticket-label uppercase">
                            Also in the block
                        </p>
                        <div class="mt-[9px] flex flex-wrap justify-center gap-[9px]">
                            @foreach ($others as $prize)
                                <x-chore-icon
                                    :icon="$prize->iconClass()"
                                    class="text-[17px]"
                                    style="color: var(--fq-lucky-grayed)"
                                    :title="$prize->name"
                                />
                            @endforeach
                        </div>
                    </div>
                @endif

                <button
                    type="button"
                    wire:click="{{ $dismissAction }}"
                    class="w-full rounded-[14px] border bg-fq-sunk py-[13px] font-baloo text-[17px] font-extrabold text-fq-text-2-b transition hover:brightness-125"
                    style="border-color: var(--fq-line-3)"
                >Nice &middot; back to the shop</button>
            </div>
        @else
            {{-- S2 — the block itself. --}}
            <div
                class="flex flex-col gap-[13px] rounded-[20px] border-2 p-[16px_15px]"
                style="border-color: var(--fq-gold); background: var(--fq-lucky-panel)"
            >
                <div class="flex items-center gap-[14px]">
                    {{-- Drawn in CSS, not shipped as an asset: a gradient face,
                         an inset bevel top and bottom, four square studs and a
                         Baloo `?`. Square studs and this app's amber deliberately
                         — it is the mystery-block convention, not any one
                         game's block. --}}
                    <div
                        class="relative grid h-[74px] w-[74px] shrink-0 place-items-center rounded-[12px]"
                        :class="hitting ? 'fq-lucky-hit' : ''"
                        style="background: var(--fq-lucky-face);
                               box-shadow: inset 0 4px 0 rgba(255,255,255,.5), inset 0 -5px 0 rgba(120,66,4,.55), var(--fq-lucky-glow)"
                    >
                        @foreach ([['top-[6px] left-[6px]'], ['top-[6px] right-[6px]'], ['bottom-[6px] left-[6px]'], ['bottom-[6px] right-[6px]']] as $stud)
                            <span
                                aria-hidden="true"
                                class="absolute h-[7px] w-[7px] rounded-[2px] {{ $stud[0] }}"
                                style="background: var(--fq-lucky-stud)"
                            ></span>
                        @endforeach

                        <span
                            class="font-baloo text-[41px] leading-none font-extrabold"
                            style="color: var(--fq-lucky-glyph); text-shadow: 0 3px 0 var(--fq-lucky-glyph-shadow)"
                        >?</span>

                        {{-- The `?` climbing out on a hit. Absolutely placed so
                             it can leave the block without moving anything. --}}
                        <span
                            x-show="hitting"
                            x-cloak
                            class="fq-lucky-rise pointer-events-none absolute font-baloo text-[41px] leading-none font-extrabold"
                            style="color: var(--fq-lucky-glyph); text-shadow: 0 3px 0 var(--fq-lucky-glyph-shadow)"
                            aria-hidden="true"
                        >?</span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="font-baloo text-[20px] leading-none font-extrabold" style="color: var(--fq-lime)">
                            Lucky Block
                        </h2>
                        <p class="mt-[5px] text-[12.5px] text-fq-notice-text">
                            One of {{ $pool->count() }} {{ Str::plural('thing', $pool->count()) }}. Paid in tickets, not points.
                        </p>

                        <div class="mt-[9px] flex flex-wrap items-center gap-[6px]">
                            @for ($pip = 1; $pip <= $cost; $pip++)
                                <i
                                    aria-hidden="true"
                                    class="fa-fw fa-solid fa-ticket text-[12px]"
                                    style="color: {{ $tickets >= $pip ? 'var(--fq-gold)' : 'var(--fq-line-2)' }}"
                                ></i>
                            @endfor

                            <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-ticket-label uppercase">
                                {{ min($tickets, $cost) }} of {{ $cost }}{{ $canHit ? ' · Ready' : '' }}
                            </span>
                        </div>
                    </div>
                </div>

                @if ($canHit)
                    <button
                        type="button"
                        @click="hit()"
                        :disabled="hitting"
                        class="w-full rounded-[14px] py-[13px] font-baloo text-[17px] font-extrabold transition hover:brightness-110 disabled:opacity-70"
                        style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                    >Hit it</button>
                @else
                    <button
                        type="button"
                        disabled
                        class="w-full cursor-not-allowed rounded-[14px] border bg-fq-sunk py-[13px] font-baloo text-[17px] font-extrabold text-fq-text-5"
                        style="border-color: var(--fq-line-2)"
                    >Need {{ $cost }} tickets</button>
                @endif

                {{-- The list, in full, before the kid commits. No tiers and no
                     hidden table: an older kid can audit this and a younger one
                     doesn't need it explained. --}}
                <div class="border-t pt-[11px]" style="border-color: color-mix(in srgb, var(--fq-gold) 30%, transparent)">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-ticket-label uppercase">
                        What's inside &middot; equal chance
                    </p>

                    <div class="mt-[9px] flex flex-wrap gap-[5px]">
                        @foreach ($pool as $prize)
                            <span
                                class="flex items-center gap-[5px] rounded-full border px-[9px] py-[5px] text-[11px] text-fq-notice-text"
                                style="border-color: var(--fq-ticket-line); background: var(--fq-lucky-chip)"
                            >
                                <x-chore-icon
                                    :icon="$prize->iconClass()"
                                    class="text-[10px]"
                                    style="color: {{ $prize->colorVar() }}"
                                />
                                {{ $prize->name }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- How to get more. Four fixed strings — identical for every kid,
                 every day — except the journal row, which is the only one that
                 can be acted on right now and is therefore the only one worth
                 calculating. --}}
            <div class="rounded-[16px] border border-fq-line bg-fq-panel px-[13px] py-3">
                <p class="font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-ticket-label uppercase">
                    Getting tickets
                </p>

                @if ($journalDone)
                    <div class="mt-[9px] flex items-center gap-[9px] rounded-[13px] border border-fq-line bg-fq-panel px-3 py-[10px]">
                        <i aria-hidden="true" class="fa-fw fa-solid fa-feather text-[14px] text-fq-text-4"></i>
                        <span class="flex-1 text-[12.5px] text-fq-text-3">Journal &middot; done today</span>
                    </div>
                @else
                    <a
                        href="{{ route('kid.journal') }}"
                        wire:navigate
                        class="mt-[9px] flex items-center gap-[9px] rounded-[13px] border px-3 py-[10px] transition hover:brightness-110"
                        style="border-color: var(--fq-green); background: var(--fq-green-panel)"
                    >
                        <i aria-hidden="true" class="fa-fw fa-solid fa-feather text-[14px]" style="color: var(--fq-green)"></i>
                        <span class="flex-1 text-[12.5px] text-fq-green-ink">Journal &middot; not done today</span>
                        <span
                            class="shrink-0 rounded-[9px] px-[10px] py-[7px] font-baloo text-[11.5px] font-extrabold"
                            style="background: var(--fq-fill-green); color: var(--fq-ink-green)"
                        >Get {{ \App\Services\GratitudeService::TICKETS }} tickets</span>
                    </a>
                @endif

                <div class="mt-[6px] flex gap-[6px]">
                    @foreach ([['fa-fire', 'var(--fq-coral)', 'Streaks'], ['fa-award', 'var(--fq-gold)', 'Badges'], ['fa-skull', 'var(--fq-violet)', 'Bosses']] as $source)
                        <span class="flex flex-1 items-center justify-center gap-[6px] rounded-[13px] border border-fq-line bg-fq-panel px-2 py-[9px] text-[11px] text-fq-text-3">
                            <i aria-hidden="true" class="fa-fw fa-solid {{ $source[0] }} text-[11px]" style="color: {{ $source[1] }}"></i>
                            {{ $source[2] }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
