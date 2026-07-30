<?php

namespace Tests\Feature;

use App\Enums\RedemptionStatus;
use App\Exceptions\InsufficientPointsException;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Notifications\ParentApprovalNeeded;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_redeeming_deducts_points_immediately_and_creates_a_pending_redemption(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['points' => 200]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 100]);

        $redemption = app(StoreService::class)->redeem($kid, $item);

        $this->assertSame(100, $kid->refresh()->points);
        $this->assertSame(RedemptionStatus::Pending, $redemption->status);
        $this->assertSame(100, $redemption->cost_snapshot);
    }

    public function test_redeeming_without_enough_points_throws_with_the_correct_shortfall(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['points' => 60]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 100]);

        try {
            app(StoreService::class)->redeem($kid, $item);
            $this->fail('Expected InsufficientPointsException.');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(40, $e->shortfall);
        }

        $this->assertSame(60, $kid->refresh()->points);
    }

    public function test_fulfilling_a_redemption_does_not_change_the_balance_again(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 200]);
        $item = StoreItem::factory()->for($household)->create(['cost' => 100]);

        $service = app(StoreService::class);
        $redemption = $service->redeem($kid, $item);
        $service->fulfill($redemption, $parent);

        $this->assertSame(100, $kid->refresh()->points);
        $this->assertSame(RedemptionStatus::Fulfilled, $redemption->refresh()->status);
        $this->assertNotNull($redemption->fulfilled_at);
    }

    public function test_redeeming_notifies_parents_of_the_household_but_not_other_kids(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create(['points' => 200]);
        $otherKid = Profile::factory()->for($household)->create();
        $item = StoreItem::factory()->for($household)->create(['cost' => 100]);

        app(StoreService::class)->redeem($kid, $item);

        Notification::assertSentTo($parent, ParentApprovalNeeded::class);
        Notification::assertNotSentTo($otherKid, ParentApprovalNeeded::class);
        Notification::assertNotSentTo($kid, ParentApprovalNeeded::class);
    }
}
