<?php

namespace Tests\Feature;

use App\Enums\AccentColor;
use App\Enums\ChoreIcon;
use App\Enums\LootCategory;
use App\Enums\RedemptionStatus;
use App\Models\Household;
use App\Models\LootFavorite;
use App\Models\Profile;
use App\Models\Redemption;
use App\Models\StoreItem;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Making the Loot Shop something a child can look at rather than read.
 *
 * The shop had grown past the point where a wall of name-and-paragraph cards
 * works: the kids stopped shopping, and new rewards were never found because
 * finding anything meant reading everything.
 */
class LootBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create([
            'name' => 'Nova',
            'points' => 5000,
        ]);
    }

    private function item(array $attributes = []): StoreItem
    {
        return StoreItem::factory()->for($this->household)->create($attributes);
    }

    private function store(): StoreService
    {
        return app(StoreService::class);
    }

    // --- New ---------------------------------------------------------------

    public function test_a_kid_who_has_never_looked_sees_the_whole_shop_as_new(): void
    {
        $this->item(['name' => 'Cinema trip']);

        // Null marker means "never looked", which is the right answer for a
        // kid opening a restocked shop for the first time.
        $this->assertNull($this->kid->loot_seen_at);
        $this->assertSame(1, $this->store()->newCountFor($this->kid));
    }

    public function test_the_rail_badge_counts_what_has_landed_since_they_looked(): void
    {
        $this->item(['name' => 'Old news']);
        $this->store()->markShopSeen($this->kid);

        $this->assertSame(0, $this->store()->newCountFor($this->kid->refresh()));

        $this->travel(1)->days();
        $this->item(['name' => 'Cinema trip']);

        // The number on the Spend tab: seen before the shop is, which is the
        // only place a kid who doesn't read the shelves notices a restock.
        $this->assertSame(1, $this->store()->newCountFor($this->kid->refresh()));
    }

    public function test_opening_the_shop_clears_the_badge_but_keeps_the_chips_up(): void
    {
        $this->item(['name' => 'Cinema trip']);

        Auth::guard('profile')->login($this->kid);

        $page = Volt::test('kid.loot')
            ->assertSee('New in')
            ->assertSee('Since you last looked');

        // Marked seen on arrival, so the tab stops shouting...
        $this->assertSame(0, $this->store()->newCountFor($this->kid->refresh()));

        // ...but the chips are snapshotted, so tapping something mid-visit
        // doesn't strip the page of the very thing it was showing.
        $page->call('setView', 'price')->assertSee('New in');
    }

    public function test_a_second_visit_no_longer_calls_it_new(): void
    {
        $this->item(['name' => 'Cinema trip']);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')->assertSee('New in');
        Volt::test('kid.loot')->assertDontSee('Since you last looked');
    }

    // --- Categories --------------------------------------------------------

    public function test_the_shop_opens_grouped_by_kind_not_by_price(): void
    {
        $this->item(['name' => 'Ice cream run', 'category' => LootCategory::Treats]);

        Auth::guard('profile')->login($this->kid);

        // "What sort of thing do I want" is the question a kid who won't read
        // the shop actually has; price answers a different one.
        Volt::test('kid.loot')
            ->assertSee('Treats')
            ->assertSee('Something to eat')
            ->assertDontSee('Treat yourself');
    }

    public function test_the_price_view_is_still_reachable(): void
    {
        $this->item(['name' => 'Ice cream run', 'cost' => 100, 'category' => LootCategory::Treats]);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')
            ->call('setView', 'price')
            ->assertSee('Treat yourself')
            ->assertDontSee('Something to eat');
    }

    public function test_an_unfiled_reward_is_still_reachable(): void
    {
        $this->item(['name' => 'Mystery box', 'category' => null]);

        Auth::guard('profile')->login($this->kid);

        // Filing is optional, so the category view must not quietly hide the
        // part of the shop nobody has sorted.
        Volt::test('kid.loot')
            ->assertSee('Everything else')
            ->assertSee('Mystery box');
    }

    public function test_a_reward_is_filed_from_its_own_words_when_it_is_added(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->set('newLootName', 'Cinema trip')
            ->set('newLootDesc', 'Pick any film')
            ->set('newLootCost', '2000')
            ->call('addItem');

        $this->assertSame(LootCategory::Outings, StoreItem::firstWhere('name', 'Cinema trip')->category);
    }

    public function test_a_reward_nothing_matches_is_left_unfiled(): void
    {
        // An item in the wrong pile is worse than one under "Everything else":
        // a kid hunting for it looks in the pile it should be in and concludes
        // it isn't there.
        $this->assertNull(LootCategory::forText('A thoughtful gesture'));
    }

    public function test_a_keyword_inside_a_longer_word_does_not_file_anything(): void
    {
        // Same trap the chore icons hit: 'park' is inside 'sparkly'.
        $this->assertNotSame(LootCategory::Outings, LootCategory::forText('Sparkly hairband'));
    }

    // --- Favorites --------------------------------------------------------

    public function test_a_kid_can_star_and_unstar_a_reward(): void
    {
        $item = $this->item(['name' => 'Cinema trip']);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')->call('toggleFavorite', $item->id);
        $this->assertSame(1, LootFavorite::where('profile_id', $this->kid->id)->count());

        Volt::test('kid.loot')->call('toggleFavorite', $item->id);
        $this->assertSame(0, LootFavorite::where('profile_id', $this->kid->id)->count());
    }

    public function test_a_kid_cannot_star_another_households_reward(): void
    {
        $theirs = StoreItem::factory()->for(Household::factory()->create())->create();

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')->call('toggleFavorite', $theirs->id);

        $this->assertSame(0, LootFavorite::count());
    }

    public function test_stars_are_per_kid_not_per_household(): void
    {
        $sibling = Profile::factory()->for($this->household)->create(['name' => 'Rex']);
        $item = $this->item();

        $this->store()->toggleFavorite($this->kid, $item);

        // A shared list would let one sibling curate the other's shop.
        $this->assertSame([$item->id => true], $this->store()->favoriteIdsFor($this->kid));
        $this->assertSame([], $this->store()->favoriteIdsFor($sibling));
    }

    public function test_repeat_purchases_are_counted_without_anyone_starring_anything(): void
    {
        $item = $this->item(['name' => 'Ice cream run', 'cost' => 100]);

        foreach (range(1, 3) as $ignored) {
            Redemption::create([
                'profile_id' => $this->kid->id,
                'store_item_id' => $item->id,
                'cost_snapshot' => 100,
                'status' => RedemptionStatus::Fulfilled,
                'requested_at' => now(),
            ]);
        }

        // The signal that needs nothing taught and no taps.
        $this->assertSame([$item->id => 3], $this->store()->boughtBeforeFor($this->kid));
    }

    public function test_a_pending_or_rejected_request_is_not_evidence_of_anything(): void
    {
        $item = $this->item(['cost' => 100]);

        foreach ([RedemptionStatus::Pending, RedemptionStatus::Rejected] as $status) {
            Redemption::create([
                'profile_id' => $this->kid->id,
                'store_item_id' => $item->id,
                'cost_snapshot' => 100,
                'status' => $status,
                'requested_at' => now(),
            ]);
        }

        // A wish not yet granted, and a wish turned down.
        $this->assertSame([], $this->store()->boughtBeforeFor($this->kid));
    }

    public function test_starred_and_repeat_bought_rewards_are_pinned_above_the_shelves(): void
    {
        $starred = $this->item(['name' => 'Cinema trip']);
        $this->item(['name' => 'Something else']);

        $this->store()->toggleFavorite($this->kid, $starred);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')
            ->assertSee('Yours')
            ->assertSee('Starred &amp; bought before', escape: false);
    }

    public function test_the_yours_shelf_is_absent_when_there_is_nothing_in_it(): void
    {
        $this->item();

        Auth::guard('profile')->login($this->kid);

        // An empty "favorites" heading is just something else to scroll past.
        Volt::test('kid.loot')->assertDontSee('Starred &amp; bought before', escape: false);
    }

    // --- Links -------------------------------------------------------------

    public function test_a_reward_with_a_link_offers_it_and_one_without_does_not(): void
    {
        $this->item(['name' => 'Lego set', 'url' => 'https://lego.com/thing']);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')
            ->assertSee('Have a look')
            ->assertSee('https://lego.com/thing');
    }

    public function test_a_reward_without_a_link_grows_no_button(): void
    {
        $this->item(['name' => 'Stay up late', 'url' => null]);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.loot')->assertDontSee('Have a look');
    }

    /** @return array<int, array{0: ?string, 1: ?string}> */
    public static function urlCases(): array
    {
        return [
            ['https://lego.com/thing', 'https://lego.com/thing'],
            ['http://lego.com/thing', 'http://lego.com/thing'],
            // What people actually paste.
            ['lego.com/thing', 'https://lego.com/thing'],
            ['  lego.com/thing  ', 'https://lego.com/thing'],
            ['', null],
            ['   ', null],
            // The whole reason this is sanitised on the way in: a parent types
            // it, a child taps it, and the family shares one tablet.
            ['javascript:alert(1)', null],
            ['JavaScript:alert(1)', null],
            ['data:text/html,<script>alert(1)</script>', null],
            ['file:///etc/passwd', null],
            // A scheme with nothing behind it is a typo, not a destination.
            ['https://', null],
        ];
    }

    #[DataProvider('urlCases')]
    public function test_a_pasted_link_is_reduced_to_something_safe(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, StoreItem::normalizeUrl($input));
    }

    public function test_a_parent_setting_a_dangerous_link_stores_nothing(): void
    {
        $item = $this->item(['url' => null]);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')->call('setItemUrl', $item->id, 'javascript:alert(1)');

        $this->assertNull($item->fresh()->url);
    }

    public function test_the_link_field_is_on_the_row_not_behind_the_picture_picker(): void
    {
        $item = $this->item(['url' => 'https://lego.com/thing']);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        Auth::guard('profile')->login($parent);

        // Without ever calling toggleItemEditor: pasting a link has nothing to
        // do with picking an icon, and it shouldn't need that editor opened.
        Volt::test('parent.loot')
            ->assertSee('setItemUrl('.$item->id.', $event.target.value)', escape: false)
            ->assertSee('https://lego.com/thing')
            // The icon and category pickers stay shut until asked for.
            ->assertDontSee('Shelf &middot; how the kids find it', escape: false);
    }

    public function test_a_parent_can_set_a_pile_a_picture_and_a_link(): void
    {
        $item = $this->item(['category' => null, 'icon' => null, 'url' => null]);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('setItemCategory', $item->id, 'treats')
            ->call('setItemIcon', $item->id, 'fa-ice-cream')
            ->call('setItemUrl', $item->id, 'lego.com/thing');

        $fresh = $item->fresh();

        $this->assertSame(LootCategory::Treats, $fresh->category);
        // Normalised the same way chore icons are — a bare name gains a style.
        $this->assertSame('fa-solid fa-ice-cream', $fresh->icon);
        $this->assertSame('https://lego.com/thing', $fresh->url);

        // Tapping the current one again clears it.
        Volt::test('parent.loot')->call('setItemCategory', $item->id, 'treats');
        $this->assertNull($item->fresh()->category);
    }

    public function test_a_parent_cannot_touch_another_households_reward(): void
    {
        $theirs = StoreItem::factory()->for(Household::factory()->create())->create(['url' => null]);
        $parent = Profile::factory()->parent()->for($this->household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('setItemUrl', $theirs->id, 'https://example.com')
            ->call('setItemCategory', $theirs->id, 'treats');

        $this->assertNull($theirs->fresh()->url);
        $this->assertNull($theirs->fresh()->category);
    }

    // --- Creating ----------------------------------------------------------

    /**
     * The whole reason these fields moved onto the create form: adding a
     * reward sends a push to every kid in the household, so what the reward
     * looks like when the button is tapped is what they are told to come and
     * look at. There is no tidying up afterwards.
     */
    public function test_a_parent_can_set_every_field_when_they_add_a_reward(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->set('newLootName', 'Skate deck')
            ->set('newLootDesc', 'Pick your own graphic')
            ->set('newLootCost', '3000')
            ->set('newLootColor', 'cyan')
            ->set('newLootUrl', 'skate.com/decks')
            ->call('setNewLootCategory', 'things')
            ->call('setNewLootIcon', 'fa-star')
            ->call('adjustNewLootMinLevel', 10)
            ->call('addItem');

        $item = StoreItem::firstWhere('name', 'Skate deck');

        $this->assertSame(LootCategory::Things, $item->category);
        $this->assertSame('fa-solid fa-star', $item->icon);
        $this->assertSame('https://skate.com/decks', $item->url);
        $this->assertSame(10, $item->min_level);
        $this->assertSame(AccentColor::Cyan, $item->color_tag);
        $this->assertSame(3000, $item->cost);
    }

    public function test_a_reward_gets_a_face_from_its_own_words_when_nobody_picks_one(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        // The keyword pass still runs, so a parent who fills in the two fields
        // they care about doesn't ship a faceless card to a push notification.
        Volt::test('parent.loot')
            ->set('newLootName', 'New toy')
            ->call('addItem');

        $this->assertSame(ChoreIcon::Toys->faClass(), StoreItem::firstWhere('name', 'New toy')->icon);
    }

    public function test_a_picked_pile_and_face_beat_the_guess(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        // 'Cinema' would file this under Days out on its own.
        Volt::test('parent.loot')
            ->set('newLootName', 'Cinema snacks')
            ->call('setNewLootCategory', 'treats')
            ->call('addItem');

        $this->assertSame(LootCategory::Treats, StoreItem::firstWhere('name', 'Cinema snacks')->category);
    }

    public function test_tapping_a_chosen_pile_or_face_again_hands_it_back_to_the_guess(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('setNewLootCategory', 'treats')
            ->call('setNewLootCategory', 'treats')
            ->assertSet('newLootCategory', '')
            ->call('setNewLootIcon', 'fa-solid fa-star')
            ->call('setNewLootIcon', 'fa-solid fa-star')
            ->assertSet('newLootIcon', '');
    }

    public function test_a_typed_class_previews_before_the_reward_exists(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        // Typing a class from memory is choosing blind — nothing else on the
        // form shows the face, and by the time the reward exists the push has
        // gone out. A bare name previews with the style the normaliser adds.
        Volt::test('parent.loot')
            ->set('newLootIcon', 'fa-bicycle')
            ->assertSee('fa-fw fa-solid fa-bicycle', escape: false);
    }

    public function test_a_class_with_nothing_usable_in_it_previews_as_a_question_mark(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->set('newLootIcon', 'not a class')
            ->assertSee('Type a class to see it here before the kids do.')
            ->assertDontSee('fa-fw fa-not', escape: false);
    }

    public function test_a_half_typed_class_is_not_saved_as_the_face(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        // The box is bound live so the preview can follow it, which means it
        // holds junk as often as a finished class. Junk falls back to the
        // guess rather than landing in a class attribute.
        Volt::test('parent.loot')
            ->set('newLootName', 'New toy')
            ->set('newLootIcon', 'fa-')
            ->call('addItem');

        $this->assertSame(ChoreIcon::Toys->faClass(), StoreItem::firstWhere('name', 'New toy')->icon);
    }

    public function test_a_dangerous_link_on_the_create_form_stores_nothing(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->set('newLootName', 'Sketchy')
            ->set('newLootUrl', 'javascript:alert(1)')
            ->call('addItem');

        $this->assertNull(StoreItem::firstWhere('name', 'Sketchy')->url);
    }

    public function test_the_level_gate_cannot_be_stepped_below_open(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('adjustNewLootMinLevel', -5)
            ->assertSet('newLootMinLevel', 0);
    }

    public function test_the_form_resets_to_auto_after_a_reward_is_added(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        // A picture or a gate left over from the last reward is worse than the
        // guess — the next one goes out on a push with somebody else's face.
        Volt::test('parent.loot')
            ->set('newLootName', 'Skate deck')
            ->set('newLootUrl', 'skate.com/decks')
            ->call('setNewLootIcon', 'fa-star')
            ->call('setNewLootCategory', 'things')
            ->call('adjustNewLootMinLevel', 5)
            ->call('addItem')
            ->assertSet('newLootIcon', '')
            ->assertSet('newLootCategory', '')
            ->assertSet('newLootUrl', '')
            ->assertSet('newLootMinLevel', 0);
    }

    public function test_a_quick_idea_clears_what_the_last_one_left_behind(): void
    {
        $parent = Profile::factory()->parent()->for($this->household)->create();
        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->call('setNewLootIcon', 'fa-star')
            ->call('adjustNewLootMinLevel', 5)
            ->call('fillPreset', 0)
            ->assertSet('newLootIcon', '')
            ->assertSet('newLootMinLevel', 0)
            ->assertSet('newLootName', 'Lego set');
    }
}
