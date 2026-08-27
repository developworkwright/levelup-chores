<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    public function test_a_parent_can_subscribe_to_push_notifications(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('push-toggle', ['audience' => 'parent'])
            ->call('subscribeToPush', self::ENDPOINT, 'p256dh-key', 'auth-token');

        $this->assertCount(1, $parent->pushSubscriptions()->get());
        $this->assertSame(self::ENDPOINT, $parent->pushSubscriptions()->first()->endpoint);
    }

    public function test_a_parent_can_unsubscribe(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $parent->updatePushSubscription(self::ENDPOINT, 'p256dh-key', 'auth-token');

        Auth::guard('profile')->login($parent);

        Volt::test('push-toggle', ['audience' => 'parent'])
            ->call('unsubscribeFromPush', self::ENDPOINT);

        $this->assertCount(0, $parent->pushSubscriptions()->get());
    }

    public function test_a_kid_can_subscribe_from_the_same_component(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('push-toggle')
            ->call('subscribeToPush', self::ENDPOINT, 'p256dh-key', 'auth-token');

        $this->assertCount(1, $kid->pushSubscriptions()->get());
    }

    public function test_the_toggle_is_in_the_kid_header_on_every_page(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        $this->get(route('kid.home'))->assertSeeLivewire('push-toggle');
        $this->get(route('kid.trades'))->assertSeeLivewire('push-toggle');
    }

    public function test_the_toggle_is_still_on_the_parent_approvals_page(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        $this->get(route('parent.approvals'))->assertSeeLivewire('push-toggle');
    }

    public function test_a_signed_out_visitor_cannot_register_a_subscription(): void
    {
        Volt::test('push-toggle')
            ->call('subscribeToPush', self::ENDPOINT, 'p256dh-key', 'auth-token')
            ->assertForbidden();
    }

    public function test_a_subscription_this_profile_already_owns_reads_as_theirs(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $kid->updatePushSubscription(self::ENDPOINT, 'p256dh-key', 'auth-token');

        Auth::guard('profile')->login($kid);

        $this->assertSame(
            ['owner' => 'mine', 'name' => null],
            Volt::test('push-toggle')->call('describeSubscription', self::ENDPOINT)->effects['returns'][0],
        );
    }

    public function test_a_subscription_no_row_backs_reads_as_unowned_so_it_can_be_adopted(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        $this->assertSame(
            ['owner' => 'none', 'name' => null],
            Volt::test('push-toggle')->call('describeSubscription', self::ENDPOINT)->effects['returns'][0],
        );
    }

    /**
     * The regression this whole ownership check exists for.
     *
     * A subscription belongs to one browser, so adopting one is taking it. The
     * page used to do that on load whenever the current profile didn't own it,
     * which meant a kid signing in on a parent's phone silently killed the
     * approval alerts that phone was there for.
     */
    public function test_another_profiles_subscription_is_reported_rather_than_silently_taken(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create(['name' => 'Dad']);
        $kid = Profile::factory()->for($household)->create();
        $parent->updatePushSubscription(self::ENDPOINT, 'p256dh-key', 'auth-token');

        Auth::guard('profile')->login($kid);

        $this->assertSame(
            ['owner' => 'other', 'name' => 'Dad'],
            Volt::test('push-toggle')->call('describeSubscription', self::ENDPOINT)->effects['returns'][0],
        );

        $this->assertCount(1, $parent->pushSubscriptions()->get());
    }

    public function test_a_subscription_held_outside_the_household_is_not_named(): void
    {
        $stranger = Profile::factory()->parent()->for(Household::factory())->create(['name' => 'Someone Else']);
        $kid = Profile::factory()->for(Household::factory())->create();
        $stranger->updatePushSubscription(self::ENDPOINT, 'p256dh-key', 'auth-token');

        Auth::guard('profile')->login($kid);

        $this->assertSame(
            ['owner' => 'other', 'name' => null],
            Volt::test('push-toggle')->call('describeSubscription', self::ENDPOINT)->effects['returns'][0],
        );
    }

    public function test_taking_a_device_over_replaces_the_other_profiles_subscription(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $parent->updatePushSubscription(self::ENDPOINT, 'p256dh-key', 'auth-token');

        Auth::guard('profile')->login($kid);

        Volt::test('push-toggle')
            ->call('subscribeToPush', self::ENDPOINT, 'p256dh-key', 'auth-token');

        $this->assertCount(0, $parent->pushSubscriptions()->get());
        $this->assertCount(1, $kid->pushSubscriptions()->get());
    }
}
