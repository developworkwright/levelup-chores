<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Notifications\ChoreReviewed;
use App\Notifications\LootRestocked;
use App\Notifications\RedemptionDecided;
use App\Services\ChoreService;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The four things that happen to a kid while the app is shut, and which used
 * to reach them only if they happened to look.
 */
class KidNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_work_tells_the_kid_who_did_it(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Dishes', 'points' => 30]);

        $service = app(ChoreService::class);
        $completion = $service->claim($kid, $chore);
        $service->approve($completion, $parent);

        // Read back rather than assumed: approving is also what settles the
        // mystery bonus, and the number the kid is told about has to be the
        // one that actually landed in their balance, bonus and all.
        $awarded = $completion->refresh()->points_awarded;

        Notification::assertSentTo(
            $kid,
            ChoreReviewed::class,
            fn (ChoreReviewed $notification) => $notification->toWebPush($kid, $notification)->toArray()['body']
                === "+{$awarded} points for Dishes.",
        );

        Notification::assertNotSentTo($parent, ChoreReviewed::class);
    }

    /**
     * The mystery bonus is settled by the approval itself, so a notification
     * built any earlier in approve() would quote the chore's face value and
     * undersell the find by 500 points.
     */
    public function test_the_points_quoted_include_a_mystery_bonus_won_by_the_approval(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        // The household's only chore, so it is necessarily the day's mystery.
        $chore = Chore::factory()->for($household)->create(['name' => 'Dishes', 'points' => 30]);

        $service = app(ChoreService::class);
        $service->approve($service->claim($kid, $chore), $parent);

        $expected = 30 + ChoreService::MYSTERY_BONUS_POINTS;

        Notification::assertSentTo(
            $kid,
            ChoreReviewed::class,
            fn (ChoreReviewed $notification) => $notification->toWebPush($kid, $notification)->toArray()['body']
                === "+{$expected} points for Dishes.",
        );
    }

    public function test_sending_work_back_points_the_kid_at_the_board(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Bins']);

        $service = app(ChoreService::class);
        $completion = $service->claim($kid, $chore);
        $service->sendBack($completion, $parent);

        Notification::assertSentTo($kid, ChoreReviewed::class, function (ChoreReviewed $notification) use ($kid) {
            $message = $notification->toWebPush($kid, $notification)->toArray();

            return $message['body'] === 'Bins needs another go.'
                && $message['data']['url'] === '/kid/quests';
        });
    }

    public function test_new_loot_is_announced_to_every_kid_and_no_parent(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $one = Profile::factory()->for($household)->create();
        $two = Profile::factory()->for($household)->create();
        $elsewhere = Profile::factory()->for(Household::factory())->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.loot')
            ->set('newLootName', 'Movie Night')
            ->set('newLootCost', '250')
            ->call('addItem');

        Notification::assertSentTo([$one, $two], LootRestocked::class);
        Notification::assertNotSentTo([$parent, $elsewhere], LootRestocked::class);
    }

    public function test_a_locked_reward_is_still_announced(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['xp' => 0]);
        $item = StoreItem::factory()->for($household)->create(['min_level' => 20]);

        app(StoreService::class)->announceNewItem($item);

        // Announced, but the gate is said out loud: a parent sets it on the
        // form that fires this, so the alternative is a kid opening the shop
        // to find the thing they were told about greyed out.
        Notification::assertSentTo($kid, LootRestocked::class, function (LootRestocked $notification) use ($kid) {
            return str_contains(
                $notification->toWebPush($kid, $notification)->toArray()['body'],
                'Unlocks at level 20.',
            );
        });
    }

    public function test_an_open_reward_is_announced_without_a_level(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $item = StoreItem::factory()->for($household)->create([
            'name' => 'Skate deck',
            'cost' => 3000,
            'min_level' => 0,
        ]);

        app(StoreService::class)->announceNewItem($item);

        Notification::assertSentTo($kid, LootRestocked::class, function (LootRestocked $notification) use ($kid) {
            return $notification->toWebPush($kid, $notification)->toArray()['body'] === 'Skate deck — 3000 points.';
        });
    }

    public function test_handing_a_reward_over_tells_the_kid_it_is_ready(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 500]);
        $item = StoreItem::factory()->for($household)->create(['name' => 'Movie Night', 'cost' => 100]);

        $service = app(StoreService::class);
        $service->fulfill($service->redeem($kid, $item), $parent);

        Notification::assertSentTo(
            $kid,
            RedemptionDecided::class,
            fn (RedemptionDecided $notification) => $notification->toWebPush($kid, $notification)->toArray()['body']
                === 'Movie Night is yours — go and collect it.',
        );
    }

    public function test_turning_a_reward_down_carries_the_reason_and_the_refund(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 500]);
        $item = StoreItem::factory()->for($household)->create(['name' => 'Movie Night', 'cost' => 100]);

        $service = app(StoreService::class);
        $service->reject($service->redeem($kid, $item), $parent, 'school night');

        Notification::assertSentTo(
            $kid,
            RedemptionDecided::class,
            fn (RedemptionDecided $notification) => $notification->toWebPush($kid, $notification)->toArray()['body']
                === 'Movie Night was turned down — school night. Your 100 points are back.',
        );
    }

    public function test_turning_a_reward_down_without_a_reason_still_names_the_refund(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 500]);
        $item = StoreItem::factory()->for($household)->create(['name' => 'Movie Night', 'cost' => 100]);

        $service = app(StoreService::class);
        $service->reject($service->redeem($kid, $item), $parent);

        Notification::assertSentTo(
            $kid,
            RedemptionDecided::class,
            fn (RedemptionDecided $notification) => $notification->toWebPush($kid, $notification)->toArray()['body']
                === 'Movie Night was turned down. Your 100 points are back.',
        );
    }

    /**
     * Already fulfilled, so there is nothing to refund and reject() bails —
     * and a kid told their reward was turned down after it was handed over
     * would be the app contradicting itself.
     */
    public function test_a_reward_already_handed_over_cannot_be_turned_down_again(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 500]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 100]);

        $service = app(StoreService::class);
        $redemption = $service->redeem($kid, $item);
        $service->fulfill($redemption, $parent);

        $this->assertFalse($service->reject($redemption, $parent));

        Notification::assertSentToTimes($kid, RedemptionDecided::class, 1);
    }
}
