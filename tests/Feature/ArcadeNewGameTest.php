<?php

namespace Tests\Feature;

use App\Enums\ArcadeGame;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\ArcadeGameAdded;
use App\Services\ArcadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Telling the kids a game has arrived.
 *
 * Nothing about the arcade ever comes looking for anybody — a chore nags, a
 * sibling's swap arrives, a monster loses health, and the arcade just sits
 * there being a thing you have to remember. So a game added quietly is a game
 * nobody plays, and this is the two halves of fixing that: a flash in the app
 * for the kid who opens it, and a push for the kid who doesn't.
 */
class ArcadeNewGameTest extends TestCase
{
    use RefreshDatabase;

    private function arcade(): ArcadeService
    {
        return app(ArcadeService::class);
    }

    /**
     * A kid who was last in the arcade the day before the newest drop landed.
     *
     * Dated off `newest()` rather than off a named game, so what these tests
     * assert stays "the games that arrived since my last visit" instead of
     * "Windy Walkies" — which is the same sentence today and a broken test the
     * next time anything is added.
     */
    private function returningKid(string $name = 'Nova', ?Household $household = null): Profile
    {
        return Profile::factory()
            ->for($household ?? Household::factory())
            ->create([
                'name' => $name,
                'arcade_seen_at' => ArcadeGame::newest()->releasedOn()->subDay(),
            ]);
    }

    /**
     * The games that landed in the newest drop — everything sharing the newest
     * release date, in the order the rail flashes them.
     *
     * @return list<ArcadeGame>
     */
    private function newestDrop(): array
    {
        $latest = ArcadeGame::newest()->releasedOn();

        return collect(ArcadeGame::cases())
            ->filter(fn (ArcadeGame $game) => $game->releasedOn()->equalTo($latest))
            ->sortByDesc(fn (ArcadeGame $game) => $game->releasedOn())
            ->values()
            ->all();
    }

    public function test_a_game_added_since_a_kid_last_looked_is_new_to_them(): void
    {
        $kid = $this->returningKid();

        $new = $this->arcade()->newGamesFor($kid);

        // The whole of the last drop, and nothing from the ones before it.
        $this->assertEquals($this->newestDrop(), $new->all());
        $this->assertSame(count($this->newestDrop()), $this->arcade()->newCountFor($kid));
    }

    public function test_a_kid_who_has_never_been_finds_every_game_new(): void
    {
        // Null is a profile that has never opened the arcade, and the same
        // reading `loot_seen_at` gets: all of it is news to them.
        $kid = Profile::factory()->for(Household::factory())->create(['arcade_seen_at' => null]);

        $this->assertSame(count(ArcadeGame::cases()), $this->arcade()->newCountFor($kid));
    }

    public function test_the_newest_unseen_game_is_the_one_that_gets_opened(): void
    {
        // Two unseen games should land a kid on the one that just arrived, not
        // the one that arrived first.
        $kid = Profile::factory()->for(Household::factory())->create(['arcade_seen_at' => null]);

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')->assertSet('game', ArcadeGame::newest());
    }

    public function test_the_flash_survives_the_visit_it_was_meant_for(): void
    {
        /*
         * The marker is stamped on mount, so a page reading it live would clear
         * the flash before the kid it was for had looked up. The snapshot taken
         * a line earlier is what holds it for the visit — the same ordering the
         * Loot Shop's "new" chips need.
         */
        $kid = $this->returningKid();

        Auth::guard('profile')->login($kid);

        Volt::test('arcade')
            ->assertSet('newGames', array_map(fn (ArcadeGame $game) => $game->value, $this->newestDrop()))
            ->assertSee('New');

        // And stamped, so the next visit is quiet.
        $this->assertNotNull($kid->fresh()->arcade_seen_at);
        $this->assertSame(0, $this->arcade()->newCountFor($kid->fresh()));
    }

    public function test_the_flash_is_gone_by_the_next_visit(): void
    {
        $kid = $this->returningKid();

        Auth::guard('profile')->login($kid);

        Volt::test('arcade');
        Volt::test('arcade')->assertSet('newGames', []);
    }

    public function test_a_new_game_is_pushed_to_every_kid_in_the_house(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $kids = collect(['Nova', 'Rook'])->map(fn (string $name) => $this->returningKid($name, $household));
        $parent = Profile::factory()->for($household)->parent()->create(['name' => 'Dad']);

        $told = $this->arcade()->announceNewGame($household, ArcadeGame::WindyWalkies);

        $this->assertSame(2, $told);

        foreach ($kids as $kid) {
            Notification::assertSentTo($kid, ArcadeGameAdded::class);
        }

        // Grown-ups put the game there and can see the switcher on their own
        // console; a push telling them about it would be telling them nothing.
        Notification::assertNotSentTo($parent, ArcadeGameAdded::class);
    }

    public function test_one_houses_announcement_does_not_reach_another(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $mine = $this->returningKid('Nova', $household);
        $theirs = $this->returningKid('Rook');

        $this->arcade()->announceNewGame($household, ArcadeGame::WindyWalkies);

        Notification::assertSentTo($mine, ArcadeGameAdded::class);
        Notification::assertNotSentTo($theirs, ArcadeGameAdded::class);
    }

    public function test_the_announce_command_defaults_to_the_newest_game(): void
    {
        Notification::fake();

        $household = Household::factory()->create(['name' => 'Wright']);
        $kid = $this->returningKid('Nova', $household);

        $this->artisan('arcade:announce')
            ->expectsOutputToContain(ArcadeGame::newest()->label())
            ->assertSuccessful();

        Notification::assertSentTo($kid, ArcadeGameAdded::class);
    }

    public function test_the_announce_command_sends_nothing_on_a_dry_run(): void
    {
        Notification::fake();

        $household = Household::factory()->create(['name' => 'Wright']);
        $this->returningKid('Nova', $household);

        $this->artisan('arcade:announce --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_announce_command_refuses_a_game_that_does_not_exist(): void
    {
        Notification::fake();

        $this->artisan('arcade:announce not_a_game')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_every_game_declares_when_it_went_in(): void
    {
        // The whole announcement hangs off these dates, and a game added
        // without one would be new to nobody, silently and forever.
        $seen = [];

        foreach (ArcadeGame::cases() as $game) {
            $seen[] = $game->releasedOn()->toDateString();
        }

        $this->assertSame($seen, array_unique($seen), 'Two games claim the same release date.');
        $this->assertSame(ArcadeGame::SlimeTime, ArcadeGame::newest());
    }

    public function test_no_game_is_dated_into_the_future(): void
    {
        /*
         * A release date ahead of today is newer than any `arcade_seen_at`
         * marker, so its game is new to everybody on every visit and the flash
         * never clears — the marker is stamped on mount and is *still* older
         * than the date.
         *
         * Worth a test of its own because a future date is a tempting way out
         * of the rule above: two games landing in one drop cannot share a date,
         * and post-dating one of them looks like the obvious fix right up until
         * every kid is told it is new on every visit.
         */
        foreach (ArcadeGame::cases() as $game) {
            $this->assertTrue(
                $game->releasedOn()->lessThanOrEqualTo(now()),
                $game->label().' is dated in the future, so its "new" flash can never clear.'
            );
        }
    }
}
