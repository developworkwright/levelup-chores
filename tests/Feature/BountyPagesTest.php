<?php

namespace Tests\Feature;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\CompletionStatus;
use App\Enums\TradeAsset;
use App\Models\Bounty;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BountyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The jobs half of the merged Trades & Jobs page, plus the parent's side
 * of it. Swaps are covered by {@see KidTradesPageTest}.
 */
class BountyPagesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Nova', 'points' => 400]);
        $this->sibling = Profile::factory()->for($this->household)->create(['name' => 'Scout', 'points' => 400]);
        Chore::factory()->for($this->household)->create();
    }

    private function loginKid(Profile $kid): void
    {
        Auth::guard('profile')->login($kid);
    }

    private function postJob(BountyKind $kind, string $description, int $amount = 100, ?Profile $target = null): Bounty
    {
        return app(BountyService::class)->post(
            $this->kid, $kind, TradeAsset::Points, $amount, $description, $target,
        );
    }

    public function test_a_kid_can_post_a_job_to_the_whole_house(): void
    {
        $this->loginKid($this->kid);

        Volt::test('kid.trades')
            ->assertOk()
            ->call('choose', 'wanted')
            ->set('jobDescription', 'Make my bed')
            ->set('jobAsset', 'points')
            ->set('jobAmount', 100)
            ->call('postJob')
            ->assertSee('Posted to the board!');

        $bounty = Bounty::where('poster_profile_id', $this->kid->id)->firstOrFail();

        $this->assertNull($bounty->target_profile_id);
        $this->assertSame(300, $this->kid->fresh()->points);
    }

    public function test_a_kid_can_aim_a_job_at_one_sibling(): void
    {
        $this->loginKid($this->kid);

        Volt::test('kid.trades')
            ->assertOk()
            ->call('choose', 'wanted')
            ->set('jobDescription', 'Make my bed')
            ->set('jobAmount', 100)
            ->call('postJob', $this->sibling->id)
            ->assertSee('Sent to Scout');

        $this->assertSame(
            $this->sibling->id,
            Bounty::where('poster_profile_id', $this->kid->id)->value('target_profile_id'),
        );
    }

    public function test_a_targeted_job_is_hidden_from_everybody_else(): void
    {
        $third = Profile::factory()->for($this->household)->create(['name' => 'Ziggy', 'points' => 400]);

        $this->postJob(BountyKind::Wanted, 'Make my bed', target: $this->sibling);

        // The two of them are doing a deal. It is nobody else's business, and
        // it must not sit on the board looking takeable.
        $this->loginKid($third);
        Volt::test('kid.trades')->assertOk()->assertDontSee('Make my bed');

        $this->loginKid($this->sibling);
        Volt::test('kid.trades')->assertOk()->assertSee('Make my bed')->assertSee('Just for you');
    }

    public function test_a_kid_a_job_is_not_aimed_at_cannot_take_it(): void
    {
        $third = Profile::factory()->for($this->household)->create(['points' => 400]);
        $bounty = $this->postJob(BountyKind::Wanted, 'Make my bed', target: $this->sibling);

        $this->loginKid($third);

        Volt::test('kid.trades')
            ->call('takeJob', $bounty->id)
            ->assertSee('meant for somebody else');

        $this->assertNull($bounty->fresh()->claimed_by_profile_id);
    }

    public function test_a_sibling_sees_an_open_job_and_can_take_it(): void
    {
        $bounty = $this->postJob(BountyKind::Wanted, 'Make my bed');

        $this->loginKid($this->sibling);

        Volt::test('kid.trades')
            ->assertOk()
            ->assertSee('Make my bed')
            ->call('takeJob', $bounty->id);

        $this->assertSame($this->sibling->id, $bounty->fresh()->claimed_by_profile_id);
    }

    public function test_the_poster_does_not_see_their_own_job_as_takeable(): void
    {
        $this->postJob(BountyKind::Wanted, 'Make my bed');

        $this->loginKid($this->kid);

        Volt::test('kid.trades')
            ->assertOk()
            ->assertSee('Take it back')
            ->assertDontSee("I'll do it");
    }

    public function test_a_losing_race_says_so_instead_of_failing(): void
    {
        $bounty = $this->postJob(BountyKind::Wanted, 'Make my bed');

        $third = Profile::factory()->for($this->household)->create(['points' => 400]);
        app(BountyService::class)->claim($bounty, $third);

        $this->loginKid($this->sibling);

        // A silently no-oping button reads as broken, and every action here can
        // lose a race with a sibling.
        Volt::test('kid.trades')
            ->call('takeJob', $bounty->id)
            ->assertSee('no longer up for grabs');
    }

    public function test_the_shell_counts_only_deals_this_kid_can_act_on(): void
    {
        $service = app(BountyService::class);

        // Waiting on the sibling to do the work, not on this kid.
        $mine = $this->postJob(BountyKind::Wanted, 'Make my bed');
        $service->claim($mine, $this->sibling);

        $this->loginKid($this->kid);
        Volt::test('kid.trades')->assertOk()->assertDontSee('waiting on you');

        // Now it is reported done, so it is this kid's move.
        $service->markDone($mine->fresh(), $this->sibling);

        $this->loginKid($this->kid);
        Volt::test('kid.trades')->assertOk()->assertSee('waiting on you');
    }

    public function test_a_parent_sees_offers_of_work_and_can_hire_at_their_own_price(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $this->postJob(BountyKind::Offered, 'Wash the car', 500);

        Auth::guard('profile')->login($parent);

        $bounty = Bounty::firstOrFail();

        Volt::test('parent.home')
            ->assertOk()
            ->assertSee('Jobs On Offer')
            ->assertSee('Wash the car')
            ->set("hirePrices.{$bounty->id}", 250)
            ->call('hire', $bounty->id)
            ->assertSee('Hired Nova');

        $this->assertSame(BountyStatus::Hired, $bounty->fresh()->status);

        $completion = ChoreCompletion::where('profile_id', $this->kid->id)->firstOrFail();
        $this->assertSame(250, $completion->points_awarded);
        $this->assertSame(CompletionStatus::Pending, $completion->status);
    }

    public function test_a_kid_can_put_something_up_for_sale(): void
    {
        $this->loginKid($this->kid);

        Volt::test('kid.trades')
            ->assertOk()
            ->assertSee('Sell something')
            ->call('choose', 'selling')
            ->assertSee('What are you selling?')
            ->set('jobDescription', 'My blue Lego set')
            ->set('jobAmount', '200')
            ->call('postJob')
            ->assertSee('Posted to the board!');

        $bounty = Bounty::firstOrFail();

        $this->assertSame(BountyKind::Selling, $bounty->kind);
        $this->assertSame('My blue Lego set', $bounty->description);
    }

    public function test_the_board_offers_a_sale_as_something_to_buy(): void
    {
        $this->postJob(BountyKind::Selling, 'My blue Lego set', 200);

        $this->loginKid($this->sibling);

        Volt::test('kid.trades')
            ->assertOk()
            ->assertSee('Is selling')
            ->assertSee('Buy it')
            // The buyer is the one paying, so the card prices it against them.
            ->assertSee('You pay');
    }

    public function test_a_parent_is_not_offered_a_sale_to_hire(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $this->postJob(BountyKind::Selling, 'My blue Lego set', 200);

        Auth::guard('profile')->login($parent);

        // Hiring it would put "My blue Lego set" on the chore board.
        Volt::test('parent.home')
            ->assertOk()
            ->assertDontSee('Jobs On Offer')
            ->assertDontSee('My blue Lego set');
    }

    public function test_a_parent_is_not_offered_a_job_aimed_at_a_sibling(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $this->postJob(BountyKind::Offered, 'Wash the car', 200, target: $this->sibling);

        Auth::guard('profile')->login($parent);

        // Taking it would be hijacking a deal between two kids.
        Volt::test('parent.home')
            ->assertOk()
            ->assertDontSee('Jobs On Offer')
            ->assertDontSee('Wash the car');
    }

    public function test_a_parent_is_not_offered_jobs_a_kid_wants_doing(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $this->postJob(BountyKind::Wanted, 'Make my bed');

        Auth::guard('profile')->login($parent);

        // That is just a chore, and chores already exist.
        Volt::test('parent.home')
            ->assertOk()
            ->assertDontSee('Jobs On Offer')
            ->assertDontSee('Make my bed');
    }

    public function test_a_hired_job_lands_in_the_ordinary_approval_queue(): void
    {
        $parent = Profile::factory()->for($this->household)->parent()->create();
        $bounty = $this->postJob(BountyKind::Offered, 'Wash the car', 200);

        app(BountyService::class)->hire($bounty, $parent);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.home')
            ->assertOk()
            ->assertSee('Chore Approvals')
            ->assertSee('Wash the car');
    }
}
