<?php

use App\Enums\CosmeticFlavor;
use App\Enums\CosmeticMotion;
use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Models\Cosmetic;
use App\Models\Profile;
use App\Services\CosmeticArt;
use App\Services\CosmeticService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * The locker's catalog, from the grown-up side: upload on the left, the live
 * preview on the right, everything already in the catalog below.
 *
 * The form replaces a whole pipeline. There is no filename convention and no
 * folder to scan — the row and the file are made in the same request, so they
 * cannot disagree — and what an interface buys that a folder never could is
 * the preview: the only question worth asking about a piece of cosmetic art is
 * whether it still reads at 40px in a header.
 *
 * Uploading never puts anything in front of a kid. A draft is invisible to the
 * locker and to the rotation until it is published, and a published item can be
 * pulled from stock but never deleted — somebody may be wearing it.
 */
new class extends Component
{
    use WithFileUploads;

    public Profile $profile;

    /** The picture being added. Untyped for the reason MusicService gives. */
    public $upload = null;

    public string $name = '';

    public string $slot = 'frame';

    /** A CosmeticFlavor value, or '' for a house basic. */
    public string $flavor = '';

    public int $cost = 5;

    public string $stock = 'shelf';

    /** A CosmeticMotion value, or '' for a still item. */
    public string $motion = '';

    /** Which slot's published items are listed below. */
    public string $listSlot = 'frame';

    public ?string $flashMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $slot = CosmeticSlot::tryFrom($this->slot) ?? CosmeticSlot::Frame;

        return [
            'upload' => ['required', 'file', 'mimetypes:image/png', 'extensions:png', 'max:'.$slot->uploadSpec()['max_kb']],
            'name' => ['required', 'string', 'max:40'],
            'slot' => ['required', 'in:'.implode(',', array_map(fn (CosmeticSlot $s) => $s->value, CosmeticSlot::uploadable()))],
            'flavor' => ['nullable', 'in:'.implode(',', array_map(fn (CosmeticFlavor $f) => $f->value, CosmeticFlavor::cases()))],
            'cost' => ['required', 'integer', 'min:1', 'max:25'],
            'stock' => ['required', 'in:'.implode(',', array_map(fn (CosmeticStock $s) => $s->value, CosmeticStock::cases()))],
            'motion' => ['nullable', 'in:'.implode(',', array_map(fn (CosmeticMotion $m) => $m->value, CosmeticMotion::cases()))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'upload.required' => 'Pick a PNG first.',
            'upload.mimetypes' => 'That has to be a PNG.',
            'upload.extensions' => 'That has to be a PNG.',
            'upload.max' => 'That picture is over '.(CosmeticSlot::tryFrom($this->slot) ?? CosmeticSlot::Frame)->uploadLimitLabel().' — shrink it or export it smaller.',
            'name.required' => 'Give it a name the kids will read.',
        ];
    }

    /** Checked the moment a file lands, so a refusal shows before anything is filled in. */
    public function updatedUpload(): void
    {
        $this->validateOnly('upload');
    }

    /** A different slot can have a different cap, so the file is checked again. */
    public function updatedSlot(): void
    {
        if ($this->upload) {
            $this->validateOnly('upload');
        }
    }

    public function bumpCost(int $by): void
    {
        $this->cost = max(1, min(25, $this->cost + $by));
    }

    public function saveDraft(): void
    {
        $this->store(publish: false);
    }

    public function publish(): void
    {
        $this->store(publish: true);
    }

    private function store(bool $publish): void
    {
        $this->validate();

        $slot = CosmeticSlot::from($this->slot);
        $art = app(CosmeticArt::class);
        $binary = (string) $this->upload->get();
        $checks = $art->inspect($binary, $slot);
        $passes = collect($checks)->doesntContain('status', 'fail');

        if ($publish && ! $passes) {
            $this->addError('upload', 'It didn\'t pass the checks, so it can only be saved as a draft.');

            return;
        }

        try {
            $path = $art->store($this->profile->household_id, $binary);
        } catch (RuntimeException $e) {
            report($e);
            $this->addError('upload', $e->getMessage());

            return;
        }

        Cosmetic::create([
            'household_id' => $this->profile->household_id,
            'slot' => $slot,
            'art_path' => $path,
            'name' => trim($this->name),
            'cost' => $this->cost,
            'stock' => $this->stock,
            'flavor' => $this->flavor !== '' ? $this->flavor : null,
            'motion' => $this->motion !== '' ? $this->motion : null,
            'checks' => $checks,
            'published_at' => $publish ? now() : null,
        ]);

        rescue(fn () => $this->upload->delete(), report: false);

        $this->flashMessage = $publish
            ? trim($this->name).' is in the shop.'
            : trim($this->name).' is saved as a draft. Nobody can see it yet.';

        $this->reset('upload', 'name', 'motion');
        app(CosmeticService::class)->forget();
    }

    private function find(int $id): ?Cosmetic
    {
        return Cosmetic::where('household_id', $this->profile->household_id)->find($id);
    }

    public function publishDraft(int $id): void
    {
        $item = $this->find($id);

        if (! $item || ! $item->isDraft()) {
            return;
        }

        if (! $item->passesChecks()) {
            $this->flashMessage = $item->name.' didn\'t pass its checks — bin it and upload a new picture.';

            return;
        }

        $item->update(['published_at' => now()]);
        $this->flashMessage = $item->name.' is in the shop.';
        app(CosmeticService::class)->forget();
    }

    /** Only a draft can be binned: nobody can own one, so nothing is lost. */
    public function binDraft(int $id): void
    {
        $item = $this->find($id);

        if (! $item || ! $item->isDraft()) {
            return;
        }

        if ($item->art_path) {
            rescue(fn () => app(CosmeticArt::class)->disk()->delete($item->art_path), report: false);
        }

        $this->flashMessage = $item->name.' is gone.';
        $item->delete();
        app(CosmeticService::class)->forget();
    }

    /** Stock can change, ownership can't: a pulled item stays on whoever bought it. */
    public function toggleStock(int $id): void
    {
        $item = $this->find($id);

        if ($item && ! $item->isDraft()) {
            $item->update(['pulled_at' => $item->isPulled() ? null : now()]);
            app(CosmeticService::class)->forget();
        }
    }

    public function pickListSlot(string $slot): void
    {
        if (CosmeticSlot::tryFrom($slot)) {
            $this->listSlot = $slot;
        }
    }

    public function with(): array
    {
        $service = app(CosmeticService::class);
        $household = $this->profile->household;
        $catalog = $service->catalog($household);
        $slot = CosmeticSlot::tryFrom($this->slot) ?? CosmeticSlot::Frame;

        $checks = [];
        $previewUrl = null;

        if ($this->upload && ! $this->getErrorBag()->has('upload')) {
            $checks = rescue(fn () => app(CosmeticArt::class)->inspect((string) $this->upload->get(), $slot), [], false);
            $previewUrl = rescue(fn () => $this->upload->temporaryUrl(), null, false);
        }

        $rotation = $service->rotationThisWeek($household)->keys()->merge($service->limitedThisWeek($household)->pluck('id'));

        return [
            'counts' => $service->counts($household),
            'uploadSlot' => $slot,
            'checks' => $checks,
            'previewUrl' => $previewUrl,
            // A real face under the preview, since a frame is judged by how it
            // sits round one.
            'previewFace' => $catalog->first(fn (Cosmetic $item) => $item->slot === CosmeticSlot::Avatar && ! $item->isDraft()),
            'drafts' => $catalog->filter(fn (Cosmetic $item) => $item->isDraft())->values(),
            'listed' => $catalog->filter(fn (Cosmetic $item) => ! $item->isDraft() && $item->slot->value === $this->listSlot)->values(),
            'inRotation' => $rotation,
        ];
    }
}; ?>

