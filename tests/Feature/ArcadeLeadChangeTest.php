<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ArcadeLeadLost;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Telling somebody they have been knocked off a board.
 *
 * Beating a sibling's score is the most interesting thing this app produces,
 * and until now the sibling found out whenever they next happened to open the
 * arcade — by which point it is a fact rather than a challenge. The push is
 * sent to the person who *lost* the lead, not the one who took it: the winner
 * watched it happen, and the one with a reason to come back is the loser.
 */
class ArcadeLeadChangeTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    /**
     * @return array{0:Household,1:Profile,2:Profile}
     */
    private function twoKids(): array
    {
        $household = Household::factory()->create();

        return [
            $household,
            Profile::factory()->for($household)->create(['name' => 'Rowan']),
            Profile::factory()->for($household)->create(['name' => 'Wren']),
        ];
    }

    public function test_it_tells_the_old_leader_they_lost_the_board(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();
        $game = ArcadeGame::default();

        $this->arcade()->post($rowan, $game, 100);
        $this->arcade()->post($wren, $game, 150);

        Notification::assertSentTo($rowan, ArcadeLeadLost::class);
        Notification::assertNotSentTo($wren, ArcadeLeadLost::class);
    }

    public function test_the_first_run_of_the_week_dethrones_nobody(): void
    {
        Notification::fake();

        [, $rowan] = $this->twoKids();

        $this->arcade()->post($rowan, ArcadeGame::default(), 100);

        Notification::assertNothingSent();
    }

    public function test_beating_your_own_score_does_not_notify_you(): void
    {
        Notification::fake();

        [, $rowan] = $this->twoKids();
        $game = ArcadeGame::default();

        $this->arcade()->post($rowan, $game, 100);
        $this->arcade()->post($rowan, $game, 300);

        Notification::assertNothingSent();
    }

    /**
     * A tie keeps the incumbent — the rule boardFor() applies and beatTarget()
     * prints — so an equal score has taken nothing and must say nothing.
     */
    public function test_a_tie_is_not_a_takeover(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();
        $game = ArcadeGame::default();

        $this->arcade()->post($rowan, $game, 100);
        $this->arcade()->post($wren, $game, 100);

        Notification::assertNothingSent();
    }

    public function test_a_lower_score_is_not_a_takeover(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();
        $game = ArcadeGame::default();

        $this->arcade()->post($rowan, $game, 100);
        $this->arcade()->post($wren, $game, 40);

        Notification::assertNothingSent();
    }

    /**
     * Two kids trading a board back and forth for an hour is the best evening
     * this app can produce and also the fastest way to make a family mute it.
     */
    public function test_it_does_not_buzz_the_same_person_twice_in_a_row(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();
        $game = ArcadeGame::default();

        $this->arcade()->post($rowan, $game, 100);
        $this->arcade()->post($wren, $game, 150);

        // Rowan retakes it and is immediately knocked off again. The second
        // loss lands inside the quiet window and must not buzz.
        $this->arcade()->post($rowan, $game, 200);
        $this->arcade()->post($wren, $game, 250);

        Notification::assertSentToTimes($rowan, ArcadeLeadLost::class, 1);
    }

    /**
     * The throttle is per game, so a busy evening on one cabinet cannot
     * silence the news from another.
     */
    public function test_the_quiet_window_is_per_game(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();

        $this->arcade()->post($rowan, ArcadeGame::WindyWalkies, 10);
        $this->arcade()->post($wren, ArcadeGame::WindyWalkies, 20);

        $this->arcade()->post($rowan, ArcadeGame::StackTheMess, 10);
        $this->arcade()->post($wren, ArcadeGame::StackTheMess, 20);

        Notification::assertSentToTimes($rowan, ArcadeLeadLost::class, 2);
    }

    /**
     * A grown-up can top a board — they just cannot be paid for it — and being
     * quietly dethroned is the same non-event for them as for anybody else.
     */
    public function test_a_parent_who_loses_the_lead_is_told(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);
        $game = ArcadeGame::default();

        $this->arcade()->post($parent, $game, 100);
        $this->arcade()->post($kid, $game, 150);

        Notification::assertSentTo($parent, ArcadeLeadLost::class);
    }

    /**
     * A toy keeps no score and reaches no board, so there is no lead on it to
     * lose.
     */
    public function test_a_toy_notifies_nobody(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();
        $toy = ArcadeGame::toys()[0] ?? null;

        if ($toy === null) {
            $this->markTestSkipped('No toys in the arcade to check.');
        }

        $this->arcade()->post($rowan, $toy, 100);
        $this->arcade()->post($wren, $toy, 150);

        Notification::assertNothingSent();
    }

    /**
     * The message has to carry the number that takes the board back, and that
     * is one past the run which just landed — not one past the score it beat.
     */
    public function test_the_message_names_the_number_that_retakes_the_board(): void
    {
        Notification::fake();

        [, $rowan, $wren] = $this->twoKids();
        $game = ArcadeGame::default();

        $this->arcade()->post($rowan, $game, 100);
        $this->arcade()->post($wren, $game, 150);

        Notification::assertSentTo($rowan, ArcadeLeadLost::class, function (ArcadeLeadLost $notification) use ($rowan): bool {
            $push = $notification->toWebPush($rowan, $notification);

            return str_contains($push->toArray()['body'], 'Wren')
                && str_contains($push->toArray()['body'], '151');
        });
    }
}
