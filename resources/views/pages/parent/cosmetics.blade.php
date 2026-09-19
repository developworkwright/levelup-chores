<?php

use App\Enums\CosmeticEffect;
use App\Enums\CosmeticFlavor;
use App\Enums\CosmeticMotion;
use App\Enums\CosmeticSlot;
use App\Enums\CosmeticStock;
use App\Enums\PetKnack;
use App\Enums\PetRarity;
use App\Enums\PetStage;
use App\Enums\PetStyle;
use App\Models\Cosmetic;
use App\Models\OwnedCosmetic;
use App\Models\Profile;
use App\Services\CosmeticArt;
use App\Services\CosmeticService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
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
 * Uploading never puts anything in front of a kid. There is no draft: an upload
 * is judged on this page — a pet can be let loose on the grown-up's own screen
 * first — and then published or tossed. A published item can be pulled from
 * stock but never deleted, because somebody may be wearing it. Drafts saved
 * before that are still listed, to publish or bin.
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

    /** A CosmeticEffect value, or '' for plain art. What a 20-ticket pet has. */
    public string $effect = '';

    /** A new pet's tier, style and — Rare and up — knack. See App\Enums\PetRarity. */
    public string $petRarity = 'common';

    public string $petStyle = 'steady';

    public string $petKnack = '';


    /** Which slot's published items are listed below. */
    public string $listSlot = 'frame';

    public ?string $flashMessage = null;

    /**
     * Said under the upload box rather than at the top of the page: Toss is
     * pressed down by the form, and a confirmation up at the top — scrolled
     * out of sight — left the page looking as if it had simply reloaded.
     */
    public ?string $uploadNote = null;

    /**
     * The published pet whose art the upload replaces, or null when the
     * upload is a new item.
     *
     * New art goes onto the same row, so every kid who owns the pet keeps it,
     * with its growth and anything it was traded for. That is how pets made
     * before the eighteen-pose sheet get their new poses: kids own them, so
     * they are never swapped for a new item.
     */
    public ?int $replacing = null;

    /**
     * Which page this console is being: 'items' is Cosmetics, everything a
     * kid wears; 'pets' is Pets, reached at /parent/pets. One component so a
     * pet's art goes through the one upload pipeline — the cut, the checks,
     * Try it out and New Art — rather than a copy of it. Pets mode shows only
     * pets, plus how every kid's pet is doing; items mode shows no pets.
     */
    #[Locked]
    public string $mode = 'items';

    public function mount(string $mode = 'items'): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);

        if ($mode === 'pets' || request()->routeIs('parent.pets')) {
            $this->mode = 'pets';
            $this->slot = CosmeticSlot::Pet->value;
            $this->listSlot = CosmeticSlot::Pet->value;
        }
    }

    private function petsMode(): bool
    {
        return $this->mode === 'pets';
    }

    /**
     * Every kid's pet, for the Pets page: the one out and how grown it is,
     * its knack and what's left of it — or the egg in its place.
     *
     * @return array<int, array{kid: Profile, pet: ?Cosmetic, stage: ?PetStage, toGo: ?int, knack: ?array, egg: ?App\Models\PetEgg, owned: int}>
     */
    private function kidsPets(): array
    {
        $pets = app(App\Services\PetService::class);
        $knacks = app(App\Services\KnackService::class);
        $cosmetics = app(CosmeticService::class);

        return Profile::where('household_id', $this->profile->household_id)
            ->where('role', App\Enums\ProfileRole::Kid)
            ->orderBy('id')
            ->get()
            ->map(function (Profile $kid) use ($pets, $knacks, $cosmetics) {
                $egg = $pets->eggFor($kid);
                $pet = $egg ? null : $cosmetics->wornIn($kid, CosmeticSlot::Pet);

                return [
                    'kid' => $kid,
                    'pet' => $pet,
                    'stage' => $pet ? $pets->stageOf($kid, $pet) : null,
                    'toGo' => $pet ? $pets->choresToGrow($kid, $pet) : null,
                    'knack' => $pet ? $knacks->stateFor($kid) : null,
                    'egg' => $egg,
                    'owned' => count($pets->stagesFor($kid)),
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $slot = CosmeticSlot::tryFrom($this->slot) ?? CosmeticSlot::Frame;

        return [
            // WebP too: an over-size PNG is sent as one by the browser (see
            // png-shrink.js) and turned back into a PNG on arrival — asPng().
            'upload' => ['required', 'file', 'mimetypes:image/png,image/webp', 'extensions:png,webp', 'max:'.$slot->uploadSpec()['max_kb']],
            'name' => ['required', 'string', 'max:40'],
            'slot' => ['required', 'in:'.implode(',', array_map(fn (CosmeticSlot $s) => $s->value, CosmeticSlot::uploadable()))],
            'flavor' => ['nullable', 'in:'.implode(',', array_map(fn (CosmeticFlavor $f) => $f->value, CosmeticFlavor::cases()))],
            'cost' => ['required', 'integer', 'min:1', 'max:25'],
            'stock' => ['required', 'in:'.implode(',', array_map(fn (CosmeticStock $s) => $s->value, CosmeticStock::forSlot($slot)))],
            'motion' => ['nullable', 'in:'.implode(',', array_map(fn (CosmeticMotion $m) => $m->value, CosmeticMotion::cases()))],
            'effect' => ['nullable', 'in:'.implode(',', array_map(fn (CosmeticEffect $e) => $e->value, CosmeticEffect::cases()))],
            ...($slot === CosmeticSlot::Pet ? $this->petRules() : []),
        ];
    }

    /**
     * A pet has a tier and a style, and Rare and up a knack its tier allows.
     *
     * @return array<string, mixed>
     */
    private function petRules(): array
    {
        $rarity = PetRarity::tryFrom($this->petRarity) ?? PetRarity::Common;

        return [
            'petRarity' => ['required', 'in:'.implode(',', array_column(PetRarity::cases(), 'value'))],
            'petStyle' => ['required', 'in:'.implode(',', array_column(PetStyle::cases(), 'value'))],
            'petKnack' => $rarity->hasKnack()
                ? ['required', 'in:'.implode(',', array_map(fn (PetKnack $knack) => $knack->value, $rarity->knacks()))]
                : ['nullable'],
        ];
    }

    /** A new tier: the knack goes to the first one that tier allows, or none. */
    public function updatedPetRarity(): void
    {
        $rarity = PetRarity::tryFrom($this->petRarity) ?? PetRarity::Common;

        if (! in_array(PetKnack::tryFrom($this->petKnack), $rarity->knacks(), true)) {
            $this->petKnack = $rarity->knacks()[0]->value ?? '';
        }
    }

    /**
     * Changes a published pet's tier, style or knack from its row.
     *
     * A kid who owns it keeps it either way; what it can do changes with it.
     * A tier change moves the knack to one the new tier allows, so a pet is
     * never left Rare with no knack or Common with one.
     */
    public function setPetTrait(int $id, string $trait, string $value): void
    {
        $pet = $this->find($id);

        if (! $pet || ! $pet->isSheet()) {
            return;
        }

        if ($trait === 'rarity' && ($rarity = PetRarity::tryFrom($value))) {
            $knack = $pet->pet_knack !== null && $pet->pet_knack->rarity() === $rarity ? $pet->pet_knack : ($rarity->knacks()[0] ?? null);
            $pet->update(['pet_rarity' => $rarity, 'pet_knack' => $knack]);
        } elseif ($trait === 'style' && ($style = PetStyle::tryFrom($value))) {
            $pet->update(['pet_style' => $style]);
        } elseif ($trait === 'knack' && ($knack = PetKnack::tryFrom($value)) && $knack->rarity() === $pet->rarity()) {
            $pet->update(['pet_knack' => $knack]);
        } else {
            return;
        }

        app(CosmeticService::class)->forget();
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

    /**
     * Which age of the new pet is out on the grown-up's own screen, or null
     * when nothing is being tried out. See tryOut().
     */
    public ?string $trialAge = null;

    /** Checked the moment a file lands, so a refusal shows before anything is filled in. */
    public function updatedUpload(): void
    {
        $this->trialAge = null;
        $this->uploadNote = null;
        $this->validateOnly('upload');
    }

    /** A different slot can have a different cap, so the file is checked again. */
    public function updatedSlot(): void
    {
        // Egg only is a pet's stock; anything else goes back on the shelf.
        if ($this->stock === CosmeticStock::Egg->value && $this->slot !== CosmeticSlot::Pet->value) {
            $this->stock = CosmeticStock::Shelf->value;
        }

        // Only a pet's art is replaced; another slot is a new item.
        if ($this->replacing !== null && $this->slot !== CosmeticSlot::Pet->value) {
            $this->stopReplacing();
        }

        if ($this->upload) {
            $this->validateOnly('upload');
        }
    }

    public function bumpCost(int $by): void
    {
        $this->cost = max(1, min(25, $this->cost + $by));
    }

    /**
     * The upload, tidied and checked — and for a whole pet sheet, cut into
     * its three ages.
     *
     * Worked out once per file and slot and kept for half an hour, because a
     * pet takes a second or two to cut and every click on this page renders it
     * again. It is also what makes the try-out honest: the pet on the screen,
     * the checks beside it and what Publish stores are the same pixels.
     *
     * @return array{family: bool, sheets: array<string, string|null>, anchors: array<string, array<string, array<int, float>>>, checks: array<int, array{label: string, status: string}>}
     */
    private function prepared(): array
    {
        $slot = CosmeticSlot::from($this->slot);
        $key = 'cosmetic-upload:v'.CosmeticArt::CUT_VERSION.':'.$this->profile->id.':'.$slot->value.':'.$this->upload->getFilename();

        $kept = Cache::remember($key, now()->addMinutes(30), function () use ($slot) {
            $art = app(CosmeticArt::class);
            $raw = $art->asPng((string) $this->upload->get());

            if ($slot === CosmeticSlot::Pet && $art->isFamilySheet($raw)) {
                $family = $art->prepareFamily($raw);
                $prepared = ['family' => true, 'sheets' => $family['sheets'], 'anchors' => $family['anchors'], 'checks' => $family['checks']];
            } elseif ($slot === CosmeticSlot::Pet && $art->isOldFamilySheet($raw)) {
                // Made from the old prompt: twelve poses an age, not eighteen.
                $prepared = ['family' => false, 'sheets' => ['adult' => null], 'anchors' => [], 'checks' => [
                    ['label' => 'That is the old square sheet with 12 poses — make a new one from the Pet prompt, 9 across and 6 down', 'status' => 'fail'],
                ]];
            } elseif ($slot === CosmeticSlot::Pet) {
                // Every pet has all three ages, so a pet is only ever the whole sheet.
                $prepared = ['family' => false, 'sheets' => ['adult' => null], 'anchors' => [], 'checks' => [
                    ['label' => 'A pet needs all three ages in one 3:2 picture — use the Pet prompt', 'status' => 'fail'],
                ]];
            } else {
                // Shrunk and, on a sprite sheet, de-gridded first — then checked,
                // so the checks and the stored file are the same pixels.
                $tidied = $art->normalize($raw, $slot);
                $prepared = ['family' => false, 'sheets' => ['adult' => $tidied['binary']], 'anchors' => [], 'checks' => [...$tidied['checks'], ...$art->inspect($tidied['binary'], $slot)]];
            }

            // Encoded, because the cache may be a database column.
            $prepared['sheets'] = array_map(fn (?string $sheet) => $sheet === null ? null : base64_encode($sheet), $prepared['sheets']);

            return $prepared;
        });

        $kept['sheets'] = array_map(fn (?string $sheet) => $sheet === null ? null : base64_decode($sheet), $kept['sheets']);

        return $kept;
    }

    /**
     * Puts the new pet out on the grown-up's own screen, as if they owned it —
     * running about, pettable, feedable — starting as the baby a kid would get.
     * Nothing is saved: it is the upload, straight from the cut.
     */
    public function tryOut(string $age = 'baby'): void
    {
        if (! $this->upload || $this->slot !== CosmeticSlot::Pet->value || ! PetStage::tryFrom($age)) {
            return;
        }

        $this->validateOnly('upload');
        $this->trialAge = $age;
    }

    public function stopTrying(): void
    {
        $this->trialAge = null;
    }

    /** Throws the upload away, to try again with another picture. */
    public function toss(): void
    {
        if ($this->upload) {
            Cache::forget('cosmetic-upload:v'.CosmeticArt::CUT_VERSION.':'.$this->profile->id.':'.$this->slot.':'.$this->upload->getFilename());
            rescue(fn () => $this->upload->delete(), report: false);
        }

        $this->reset('upload', 'trialAge', 'motion', 'effect');
        $this->resetErrorBag();
        $this->uploadNote = 'Discarded — nothing was saved. Choose another picture when you have one.';
    }

    /**
     * Straight to the shop. There is no draft: the art is judged here, on this
     * page, and anything that fails is tossed rather than kept.
     */
    public function publish(): void
    {
        $replacing = $this->replacing === null ? null : $this->find($this->replacing);

        // New art for a pet already in the shop keeps its name, price and
        // stock, so only the picture is checked.
        $replacing ? $this->validateOnly('upload') : $this->validate();

        $slot = CosmeticSlot::from($this->slot);
        $art = app(CosmeticArt::class);
        $prepared = $this->prepared();
        $binary = $prepared['sheets']['adult'] ?? null;
        $checks = $prepared['checks'];
        $younger = array_filter(['baby' => $prepared['sheets']['baby'] ?? null, 'young' => $prepared['sheets']['young'] ?? null]);

        if ($binary === null || collect($checks)->contains('status', 'fail')) {
            $this->addError('upload', 'It didn\'t pass the checks — discard it and try another picture.');

            return;
        }

        try {
            $path = $art->store($this->profile->household_id, $binary);
            $youngerPaths = array_map(fn (string $sheet) => $art->store($this->profile->household_id, $sheet), $younger);
        } catch (RuntimeException $e) {
            report($e);
            $this->addError('upload', $e->getMessage());

            return;
        }

        $rig = $slot === CosmeticSlot::Pet ? ['anchors' => $prepared['anchors'] ?? []] : null;

        if ($replacing) {
            $this->swapArt($replacing, $path, $youngerPaths, $rig, $checks);

            return;
        }

        Cosmetic::create([
            'household_id' => $this->profile->household_id,
            'slot' => $slot,
            'art_path' => $path,
            'baby_art_path' => $youngerPaths['baby'] ?? null,
            'young_art_path' => $youngerPaths['young'] ?? null,
            'pet_rig' => $rig,
            ...($slot === CosmeticSlot::Pet ? [
                'pet_rarity' => $this->petRarity,
                'pet_style' => $this->petStyle,
                'pet_knack' => PetRarity::from($this->petRarity)->hasKnack() ? $this->petKnack : null,
            ] : []),
            'name' => trim($this->name),
            'cost' => $this->cost,
            'stock' => $this->stock,
            'flavor' => $this->flavor !== '' ? $this->flavor : null,
            'motion' => $this->motion !== '' ? $this->motion : null,
            'effect' => $this->effect !== '' ? $this->effect : null,
            'checks' => $checks,
            'published_at' => now(),
        ]);

        Cache::forget('cosmetic-upload:v'.CosmeticArt::CUT_VERSION.':'.$this->profile->id.':'.$slot->value.':'.$this->upload->getFilename());
        rescue(fn () => $this->upload->delete(), report: false);

        $this->flashMessage = trim($this->name).' is in the shop.';

        $this->reset('upload', 'name', 'motion', 'effect', 'trialAge', 'petRarity', 'petStyle', 'petKnack');
        app(CosmeticService::class)->forget();
    }

    /**
     * Puts the new sheets on a pet that is already in the shop, and throws
     * the old ones away. The row stays the same, so nobody who owns it loses
     * anything. The art URL carries the row's timestamp, so every screen
     * fetches the new picture rather than a cached one.
     *
     * @param  array<string, string>  $youngerPaths
     * @param  array{anchors: array<string, array<string, array<int, float>>>}|null  $rig
     * @param  array<int, array{label: string, status: string}>  $checks
     */
    private function swapArt(Cosmetic $pet, string $path, array $youngerPaths, ?array $rig, array $checks): void
    {
        $old = $pet->artPaths();

        $pet->update([
            'art_path' => $path,
            'baby_art_path' => $youngerPaths['baby'] ?? null,
            'young_art_path' => $youngerPaths['young'] ?? null,
            'pet_rig' => $rig,
            'checks' => $checks,
        ]);

        foreach (array_diff($old, $pet->artPaths()) as $gone) {
            rescue(fn () => app(CosmeticArt::class)->disk()->delete($gone), report: false);
        }

        Cache::forget('cosmetic-upload:v'.CosmeticArt::CUT_VERSION.':'.$this->profile->id.':'.$this->slot.':'.$this->upload->getFilename());
        rescue(fn () => $this->upload->delete(), report: false);

        $this->flashMessage = $pet->name.' has its new art. Every kid who owns it sees it now.';

        $this->reset('upload', 'name', 'motion', 'effect', 'trialAge', 'replacing');
        app(CosmeticService::class)->forget();
    }

    /** Starts new art for a pet already in the shop — see $replacing. */
    public function replaceArt(int $id): void
    {
        $pet = $this->find($id);

        if (! $pet || ! $pet->isSheet() || $pet->isDraft()) {
            return;
        }

        if ($this->upload) {
            $this->toss();
        }

        $this->replacing = $pet->id;
        $this->slot = CosmeticSlot::Pet->value;
        $this->name = $pet->name;
        $this->uploadNote = null;
        $this->resetErrorBag();
    }

    public function stopReplacing(): void
    {
        $this->reset('replacing', 'name');
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

        // Checked again, against today's rules: the checks saved with a draft
        // are from the day it was uploaded, and a check that has since been
        // fixed must not keep good art stuck in drafts forever. A pet's
        // younger ages were settled when it was cut, so only the adult sheet —
        // the one that must pass — is looked at again.
        if ($item->isUpload()) {
            $art = app(CosmeticArt::class);
            $stored = rescue(fn () => $art->disk()->get($item->art_path), null, false);

            if ($stored !== null) {
                $fresh = $art->inspect($stored, $item->slot);
                $kept = collect($item->checks ?? [])->filter(fn (array $check) => $check['status'] === 'warn' && ! str_starts_with($check['label'], 'Adult: '));
                $item->update(['checks' => [...$fresh, ...$kept->values()->all()]]);
            }
        }

        if (! $item->passesChecks()) {
            $this->flashMessage = $item->name.' didn\'t pass its checks — '.(collect($item->checks)->firstWhere('status', 'fail')['label'] ?? 'see its row').'. Bin it and upload a new picture.';

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

        foreach ($item->artPaths() as $path) {
            rescue(fn () => app(CosmeticArt::class)->disk()->delete($path), report: false);
        }

        $this->flashMessage = $item->name.' is gone.';
        $item->delete();
        app(CosmeticService::class)->forget();
    }

    /**
     * Takes a pet out for the grown-up — free, for testing, and nothing like a
     * kid's: no tickets, no growing with chores (the age is picked by hand
     * with petAge()), and no trades, which are the kids' alone. It starts as
     * a baby, as a kid's does.
     */
    public function takeOutPet(int $id): void
    {
        $pet = $this->find($id);

        if (! $pet || ! $pet->isSheet() || $pet->isDraft()) {
            return;
        }

        OwnedCosmetic::firstOrCreate(
            ['profile_id' => $this->profile->id, 'cosmetic_id' => $pet->id],
            ['household_id' => $this->profile->household_id, 'tickets_paid' => 0, 'growth' => 0],
        );

        $service = app(CosmeticService::class);
        $service->forget();
        $service->wear($this->profile, $pet);
        $this->flashMessage = "{$pet->name} is out — it follows you round the console.";
    }

    public function putAwayPet(): void
    {
        app(CosmeticService::class)->takeOff($this->profile, CosmeticSlot::Pet);
        $this->flashMessage = 'Your pet is put away.';
    }

    /** Straight to an age, since a grown-up's pet never grows by chores. */
    public function petAge(string $age): void
    {
        $stage = PetStage::tryFrom($age);
        $pet = app(CosmeticService::class)->wornIn($this->profile, CosmeticSlot::Pet);

        if (! $stage || ! $pet) {
            return;
        }

        OwnedCosmetic::where('profile_id', $this->profile->id)
            ->where('cosmetic_id', $pet->id)
            ->update(['growth' => $stage->startsAt()]);
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
        $previewIsFamily = false;
        $trial = null;

        if ($this->upload && ! $this->getErrorBag()->has('upload')) {
            $prepared = rescue(fn () => $this->prepared(), null, false);
            $checks = $prepared['checks'] ?? [];
            $previewIsFamily = $prepared['family'] ?? false;
            $previewUrl = rescue(fn () => $this->upload->temporaryUrl(), null, false);

            /*
             * The pet out on this screen: the age being tried, drawn from its
             * own sheet when the cut gave it one and from the next age up —
             * shrunk — when it did not, exactly as a kid's would be.
             */
            $age = $this->trialAge ? PetStage::tryFrom($this->trialAge) : null;

            if ($age && $slot === CosmeticSlot::Pet && $prepared) {
                $drawn = $age;

                while ($drawn->next() !== null && ($prepared['sheets'][$drawn->value] ?? null) === null) {
                    $drawn = $drawn->next();
                }

                $sheet = $prepared['sheets'][$drawn->value] ?? null;

                $trial = $sheet === null ? null : [
                    'age' => $age,
                    'src' => 'data:image/png;base64,'.base64_encode($sheet),
                    'rig' => ['poses' => count(CosmeticSlot::PET_POSES), 'anchors' => $prepared['anchors'][$drawn->value] ?? []],
                    'scale' => $age->scale(),
                    'borrowed' => $drawn === $age ? null : $drawn,
                    'own' => collect(PetStage::cases())->mapWithKeys(fn (PetStage $one) => [$one->value => ($prepared['sheets'][$one->value] ?? null) !== null])->all(),
                    'passes' => collect($checks)->doesntContain('status', 'fail'),
                ];
            }
        }

        $rotation = $service->rotationThisWeek($household)->keys()->merge($service->limitedThisWeek($household)->pluck('id'));

        return [
            'counts' => $service->counts($household),
            'uploadSlot' => $slot,
            'checks' => $checks,
            'previewUrl' => $previewUrl,
            'previewIsFamily' => $previewIsFamily,
            'trial' => $trial,
            'replacingPet' => $this->replacing === null ? null : $this->find($this->replacing),
            'petsMode' => $this->petsMode(),
            'kidsPets' => $this->petsMode() ? $this->kidsPets() : [],
            // A real face under the preview, since a frame is judged by how it
            // sits round one.
            'previewFace' => $catalog->first(fn (Cosmetic $item) => $item->slot === CosmeticSlot::Avatar && ! $item->isDraft()),
            'drafts' => $catalog->filter(fn (Cosmetic $item) => $item->isDraft() && $item->isSheet() === $this->petsMode())->values(),
            'listed' => $catalog->filter(fn (Cosmetic $item) => ! $item->isDraft() && $item->slot->value === $this->listSlot)->values(),
            'inRotation' => $rotation,
            // The grown-up's own pet, out for testing, and its age.
            'myPet' => $myPet = $service->wornIn($this->profile, CosmeticSlot::Pet),
            'myPetAge' => $myPet ? app(App\Services\PetService::class)->stageOf($this->profile, $myPet) : null,
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
        'pet' => 'Baby, young and adult in ONE picture, nine poses across and six down — a generator keeps a character far more consistent inside one image than across separate ones. Upload it as it comes and it is cut apart for you. Every pet needs all three ages: one missing a pose, or copied from another age, means generating again. Press Try it out to watch each age run about on your own screen before publishing.',
    ];

    // Six of the prompts ship with the artwork bundle. The pet's is the app's
    // own — every age in one picture — so it is handed to the panel from here.
    $ownPrompts = ['pet' => App\Enums\CosmeticSlot::PET_FAMILY_PROMPT];

    // And every one of them gets the file it must hand back spelled out on the
    // end, which none of the bundled six ever said. See promptOutput().
    $promptOutputs = collect(App\Enums\CosmeticSlot::uploadable())
        ->mapWithKeys(fn (App\Enums\CosmeticSlot $case) => [$case->value => $case->promptOutput()])

        ->all();

    // Pets mode is pets only; Cosmetics is everything else.
    $modeSlots = collect(App\Enums\CosmeticSlot::cases())
        ->filter(fn (App\Enums\CosmeticSlot $case) => ($case === App\Enums\CosmeticSlot::Pet) === $petsMode)
        ->values();

    $promptTabs = collect(App\Enums\CosmeticSlot::uploadable())
        ->filter(fn (App\Enums\CosmeticSlot $case) => $modeSlots->contains($case))
        ->mapWithKeys(fn (App\Enums\CosmeticSlot $case) => [$case->value => [$case->label(), $promptNotes[$case->value]]])
        ->all();

    $stockLook = [
        'shelf' => ['#3a2360', '#8c7bab'],
        'weekly' => ['#6a3fb0', '#c9a0ff'],
        'limited' => ['#ffc93d', '#ffe14d'],
        'egg' => ['#b8ff6a', '#b8ff6a'],
    ];
@endphp

<x-parent.shell :profile="$profile" :active="$petsMode ? 'pets' : 'cosmetics'">
    <div class="flex flex-col gap-4 rounded-[24px] border border-fq-nav-line bg-fq-bg p-[14px] md:p-[18px]">
        <div class="flex flex-wrap items-end justify-between gap-[14px] border-b border-fq-track pb-[13px]">
            <div>
                <h2 class="font-baloo text-[24px] font-extrabold">{{ $petsMode ? 'Pets' : 'Cosmetics' }}</h2>
                <p class="mt-[2px] text-[12.5px] text-fq-text-4">{{ $petsMode ? 'Making pets, what they can do, and how every kid\'s is doing' : 'What the kids can buy with tickets' }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <span class="rounded-[9px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[7px] font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-green">{{ $counts['live'] }} LIVE</span>
                @if ($counts['drafts'] > 0)
                    <span class="rounded-[9px] border border-fq-ticket-line px-[11px] py-[7px] font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-lime" style="background: var(--fq-gold-fill)">{{ $counts['drafts'] }} OLD {{ Str::plural('DRAFT', $counts['drafts']) }}</span>
                @endif
                <span class="rounded-[9px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[7px] font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-text-4">{{ $counts['rotation'] }} IN ROTATION</span>
            </div>
        </div>

        @if ($flashMessage)
            <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">{{ $flashMessage }}</div>
        @endif

        {{-- Pets mode: how every kid's pet is doing — the one out, how grown,
             its knack and what's left of it, or the egg in its place. --}}
        @if ($petsMode)
            <div class="flex flex-col gap-[8px]" data-kids-pets>
                <p class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">The kids' pets</p>

                <div class="grid gap-[8px] sm:grid-cols-2">
                    @forelse ($kidsPets as $row)
                        <div wire:key="kid-pet-{{ $row['kid']->id }}" class="flex items-center gap-[11px] rounded-[14px] border border-fq-line bg-fq-panel px-[12px] py-[10px]" data-kid-pet="{{ $row['kid']->id }}">
                            <span class="relative h-[44px] w-[44px] shrink-0 overflow-hidden rounded-[11px] bg-fq-bg">
                                @if ($row['pet'])
                                    <x-cosmetic.art :item="$row['pet']" :stage="$row['stage']" still class="absolute inset-0" />
                                @elseif ($row['egg'])
                                    <img x-data :src="window.fqEggSvg?.({{ $row['egg']->cracks }}, {{ $row['egg']->hue() }}, '{{ App\Models\PetEgg::patternFor($row['egg']->pet) }}')" alt="" class="absolute inset-0 h-full w-full">
                                @endif
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] font-semibold">
                                    {{ $row['kid']->name }}
                                    <span class="font-mono-fq text-[9px] tracking-[0.08em] text-fq-text-5 uppercase">· {{ $row['owned'] }} {{ Str::plural('pet', $row['owned']) }}</span>
                                </p>

                                @if ($row['pet'])
                                    <p class="text-[11.5px] text-fq-text-3">
                                        {{ $row['pet']->name }} · {{ $row['stage']->label() }}
                                        @if ($row['toGo'] !== null) <span class="text-fq-text-5">({{ $row['toGo'] }} to grow)</span> @endif
                                        · <span style="color: {{ $row['pet']->rarity()->color() }}">{{ $row['pet']->rarity()->label() }}</span>
                                        @if ($row['pet']->pet_style) · {{ $row['pet']->pet_style->label() }} @endif
                                    </p>
                                    @if ($row['knack'])
                                        <p class="text-[11px] text-fq-text-4">
                                            <i class="fa-solid {{ $row['knack']['knack']->icon() }} mr-[3px]"></i>{{ $row['knack']['knack']->label() }}:
                                            @if (! $row['knack']['unlocked'])
                                                still learning
                                            @elseif ($row['knack']['uses'] === null)
                                                always on{{ $row['knack']['doubled'] ? ', doubled today' : '' }}
                                            @else
                                                {{ $row['knack']['left'] }} left{{ $row['knack']['treats'] > 0 ? ' ('.$row['knack']['treats'].' from treats)' : '' }}
                                            @endif
                                        </p>
                                    @endif
                                @elseif ($row['egg'])
                                    <p class="text-[11.5px] text-fq-text-3">An egg — {{ $row['egg']->cracks }} of {{ App\Models\PetEgg::CRACKS_TO_HATCH }} cracks</p>
                                @else
                                    <p class="text-[11.5px] text-fq-text-5">No pet out</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-[12px] text-fq-text-5">No kids yet.</p>
                    @endforelse
                </div>
            </div>
        @else
            <a href="{{ route('parent.pets') }}" wire:navigate class="flex items-center gap-[8px] self-start rounded-[11px] border border-fq-line-2 px-[12px] py-[7px] text-[12px] text-fq-text-3 hover:border-fq-line-focus" data-pets-link>
                <i class="fa-solid fa-paw text-fq-green"></i>Pets have their own page now →
            </a>
        @endif



        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_300px] md:items-start" id="cosmetic-upload">
            <div class="flex flex-col gap-[13px]">
                @if ($replacingPet)
                    {{-- New art on a pet already in the shop: same row, so the
                         kids who own it keep it. Name, price and stock stay. --}}
                    <div class="flex flex-wrap items-center gap-[10px] rounded-[14px] border border-fq-cyan px-[13px] py-[10px]" style="background: #0d1f24" data-replacing="{{ $replacingPet->id }}">
                        <span class="relative h-[40px] w-[40px] shrink-0 overflow-hidden rounded-[10px] bg-fq-bg">
                            <x-cosmetic.art :item="$replacingPet" still class="absolute inset-0" />
                        </span>
                        <span class="min-w-[160px] flex-1">
                            <span class="block font-mono-fq text-[9px] tracking-[0.14em] text-fq-cyan uppercase">New art for {{ $replacingPet->name }}</span>
                            <span class="block text-[12px] text-fq-text-3">Every kid who owns it keeps it, grown as far as it has. Its name, price and stock stay as they are.</span>
                        </span>
                        <button type="button" wire:click="stopReplacing" class="shrink-0 rounded-[8px] border border-fq-line-2 px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap text-fq-text-4">CANCEL</button>
                    </div>
                @else
                    <p class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">New item</p>
                @endif

                @php $spec = $uploadSlot->uploadSpec(); @endphp

                <label
                    class="flex cursor-pointer items-center gap-[15px] rounded-[16px] border border-dashed border-fq-line-focus p-5"
                    style="background: repeating-linear-gradient(45deg,#120a22 0 8px,#0d0718 8px 16px)"
                >
                    <i class="fa-solid fa-arrow-up-from-bracket text-[22px] text-fq-magenta"></i>
                    <span class="min-w-0 flex-1">
                        <span class="block font-baloo text-[16px] font-extrabold">{{ $upload ? 'Choose a different PNG' : 'Choose a PNG' }}</span>
                        <span class="mt-1 block font-mono-fq text-[10px] text-fq-text-4 uppercase">
                            {{ $uploadSlot->label() }} · {{ $uploadSlot === App\Enums\CosmeticSlot::Pet ? '3:2, all three ages' : $spec['width'].'×'.$spec['height'] }} · {{ $spec['alpha'] ? 'transparent background' : 'solid, seamless' }} · max {{ $uploadSlot->uploadLimitLabel() }}
                        </span>
                    </span>
                    <span class="shrink-0 rounded-[9px] px-3 py-2 font-mono-fq text-[10px] tracking-[0.1em] whitespace-nowrap text-fq-bg" style="background: var(--fq-cyan)">CHOOSE</span>
                    {{-- Uploaded by hand rather than wire:model, so a picture over
                         the cap can be shrunk in the browser first — an all-ages
                         sheet straight out of a generator is usually over 2 MB,
                         which PHP drops before this app can say why. --}}
                    <input
                        type="file"
                        accept="image/png"
                        class="sr-only"
                        x-data
                        data-upload-input
                        x-on:change="
                            const file = $event.target.files[0];
                            $event.target.value = '';
                            if (! file) return;
                            const ready = await window.fqShrinkPng(file, {{ $spec['max_kb'] }});
                            // Still too big: say so here, rather than send it
                            // and have PHP drop it with no reason given.
                            if (ready.size > {{ $spec['max_kb'] }} * 1024) {
                                $wire.set('uploadNote', 'That picture is too big to upload even shrunk — export it smaller (under {{ $uploadSlot->uploadLimitLabel() }}) and try again.');
                                return;
                            }
                            $wire.upload('upload', ready);
                        "
                    >
                </label>

                <div wire:loading wire:target="upload" class="font-mono-fq text-[10px] text-fq-text-4">UPLOADING…</div>
                @if ($uploadSlot === App\Enums\CosmeticSlot::Pet)
                    <p class="text-[11.5px] text-fq-text-4">One 3:2 picture with the baby, young and adult on it — made from the Pet prompt below.</p>
                @endif
                @error('upload') <p class="text-[12.5px] text-fq-danger">{{ $message }}</p> @enderror

                @if ($uploadNote && ! $upload)
                    <p class="flex items-center gap-[7px] rounded-[11px] border border-fq-line-2 bg-fq-sunk px-3 py-[9px] text-[12.5px] text-fq-text-2" data-upload-note>
                        <i class="fa-solid fa-trash-can text-[11px] text-fq-text-4"></i>{{ $uploadNote }}
                    </p>
                @endif

                {{-- New art keeps everything else about the item. --}}
                @unless ($replacingPet)
                {{-- Chips rather than a dropdown: all six kinds of upload are on
                     screen at once, so nobody has to open a menu to find out a
                     background can be uploaded at all. --}}
                <div class="flex flex-col gap-[6px]">
                    <span class="{{ $label }}">What is it?</span>
                    <div class="flex flex-wrap gap-[6px]" role="radiogroup" aria-label="Slot">
                        @foreach (array_filter(App\Enums\CosmeticSlot::uploadable(), fn ($case) => $modeSlots->contains($case)) as $case)
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
                            {{-- Egg only is for pets: it is never sold, only hatched. --}}
                            @foreach (App\Enums\CosmeticStock::forSlot($uploadSlot) as $case)
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

                {{-- Laid over the art rather than drawn into it, so one sheet can
                     be sold plain and again as the special one. What a 20-ticket
                     pet is worth 20 for. --}}
                <label class="flex flex-col gap-[6px]">
                    <span class="{{ $label }}">Special</span>
                    <select wire:model.live="effect" class="{{ $field }}">
                        <option value="">Nothing — the art as it is</option>
                        @foreach (App\Enums\CosmeticEffect::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    <span class="text-[11.5px] text-fq-text-4">A rainbow or a flame on top of the picture. Worth about 20 tickets on a pet.</span>
                </label>

                {{-- A pet's tier, style and knack. Style is what it does in the
                     arcade and is the same strength at every tier; the tier
                     decides whether it has a knack, and which. --}}
                @if ($uploadSlot === App\Enums\CosmeticSlot::Pet)
                    @php $tier = App\Enums\PetRarity::tryFrom($petRarity) ?? App\Enums\PetRarity::Common; @endphp

                    <div class="flex flex-col gap-[6px]" data-pet-traits>
                        <span class="{{ $label }}">Rarity</span>
                        <div class="flex flex-wrap gap-[6px]">
                            @foreach (App\Enums\PetRarity::cases() as $case)
                                @php $on = $tier === $case; @endphp
                                <button
                                    type="button"
                                    wire:click="$set('petRarity', '{{ $case->value }}')"
                                    class="rounded-full border px-3 py-[6px] font-mono-fq text-[9.5px] tracking-[0.08em] uppercase"
                                    style="border-color: {{ $on ? $case->color() : '#241539' }}; background: {{ $on ? '#1d1036' : '#0b0616' }}; color: {{ $on ? $case->color() : '#6f6288' }}"
                                >{{ $case->label() }}</button>
                            @endforeach
                        </div>
                        <span class="text-[11.5px] text-fq-text-4">
                            {{ $tier->hasKnack() ? 'Has a knack on top of its style.' : 'A style only — no knack.' }}
                            @if ($stock === App\Enums\CosmeticStock::Egg->value) Its egg costs {{ $tier->eggPrice() }} ✦. @endif
                        </span>
                    </div>

                    <div class="flex flex-col gap-[6px]">
                        <span class="{{ $label }}">Style · what it does in the arcade</span>
                        <div class="flex flex-wrap gap-[6px]">
                            @foreach (App\Enums\PetStyle::cases() as $case)
                                @php $on = $petStyle === $case->value; @endphp
                                <button
                                    type="button"
                                    wire:click="$set('petStyle', '{{ $case->value }}')"
                                    class="flex items-center gap-[6px] rounded-full border px-3 py-[6px] text-[12px]"
                                    style="border-color: {{ $on ? '#c9a0ff' : '#241539' }}; background: {{ $on ? '#241546' : '#0b0616' }}; color: {{ $on ? '#d8b4ff' : '#8c7bab' }}"
                                    title="{{ $case->blurb() }}"
                                ><i class="fa-solid {{ $case->icon() }} text-[10px]"></i>{{ $case->label() }}</button>
                            @endforeach
                        </div>
                        @error('petStyle') <span class="text-[12px] text-fq-danger">{{ $message }}</span> @enderror
                    </div>

                    @if ($tier->hasKnack())
                        <label class="flex flex-col gap-[6px]">
                            <span class="{{ $label }}">Knack · {{ $tier->label() }}</span>
                            <select wire:model.live="petKnack" class="{{ $field }}">
                                <option value="">Pick one</option>
                                @foreach ($tier->knacks() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                            @if ($chosenKnack = App\Enums\PetKnack::tryFrom($petKnack))
                                <span class="text-[11.5px] text-fq-text-4">{{ $chosenKnack->describe(App\Enums\PetStage::Adult) }} Half strength while it's young, and not yet as a baby.</span>
                            @endif
                            @error('petKnack') <span class="text-[12px] text-fq-danger">Pick a knack for a {{ mb_strtolower($tier->label()) }} pet.</span> @enderror
                        </label>
                    @endif
                @endif

                @endunless

                {{-- No drafts: the art is judged here and now. A pet can be let
                     loose on this screen first; anything that is not right is
                     tossed, and the next picture uploaded in its place. --}}
                <div class="flex flex-wrap gap-[9px] pt-[2px]">
                    @if ($upload && $uploadSlot === App\Enums\CosmeticSlot::Pet)
                        <button
                            type="button"
                            wire:click="tryOut('baby')"
                            wire:loading.attr="disabled"
                            class="rounded-[12px] px-[18px] py-3 text-center font-baloo text-[15px] font-extrabold text-fq-ink"
                            style="background: linear-gradient(150deg,#b8ffd9,#54e8d0)"
                        ><i class="fa-solid fa-paw mr-[6px] text-[13px]"></i>Try it out</button>
                    @endif
                    <button
                        type="button"
                        wire:click="publish"
                        wire:loading.attr="disabled"
                        class="min-w-[160px] flex-1 rounded-[12px] p-3 text-center font-baloo text-[15px] font-extrabold text-fq-ink"
                        style="background: linear-gradient(150deg,#fff6b0,#ffc93d)"
                    >{{ $replacingPet ? 'Put the new art on '.$replacingPet->name : 'Publish to the shop' }}</button>
                    @if ($upload)
                        <button
                            type="button"
                            wire:click="toss"
                            wire:loading.attr="disabled"
                            class="rounded-[12px] border border-fq-line-3 px-[18px] py-3 text-center font-baloo text-[15px] font-extrabold text-fq-text-3"
                        >Discard</button>
                    @endif
                </div>
            </div>

            <div class="flex flex-col gap-[11px]">
                <p class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">Preview</p>

                <div class="flex flex-col gap-3 rounded-[16px] border border-fq-line-2 bg-fq-panel p-[13px]">
                    @if ($previewUrl && $previewIsFamily)
                        <img src="{{ $previewUrl }}" alt="" class="w-full rounded-[10px]" style="background: repeating-conic-gradient(#1d1036 0 25%, #0b0616 0 50%) 0 0 / 16px 16px">
                        <p class="text-[12px] text-fq-text-4">All three ages in one picture. It is cut into baby, young and adult — press Try it out to see each age running about on this screen before it goes in the shop.</p>
                    @elseif ($previewUrl)
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

        {{-- The prompts: six out of cosmetics.js, plus the app's own for a pet.
             Same shape every time — a subject to change, then geometry and
             negative blocks to leave alone, since the geometry is what image
             models get wrong by default.

             `Js::from` rather than `@js` in the x-data: it escapes quotes as
             unicode, and one literal double quote inside an x-data attribute
             ends the attribute early and kills the whole component. --}}
        <div
            x-data="{
                promptKind: '{{ $petsMode ? 'pet' : 'frame' }}',
                promptCopied: false,
                own: {{ Js::from($ownPrompts) }},
                outputs: {{ Js::from($promptOutputs) }},
                get promptText() {
                    const body = this.own[this.promptKind] ?? (window.FQCosmetics?.PROMPTS[this.promptKind] || '');

                    return body ? body + (this.outputs[this.promptKind] ?? '') : '';
                },
            }"
            class="flex flex-col gap-[11px] rounded-[18px] border border-fq-line-2 p-[14px]"
            style="background: linear-gradient(160deg,#150c26,#0a0512 70%)"
        >
            <div class="flex flex-wrap items-center gap-[11px]">
                <span class="font-baloo text-[16px] font-extrabold">Need art? Start from this prompt</span>
                <span class="text-[12px] text-fq-text-4">Swap the bracketed subject, leave the rest alone.</span>
                <button
                    type="button"
                    x-on:click="navigator.clipboard?.writeText(promptText); promptCopied = true; setTimeout(() => promptCopied = false, 1600)"
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
                        x-on:click="promptKind = '{{ $kind }}'; promptCopied = false; $wire.set('slot', '{{ str_starts_with($kind, 'pet') ? 'pet' : $kind }}')"
                        class="rounded-full border px-3 py-[6px] font-mono-fq text-[9.5px] tracking-[0.08em] uppercase"
                        :style="promptKind === '{{ $kind }}' ? 'border-color:#c9a0ff;background:#241546;color:#d8b4ff' : 'border-color:#241539;background:#0b0616;color:#6f6288'"
                    >{{ $tab }}</button>
                @endforeach
            </div>


            <div
                class="rounded-[13px] border border-fq-line bg-fq-bg p-[14px] font-mono-fq text-[10.5px] leading-[1.8] whitespace-pre-wrap text-fq-text-2"
                x-text="promptText"
            ></div>

            <div class="flex items-start gap-[9px]">
                <i class="fa-solid fa-lightbulb mt-[2px] text-[12px] text-fq-gold"></i>
                @foreach ($promptTabs as $kind => [$tab, $note])
                    <span class="flex-1 text-[11.5px] text-fq-text-4" x-show="promptKind === '{{ $kind }}'" @if ($kind !== ($petsMode ? 'pet' : 'frame')) x-cloak @endif>{{ $note }}</span>
                @endforeach
            </div>
        </div>

        @if ($drafts->isNotEmpty())
            <div class="flex items-center gap-[9px] pt-1">
                <span class="font-mono-fq text-[9.5px] tracking-[0.16em] text-fq-text-4 uppercase">Old drafts · not visible to anyone · publish or bin them</span>
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
                        <button type="button" wire:click="publishDraft({{ $draft->id }})" class="shrink-0 rounded-[8px] px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap" style="background: {{ $failed ? '#ffc93d' : '#7dffb0' }}; color: #05170c">{{ $failed ? 'CHECK AGAIN' : 'PUBLISH' }}</button>
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
            @foreach ($modeSlots as $case)
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
                            {{-- Made before the eighteen-pose sheet: no walk cycle and no
                                 empty paws until it gets new art. --}}
                            @if ($item->isSheet() && ! $item->pet_rig) · <span class="text-fq-gold" data-old-pet-art>old 12-pose art</span> @endif
                            @if ($inRotation->contains($item->id)) · <span class="text-fq-gold">out this week</span> @endif
                        </p>
                    </div>
                    <span class="w-[34px] shrink-0 font-baloo text-[14px] font-extrabold text-fq-lime">{{ $item->isFree() ? 'Free' : $item->cost }}</span>
                    <span class="shrink-0 rounded-full border px-[9px] py-1 font-mono-fq text-[8.5px] tracking-[0.08em] whitespace-nowrap uppercase" style="border-color: {{ $rim }}; color: {{ $ink }}">{{ $item->stock->label() }}</span>
                    @if ($item->isSheet())
                        {{-- Tier, style and knack, changed in place. Kids who own
                             it keep it; what it can do changes with it. --}}
                        @php
                            $mini = 'rounded-[8px] border border-fq-line-2 bg-fq-bg px-[7px] py-[5px] font-mono-fq text-[9px] tracking-[0.06em] uppercase';
                            $itemTier = $item->rarity();
                        @endphp
                        <span class="flex shrink-0 flex-wrap items-center gap-[4px]" data-pet-row-traits="{{ $item->id }}">
                            <select wire:change="setPetTrait({{ $item->id }}, 'rarity', $event.target.value)" class="{{ $mini }}" style="color: {{ $itemTier->color() }}; border-color: {{ $itemTier->color() }}" aria-label="Rarity">
                                @foreach (App\Enums\PetRarity::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($itemTier === $case)>{{ $case->label() }}</option>
                                @endforeach
                            </select>
                            <select wire:change="setPetTrait({{ $item->id }}, 'style', $event.target.value)" class="{{ $mini }} text-fq-text-3" aria-label="Style">
                                @foreach (App\Enums\PetStyle::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($item->pet_style === $case)>{{ $case->label() }}</option>
                                @endforeach
                            </select>
                            @if ($itemTier->hasKnack())
                                <select wire:change="setPetTrait({{ $item->id }}, 'knack', $event.target.value)" class="{{ $mini }} text-fq-text-3" aria-label="Knack">
                                    @foreach ($itemTier->knacks() as $case)
                                        <option value="{{ $case->value }}" @selected($item->knack() === $case)>{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </span>

                        <button
                            type="button"
                            wire:click="replaceArt({{ $item->id }})"
                            x-data
                            x-on:click="document.getElementById('cosmetic-upload')?.scrollIntoView({ behavior: 'smooth' })"
                            class="shrink-0 rounded-[8px] border px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap"
                            style="border-color: {{ $item->pet_rig ? '#3a2360' : '#ffc93d' }}; color: {{ $item->pet_rig ? '#b0a3cc' : '#ffe14d' }}"
                        ><i class="fa-solid fa-arrows-rotate mr-[4px] text-[9px]"></i>NEW ART</button>

                        {{-- A grown-up's pet, for testing: free, no trades. --}}
                        @if ($myPet?->id === $item->id)
                            <span class="flex shrink-0 items-center gap-[4px]" data-my-pet="{{ $item->id }}">
                                @foreach (App\Enums\PetStage::cases() as $age)
                                    @php $on = $myPetAge === $age; @endphp
                                    <button
                                        type="button"
                                        wire:click="petAge('{{ $age->value }}')"
                                        class="rounded-full border px-[9px] py-[5px] font-mono-fq text-[8.5px] tracking-[0.08em] uppercase"
                                        style="border-color: {{ $on ? '#54e8d0' : '#3a2360' }}; color: {{ $on ? '#54e8d0' : '#8c7bab' }}"
                                    >{{ $age === App\Enums\PetStage::Adult ? 'grown' : $age->value }}</button>
                                @endforeach
                                <button type="button" wire:click="putAwayPet" class="rounded-[8px] border border-fq-line-2 px-[10px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap text-fq-text-4">PUT AWAY</button>
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="takeOutPet({{ $item->id }})"
                                class="shrink-0 rounded-[8px] border px-[11px] py-[6px] font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap"
                                style="border-color: #54e8d0; color: #54e8d0"
                            ><i class="fa-solid fa-paw mr-[4px] text-[9px]"></i>TAKE OUT</button>
                        @endif
                    @endif
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

    {{-- The pet being tried out, loose on this screen as it would be on a
         kid's: pettable, draggable, feedable, with its toy out. The layer is
         fixed to the window, so the pet walks along the bottom of whatever is
         in view. Keyed on the age, so switching age is a fresh animal. --}}
    @if ($trial)
        <div wire:key="trial-{{ $trial['age']->value }}" class="pointer-events-none fixed inset-0 z-30" data-pet-trial="{{ $trial['age']->value }}">
            <fq-pets
                sheet="{{ $trial['src'] }}"
                rig="{{ json_encode($trial['rig'], JSON_UNESCAPED_SLASHES) }}"
                scale="{{ $trial['scale'] }}"
                @if ($effect !== '') effect="{{ App\Enums\CosmeticEffect::from($effect)->cssClass() }}" @endif
                toy
                drag
                feed-on-tap
            ></fq-pets>
        </div>

        {{-- At the top: the pet lives along the bottom of the window, and a
             bar there sat right on top of it. --}}
        <div class="fixed inset-x-0 top-0 z-40 flex justify-center px-3 pt-3">
            <div class="flex max-w-[720px] flex-1 flex-wrap items-center gap-[8px] rounded-[16px] border border-fq-line-2 px-[12px] py-[10px] shadow-2xl" style="background: rgba(14,7,25,.96)">
                <div class="min-w-[140px] flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.14em] text-fq-cyan uppercase">Trying out · nothing is saved</p>
                    <p class="text-[12px] text-fq-text-3">
                        {{ $name !== '' ? $name : 'The new pet' }}, as a {{ mb_strtolower($trial['age']->label()) }}.
                        @if ($trial['borrowed'])
                            <span class="text-fq-gold">No {{ $trial['age']->value }} art — it's the {{ mb_strtolower($trial['borrowed']->label()) }} drawn smaller.</span>
                        @endif
                        Pet it, pick it up, tap anywhere empty to feed it.
                    </p>
                </div>

                <div class="flex gap-[4px]" role="radiogroup" aria-label="Age">
                    @foreach (App\Enums\PetStage::cases() as $age)
                        @php $on = $trial['age'] === $age; @endphp
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $on ? 'true' : 'false' }}"
                            wire:click="tryOut('{{ $age->value }}')"
                            class="rounded-full border px-[10px] py-[5px] font-mono-fq text-[9px] tracking-[0.08em] uppercase"
                            style="border-color: {{ $on ? '#54e8d0' : '#3a2360' }}; color: {{ $on ? '#54e8d0' : ($trial['own'][$age->value] ? '#b0a3cc' : '#6f6288') }}"
                        >{{ $age->value }}</button>
                    @endforeach
                </div>

                <button
                    type="button"
                    x-data
                    x-on:click="window.dispatchEvent(new CustomEvent('fq-pet-feed'))"
                    class="rounded-[10px] border border-fq-line-2 px-[11px] py-[7px] font-baloo text-[13px] font-extrabold text-fq-green"
                ><i class="fa-solid fa-drumstick-bite mr-[4px] text-[11px]"></i>Feed</button>

                <button
                    type="button"
                    wire:click="publish"
                    @disabled(! $trial['passes'])
                    class="rounded-[10px] px-[12px] py-[7px] font-baloo text-[13px] font-extrabold text-fq-ink disabled:opacity-40"
                    style="background: linear-gradient(150deg,#fff6b0,#ffc93d)"
                    title="{{ $name === '' ? 'Give it a name first' : '' }}"
                >{{ $replacingPet ? 'Use this art' : 'Publish' }}</button>

                <button type="button" wire:click="toss" class="rounded-[10px] border border-fq-line-2 px-[11px] py-[7px] font-baloo text-[13px] font-extrabold text-fq-text-3">Discard</button>
                <button type="button" wire:click="stopTrying" aria-label="Put it away" class="px-[6px] py-[7px] text-[13px] text-fq-text-4"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
    @endif
</x-parent.shell>
