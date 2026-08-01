<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TestNotificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_missing_vapid_keys(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        Profile::factory()->parent()->for(Household::factory())->create();

        $this->artisan('notifications:test', ['--check' => true])
            ->expectsOutputToContain('VAPID public key')
            ->expectsOutputToContain('webpush:vapid')
            ->assertFailed();
    }

    public function test_it_warns_when_the_queue_needs_a_worker(): void
    {
        // The failure mode with no symptom: the claim succeeds, the job is
        // stored, and nothing ever runs it.
        config(['queue.default' => 'database']);
        Profile::factory()->parent()->for(Household::factory())->create();

        $this->artisan('notifications:test', ['--check' => true])
            ->expectsOutputToContain('need a running worker');
    }

    public function test_it_fails_when_no_device_is_subscribed(): void
    {
        Profile::factory()->parent()->for(Household::factory())->create();

        $this->artisan('notifications:test')
            ->expectsOutputToContain('Nobody has subscribed a device yet')
            ->assertFailed();
    }

    public function test_it_fails_when_there_is_no_parent_at_all(): void
    {
        Profile::factory()->for(Household::factory())->create();

        $this->artisan('notifications:test')
            ->expectsOutputToContain('nobody to notify')
            ->assertFailed();
    }

    public function test_it_sends_to_a_subscribed_parent(): void
    {
        Notification::fake();

        $parent = Profile::factory()->parent()->for(Household::factory())->create();
        $parent->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'key', 'token');

        $this->artisan('notifications:test')->assertSuccessful();

        Notification::assertSentTo($parent, ParentApprovalNeeded::class);
    }

    public function test_it_skips_parents_with_no_subscribed_device(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $subscribed = Profile::factory()->parent()->for($household)->create(['name' => 'Alex']);
        $bare = Profile::factory()->parent()->for($household)->create(['name' => 'Sam']);
        $subscribed->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'key', 'token');

        $this->artisan('notifications:test')->assertSuccessful();

        Notification::assertSentTo($subscribed, ParentApprovalNeeded::class);
        Notification::assertNotSentTo($bare, ParentApprovalNeeded::class);
    }

    public function test_it_can_target_one_parent_by_name(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $alex = Profile::factory()->parent()->for($household)->create(['name' => 'Alex']);
        $sam = Profile::factory()->parent()->for($household)->create(['name' => 'Sam']);
        $alex->updatePushSubscription('https://fcm.googleapis.com/fcm/send/a', 'key', 'token');
        $sam->updatePushSubscription('https://fcm.googleapis.com/fcm/send/b', 'key', 'token');

        $this->artisan('notifications:test', ['--parent' => 'alex'])->assertSuccessful();

        Notification::assertSentTo($alex, ParentApprovalNeeded::class);
        Notification::assertNotSentTo($sam, ParentApprovalNeeded::class);
    }

    public function test_it_fails_on_an_unknown_parent_name(): void
    {
        Profile::factory()->parent()->for(Household::factory())->create(['name' => 'Alex']);

        $this->artisan('notifications:test', ['--parent' => 'Nobody'])->assertFailed();
    }

    public function test_check_sends_nothing(): void
    {
        Notification::fake();

        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);
        $parent = Profile::factory()->parent()->for(Household::factory())->create();
        $parent->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'key', 'token');

        $this->artisan('notifications:test', ['--check' => true])->assertSuccessful();

        Notification::assertNothingSent();
    }
}
