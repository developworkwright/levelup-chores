<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Enums\PerkEffect;
use App\Enums\PetKnack;
use App\Enums\PetRarity;
use App\Enums\PetStage;
use App\Enums\PetStyle;
use App\Enums\SleepOutcome;
use App\Enums\TicketKind;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Cosmetic;
use App\Models\DailyMystery;
use App\Models\Household;
use App\Models\LuckyHit;
use App\Models\LuckyPrize;
use App\Models\OwnedCosmetic;
use App\Models\PetEgg;
use App\Models\PetKnackUse;
use App\Models\PetTreat;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\ArcadeService;
use App\Services\ChoreService;
use App\Services\CosmeticService;
use App\Services\HouseholdClock;
use App\Services\KnackService;
use App\Services\LuckyBlockService;
use App\Services\MonsterService;
use App\Services\PetService;
use App\Services\SleepService;
use App\Services\SpinService;
use App\Services\StreakService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Pet rarity, styles and knacks — see App\Enums\PetRarity, PetStyle,
 * PetKnack and App\Services\KnackService.
 */
class PetKnackTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $parent;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('drawings');

        $this->household = Household::factory()->create();
        $this->parent = Profile::factory()->parent()->for($this->household)->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Colton', 'bonus_tickets' => 100]);
    }

    private function pet(string $name, array $attributes = []): Cosmetic
    {
        return Cosmetic::create([
            'household_id' => $this->household->id,
            'slot' => 'pet',
            'art_path' => 'cosmetics/'.$this->household->id.'/'.$name.'.png',
            'name' => $name,
            'cost' => 10,
            'stock' => 'shelf',
            'pet_rarity' => 'common',
            'pet_style' => 'steady',
            'published_at' => now(),
            ...$attributes,
        ]);
    }

    /** Out on the kid, at this many chores of growth. */
    private function outOn(Profile $kid, Cosmetic $pet, int $growth): void
    {
        OwnedCosmetic::updateOrCreate(
            ['profile_id' => $kid->id, 'cosmetic_id' => $pet->id],
            ['household_id' => $kid->household_id, 'tickets_paid' => 0, 'growth' => $growth],
        );

        app(CosmeticService::class)->forget();
        app(CosmeticService::class)->wear($kid->fresh(), $pet);
        app()->forgetScopedInstances();
    }

    /** The tickets the pet itself has tipped — chores pay levels too. */
    private function tipped(): int
    {
        return (int) BonusTicketEntry::where('profile_id', $this->kid->id)
            ->where('kind', TicketKind::Pet)
            ->sum('amount');
    }

    /** Signs off this many fresh chores for the kid, as a parent would. */
    private function approve(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $chore = Chore::factory()->for($this->household)->create();
            $completion = ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $this->kid->id,
                'status' => 'pending',
                'points_awarded' => 100,
                'submitted_at' => now(),
            ]);

            app(ChoreService::class)->approve($completion, $this->parent);
            app()->forgetScopedInstances();
        }
    }

    private function knacks(): KnackService
    {
        return app(KnackService::class);
    }

    public function test_every_knack_belongs_to_exactly_one_tier_above_common(): void
    {
        $this->assertSame([], PetRarity::Common->knacks());
        $this->assertFalse(PetRarity::Common->hasKnack());

        $tiered = array_merge(...array_map(fn (PetRarity $tier) => $tier->knacks(), PetRarity::cases()));

        $this->assertEqualsCanonicalizing(PetKnack::cases(), $tiered);

        foreach ([PetRarity::Rare, PetRarity::Epic, PetRarity::Legendary] as $tier) {
            $this->assertGreaterThanOrEqual(3, count($tier->knacks()), $tier->value);
        }

        $this->assertSame([PetKnack::CoinSniffer, PetKnack::BigPockets, PetKnack::Fetch, PetKnack::Sniffer], PetRarity::Rare->knacks());
    }

    /** A baby is still learning; young is half strength; grown is the whole thing. */
    public function test_knacks_grow_with_the_pet(): void
    {
        foreach (PetKnack::cases() as $knack) {
            $this->assertNull($knack->allowance(PetStage::Baby), $knack->value);
            $this->assertStringContainsString('Still learning', $knack->describe(PetStage::Baby));
        }

        $this->assertSame(['uses' => 1, 'days' => 7], PetKnack::Sniffer->allowance(PetStage::Young));
        $this->assertSame(['uses' => 2, 'days' => 7], PetKnack::Sniffer->allowance(PetStage::Adult));
        $this->assertStringContainsString('down to 5', PetKnack::Sniffer->describe(PetStage::Young));
        $this->assertStringContainsString('down to 3', PetKnack::Sniffer->describe(PetStage::Adult));

        $this->assertSame(['uses' => 1, 'days' => 14], PetKnack::Fetch->allowance(PetStage::Young));
        $this->assertSame(['uses' => 1, 'days' => 7], PetKnack::Fetch->allowance(PetStage::Adult));
        $this->assertStringContainsString('2x', PetKnack::Fetch->describe(PetStage::Adult));

        // A young Paw Nudge picks its own way; a grown one lets the kid choose.
        $this->assertStringContainsString('whichever way it likes', PetKnack::PawNudge->describe(PetStage::Young));
        $this->assertStringContainsString('you pick which way', PetKnack::PawNudge->describe(PetStage::Adult));

        // Always-on knacks never need spending.
        $this->assertNull(PetKnack::BigPockets->allowance(PetStage::Adult));
        $this->assertTrue(PetKnack::GuardDog->automatic());
        $this->assertFalse(PetKnack::Sniffer->automatic());
    }

    public function test_a_common_pet_has_a_style_and_no_knack(): void
    {
        $tabby = $this->pet('Tabby', ['pet_style' => 'quick']);
        $this->outOn($this->kid, $tabby, 40);

        $this->assertSame(PetStyle::Quick, $tabby->fresh()->pet_style);
        $this->assertNull($this->knacks()->stateFor($this->kid->fresh()));
    }

    /** A knack a tier doesn't allow is never honoured, whatever the column says. */
    public function test_a_knack_from_the_wrong_tier_is_ignored(): void
    {
        $this->assertNull($this->pet('Odd', ['pet_rarity' => 'rare', 'pet_knack' => 'guard_dog'])->knack());
        $this->assertSame(PetKnack::GuardDog, $this->pet('Rex', ['pet_rarity' => 'legendary', 'pet_knack' => 'guard_dog'])->knack());
    }

    public function test_a_baby_is_still_learning_its_knack(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 4);

        $state = $this->knacks()->stateFor($this->kid->fresh());

        $this->assertFalse($state['unlocked']);
        $this->assertSame(6, $state['choresToUnlock']);
        $this->assertFalse($this->knacks()->available($this->kid->fresh(), PetKnack::Sniffer));
        $this->assertFalse($this->knacks()->use($this->kid->fresh(), PetKnack::Sniffer));
    }

    /** Uses come back on a rolling window — nothing has to reset on a schedule. */
    public function test_uses_are_spent_and_come_back_after_their_window(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 40);
        $kid = $this->kid->fresh();

        $this->assertSame(2, $this->knacks()->stateFor($kid)['left']);
        $this->assertTrue($this->knacks()->use($kid, PetKnack::Sniffer));
        $this->assertTrue($this->knacks()->use($kid, PetKnack::Sniffer));
        $this->assertFalse($this->knacks()->use($kid, PetKnack::Sniffer), 'Spent a third sniff in a week.');

        $state = $this->knacks()->stateFor($kid);
        $this->assertSame(0, $state['left']);
        $this->assertTrue($state['backAt']->isSameDay(now()->addDays(7)->setTimezone($this->household->timezone)));

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->assertSame(2, $this->knacks()->stateFor($kid)['left']);
    }

    public function test_a_young_pet_has_half_as_many_uses(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 12);

        $state = $this->knacks()->stateFor($this->kid->fresh());

        $this->assertSame('half', $state['strength']);
        $this->assertSame(1, $state['uses']);
        $this->assertSame(18, $state['choresToFull']);
    }

    /** Two pets with the same knack don't double it: uses are the kid's. */
    public function test_swapping_between_pets_with_the_same_knack_does_not_double_it(): void
    {
        $one = $this->pet('One', ['pet_rarity' => 'rare', 'pet_knack' => 'fetch']);
        $two = $this->pet('Two', ['pet_rarity' => 'rare', 'pet_knack' => 'fetch']);

        $this->outOn($this->kid, $one, 40);
        $this->assertTrue($this->knacks()->use($this->kid->fresh(), PetKnack::Fetch));

        $this->outOn($this->kid, $two, 40);
        $this->assertFalse($this->knacks()->use($this->kid->fresh(), PetKnack::Fetch));
    }

    /** Only the kid's own pet that's out: not a grown-up's, not with an egg out. */
    public function test_grown_ups_and_eggs_have_no_knack(): void
    {
        $rex = $this->pet('Rex', ['pet_rarity' => 'legendary', 'pet_knack' => 'guard_dog']);
        $this->outOn($this->parent, $rex, 40);

        $this->assertNull($this->knacks()->stateFor($this->parent->fresh()));

        $this->outOn($this->kid, $rex, 40);
        $this->assertNotNull($this->knacks()->stateFor($this->kid->fresh()));

        PetEgg::create(['household_id' => $this->household->id, 'profile_id' => $this->kid->id, 'tickets_paid' => 15]);
        $this->assertNull($this->knacks()->stateFor($this->kid->fresh()));
    }

    /** The tier is on the shell and in the price — no luck about which is which. */
    public function test_an_egg_costs_its_tiers_price_and_shows_its_pattern(): void
    {
        $epic = $this->pet('Glimmer', ['stock' => 'egg', 'pet_rarity' => 'epic', 'pet_knack' => 'paw_nudge']);
        $this->pet('Plain', ['stock' => 'egg']);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.pets')
            ->assertSee('data-egg-tier="epic"', false)
            ->assertSee("'striped'", false)
            ->assertSee('30 ✦')
            ->assertSee('15 ✦')
            ->call('buyEgg', $epic->id);

        $egg = PetEgg::where('profile_id', $this->kid->id)->firstOrFail();

        $this->assertSame(30, $egg->tickets_paid);
        $this->assertSame(70, $this->kid->fresh()->bonus_tickets);
        $this->assertSame('striped', app(PetService::class)->spriteFor($this->kid->fresh())['pattern']);
    }

    public function test_the_locker_shows_the_pets_tier_style_and_knack_with_its_uses(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_style' => 'lucky', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 12);
        $this->knacks()->use($this->kid->fresh(), PetKnack::Sniffer);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->assertSee('data-pet-tier', false)
            ->assertSee('Lucky')
            ->assertSee('data-pet-knack="sniffer"', false)
            ->assertSee('Half strength')
            ->assertSee('narrows the Mystery Chore down to 5')
            ->assertSee('data-knack-left="0"', false)
            ->assertSee('Full strength in 18 more chores');
    }

    private function familyPng(): string
    {
        $height = 1200;
        $width = 1800;
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        $cell = $height / 6;
        $coats = [imagecolorallocate($image, 236, 196, 140), imagecolorallocate($image, 204, 150, 96), imagecolorallocate($image, 168, 116, 72)];

        foreach ([0.40, 0.55, 0.70] as $band => $size) {
            foreach (range(0, 17) as $index) {
                $bottom = ($band * 2 + intdiv($index, 9)) * $cell + $cell * 0.9;
                imagefilledellipse($image, (int) (($index % 9) * $cell + $cell / 2), (int) ($bottom - $cell * $size / 2), (int) ($cell * $size * 0.8), (int) ($cell * $size), $coats[$band]);
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_a_grown_up_publishes_a_rare_pet_with_its_style_and_knack(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Sniffy')
            ->set('petRarity', 'rare')
            // A new tier picks the first knack it allows.
            ->assertSet('petKnack', 'coin_sniffer')
            ->set('petKnack', 'sniffer')
            ->set('petStyle', 'big')
            ->call('publish')
            ->assertHasNoErrors();

        $pet = Cosmetic::where('name', 'Sniffy')->firstOrFail();

        $this->assertSame([PetRarity::Rare, PetStyle::Big, PetKnack::Sniffer], [$pet->rarity(), $pet->pet_style, $pet->knack()]);
    }

    public function test_a_knack_from_another_tier_is_refused_on_upload(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Cheat')
            ->set('petRarity', 'rare')
            ->set('petKnack', 'guard_dog')
            ->call('publish')
            ->assertHasErrors('petKnack');

        $this->assertSame(0, Cosmetic::where('name', 'Cheat')->count());
    }

    public function test_a_common_pet_is_published_without_a_knack(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.cosmetics')
            ->call('$set', 'slot', 'pet')
            ->set('upload', UploadedFile::fake()->createWithContent('family.png', $this->familyPng()))
            ->set('name', 'Plain')
            ->set('petKnack', 'guard_dog')
            ->call('publish')
            ->assertHasNoErrors();

        $pet = Cosmetic::where('name', 'Plain')->firstOrFail();

        $this->assertSame(PetRarity::Common, $pet->rarity());
        $this->assertNull($pet->pet_knack);
    }

    /** A tier change moves the knack to one the new tier allows — never none, never a wrong one. */
    public function test_a_published_pets_tier_style_and_knack_change_from_its_row(): void
    {
        $rex = $this->pet('Rex');

        Auth::guard('profile')->login($this->parent);

        $page = Volt::test('parent.cosmetics')
            ->call('pickListSlot', 'pet')
            ->assertSee('data-pet-row-traits="'.$rex->id.'"', false)
            ->call('setPetTrait', $rex->id, 'rarity', 'epic');

        $this->assertSame(PetKnack::PawNudge, $rex->fresh()->knack());

        $page->call('setPetTrait', $rex->id, 'knack', 'digger')
            ->call('setPetTrait', $rex->id, 'knack', 'guard_dog')
            ->call('setPetTrait', $rex->id, 'style', 'lucky');

        $this->assertSame([PetKnack::Digger, PetStyle::Lucky], [$rex->fresh()->knack(), $rex->fresh()->pet_style]);

        $page->call('setPetTrait', $rex->id, 'rarity', 'common');

        $this->assertNull($rex->fresh()->knack());
    }

    /* ------------------------------------------------------------------ *
     * The Rare knacks
     * ------------------------------------------------------------------ */

    public function test_big_pockets_makes_room_for_more_tokens_as_the_pet_grows(): void
    {
        $tokens = app(TokenService::class);
        $pockets = $this->pet('Pockets', ['pet_rarity' => 'rare', 'pet_knack' => 'big_pockets']);
        $base = TokenService::BASE_CAP;

        $this->outOn($this->kid, $pockets, 4);
        $this->assertSame($base, $tokens->capFor($this->kid->fresh()), 'A baby has not learned it yet.');

        $this->outOn($this->kid, $pockets, 12);
        $this->assertSame($base + 5, $tokens->capFor($this->kid->fresh()));

        $this->outOn($this->kid, $pockets, 30);
        $this->assertSame($base + 10, $tokens->capFor($this->kid->fresh()));
        $this->assertSame(10, $tokens->meterFor($this->kid->fresh())['pockets']);
    }

    public function test_coin_sniffer_finds_a_bonus_token_on_new_rungs(): void
    {
        $tokens = app(TokenService::class);
        $ladder = ArcadeService::milestonesFor(ArcadeGame::StackTheMess);
        $score = $ladder[3][0];
        $sniffer = $this->pet('Coins', ['pet_rarity' => 'rare', 'pet_knack' => 'coin_sniffer']);

        // Grown: one a new rung — three rungs past the first.
        $this->outOn($this->kid, $sniffer, 30);
        $payout = $tokens->payRun($this->kid->fresh(), ArcadeGame::StackTheMess, $score, null);
        $pet = collect($payout['lines'])->firstWhere('pet', true);

        $this->assertSame('🐾 Coin Sniffer', $pet['name']);
        $this->assertSame(3, $pet['tokens']);
        $this->assertSame(1 + 3 + 3, $payout['paid']);

        // Young: every other one, rounded up.
        $sibling = Profile::factory()->for($this->household)->create();
        $this->outOn($sibling, $sniffer, 12);
        $young = $tokens->payRun($sibling->fresh(), ArcadeGame::StackTheMess, $score, null);

        $this->assertSame(2, collect($young['lines'])->firstWhere('pet', true)['tokens']);

        // No new rung, nothing sniffed.
        $again = $tokens->payRun($this->kid->fresh(), ArcadeGame::StackTheMess, $score, $score);
        $this->assertNull(collect($again['lines'])->firstWhere('pet', true));
    }

    /** A 2x on the wheel, on a chore still to do: Fetch rolls the boost again. */
    public function test_fetch_rolls_a_2x_boost_again_on_the_same_chore(): void
    {
        $fetcher = $this->pet('Fetcher', ['pet_rarity' => 'rare', 'pet_knack' => 'fetch']);
        $this->outOn($this->kid, $fetcher, 30);
        $chore = Chore::factory()->for($this->household)->create();

        $spin = Spin::create([
            'profile_id' => $this->kid->id,
            'spin_date' => HouseholdClock::for($this->household)->today(),
            'chore_id' => $chore->id,
            'multiplier' => 2,
            'was_op' => false,
        ]);

        $kid = $this->kid->fresh();

        $this->assertNotNull($this->knacks()->fetchable($kid));

        $multiplier = $this->knacks()->fetch($kid);

        $this->assertContains($multiplier, [2, 3]);
        $this->assertSame($chore->id, $spin->fresh()->chore_id);
        $this->assertSame($multiplier, $spin->fresh()->multiplier);

        // One a week, grown.
        $spin->update(['multiplier' => 2]);
        $this->assertNull($this->knacks()->fetchable($kid));
        $this->assertNull($this->knacks()->fetch($kid));
    }

    public function test_fetch_is_not_offered_on_a_3x_or_a_chore_already_claimed(): void
    {
        $fetcher = $this->pet('Fetcher', ['pet_rarity' => 'rare', 'pet_knack' => 'fetch']);
        $this->outOn($this->kid, $fetcher, 30);
        $chore = Chore::factory()->for($this->household)->create();
        $today = HouseholdClock::for($this->household)->today();

        $spin = Spin::create(['profile_id' => $this->kid->id, 'spin_date' => $today, 'chore_id' => $chore->id, 'multiplier' => 3, 'was_op' => false]);
        $this->assertNull($this->knacks()->fetchable($this->kid->fresh()));

        $spin->update(['multiplier' => 2]);
        ChoreCompletion::create(['chore_id' => $chore->id, 'profile_id' => $this->kid->id, 'status' => 'pending', 'points_awarded' => 100, 'submitted_at' => now()]);
        $this->assertNull($this->knacks()->fetchable($this->kid->fresh()));
    }

    public function test_the_wheel_offers_fetch_and_the_pet_fetches(): void
    {
        $fetcher = $this->pet('Fetcher', ['pet_rarity' => 'rare', 'pet_knack' => 'fetch']);
        $this->outOn($this->kid, $fetcher, 30);
        $chore = Chore::factory()->for($this->household)->create();
        $spin = Spin::create(['profile_id' => $this->kid->id, 'spin_date' => HouseholdClock::for($this->household)->today(), 'chore_id' => $chore->id, 'multiplier' => 2, 'was_op' => false]);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertSee('data-fq-knack-offer="fetch"', false)
            ->assertSee('can fetch you another go at that boost.')
            ->call('useFetch')
            ->assertDontSee('data-fq-knack-offer="fetch"', false);

        $this->assertSame(1, PetKnackUse::where('knack', 'fetch')->count());
        $this->assertContains($spin->fresh()->multiplier, [2, 3]);
    }

    /** Chores on the board that could be the mystery chore, with one of them it. */
    private function mysteryBoard(int $count): Chore
    {
        Chore::factory()->for($this->household)->count($count)->create();
        app(ChoreService::class)->forgetBoards();

        return app(ChoreService::class)->mysteryChoreFor($this->household->fresh());
    }

    public function test_sniffer_narrows_the_mystery_chore_to_three_grown_and_five_young(): void
    {
        $mystery = $this->mysteryBoard(9);
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);

        $this->outOn($this->kid, $sniffy, 30);
        $grown = $this->knacks()->sniff($this->kid->fresh());

        $this->assertCount(3, $grown);
        $this->assertContains($mystery->id, $grown);
        $this->assertSame($grown, $this->knacks()->sniffedToday($this->kid->fresh()));
        // Once a day, even with a sniff left.
        $this->assertFalse($this->knacks()->sniffable($this->kid->fresh()));

        $sibling = Profile::factory()->for($this->household)->create();
        $this->outOn($sibling, $sniffy, 12);
        $young = $this->knacks()->sniff($sibling->fresh());

        $this->assertCount(5, $young);
        $this->assertContains($mystery->id, $young);

        // The marks are the sniffer's own.
        $third = Profile::factory()->for($this->household)->create();
        $this->assertNull($this->knacks()->sniffedToday($third));
    }

    /** With no more chores in the running than a sniff would leave, nothing is spent. */
    public function test_sniffer_is_not_offered_when_there_is_nothing_to_narrow(): void
    {
        $this->mysteryBoard(3);
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 30);

        $this->assertFalse($this->knacks()->sniffable($this->kid->fresh()));
        $this->assertNull($this->knacks()->sniff($this->kid->fresh()));
        $this->assertSame(0, PetKnackUse::count());
    }

    /** Every card is marked after a sniff — including chores that could never be it. */
    public function test_the_board_marks_every_card_after_a_sniff_and_clears_once_it_is_found(): void
    {
        $mystery = $this->mysteryBoard(8);
        // Could never be the mystery chore: age-locked.
        Chore::factory()->for($this->household)->create(['name' => 'Mow the lawn', 'min_age' => 5]);
        app(ChoreService::class)->forgetBoards();

        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 30);

        Auth::guard('profile')->login($this->kid->fresh());

        $page = Volt::test('kid.quests')
            ->assertSee('data-fq-knack-offer="sniffer"', false)
            ->call('useSniffer')
            ->assertDontSee('data-fq-knack-offer="sniffer"', false)
            ->assertSee('data-sniffed="3"', false);

        $html = $page->html();
        $this->assertSame(3, substr_count($html, 'data-sniff="maybe"'));
        $this->assertSame(Chore::count() - 3, substr_count($html, 'data-sniff="paw"'));

        // Found: nothing left to narrow, so the marks go.
        DailyMystery::where('household_id', $this->household->id)->update(['found_by_profile_id' => $this->kid->id, 'found_at' => now()]);
        $this->assertNull($this->knacks()->sniffedToday($this->kid->fresh()));
        $this->assertNotNull($mystery);
    }

    /* ------------------------------------------------------------------ *
     * The parent's Pets page
     * ------------------------------------------------------------------ */

    /** Pets split out of Cosmetics for grown-ups too: the same console, pets only. */
    public function test_the_parent_pets_page_shows_only_pets_and_every_kids_pet(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 12);
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Mae']);
        PetEgg::create(['household_id' => $this->household->id, 'profile_id' => $sibling->id, 'tickets_paid' => 15, 'cracks' => 2]);

        Auth::guard('profile')->login($this->parent);

        $this->get(route('parent.pets'))->assertOk()->assertSee("The kids' pets", false);

        Volt::test('parent.cosmetics', ['mode' => 'pets'])
            ->assertSet('slot', 'pet')
            ->assertSee('data-kid-pet="'.$this->kid->id.'"', false)
            ->assertSee('Sniffy · Young')
            ->assertSee('Sniffer:')
            ->assertSee('1 left')
            ->assertSee('An egg — 2 of 5 cracks')
            ->assertSee('data-pet-row-traits="'.$sniffy->id.'"', false)
            ->assertDontSee("\$set('slot', 'frame')", false);

        // And Cosmetics has no pets, only the way to them.
        Volt::test('parent.cosmetics')
            ->assertSee('data-pets-link', false)
            ->assertDontSee('data-kids-pets', false)
            ->assertDontSee("\$set('slot', 'pet')", false);
    }

    /* ------------------------------------------------------------------ *
     * The Epic knacks
     * ------------------------------------------------------------------ */

    /** A wheel of five chores, today's spin landed on the middle one at 3x. */
    private function spunWheel(): array
    {
        $wheel = Chore::factory()->for($this->household)->count(5)->create()->sortBy('id')->values();
        app(ChoreService::class)->forgetBoards();

        $spin = Spin::create([
            'profile_id' => $this->kid->id,
            'spin_date' => HouseholdClock::for($this->household)->today(),
            'chore_id' => $wheel[2]->id,
            'multiplier' => 3,
            'was_op' => false,
        ]);

        return [$wheel, $spin];
    }

    /** Grown: the kid picks the way, and the boost goes with it. */
    public function test_a_grown_paw_nudge_bats_the_wheel_the_way_the_kid_picks(): void
    {
        $nudger = $this->pet('Nudger', ['pet_rarity' => 'epic', 'pet_knack' => 'paw_nudge']);
        $this->outOn($this->kid, $nudger, 30);
        [$wheel, $spin] = $this->spunWheel();
        $kid = $this->kid->fresh();

        $targets = $this->knacks()->nudgeTargets($kid);
        $this->assertSame([$wheel[1]->id, $wheel[3]->id], [$targets['left']->id, $targets['right']->id]);

        $result = $this->knacks()->nudge($kid, 'right');

        $this->assertSame('right', $result['direction']);
        $this->assertSame($wheel[3]->id, $spin->fresh()->chore_id);
        $this->assertSame(3, $spin->fresh()->multiplier, 'The boost moved with it.');

        // One nudge a spin.
        $this->assertNull($this->knacks()->nudgeTargets($kid));
    }

    /** Young: it picks its own way, and it can be put back — the nudge is spent either way. */
    public function test_a_young_paw_nudge_picks_its_own_way_and_can_be_put_back(): void
    {
        $nudger = $this->pet('Nudger', ['pet_rarity' => 'epic', 'pet_knack' => 'paw_nudge']);
        $this->outOn($this->kid, $nudger, 12);
        [$wheel, $spin] = $this->spunWheel();
        $kid = $this->kid->fresh();

        $result = $this->knacks()->nudge($kid, 'left');

        $this->assertContains($spin->fresh()->chore_id, [$wheel[1]->id, $wheel[3]->id]);
        $this->assertContains($result['direction'], ['left', 'right']);

        $this->assertTrue($this->knacks()->unnudge($kid));
        $this->assertSame($wheel[2]->id, $spin->fresh()->chore_id);
        $this->assertFalse($this->knacks()->unnudge($kid), 'Put back twice.');
        $this->assertSame(1, $this->knacks()->stateFor($kid)['left']);
    }

    public function test_the_wheel_offers_paw_nudge_with_both_ways_for_a_grown_pet(): void
    {
        $nudger = $this->pet('Nudger', ['pet_rarity' => 'epic', 'pet_knack' => 'paw_nudge']);
        $this->outOn($this->kid, $nudger, 30);
        [$wheel, $spin] = $this->spunWheel();

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertSee('data-fq-knack-offer="paw_nudge"', false)
            ->assertSee('data-knack-choice="left"', false)
            ->assertSee('data-knack-choice="right"', false)
            ->call('useNudge', 'left')
            ->assertDontSee('data-fq-knack-offer="paw_nudge"', false);

        $this->assertSame($wheel[1]->id, $spin->fresh()->chore_id);
    }

    public function test_second_look_clears_the_spin_for_another_go(): void
    {
        $looker = $this->pet('Looker', ['pet_rarity' => 'epic', 'pet_knack' => 'second_look']);
        $this->outOn($this->kid, $looker, 30);
        [, $spin] = $this->spunWheel();

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertSee('data-fq-knack-offer="second_look"', false)
            ->call('useSecondLook')
            ->assertSet('spinRevealed', false);

        $this->assertNull($spin->fresh());
        $this->assertFalse($this->knacks()->secondLookable($this->kid->fresh()));
    }

    /** Lucky Tail charges the week's first spin by itself — never on top of a charge the kid bought. */
    public function test_lucky_tail_charges_the_weeks_first_spin_by_itself(): void
    {
        $tail = $this->pet('Wags', ['pet_rarity' => 'epic', 'pet_knack' => 'lucky_tail']);
        $this->outOn($this->kid, $tail, 30);
        Chore::factory()->for($this->household)->count(3)->create();
        app(ChoreService::class)->forgetBoards();
        $kid = $this->kid->fresh();

        Auth::guard('profile')->login($kid);
        Volt::test('kid.quests')->assertSee('data-lucky-tail-ready', false)->assertSee('4x is in play');

        $spin = app(SpinService::class)->spin($kid);

        $this->assertTrue($spin->was_op, 'A grown Lucky Tail spins on the OP table.');
        $this->assertTrue($this->knacks()->luckyTailOn($spin));
        $this->assertSame(0, $this->knacks()->stateFor($kid)['left']);

        // Spent for the week: the next spin is a plain one.
        $spin->delete();
        $this->assertFalse(app(SpinService::class)->spin($kid->fresh())->was_op);
    }

    public function test_lucky_tail_leaves_a_charge_the_kid_bought_alone(): void
    {
        $tail = $this->pet('Wags', ['pet_rarity' => 'epic', 'pet_knack' => 'lucky_tail']);
        $this->outOn($this->kid, $tail, 30);
        Chore::factory()->for($this->household)->count(3)->create();
        app(ChoreService::class)->forgetBoards();
        $this->kid->update(['op_spin_armed_at' => now()]);

        $spin = app(SpinService::class)->spin($this->kid->fresh());

        $this->assertTrue($spin->was_op);
        $this->assertFalse($this->knacks()->luckyTailOn($spin));
        $this->assertSame(1, $this->knacks()->stateFor($this->kid->fresh())['left']);
    }

    public function test_good_luck_charm_lights_five_grown_and_one_young(): void
    {
        Chore::factory()->for($this->household)->count(8)->create();
        app(ChoreService::class)->forgetBoards();
        $charmer = $this->pet('Charmer', ['pet_rarity' => 'epic', 'pet_knack' => 'good_luck_charm']);

        $this->outOn($this->kid, $charmer, 30);
        $this->assertCount(ChoreService::CHARM_CHORES, $this->knacks()->charm($this->kid->fresh()));

        $sibling = Profile::factory()->for($this->household)->create();
        $this->outOn($sibling, $charmer, 12);
        $this->assertCount(1, $this->knacks()->charm($sibling->fresh()));
        $this->assertNull($this->knacks()->charm($sibling->fresh()), 'Charmed twice in a week.');
    }

    public function test_digger_digs_a_free_hit_on_the_lucky_block(): void
    {
        LuckyPrize::factory()->for($this->household)->create(['name' => 'Pizza night']);
        $digger = $this->pet('Digger', ['pet_rarity' => 'epic', 'pet_knack' => 'digger']);
        $this->outOn($this->kid, $digger, 30);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.loot')
            ->assertSee('data-fq-knack-offer="digger"', false)
            ->call('useDigger')
            ->assertSee('Pizza night')
            ->assertDontSee('data-fq-knack-offer="digger"', false);

        $hit = LuckyHit::sole();
        $this->assertSame(0, $hit->tickets_spent);
        $this->assertSame(100, $this->kid->fresh()->bonus_tickets, 'A dug hit costs nothing.');
    }

    /* ------------------------------------------------------------------ *
     * The Legendary knacks
     * ------------------------------------------------------------------ */

    /** Two days earned, then a day missed: standing on the day after it. */
    private function brokenStreak(): void
    {
        $chore = Chore::factory()->for($this->household)->create(['points' => 0]);
        $chores = app(ChoreService::class);

        foreach ([0, 1] as $ignored) {
            $chores->approve($chores->claim($this->kid->fresh(), $chore), $this->parent);
            $this->travel(1)->days();
        }

        $this->travel(1)->days();
    }

    /** Guard Dog saves the streak by itself, on the way in — and Home says so, once. */
    public function test_guard_dog_saves_a_broken_streak_on_the_way_in(): void
    {
        $rex = $this->pet('Rex', ['pet_rarity' => 'legendary', 'pet_knack' => 'guard_dog']);
        $this->outOn($this->kid, $rex, 30);
        $this->brokenStreak();

        $this->actingAs($this->kid->fresh(), 'profile')
            ->get(route('kid.home'))
            ->assertOk()
            ->assertSee('Rex guarded your streak');

        $this->assertSame(3, $this->kid->fresh()->streak);
        $this->assertSame(0, $this->knacks()->stateFor($this->kid->fresh())['left']);

        // Told once.
        $this->actingAs($this->kid->fresh(), 'profile')->get(route('kid.home'))->assertDontSee('Rex guarded your streak');
    }

    public function test_guard_dog_does_nothing_without_a_use_left(): void
    {
        $rex = $this->pet('Rex', ['pet_rarity' => 'legendary', 'pet_knack' => 'guard_dog']);
        $this->outOn($this->kid, $rex, 12);
        // A young Guard Dog has one every other month — spent already.
        $this->knacks()->use($this->kid->fresh(), PetKnack::GuardDog);
        $this->brokenStreak();

        $this->assertFalse($this->knacks()->guardStreak($this->kid->fresh()));

        app(StreakService::class)->syncStreak($this->kid->fresh());
        $this->assertSame(0, $this->kid->fresh()->streak);
    }

    /** Night Owl saves the bedtime run the moment the night is answered. */
    public function test_night_owl_saves_the_bedtime_run(): void
    {
        $this->household->update(['sleep_card_enabled' => true]);
        $this->kid->update(['sleep_card_enabled' => true, 'age' => 6]);
        $owl = $this->pet('Hoot', ['pet_rarity' => 'legendary', 'pet_knack' => 'night_owl']);
        $this->outOn($this->kid, $owl, 30);
        $this->travelTo(now()->setTime(9, 0));

        $sleep = app(SleepService::class);

        foreach (range(1, 3) as $ignored) {
            $sleep->record($this->kid->fresh(), SleepOutcome::OwnBed);
            $this->travel(1)->days();
        }

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.quests')
            ->call('answerSleep', SleepOutcome::Visited->value)
            ->assertDispatched('celebrate', fn (string $name, array $params) => str_contains($params['message'], 'Hoot saved your run'));

        $this->assertSame(4, $this->kid->fresh()->sleep_run);
        // Said in the moment, so Home need not say it again.
        $this->assertSame([], $this->knacks()->takeRescues($this->kid->fresh()));
    }

    public function test_sidekick_makes_chores_hit_the_monster_harder(): void
    {
        $monster = app(MonsterService::class)->spawn($this->household, 'Weekend away', 10000);
        $buddy = $this->pet('Buddy', ['pet_rarity' => 'legendary', 'pet_knack' => 'sidekick']);
        $this->outOn($this->kid, $buddy, 30);

        $this->assertSame(10, $this->knacks()->sidekickPercentFor($this->kid->fresh()));

        $chore = Chore::factory()->for($this->household)->create(['points' => 100]);
        $chores = app(ChoreService::class);
        $completion = $chores->claim($this->kid->fresh(), $chore);
        $chores->approve($completion, $this->parent);

        // A tenth harder than the chore would have hit for — its payout,
        // doubled when it is the monster's weak point (the only chore here is).
        $awarded = $completion->fresh()->points_awarded;
        $base = $awarded * ($completion->fresh()->struck_weak_point ? MonsterService::WEAK_MULTIPLIER : 1);
        $this->assertSame((int) round($base * 1.1), (int) $monster->fresh()->hits()->sum('damage'));
        $this->assertSame($awarded, (int) $this->kid->fresh()->points, 'The kid\'s own points are untouched.');

        // A treat doubles it for the day.
        $this->knacks()->buyTreat($this->kid->fresh());
        $this->assertSame(20, $this->knacks()->sidekickPercentFor($this->kid->fresh()));
    }

    /* ------------------------------------------------------------------ *
     * Power Treats
     * ------------------------------------------------------------------ */

    /** A ticket under the perk the knack matches, at the house's own price for it. */
    public function test_a_power_treat_costs_a_ticket_less_than_the_perk_it_matches(): void
    {
        $knacks = $this->knacks();

        $this->assertSame(PerkEffect::WheelRespin->defaults()['cost'] - 1, $knacks->treatPrice($this->kid, PetKnack::Fetch));
        $this->assertSame(PerkEffect::MysteryHint->defaults()['cost'] - 1, $knacks->treatPrice($this->kid, PetKnack::Sniffer));
        $this->assertSame(PerkEffect::StreakRestore->defaults()['cost'] - 1, $knacks->treatPrice($this->kid, PetKnack::GuardDog));
        $this->assertSame(PerkEffect::OpSpin->defaults()['cost'] - 1, $knacks->treatPrice($this->kid, PetKnack::LuckyTail));

        // Never under a ticket, whatever the house charges for the perk.
        BonusPerk::updateOrCreate(
            ['household_id' => $this->household->id, 'effect' => PerkEffect::OpSpin],
            ['name' => 'OP Spin', 'description' => 'Charged.', 'cost' => 1, 'enabled' => true],
        );

        $this->assertSame(1, $knacks->treatPrice($this->kid, PetKnack::LuckyTail));
        // The Lucky Block stands in for Digger's perk.
        $this->assertSame(LuckyBlockService::TICKET_COST - 1, $knacks->treatPrice($this->kid, PetKnack::Digger));
        // Nothing to match: by tier.
        $this->assertSame(2, $knacks->treatPrice($this->kid, PetKnack::CoinSniffer));
        $this->assertSame(4, $knacks->treatPrice($this->kid, PetKnack::Sidekick));

        // The house's own price for the perk, not the default.
        BonusPerk::updateOrCreate(
            ['household_id' => $this->household->id, 'effect' => PerkEffect::WheelRespin],
            ['name' => 'Respin', 'description' => 'Again', 'cost' => 7, 'glyph' => '↻'],
        );

        $this->assertSame(6, $knacks->treatPrice($this->kid, PetKnack::Fetch));
    }

    /** A treat is one more use, banked until the week's own are gone. */
    public function test_a_power_treat_banks_an_extra_use_on_top_of_the_weeks(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 12);
        $kid = $this->kid->fresh();
        $price = $this->knacks()->treatPrice($kid, PetKnack::Sniffer);

        $this->knacks()->buyTreat($kid);

        $this->assertSame(100 - $price, $kid->fresh()->bonus_tickets);
        $state = $this->knacks()->stateFor($kid);
        $this->assertSame([2, 1], [$state['left'], $state['treats']]);

        // The week's own goes first; the treat waits.
        $this->knacks()->use($kid, PetKnack::Sniffer);
        $this->assertSame([1, 1], [$this->knacks()->stateFor($kid)['left'], $this->knacks()->stateFor($kid)['treats']]);

        // Then the treat.
        $this->assertTrue($this->knacks()->use($kid, PetKnack::Sniffer));
        $this->assertSame([0, 0], [$this->knacks()->stateFor($kid)['left'], $this->knacks()->stateFor($kid)['treats']]);
        $this->assertFalse($this->knacks()->use($kid, PetKnack::Sniffer));

        // The treat's use was never one of the week's: only one comes back.
        $this->travel(8)->days();
        $this->assertSame(1, $this->knacks()->stateFor($kid)['left']);
    }

    /** An always-on knack has nothing to spend: a treat doubles it for the day. */
    public function test_a_power_treat_doubles_an_always_on_knack_for_the_day(): void
    {
        $pockets = $this->pet('Pockets', ['pet_rarity' => 'rare', 'pet_knack' => 'big_pockets']);
        $this->outOn($this->kid, $pockets, 30);
        $kid = $this->kid->fresh();

        $this->assertSame(10, $this->knacks()->pocketsFor($kid));

        $this->knacks()->buyTreat($kid);

        $this->assertSame(20, $this->knacks()->pocketsFor($kid));
        $this->assertTrue($this->knacks()->stateFor($kid)['doubled']);

        $this->travel(1)->days();
        $this->assertSame(10, $this->knacks()->pocketsFor($kid));
    }

    public function test_a_baby_or_a_common_takes_no_treat(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 3);

        try {
            $this->knacks()->buyTreat($this->kid->fresh());
            $this->fail('A baby took a treat.');
        } catch (PerkUnavailableException $e) {
            $this->assertStringContainsString('still learning', $e->getMessage());
        }

        $this->outOn($this->kid, $this->pet('Plain'), 40);
        $this->expectException(PerkUnavailableException::class);
        $this->knacks()->buyTreat($this->kid->fresh());
    }

    public function test_the_pets_page_feeds_a_power_treat(): void
    {
        $fetcher = $this->pet('Fetcher', ['pet_rarity' => 'rare', 'pet_knack' => 'fetch']);
        $this->outOn($this->kid, $fetcher, 30);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->assertSee('data-power-treat', false)
            ->assertSee('Power Treat · 2 ✦')
            ->call('buyTreat')
            ->assertDispatched('fq-pet-treat')
            ->assertSee('from treats');

        $this->assertSame(1, PetTreat::count());

        // Out of tickets: said on the card, nothing bought.
        $this->kid->update(['bonus_tickets' => 0]);
        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->call('buyTreat')
            ->assertSee('Not enough tickets for a Power Treat yet.');

        $this->assertSame(1, PetTreat::count());
    }

    /** The pets page: everything about the pet, and swapping between them. */
    public function test_the_pets_page_shows_the_pet_its_style_in_the_arcade_and_swaps(): void
    {
        $rex = $this->pet('Rex', ['pet_style' => 'lucky']);
        $tabby = $this->pet('Tabby');
        $this->outOn($this->kid, $tabby, 0);
        $this->outOn($this->kid, $rex, 12);

        Auth::guard('profile')->login($this->kid->fresh());

        $this->get(route('kid.pets'))->assertOk()->assertSee('Pets');

        Volt::test('kid.pets')
            ->assertSee('data-pet-out', false)
            ->assertSee('data-pet-style-games', false)
            ->assertSee('catches your first miss')
            ->assertSee('data-owned-pet="'.$tabby->id.'"', false)
            ->call('wear', $tabby->id)
            ->assertSee('Tabby is out.');

        $this->assertSame($tabby->id, $this->kid->fresh()->worn_pet_id);
    }

    /** A baby can't use its perk yet, but the kid is told what it will do. */
    public function test_a_babys_perk_card_says_what_the_perk_will_do(): void
    {
        $sniffy = $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_style' => 'lucky', 'pet_knack' => 'sniffer']);
        $this->outOn($this->kid, $sniffy, 0);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->assertSee('Still learning')
            ->assertSee('narrows the Mystery Chore down to 5')
            ->assertSee('narrows the Mystery Chore down to 3')
            ->assertSee('Young · Once a week')
            ->assertSee('Grown up · 2 times a week')
            ->assertDontSee('Still learning this one')
            ->assertSee('Learns it in 10 more chores.')
            ->assertDontSee('data-power-treat', false);
    }

    /** Tip Jar: a ticket every other signed-off chore, by itself. */
    public function test_the_tip_jar_pays_a_ticket_every_other_chore_when_grown(): void
    {
        $tipper = $this->pet('Tipper', ['pet_rarity' => 'legendary', 'pet_knack' => 'tip_jar']);
        $this->outOn($this->kid, $tipper, 30);

        $this->approve(1);
        $this->assertSame(0, $this->tipped(), 'The first chore is not a tip.');

        $this->approve(1);
        $this->assertSame(1, $this->tipped());

        // And the rhythm starts again, rather than every chore paying from here.
        $this->approve(1);
        $this->assertSame(1, $this->tipped());
        $this->approve(1);
        $this->assertSame(2, $this->tipped());

        $entry = BonusTicketEntry::where('profile_id', $this->kid->id)
            ->where('kind', TicketKind::Pet)
            ->latest('id')
            ->firstOrFail();

        $this->assertStringContainsString('Tipper tipped you', $entry->description);
    }

    /** A young one tips half as often, and no treat is sold for it. */
    public function test_a_young_tip_jar_tips_every_fourth_chore_and_takes_no_treat(): void
    {
        $tipper = $this->pet('Tipper', ['pet_rarity' => 'legendary', 'pet_knack' => 'tip_jar']);
        $this->outOn($this->kid, $tipper, 12);

        $this->approve(3);
        $this->assertSame(0, $this->tipped());

        $this->approve(1);
        $this->assertSame(1, $this->tipped());

        // A treat costs more tickets than the jar would ever tip back, so
        // there is none to buy — see PetKnack::takesTreat().
        $this->assertFalse(PetKnack::TipJar->takesTreat());

        try {
            $this->knacks()->buyTreat($this->kid->fresh());
            $this->fail('A Tip Jar should not sell a Power Treat.');
        } catch (PerkUnavailableException $e) {
            $this->assertStringContainsString('pays in tickets already', $e->getMessage());
        }

        $this->assertSame(0, PetTreat::where('profile_id', $this->kid->id)->count());

        // And the tips keep their own rhythm, a ticket at a time.
        $this->approve(4);
        $this->assertSame(2, $this->tipped());
    }

    /** No pet out, or a baby, and nothing is tipped. */
    public function test_a_baby_tips_nothing(): void
    {
        $tipper = $this->pet('Tipper', ['pet_rarity' => 'legendary', 'pet_knack' => 'tip_jar']);
        $this->outOn($this->kid, $tipper, 0);

        $this->approve(6);

        $this->assertSame(0, $this->tipped());
        $this->assertSame(0, PetKnackUse::where('profile_id', $this->kid->id)->count());
    }

    /** The card sells no treat for it, and says why. */
    public function test_the_pets_page_offers_no_power_treat_for_a_tip_jar(): void
    {
        $tipper = $this->pet('Tipper', ['pet_rarity' => 'legendary', 'pet_knack' => 'tip_jar']);
        $this->outOn($this->kid, $tipper, 30);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->assertSee('data-pet-knack="tip_jar"', false)
            ->assertDontSee('data-power-treat', false)
            ->assertSee('data-no-treat', false)
            ->assertSee('it pays in tickets already');
    }

    /** Sure Paw: a grown pet puts the boost on any chore the kid points at. */
    public function test_sure_paw_puts_the_boost_on_the_chore_the_kid_picks(): void
    {
        $pointer = $this->pet('Pointer', ['pet_rarity' => 'legendary', 'pet_knack' => 'sure_paw']);
        $this->outOn($this->kid, $pointer, 30);
        $wheel = Chore::factory()->for($this->household)->count(4)->create();

        $spin = Spin::create([
            'profile_id' => $this->kid->id,
            'spin_date' => HouseholdClock::for($this->household)->today(),
            'chore_id' => $wheel->first()->id,
            'multiplier' => 2,
            'was_op' => false,
        ]);

        $kid = $this->kid->fresh();
        $targets = $this->knacks()->pawTargets($kid);

        // Every other chore on the wheel, for a grown pet.
        $this->assertCount(3, $targets);
        $this->assertNotContains($spin->chore_id, $targets->pluck('id')->all());

        $wanted = $targets->last();

        $this->assertSame($wanted->id, $this->knacks()->surePaw($kid, $wanted->id)?->id);
        $this->assertSame($wanted->id, $spin->fresh()->chore_id);
        // The boost itself is untouched — only which chore carries it.
        $this->assertSame(2, $spin->fresh()->multiplier);
        $this->assertSame($wanted->id, $this->knacks()->pawedToday($kid)['to']);

        // One point per spin.
        $this->assertNull($this->knacks()->pawTargets($kid->fresh()));
    }

    /** A young one narrows the wheel to three of its own choosing. */
    public function test_a_young_sure_paw_offers_three_chores_it_picked(): void
    {
        $pointer = $this->pet('Pointer', ['pet_rarity' => 'legendary', 'pet_knack' => 'sure_paw']);
        $this->outOn($this->kid, $pointer, 12);
        $wheel = Chore::factory()->for($this->household)->count(6)->create();

        Spin::create([
            'profile_id' => $this->kid->id,
            'spin_date' => HouseholdClock::for($this->household)->today(),
            'chore_id' => $wheel->first()->id,
            'multiplier' => 2,
            'was_op' => false,
        ]);

        $kid = $this->kid->fresh();
        $targets = $this->knacks()->pawTargets($kid);

        $this->assertCount(PetKnack::PAW_PICKS_YOUNG, $targets);
        // The same three every time the page is drawn, so nothing shuffles
        // under a kid deciding between them.
        $this->assertSame($targets->pluck('id')->all(), $this->knacks()->pawTargets($kid)->pluck('id')->all());

        // And a chore it did not offer is not a chore it will point at.
        $offered = $targets->pluck('id')->all();
        $other = $wheel->firstWhere(fn ($chore) => ! in_array($chore->id, $offered, true) && $chore->id !== $wheel->first()->id);

        $this->assertNull($this->knacks()->surePaw($kid, $other->id));
    }

    /** The wheel offer, on the page a kid actually spins on. */
    public function test_the_quest_board_offers_sure_paw_after_a_spin(): void
    {
        $pointer = $this->pet('Pointer', ['pet_rarity' => 'legendary', 'pet_knack' => 'sure_paw']);
        $this->outOn($this->kid, $pointer, 30);
        $wheel = Chore::factory()->for($this->household)->count(3)->create();

        Auth::guard('profile')->login($this->kid->fresh());

        app(SpinService::class)->spin($this->kid->fresh());

        Volt::test('kid.quests')
            ->assertSee('data-fq-knack-offer="sure_paw"', false)
            ->assertSee('can put the boost on the chore you pick');
    }

    /** How often, in a kid's words, straight from the allowance. */
    public function test_how_often_a_perk_goes_off_at_each_age(): void
    {
        $this->assertNull(PetKnack::Fetch->howOften(PetStage::Baby));
        $this->assertSame('Once every 2 weeks', PetKnack::Fetch->howOften(PetStage::Young));
        $this->assertSame('Once a week', PetKnack::Fetch->howOften(PetStage::Adult));
        $this->assertSame('2 times a week', PetKnack::PawNudge->howOften(PetStage::Adult));
        $this->assertSame('Once every 2 months, by itself', PetKnack::GuardDog->howOften(PetStage::Young));
        $this->assertSame('Always on', PetKnack::Sidekick->howOften(PetStage::Young));
    }

    /** The charm says how many chores, that they're marked, and what they pay. */
    public function test_the_charm_says_what_it_does_at_each_age(): void
    {
        $this->assertStringContainsString('Charms 1 chore', PetKnack::GoodLuckCharm->describe(PetStage::Young));
        $this->assertStringContainsString('Charms '.ChoreService::CHARM_CHORES.' chores', PetKnack::GoodLuckCharm->describe(PetStage::Adult));

        foreach ([PetStage::Young, PetStage::Adult] as $stage) {
            $this->assertStringContainsString('marked', PetKnack::GoodLuckCharm->describe($stage));
            $this->assertStringContainsString('50% more points today', PetKnack::GoodLuckCharm->describe($stage));
        }
    }

    /** Looking at a pet for sale shows what its style does in each game. */
    public function test_a_pet_for_sale_shows_its_style_in_every_game(): void
    {
        $bolt = $this->pet('Bolt', ['pet_style' => 'quick']);

        Auth::guard('profile')->login($this->kid->fresh());

        $page = Volt::test('kid.pets')
            ->assertDontSee('data-looking-style-games', false)
            ->call('look', $bolt->id)
            ->assertSee('data-looking-style-games', false)
            ->assertSee('Quick in the arcade');

        foreach (ArcadeGame::ranked() as $game) {
            $page->assertSee($game->styleHelp(PetStyle::Quick));
        }

        // Nothing to try out without a pet being looked at.
        $page->call('stopLooking')->call('tryOut', 'baby')->assertDontSee('data-pet-trial', false);
    }

    /**
     * An egg-only pet's shelf cost never reaches a kid — it is sold in its
     * shell at its tier's price — so that is the price the catalogue shows.
     */
    public function test_the_catalogue_prices_an_egg_only_pet_by_its_tier(): void
    {
        $glimmer = $this->pet('Glimmer', ['stock' => 'egg', 'pet_rarity' => 'epic', 'pet_knack' => 'paw_nudge', 'cost' => 23]);

        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        $this->assertSame(30, PetEgg::priceFor($glimmer));

        Volt::test('parent.cosmetics', ['mode' => 'pets'])
            ->assertSee('>30</span>', false)
            ->assertDontSee('>23</span>', false);
    }

    /** A grown-up sees every egg in the shop, what is in it, and who has it. */
    public function test_the_parent_pets_page_lists_the_eggs_and_what_is_in_them(): void
    {
        $glimmer = $this->pet('Glimmer', ['stock' => 'egg', 'pet_rarity' => 'epic', 'pet_style' => 'quick', 'pet_knack' => 'paw_nudge']);
        $plain = $this->pet('Plain', ['stock' => 'egg']);

        app(PetService::class)->buyEgg($this->kid, $plain);

        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.cosmetics', ['mode' => 'pets'])
            ->assertSee('data-egg-shelf', false)
            ->assertSee('data-egg-shelf-row="'.$glimmer->id.'"', false)
            // What is inside, which a kid never sees.
            ->assertSee('Glimmer')
            ->assertSee(PetKnack::PawNudge->label())
            ->assertSee('In the shop for')
            ->assertSee(PetEgg::priceFor($glimmer).' ✦')
            // And the one a kid is already cracking.
            ->assertSee($this->kid->name.' is cracking it — 0 of '.PetEgg::CRACKS_TO_HATCH);
    }

    /**
     * The Pets page explains all four styles, not only the one the kid has
     * out: a style name on a pet in the shop means nothing on its own.
     */
    public function test_the_pets_page_explains_every_style_in_every_game(): void
    {
        $rex = $this->pet('Rex', ['pet_style' => 'quick']);
        $this->outOn($this->kid, $rex, 30);

        Auth::guard('profile')->login($this->kid->fresh());

        $page = Volt::test('kid.pets')->assertSee('data-all-styles', false)->assertSee('What the styles do');

        foreach (PetStyle::cases() as $style) {
            $page->assertSee('data-style-row="'.$style->value.'"', false);
            $page->assertSee($style->blurb());

            foreach (ArcadeGame::ranked() as $game) {
                if ($help = $game->styleHelp($style)) {
                    $page->assertSee($help);
                }
            }
        }

        // Their own is the one named and marked.
        $page->assertSee('Yours')->assertSee('Rex '.ArcadeGame::StackTheMess->styleHelp(PetStyle::Quick));
    }

    /** And it is there with no pet out at all, which is when a kid is shopping. */
    public function test_the_styles_are_explained_with_no_pet_out(): void
    {
        $this->pet('Rex', ['pet_style' => 'quick']);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->assertSee('data-no-pet', false)
            ->assertSee('data-all-styles', false)
            ->assertSee(PetStyle::Lucky->blurb())
            ->assertDontSee('Yours');
    }

    /**
     * A pet that is only in the shop this week says so — on its tile and,
     * with how long is left, on the card that opens. A shelf pet says
     * nothing, because there is nothing to say.
     */
    public function test_the_shop_says_when_a_pet_is_only_here_this_week(): void
    {
        $rotating = $this->pet('Archie', ['stock' => 'weekly', 'pet_rarity' => 'rare', 'pet_knack' => 'big_pockets']);
        $limited = $this->pet('Comet', ['stock' => 'limited']);
        $always = $this->pet('Gabby');

        // Whatever the rotation picked this week, these two are in it.
        app(CosmeticService::class)->forget();

        Auth::guard('profile')->login($this->kid->fresh());

        $page = Volt::test('kid.pets');
        $html = $page->html();

        if (! str_contains($html, 'data-for-sale="'.$rotating->id.'"')) {
            $this->markTestSkipped('The rotation did not pick it this week.');
        }

        $page->assertSee('data-sale-stock="weekly"', false)
            ->assertDontSee('data-sale-stock="shelf"', false)
            ->call('look', $rotating->id)
            ->assertSee('data-looking-stock="weekly"', false)
            ->assertSee('In the shop this week')
            ->assertSee('it comes back another week');

        // A shelf pet has no week to worry about.
        $page->call('look', $always->id)->assertDontSee('data-looking-stock', false);

        if (str_contains($html, 'data-for-sale="'.$limited->id.'"')) {
            $page->call('look', $limited->id)
                ->assertSee('data-looking-stock="limited"', false)
                ->assertSee('This week only, ever.');
        }
    }

    /** A Common has a style and no perk — the page says so instead of hiding the card. */
    public function test_the_pets_page_says_a_common_has_no_perk(): void
    {
        $rex = $this->pet('Rex', ['pet_style' => 'steady']);
        $this->outOn($this->kid, $rex, 3);

        Auth::guard('profile')->login($this->kid->fresh());

        Volt::test('kid.pets')
            ->assertSee('data-pet-no-perk', false)
            ->assertSee('No perk')
            ->assertDontSee('data-pet-knack=', false)
            ->assertDontSee('data-power-treat', false)
            // Still told what its style is worth, perk or no perk.
            ->assertSee('data-style-row="steady"', false)
            ->assertSee('Yours');
    }

    /** The shop says what each pet brings, and an egg's confirm never names its perk. */
    public function test_the_pets_page_shop_shows_perk_lines_and_keeps_the_egg_a_surprise(): void
    {
        $this->pet('Sniffy', ['pet_rarity' => 'rare', 'pet_style' => 'lucky', 'pet_knack' => 'sniffer']);
        $this->pet('Plainy', ['pet_style' => 'big']);
        $this->pet('Glimmer', ['stock' => 'egg', 'pet_rarity' => 'epic', 'pet_style' => 'quick', 'pet_knack' => 'paw_nudge']);

        Auth::guard('profile')->login($this->kid->fresh());

        $html = Volt::test('kid.pets')
            ->assertSee('Lucky + '.PetKnack::Sniffer->label())
            ->assertDontSee('no perk')
            ->assertSee("It'll have a style and an Epic perk — which one is a surprise.")
            ->assertDontSee(PetKnack::PawNudge->label())
            ->assertSee(route('kid.trades'), false)
            ->html();

        $this->assertStringNotContainsString('Glimmer', $html);
    }

    /** Old pets get a style each, spread across the four rather than all the same. */
    public function test_old_pets_start_with_styles_spread_across_the_four(): void
    {
        $styles = array_map(fn (int $id) => PetStyle::startingFor($id), range(1, 8));

        $this->assertCount(4, array_unique(array_map(fn (PetStyle $style) => $style->value, $styles)));
    }
}
