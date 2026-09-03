<?php

namespace Tests\Feature;

use App\Enums\AccentColor;
use App\Enums\Rank;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LoginProfilePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_a_kids_rank_rather_than_their_age(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create([
            'name' => 'Nova',
            'age' => 12,
            'xp' => Profile::xpToReachLevel(12),
        ]);

        Volt::test('login')
            ->assertSee('Nova')
            ->assertSee(Rank::Bonebreaker->label())
            ->assertDontSee('Age 12');
    }

    public function test_a_brand_new_kid_wears_the_first_rank(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Scout', 'xp' => 0]);

        Volt::test('login')->assertSee(Rank::Prowler->label());
    }

    public function test_the_level_is_shown_but_not_the_bar_to_the_next_one(): void
    {
        // The bar took the eye before the level did. It came off this screen
        // deliberately while the level stayed and got louder — if the bar
        // comes back, it should be a decision rather than a drift.
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create([
            'name' => 'Nova',
            'xp' => Profile::xpToReachLevel(12) + 100,
        ]);

        Volt::test('login')
            ->assertSee('LVL 12')
            ->assertDontSee('h-[6px] w-full overflow-hidden rounded-full', false);
    }

    public function test_each_tile_wears_the_kids_streak(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'streak' => 4]);

        Volt::test('login')->assertSee('4d');
    }

    public function test_a_kid_with_no_run_going_is_not_told_so(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova', 'streak' => 0]);

        // The chip itself, not the "0d" it would read: Livewire's checksum is
        // hex and lands on that pair often enough to fail the test at random.
        Volt::test('login')->assertDontSee('fq-avatar-chip');
    }

    public function test_the_parent_is_a_console_link_rather_than_an_avatar_tile(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Rowan']);

        // The console is a door for grown-ups, not one of the avatars to pick —
        // so it sits below the row under a neutral label, and a parent gets no
        // rank or streak of their own.
        Volt::test('login')
            ->assertSee('Grown-ups')
            ->assertSee('Console')
            ->assertDontSee('Rowan')
            ->assertDontSee(Rank::Prowler->label());
    }

    public function test_the_console_links_wrap_instead_of_squeezing_the_rule_flat(): void
    {
        // Two nowrap links sharing one row with a flex-1 rule between them left
        // the rules nothing to occupy on a phone: they collapsed to zero and
        // the links ran edge to edge. One rule on its own line, links wrapping
        // beneath it.
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Rowan']);
        Profile::factory()->parent()->for($household)->create(['name' => 'Sage']);

        $html = Volt::test('login')->html();

        $this->assertSame(1, substr_count($html, 'bg-fq-line'));
        $this->assertStringContainsString('flex flex-wrap items-center justify-center', $html);
    }

    public function test_several_parents_are_each_named_so_they_can_be_told_apart(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->parent()->for($household)->create(['name' => 'Rowan']);
        Profile::factory()->parent()->for($household)->create(['name' => 'Sage']);

        // "Grown-ups" is only unambiguous while there's one of them.
        Volt::test('login')
            ->assertSee('Rowan')
            ->assertSee('Sage')
            ->assertDontSee('Grown-ups');
    }

    public function test_the_lilac_kid_is_dealt_into_the_middle_of_the_row(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Alder', 'age' => 13, 'color' => AccentColor::Cyan]);
        Profile::factory()->for($household)->create(['name' => 'Birch', 'age' => 8, 'color' => AccentColor::Lime]);
        Profile::factory()->for($household)->create(['name' => 'Cedar', 'age' => 6, 'color' => AccentColor::Gold]);

        $html = Volt::test('login')->html();

        // Plain oldest-first would seat Lime next to Gold, and the two yellows
        // read as one wide tile. Pulling the lilac kid into the middle splits
        // them: Birch, Alder, Cedar.
        $this->assertLessThan(strpos($html, 'Alder'), strpos($html, 'Birch'));
        $this->assertLessThan(strpos($html, 'Cedar'), strpos($html, 'Alder'));
    }

    public function test_a_lone_kid_sits_straight(): void
    {
        // The fan divides by one less than the count, so a single tile is the
        // case that would blow up on a naive implementation.
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Nova']);

        Volt::test('login')
            ->assertOk()
            ->assertSee('--fq-tilt: 0deg', false);
    }

    public function test_age_is_still_stored_because_chore_gating_needs_it(): void
    {
        // Only the label changed — min_age gating reads this column, so it
        // has to survive being taken off the picker.
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['age' => 9]);

        $this->assertSame(9, $kid->refresh()->age);
    }
}
