<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\ArcadeScore;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repairing a run the app threw away.
 *
 * The penguin slide's ceiling was 4000 and a kid landed 7659m; the score was
 * refused, the row was never written, and removing the ceiling does not bring
 * it back. This is how it gets put where it always belonged.
 */
class ArcadePostScoreCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_run(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Westin']);

        $this->artisan('arcade:post-score', [
            'kid' => 'Westin',
            'game' => ArcadeGame::PenguinLaunch->value,
            'score' => 7659,
        ])->assertSuccessful();

        $run = ArcadeScore::sole();

        $this->assertSame(7659, $run->score);
        $this->assertSame(ArcadeGame::PenguinLaunch, $run->game);
        $this->assertSame('Westin', $run->codename);
    }

    public function test_the_name_is_not_case_sensitive(): void
    {
        Profile::factory()->create(['name' => 'Westin']);

        $this->artisan('arcade:post-score', [
            'kid' => 'westin',
            'game' => ArcadeGame::PenguinLaunch->value,
            'score' => 7659,
        ])->assertSuccessful();

        $this->assertSame(1, ArcadeScore::count());
    }

    public function test_it_can_file_the_run_under_the_week_it_was_played(): void
    {
        Profile::factory()->create(['name' => 'Westin']);

        $this->artisan('arcade:post-score', [
            'kid' => 'Westin',
            'game' => ArcadeGame::PenguinLaunch->value,
            'score' => 7659,
            '--week' => '2026-W36',
        ])->assertSuccessful();

        $this->assertSame('2026-W36', ArcadeScore::sole()->week);
    }

    public function test_it_refuses_an_unknown_kid(): void
    {
        $this->artisan('arcade:post-score', [
            'kid' => 'Nobody',
            'game' => ArcadeGame::PenguinLaunch->value,
            'score' => 100,
        ])->assertFailed();

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_it_refuses_a_toy(): void
    {
        Profile::factory()->create(['name' => 'Westin']);

        $toy = ArcadeGame::toys()[0] ?? null;

        if ($toy === null) {
            $this->markTestSkipped('No toys in the arcade to check.');
        }

        $this->artisan('arcade:post-score', [
            'kid' => 'Westin',
            'game' => $toy->value,
            'score' => 100,
        ])->assertFailed();

        $this->assertSame(0, ArcadeScore::count());
    }

    public function test_it_refuses_a_run_worth_nothing(): void
    {
        Profile::factory()->create(['name' => 'Westin']);

        $this->artisan('arcade:post-score', [
            'kid' => 'Westin',
            'game' => ArcadeGame::PenguinLaunch->value,
            'score' => 0,
        ])->assertFailed();

        $this->assertSame(0, ArcadeScore::count());
    }
}
