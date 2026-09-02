<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ChoreReviewed;
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

    public function test_it_fails_when_there_are_no_profiles_at_all(): void
    {
        $this->artisan('notifications:test')
            ->expectsOutputToContain('nobody to notify')
            ->assertFailed();
    }

    public function test_it_sends_to_a_subscribed_kid(): void
    {
        Notification::fake();

        $kid = Profile::factory()->for(Household::factory())->create();
        $kid->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'key', 'token');

        $this->artisan('notifications:test')->assertSuccessful();

        Notification::assertSentTo($kid, ChoreReviewed::class);
    }

    /**
     * A kid sent to /parent/home is bounced by the role middleware, which
     * looks exactly like the broken delivery this command exists to rule out.
     */
    public function test_each_role_gets_a_notification_pointing_somewhere_they_can_go(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $kid = Profile::factory()->for($household)->create();
        $parent->updatePushSubscription('https://fcm.googleapis.com/fcm/send/a', 'key', 'token');
        $kid->updatePushSubscription('https://fcm.googleapis.com/fcm/send/b', 'key', 'token');

        $this->artisan('notifications:test')->assertSuccessful();

        Notification::assertSentTo($parent, ParentApprovalNeeded::class);
        Notification::assertNotSentTo($parent, ChoreReviewed::class);
        Notification::assertSentTo($kid, ChoreReviewed::class);
        Notification::assertNotSentTo($kid, ParentApprovalNeeded::class);
    }

    public function test_it_can_target_one_kid_by_name(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $mia = Profile::factory()->for($household)->create(['name' => 'Mia']);
        $noah = Profile::factory()->for($household)->create(['name' => 'Noah']);
        $mia->updatePushSubscription('https://fcm.googleapis.com/fcm/send/a', 'key', 'token');
        $noah->updatePushSubscription('https://fcm.googleapis.com/fcm/send/b', 'key', 'token');

        $this->artisan('notifications:test', ['--kid' => 'mia'])->assertSuccessful();

        Notification::assertSentTo($mia, ChoreReviewed::class);
        Notification::assertNotSentTo($noah, ChoreReviewed::class);
    }

    public function test_it_refuses_to_target_a_parent_and_a_kid_at_once(): void
    {
        $this->artisan('notifications:test', ['--parent' => 'Alex', '--kid' => 'Mia'])
            ->expectsOutputToContain('not both')
            ->assertFailed();
    }

    /** A kid's name is not a parent's, and the error has to say which it looked for. */
    public function test_it_fails_on_an_unknown_kid_name(): void
    {
        Profile::factory()->for(Household::factory())->create(['name' => 'Mia']);

        $this->artisan('notifications:test', ['--kid' => 'Nobody'])
            ->expectsOutputToContain('No kid named')
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