@php
    $label = 'font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-4 uppercase';
    $field = 'rounded-[11px] border border-fq-line-2 bg-fq-panel px-3 py-[10px] text-[13.5px] outline-none focus:border-fq-cyan';
    // One tab per slot that takes a picture, with the one line worth knowing
    // before generating art for it. The prompts themselves live in cosmetics.js.
    // Labelled from the slot itself, so a tab and the upload chip it goes with
    // can never be called two different things again ("Background" here and
    // "Home pattern" up there hid the fact that one could be uploaded).
    $promptNotes = [
        'frame' => 'The 62% hole is the whole trick — without it you get a beautiful ring with a face already in the middle, and the kid\'s own avatar has nowhere to sit.',
        'avatar' => 'Ask for head and shoulders. A full body shrinks to an unreadable speck at 30px in a feed row.',
        'plate' => 'The flat middle band is what keeps a name legible. Decoration belongs at the two ends.',
        'pattern' => 'Seamless is the one thing you cannot fix later, and the uploader checks it: opposite edges have to match or the page shows a grid of seams.',
        'cabinet' => 'The screen must come back empty and black — the game draws into it.',
        'spark' => 'Keep the center empty: this animates outward from whatever button was tapped.',
    ];

    $promptTabs = collect(App\Enums\CosmeticSlot::uploadable())
        ->mapWithKeys(fn (App\Enums\CosmeticSlot $case) => [$case->value => [$case->label(), $promptNotes[$case->value]]])
        ->all();

    $stockLook = [
        'shelf' => ['#3a2360', '#8c7bab'],
        'weekly' => ['#6a3fb0', '#c9a0ff'],
        'limited' => ['#ffc93d', '#ffe14d'],
    ];
