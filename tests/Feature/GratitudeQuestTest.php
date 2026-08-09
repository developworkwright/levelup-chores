<?php

namespace Tests\Feature;

use App\Enums\TicketKind;
use App\Models\BonusTicketEntry;
use App\Models\Chore;
use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Services\GratitudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GratitudeQuestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pinned to midday because the household day rolls over at 4am, not
     * midnight. Fixtures here date themselves with now()->subDays(), so a suite
     * run between midnight and 4am put "yesterday" on the household's *today* —
     * the entry meant to prove old days stay off this page turned up on it, and
     * the test failed for reasons that had nothing to do with the code.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gratitude(): GratitudeService
    {
        return app(GratitudeService::class);
    }

    private function kid(array $attributes = []): Profile
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->create(['quest_eligible' => true]);

        return Profile::factory()->for($household)->create($attributes);
    }

    /** @return array<int, string> */
    private function threeThings(): array
    {
        return ['My dog', 'Pancakes', 'Grandma calling'];
    }

    public function test_three_things_bank_three_tickets(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        $entry = $this->gratitude()->record($kid, $this->threeThings());

        $this->assertNotNull($entry);
        $this->assertSame($this->threeThings(), $entry->items);
        $this->assertSame($kid->household_id, $entry->household_id);
        $this->assertSame(GratitudeService::TICKETS, $kid->refresh()->bonus_tickets);
    }

    public function test_the_ticket_entry_records_the_kind_and_points_back_at_the_entry(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        $entry = $this->gratitude()->record($kid, $this->threeThings());

        $ticket = BonusTicketEntry::where('profile_id', $kid->id)->firstOrFail();

        $this->assertSame(TicketKind::Gratitude, $ticket->kind);
        $this->assertSame(GratitudeService::TICKETS, $ticket->amount);
        $this->assertTrue($ticket->related->is($entry));
    }

    public function test_only_one_a_day(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        $this->gratitude()->record($kid, $this->threeThings());

        $this->assertFalse($this->gratitude()->isAvailable($kid->refresh()));
        $this->assertNull($this->gratitude()->record($kid, ['Another', 'Three', 'Things']));
        $this->assertSame(1, GratitudeEntry::where('profile_id', $kid->id)->count());
        $this->assertSame(GratitudeService::TICKETS, $kid->refresh()->bonus_tickets);
    }

    public function test_a_new_one_opens_the_next_day(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        $this->gratitude()->record($kid, $this->threeThings());

        Carbon::setTestNow(now()->addDay());

        $this->assertTrue($this->gratitude()->isAvailable($kid->refresh()));
        $this->assertNotNull($this->gratitude()->record($kid, ['Sunshine', 'My bike', 'Pizza night']));
        $this->assertSame(2, GratitudeEntry::where('profile_id', $kid->id)->count());
        $this->assertSame(GratitudeService::TICKETS * 2, $kid->refresh()->bonus_tickets);
    }

    public function test_the_household_day_boundary_is_what_counts(): void
    {
        // Written at 1am, which still belongs to the previous household day —
        // so it must not hand over a second lot of tickets for "today".
        $household = Household::factory()->create(['timezone' => 'UTC', 'day_boundary_hour' => 4]);
        Chore::factory()->for($household)->create(['quest_eligible' => true]);
        $kid = Profile::factory()->for($household)->create(['bonus_tickets' => 0]);

        Carbon::setTestNow(Carbon::parse('2026-08-06 22:00:00', 'UTC'));
        $this->assertNotNull($this->gratitude()->record($kid, $this->threeThings()));

        Carbon::setTestNow(Carbon::parse('2026-08-07 01:00:00', 'UTC'));
        $this->assertFalse($this->gratitude()->isAvailable($kid->refresh()));

        Carbon::setTestNow(Carbon::parse('2026-08-07 05:00:00', 'UTC'));
        $this->assertTrue($this->gratitude()->isAvailable($kid->refresh()));
    }

    public function test_fewer_than_three_things_is_not_an_entry(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        $this->assertNull($this->gratitude()->record($kid, ['My dog', '  ', '']));
        $this->assertSame(0, GratitudeEntry::where('profile_id', $kid->id)->count());
        $this->assertSame(0, $kid->refresh()->bonus_tickets);
        $this->assertTrue($this->gratitude()->isAvailable($kid->refresh()));
    }

    public function test_answers_are_trimmed_and_capped(): void
    {
        $kid = $this->kid();

        $entry = $this->gratitude()->record($kid, [
            '  My dog  ',
            str_repeat('a', GratitudeService::MAX_LENGTH + 50),
            'Pizza',
            // A fourth box would be a bug, but storing it would be a worse one.
            'Extra',
        ]);

        $this->assertSame('My dog', $entry->items[0]);
        $this->assertSame(GratitudeService::MAX_LENGTH, mb_strlen($entry->items[1]));
        $this->assertCount(GratitudeService::ITEMS, $entry->items);
    }

    public function test_the_journal_query_reads_newest_first_and_stays_with_its_own_kid(): void
    {
        $kid = $this->kid();
        $sibling = Profile::factory()->for($kid->household)->create();

        GratitudeEntry::factory()->for($kid->household)->for($kid)->daysAgo(2)->create(['items' => ['Older', 'Older', 'Older']]);
        GratitudeEntry::factory()->for($kid->household)->for($kid)->daysAgo(1)->create(['items' => ['Newer', 'Newer', 'Newer']]);
        GratitudeEntry::factory()->for($kid->household)->for($sibling)->create(['items' => ['Theirs', 'Theirs', 'Theirs']]);

        $journal = $this->gratitude()->journalFor($kid);

        $this->assertCount(2, $journal);
        $this->assertSame('Newer', $journal->first()->items[0]);

        // The household journal is the parent's view, so it collects everyone.
        $this->assertCount(3, $this->gratitude()->journalForHousehold($kid->household));
    }

    public function test_the_quests_page_takes_an_entry_and_pays_out(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        Auth::guard('profile')->login($kid);

        // The card keeps one title in both states now, so the button is what
        // says whether there is still something to fill in.
        Volt::test('kid.quests')
            ->assertSee('Hand it in')
            ->set('gratitude', $this->threeThings())
            ->call('logGratitude')
            // Hearts rather than the money rain every other quest throws. This
            // is the one thing on the board that isn't about earning, and the
            // tickets it pays are a thank-you rather than the point of it.
            ->assertDispatched(
                'celebrate',
                fn (string $event, array $params) => $params['style'] === 'heart',
            )
            ->assertSee('Today you were grateful for')
            ->assertSee('Pancakes')
            ->assertDontSee('Hand it in')
            // Boxes emptied, so a re-render doesn't hand back a filled-in form
            // for a quest that's finished.
            ->assertSet('gratitude', ['', '', ''])
            ->assertSet('gratitudeMessage', null);

        $this->assertSame(GratitudeService::TICKETS, $kid->refresh()->bonus_tickets);
    }

    public function test_a_half_filled_form_is_refused_with_a_reason(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->set('gratitude', ['My dog', '', ''])
            ->call('logGratitude')
            ->assertNotDispatched('celebrate')
            ->assertSet('gratitudeMessage', 'Fill in all three before you hand it in.')
            // Still fillable — a refusal must not eat what they already typed.
            ->assertSee('Hand it in');

        $this->assertSame(0, $kid->refresh()->bonus_tickets);
    }

    public function test_a_second_hand_in_the_same_day_says_so(): void
    {
        $kid = $this->kid(['bonus_tickets' => 0]);

        Auth::guard('profile')->login($kid);

        $component = Volt::test('kid.quests')
            ->set('gratitude', $this->threeThings())
            ->call('logGratitude');

        // Another tab got there first — the form is gone from this render, but
        // the action can still arrive.
        $component
            ->set('gratitude', ['Again', 'And again', 'And again'])
            ->call('logGratitude')
            ->assertSet('gratitudeMessage', "Today's gratitude quest is already done — back tomorrow!");

        $this->assertSame(1, GratitudeEntry::where('profile_id', $kid->id)->count());
        $this->assertSame(GratitudeService::TICKETS, $kid->refresh()->bonus_tickets);
    }

    public function test_the_quests_page_keeps_only_today_and_points_at_the_journal(): void
    {
        // Older days belong on the Journal tab — the Quests page is the day in
        // front of you, and a growing list under the card would bury the board.
        $kid = $this->kid();

        GratitudeEntry::factory()->for($kid->household)->for($kid)->daysAgo(1)
            ->create(['items' => ['Yesterday one', 'Yesterday two', 'Yesterday three']]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.quests')
            ->set('gratitude', $this->threeThings())
            ->call('logGratitude')
            ->assertSee('Pancakes')
            ->assertDontSee('Yesterday one')
            ->assertSee(route('kid.journal'), false);
    }

    public function test_the_journal_page_reads_every_day_back(): void
    {
        $kid = $this->kid();
        $sibling = Profile::factory()->for($kid->household)->create();

        GratitudeEntry::factory()->for($kid->household)->for($kid)->daysAgo(2)->create(['items' => ['Older one', 'Older two', 'Older three']]);
        GratitudeEntry::factory()->for($kid->household)->for($kid)->daysAgo(1)->create(['items' => ['Newer one', 'Newer two', 'Newer three']]);
        GratitudeEntry::factory()->for($kid->household)->for($sibling)->create(['items' => ['Theirs one', 'Theirs two', 'Theirs three']]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')
            ->assertSee('Journal')
            ->assertSee('Older one')
            ->assertSee('Newer one')
            // A sibling's journal is theirs, not a shared feed.
            ->assertDontSee('Theirs one')
            ->assertViewHas('total', 2)
            ->assertViewHas('ticketsBanked', 2 * GratitudeService::TICKETS);
    }

    public function test_the_journal_page_pages_rather_than_capping(): void
    {
        $kid = $this->kid();

        // Well past a page's worth, so nothing can quietly fall off the end.
        foreach (range(1, 20) as $daysAgo) {
            GratitudeEntry::factory()->for($kid->household)->for($kid)->daysAgo($daysAgo)
                ->create(['items' => ["Day {$daysAgo} first", 'Second', 'Third']]);
        }

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')
            ->assertSee('Day 1 first')
            ->assertDontSee('Day 20 first')
            ->call('nextPage')
            ->assertSee('Day 20 first');
    }

    public function test_the_journal_page_nudges_only_while_today_is_unwritten(): void
    {
        $kid = $this->kid();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')
            ->assertSee('Nothing written down yet')
            ->assertSee("Write today's", false);

        $this->gratitude()->record($kid, $this->threeThings());

        Volt::test('kid.journal')
            ->assertSee('Pancakes')
            ->assertDontSee("Write today's", false);
    }

    public function test_the_journal_sits_in_the_me_world_of_the_kid_nav(): void
    {
        $kid = $this->kid();

        Auth::guard('profile')->login($kid);

        // Reached from Stats, which is already in "Me" — so the pill has to be
        // on screen without switching worlds first.
        Volt::test('kid.stats')->assertSee(route('kid.journal'), false);
    }

    public function test_a_parent_cannot_open_a_kids_journal(): void
    {
        $kid = $this->kid();
        $parent = Profile::factory()->for($kid->household)->parent()->create();

        $this->actingAs($parent, 'profile')
            ->get(route('kid.journal'))
            ->assertForbidden();
    }

    public function test_a_parent_can_read_the_household_journal(): void
    {
        $kid = $this->kid();
        $parent = Profile::factory()->for($kid->household)->parent()->create();

        $this->gratitude()->record($kid, ['My dog', 'Pancakes', 'Grandma calling']);

        $outsider = Profile::factory()->create();
        $this->gratitude()->record($outsider, ['Not', 'Your', 'Household']);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.activity')
            ->assertSee('Grateful For')
            ->assertSee('Grandma calling')
            ->assertSee($kid->name)
            ->assertDontSee('Your Household');
    }

    public function test_the_parent_journal_explains_itself_when_empty(): void
    {
        $kid = $this->kid();
        $parent = Profile::factory()->for($kid->household)->parent()->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.activity')->assertSee('Nothing yet.');
    }
}
