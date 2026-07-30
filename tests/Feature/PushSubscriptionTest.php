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

    public function test_a_parent_can_subscribe_to_push_notifications_from_the_approvals_page(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.approvals')
            ->call('subscribeToPush', 'https://fcm.googleapis.com/fcm/send/abc123', 'p256dh-key', 'auth-token');

        $this->assertCount(1, $parent->pushSubscriptions()->get());
        $this->assertSame('https://fcm.googleapis.com/fcm/send/abc123', $parent->pushSubscriptions()->first()->endpoint);
    }

    public function test_a_parent_can_unsubscribe(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $parent->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc123', 'p256dh-key', 'auth-token');

        Auth::guard('profile')->login($parent);

        Volt::test('parent.approvals')
            ->call('unsubscribeFromPush', 'https://fcm.googleapis.com/fcm/send/abc123');

        $this->assertCount(0, $parent->pushSubscriptions()->get());
    }
}