@endphp

<x-parent.shell :profile="$profile" active="cosmetics">
    <div class="flex flex-col gap-4 rounded-[24px] border border-fq-nav-line bg-fq-bg p-[14px] md:p-[18px]">
        <div class="flex flex-wrap items-end justify-between gap-[14px] border-b border-fq-track pb-[13px]">
            <div>
                <h2 class="font-baloo text-[24px] font-extrabold">Cosmetics</h2>
                <p class="mt-[2px] text-[12.5px] text-fq-text-4">What the kids can buy with tickets</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <span class="rounded-[9px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[7px] font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-green">{{ $counts['live'] }} LIVE</span>
                <span class="rounded-[9px] border border-fq-ticket-line px-[11px] py-[7px] font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-lime" style="background: var(--fq-gold-fill)">{{ $counts['drafts'] }} {{ Str::plural('DRAFT', $counts['drafts']) }}</span>
                <span class="rounded-[9px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[7px] font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-text-4">{{ $counts['rotation'] }} IN ROTATION</span>
            </div>
        </div>

        @if ($flashMessage)
            <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">{{ $flashMessage }}</div>
        @endif

        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_300px] md:items-start">
            <div class="flex flex-col gap-[13px]">
                <p class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">New item</p>

                @php $spec = $uploadSlot->uploadSpec(); @endphp

                <label
                    class="flex cursor-pointer items-center gap-[15px] rounded-[16px] border border-dashed border-fq-line-focus p-5"
                    style="background: repeating-linear-gradient(45deg,#120a22 0 8px,#0d0718 8px 16px)"
                >
                    <i class="fa-solid fa-arrow-up-from-bracket text-[22px] text-fq-magenta"></i>
                    <span class="min-w-0 flex-1">
                        <span class="block font-baloo text-[16px] font-extrabold">{{ $upload ? 'Choose a different PNG' : 'Choose a PNG' }}</span>
                        <span class="mt-1 block font-mono-fq text-[10px] text-fq-text-4 uppercase">
                            {{ $uploadSlot->label() }} · {{ $spec['width'] }}×{{ $spec['height'] }} · {{ $spec['alpha'] ? 'transparent background' : 'solid, seamless' }} · max {{ $uploadSlot->uploadLimitLabel() }}
                        </span>
                    </span>
                    <span class="shrink-0 rounded-[9px] px-3 py-2 font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-bg" style="background: var(--fq-cyan)">CHOOSE</span>
                    <input type="file" wire:model="upload" accept="image/png" class="sr-only">
                </label>

                <div wire:loading wire:target="upload" class="font-mono-fq text-[10px] text-fq-text-4">UPLOADING…</div>
                @error('upload') <p class="text-[12.5px] text-fq-danger">{{ $message }}</p> @enderror

                {{-- Chips rather than a dropdown: all six kinds of upload are on
                     screen at once, so nobody has to open a menu to find out a
                     background can be uploaded at all. --}}
                <div class="flex flex-col gap-[6px]">
                    <span class="{{ $label }}">What is it?</span>
                    <div class="flex flex-wrap gap-[6px]" role="radiogroup" aria-label="Slot">
                        @foreach (App\Enums\CosmeticSlot::uploadable() as $case)
                            @php $on = $uploadSlot === $case; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $on ? 'true' : 'false' }}"
                                wire:key="upload-slot-{{ $case->value }}"
                                wire:click="$set('slot', '{{ $case->value }}')"
                                class="flex items-center gap-[7px] rounded-full border px-3 py-[7px] text-[12.5px]"
                                style="border-color: {{ $on ? '#c9a0ff' : '#241539' }}; background: {{ $on ? '#241546' : '#0b0616' }}; color: {{ $on ? '#d8b4ff' : '#8c7bab' }}"
                            >
                                <i class="fa-solid {{ $case->icon() }} text-[11px]"></i>{{ $case->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <label class="flex flex-col gap-[6px]">
                    <span class="{{ $label }}">Name</span>
                    <input type="text" wire:model="name" maxlength="40" placeholder="Gilded Antlers" class="{{ $field }}">
                    @error('name') <span class="text-[12px] text-fq-danger">{{ $message }}</span> @enderror
                </label>

                <div class="flex flex-col gap-[6px]">
                    <span class="{{ $label }}">Flavor set</span>
                    <div class="flex flex-wrap gap-[6px]">
                        @foreach (App\Enums\CosmeticFlavor::cases() as $case)
                            @php $on = $flavor === $case->value || ($flavor === '' && $case === App\Enums\CosmeticFlavor::House); @endphp
                            <button
                                type="button"
                                wire:click="$set('flavor', '{{ $case === App\Enums\CosmeticFlavor::House ? '' : $case->value }}')"
                                class="rounded-full border px-3 py-[6px] font-mono-fq text-[9.5px] tracking-[0.08em] uppercase"
                                style="border-color: {{ $on ? $case->border() : '#241539' }}; background: {{ $on ? '#1d1036' : '#0b0616' }}; color: {{ $on ? $case->ink() : '#6f6288' }}"
                            >{{ $case->label() }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-[11px] sm:grid-cols-[150px_minmax(0,1fr)] sm:items-start">
                    <div class="flex flex-col gap-[6px]">
                        <span class="{{ $label }}">Tickets</span>
                        <span class="flex items-center justify-between gap-[10px] rounded-[11px] border border-fq-ticket-line px-3 py-[6px]" style="background: var(--fq-gold-fill)">
                            <button type="button" wire:click="bumpCost(-1)" aria-label="One fewer ticket" class="px-2 py-1 text-[10px] text-fq-ticket-label"><i class="fa-solid fa-minus"></i></button>
                            <span class="font-baloo text-[17px] font-extrabold text-fq-lime">{{ $cost }}</span>
                            <button type="button" wire:click="bumpCost(1)" aria-label="One more ticket" class="px-2 py-1 text-[10px] text-fq-lime"><i class="fa-solid fa-plus"></i></button>
                        </span>
                    </div>

                    <div class="flex flex-col gap-[6px]">
                        <span class="{{ $label }}">Stock</span>
                        <div class="flex gap-[6px]">
                            @foreach (App\Enums\CosmeticStock::cases() as $case)
                                @php $on = $stock === $case->value; $gold = $case === App\Enums\CosmeticStock::Limited; @endphp
                                <button
                                    type="button"
                                    wire:click="$set('stock', '{{ $case->value }}')"
                                    class="flex flex-1 flex-col gap-[3px] rounded-[11px] border px-[10px] py-[9px] text-left"
                                    style="border-color: {{ $on ? ($gold ? '#ffc93d' : '#c9a0ff') : '#241539' }}; background: {{ $on ? ($gold ? '#2a2405' : '#1d1036') : '#0b0616' }}"
                                >
                                    <span class="text-[12.5px]" style="color: {{ $on ? ($gold ? '#ffe14d' : '#d8b4ff') : '#b0a3cc' }}">{{ $case->label() }}</span>
                                    <span class="font-mono-fq text-[8.5px] uppercase" style="color: {{ $on && $gold ? '#a3934f' : '#6f6288' }}">{{ $case->note() }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <label class="flex flex-col gap-[6px]">
                    <span class="{{ $label }}">Movement</span>
                    <select wire:model.live="motion" class="{{ $field }}">
                        <option value="">None — it holds still</option>
                        @foreach (App\Enums\CosmeticMotion::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    <span class="text-[11.5px] text-fq-text-4">Moving items belong at 6–8 tickets — motion is how a kid tells the good one.</span>
                </label>

                <div class="flex gap-[9px] pt-[2px]">
                    <button
                        type="button"
                        wire:click="publish"
                        wire:loading.attr="disabled"
                        class="flex-1 rounded-[12px] p-3 text-center font-baloo text-[15px] font-extrabold text-fq-ink"
                        style="background: linear-gradient(150deg,#fff6b0,#ffc93d)"
                    >Publish to the shop</button>
                    <button
                        type="button"
                        wire:click="saveDraft"
                        wire:loading.attr="disabled"
                        class="rounded-[12px] border border-fq-line-3 px-[18px] py-3 text-center font-baloo text-[15px] font-extrabold text-fq-cyan"
                    >Save draft</button>
                </div>
            </div>

            <div class="flex flex-col gap-[11px]">
                <p class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">Preview</p>

                <div class="flex flex-col gap-3 rounded-[16px] border border-fq-line-2 bg-fq-panel p-[13px]">
                    @if ($previewUrl)
                        @php
                            $motionCss = App\Enums\CosmeticMotion::tryFrom($motion)?->value;
                            $sizes = in_array($uploadSlot, [App\Enums\CosmeticSlot::Frame, App\Enums\CosmeticSlot::Avatar], true)
                                ? [[78, 'LOGIN 78PX'], [52, 'LOCKER 52'], [40, 'HEADER 40']]
                                : [[96, 'LOCKER TILE'], [52, 'SMALL']];
                        @endphp

                        <div class="flex items-end gap-3" data-fq-preview>
                            @foreach ($sizes as [$px, $caption])
                                <div class="flex flex-col items-center gap-[6px]">
                                    <span class="relative block" style="width: {{ $px }}px; height: {{ $px }}px">
                                        @if ($uploadSlot === App\Enums\CosmeticSlot::Frame && $previewFace)
                                            <x-cosmetic.art :item="$previewFace" mode="fill" still class="absolute" style="inset: 13%" />
                                        @endif
                                        <fq-cosmetic
                                            kind="{{ $uploadSlot->value }}"
                                            src="{{ $previewUrl }}"
                                            @if ($motionCss) motion="{{ $motionCss }}" @endif
                                            mode="{{ in_array($uploadSlot, [App\Enums\CosmeticSlot::Frame, App\Enums\CosmeticSlot::Avatar], true) ? 'fill' : 'tile' }}"
                                            label="COLTON"
                                            class="absolute inset-0"
                                        ></fq-cosmetic>
                                    </span>
                                    <span class="font-mono-fq text-[7.5px] tracking-[0.1em] {{ $px === 40 ? 'text-fq-blue' : 'text-fq-text-5' }}">{{ $caption }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-[12px] text-fq-text-5">Choose a picture and it shows here at the sizes the app draws it.</p>
                    @endif

                    <p class="border-t border-fq-track pt-[10px] text-[11.5px] text-fq-text-4">Shown over a real avatar, at the sizes the app actually draws. If it's mush at 40px it doesn't ship.</p>
                </div>

                <div class="flex flex-col gap-[9px] rounded-[16px] border border-fq-line bg-fq-panel p-[13px]">
                    <p class="{{ $label }}">Checks</p>

                    @forelse ($checks as $check)
                        <div class="flex items-start gap-[9px]" data-check="{{ $check['status'] }}">
                            <i @class([
                                'fa-solid mt-[2px] text-[12px]',
                                'fa-circle-check text-fq-green' => $check['status'] === 'pass',
                                'fa-triangle-exclamation text-fq-gold' => $check['status'] === 'warn',
                                'fa-circle-xmark text-fq-danger' => $check['status'] === 'fail',
                            ])></i>
                            <span class="flex-1 text-[12px] text-fq-text-2">{{ $check['label'] }}</span>
                        </div>
                    @empty
                        <p class="text-[12px] text-fq-text-5">Nothing to check yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- The prompt, straight out of cosmetics.js. Same shape every time: a
             subject to change, then geometry and negative blocks to leave
             alone — the geometry is what image models get wrong by default. --}}
        <div
            x-data="{ promptKind: 'frame', promptCopied: false }"
            class="flex flex-col gap-[11px] rounded-[18px] border border-fq-line-2 p-[14px]"
            style="background: linear-gradient(160deg,#150c26,#0a0512 70%)"
        >
            <div class="flex flex-wrap items-center gap-[11px]">
                <span class="font-baloo text-[16px] font-extrabold">Need art? Start from this prompt</span>
                <span class="text-[12px] text-fq-text-4">Swap the bracketed subject, leave the rest alone.</span>
                <button
                    type="button"
                    x-on:click="navigator.clipboard?.writeText(window.FQCosmetics?.PROMPTS[promptKind] || ''); promptCopied = true; setTimeout(() => promptCopied = false, 1600)"
                    class="ml-auto flex shrink-0 items-center gap-[7px] rounded-[9px] px-[13px] py-2 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap"
                    :style="promptCopied ? 'background:#7dffb0;color:#05170c' : 'background:#d8b4ff;color:#0a0512'"
                >
                    <i class="fa-solid text-[10px]" :class="promptCopied ? 'fa-check' : 'fa-clipboard'"></i>
                    <span x-text="promptCopied ? 'COPIED' : 'COPY PROMPT'">COPY PROMPT</span>
                </button>
            </div>

            <div class="flex flex-wrap gap-[6px]">
                @foreach ($promptTabs as $kind => [$tab, $note])
                    <button
                        type="button"
                        {{-- Picks the upload slot to match: the art made from
                             this prompt is what gets uploaded next. --}}
                        x-on:click="promptKind = '{{ $kind }}'; promptCopied = false; $wire.set('slot', '{{ $kind }}')"
                        class="rounded-full border px-3 py-[6px] font-mono-fq text-[9.5px] tracking-[0.08em] uppercase"
                        :style="promptKind === '{{ $kind }}' ? 'border-color:#c9a0ff;background:#241546;color:#d8b4ff' : 'border-color:#241539;background:#0b0616;color:#6f6288'"
                    >{{ $tab }}</button>
                @endforeach
            </div>

            <div
                class="rounded-[13px] border border-fq-line bg-fq-bg p-[14px] font-mono-fq text-[10.5px] leading-[1.8] whitespace-pre-wrap text-fq-text-2"
                x-text="window.FQCosmetics?.PROMPTS[promptKind] || ''"
            ></div>

            <div class="flex items-start gap-[9px]">
                <i class="fa-solid fa-lightbulb mt-[2px] text-[12px] text-fq-gold"></i>
                @foreach ($promptTabs as $kind => [$tab, $note])
                    <span class="flex-1 text-[11.5px] text-fq-text-4" x-show="promptKind === '{{ $kind }}'" @if ($kind !== 'frame') x-cloak @endif>{{ $note }}</span>
                @endforeach
            </div>
        </div>

        @if ($drafts->isNotEmpty())
            <div class="flex items-center gap-[9px] pt-1">
                <span class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">Drafts · not visible to anyone yet</span>
                <span class="h-px flex-1 bg-fq-track"></span>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($drafts as $draft)
                    @php
                        $failed = collect($draft->checks ?? [])->firstWhere('status', 'fail');
                        $warned = collect($draft->checks ?? [])->firstWhere('status', 'warn');
                        [$rim, $ink] = $stockLook[$draft->stock->value];
                    @endphp

                    <div wire:key="draft-{{ $draft->id }}" class="flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-line bg-fq-panel px-[13px] py-[10px]">
                        <span class="relative h-[44px] w-[44px] shrink-0 overflow-hidden rounded-[11px] bg-fq-bg">
                            <x-cosmetic.art :item="$draft" still label="COLTON" class="absolute inset-0" />
                        </span>
                        <div class="w-[170px] shrink-0">
                            <p class="text-[13.5px] font-semibold">{{ $draft->name }}</p>
                            <p class="mt-[3px] font-mono-fq text-[9px] tracking-[0.08em] text-fq-text-4 uppercase">{{ $draft->slot->label() }} · uploaded png</p>
                        </div>
                        <span class="w-[34px] shrink-0 font-baloo text-[15px] font-extrabold text-fq-lime">{{ $draft->cost }}</span>
                        <span class="shrink-0 rounded-full border px-[9px] py-1 font-mono-fq text-[8.5px] tracking-[0.08em] whitespace-nowrap uppercase" style="border-color: {{ $rim }}; color: {{ $ink }}">{{ $draft->stock->label() }}</span>
                        <span class="min-w-[120px] flex-1 text-[11.5px]" style="color: {{ $failed ? '#ff8098' : ($warned ? '#e8ddbd' : '#7dffb0') }}">
                            {{ $failed['label'] ?? $warned['label'] ?? 'All checks passed' }}
                        </span>
                        <button type="button" wire:click="publishDraft({{ $draft->id }})" @disabled($failed) class="shrink-0 rounded-[8px] px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap disabled:opacity-40" style="background: #7dffb0; color: #05170c">PUBLISH</button>
                        <button type="button" wire:click="binDraft({{ $draft->id }})" wire:confirm="Bin {{ $draft->name }}? The picture goes too." class="shrink-0 rounded-[8px] border border-fq-line-2 px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap text-fq-text-4">BIN</button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-[9px] pt-1">
            <span class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">In the shop</span>
            <span class="h-px flex-1 bg-fq-track"></span>
        </div>

        <div class="flex flex-wrap gap-[6px]">
            @foreach (App\Enums\CosmeticSlot::cases() as $case)
                @php $on = $listSlot === $case->value; @endphp
                <button
                    type="button"
                    wire:click="pickListSlot('{{ $case->value }}')"
                    class="rounded-full border px-3 py-[6px] font-mono-fq text-[9.5px] tracking-[0.08em] uppercase"
                    style="border-color: {{ $on ? '#c9a0ff' : '#241539' }}; background: {{ $on ? '#241546' : '#0b0616' }}; color: {{ $on ? '#d8b4ff' : '#6f6288' }}"
                >{{ $case->label() }}</button>
            @endforeach
        </div>

        <div class="flex flex-col gap-2">
            @foreach ($listed as $item)
                @php [$rim, $ink] = $stockLook[$item->stock->value]; @endphp

                <div wire:key="listed-{{ $item->id }}" @class(['flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-line bg-fq-panel px-[13px] py-[8px]', 'opacity-55' => $item->isPulled()])>
                    <span class="relative h-[40px] w-[40px] shrink-0 overflow-hidden rounded-[10px] bg-fq-bg">
                        <x-cosmetic.art :item="$item" still label="COLTON" class="absolute inset-0" />
                    </span>
                    <div class="min-w-[140px] flex-1">
                        <p class="text-[13px] font-semibold">{{ $item->name }}</p>
                        <p class="mt-[2px] font-mono-fq text-[8.5px] tracking-[0.08em] text-fq-text-5 uppercase">
                            {{ $item->isUpload() ? 'uploaded png' : 'drawn by the app' }}
                            @if ($item->motion) · moves @endif
                            @if ($inRotation->contains($item->id)) · <span class="text-fq-gold">out this week</span> @endif
                        </p>
                    </div>
                    <span class="w-[34px] shrink-0 font-baloo text-[14px] font-extrabold text-fq-lime">{{ $item->isFree() ? 'Free' : $item->cost }}</span>
                    <span class="shrink-0 rounded-full border px-[9px] py-1 font-mono-fq text-[8.5px] tracking-[0.08em] whitespace-nowrap uppercase" style="border-color: {{ $rim }}; color: {{ $ink }}">{{ $item->stock->label() }}</span>
                    @unless ($item->isFree())
                        <button
                            type="button"
                            wire:click="toggleStock({{ $item->id }})"
                            class="shrink-0 rounded-[8px] border border-fq-line-2 px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap {{ $item->isPulled() ? 'text-fq-green' : 'text-fq-text-4' }}"
                        >{{ $item->isPulled() ? 'PUT BACK' : 'PULL FROM STOCK' }}</button>
                    @endunless
                </div>
            @endforeach
        </div>

        <div class="flex items-start gap-[9px] rounded-[14px] border border-dashed border-fq-line-2 px-[13px] py-[11px]">
            <i class="fa-solid fa-circle-info mt-[2px] text-[12px] text-fq-text-4"></i>
            <span class="flex-1 text-[11.5px] text-fq-text-4">
                <strong class="text-fq-text-3">Pull from stock</strong> instead of deleting — stock can change, ownership can't. A pulled item stays worn by whoever bought it and simply stops being sold.
            </span>
        </div>
    </div>
</x-parent.shell>
