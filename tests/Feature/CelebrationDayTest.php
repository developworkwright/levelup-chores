<?php

namespace Tests\Feature;

use App\Models\BonusTicketEntry;
use App\Models\CelebrationChest;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\BadgeService;
use App\Services\CelebrationService;
use App\Services\LedgerService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CelebrationDayTest extends TestCase
{
    use RefreshDatabase;

    /** The day under test — the first one in the map, whatever it is. */
    private const KEY = 'first-day-of-school-2026';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function celebrations(): CelebrationService
    {
        return app(CelebrationService::class);
    }

    /** @return array<string, mixed> */
    private function day(): array
    {
        return CelebrationService::DAYS[self::KEY];
    }

    private function household(): Household
    {
        $household = Household::factory()->create();
        Chore::factory()->for($household)->count(3)->create();

        return $household;
    }

    /** Midday on the celebration day, in the household's own timezone. */
    private function onTheDay(Household $household, int $addDays = 0): void
    {
        Carbon::setTestNow(
            Carbon::parse($this->day()['date'], $household->timezone)->setTime(12, 0)->addDays($addDays)
        );
    }

    public function test_there_is_no_celebration_on_an_ordinary_day(): void
    {
        $household = $this->household();

        Carbon::setTestNow(Carbon::parse($this->day()['date'], $household->timezone)->subDays(3));

        $this->assertNull($this->celebrations()->activeFor($household));
    }

    public function test_the_celebration_is_live_on_the_day(): void
    {
        $household = $this->household();
        $this->onTheDay($household);

        $day = $this->celebrations()->activeFor($household);

        $this->assertNotNull($day);
        $this->assertSame(self::KEY, $day['key']);
        $this->assertTrue($this->celebrations()->isTheDay($household, $day));
    }

    /**
     * The grace window. A kid who is too flat to open the app on the day itself
     * must not lose the good thing over it — see the service.
     */
    public function test_the_card_keeps_asking_for_a_couple_of_days_but_the_balloons_do_not(): void
    {
        $household = $this->household();

        $this->onTheDay($household, CelebrationService::GRACE_DAYS);
        $day = $this->celebrations()->activeFor($household);

        $this->assertNotNull($day);
        $this->assertFalse($this->celebrations()->isTheDay($household, $day));

        $this->onTheDay($household, CelebrationService::GRACE_DAYS + 1);

        $this->assertNull($this->celebrations()->activeFor($household));
    }

    public function test_the_chest_will_not_open_until_the_day_has_been_answered(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        $this->assertFalse($this->celebrations()->isOpenable($kid, self::KEY));
        $this->assertNull($this->celebrations()->open($kid, self::KEY));
        $this->assertSame(0, $kid->refresh()->points);
    }

    public function test_answering_opens_the_chest_and_pays(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        $this->celebrations()->answer($kid, self::KEY, 'hard', 'It was loud.');

        $this->assertTrue($this->celebrations()->isOpenable($kid->refresh(), self::KEY));

        $entry = $this->celebrations()->open($kid->refresh(), self::KEY);
        $reward = $this->celebrations()->rewardFor($household, $this->day());

        $this->assertNotNull($entry);
        $this->assertNotNull($entry->opened_at);
        $this->assertSame($reward['points'], (int) $entry->reward_points);

        $kid->refresh();
        $this->assertSame($reward['points'], $kid->points);

        // Balances are floors rather than equalities here, and deliberately: a
        // chest this size crosses a level, a level mints tickets, and the points
        // tick badges off that pay XP of their own. What is asserted exactly is
        // the chest's own contribution, off the ticket ledger.
        $this->assertSame($reward['tickets'], (int) BonusTicketEntry::where('profile_id', $kid->id)
            ->where('related_type', $entry->getMorphClass())
            ->where('related_id', $entry->id)
            ->sum('amount'));

        $this->assertSame($reward['xp'], (int) $entry->reward_xp);
        $this->assertGreaterThanOrEqual($reward['tickets'], $kid->bonus_tickets);
        $this->assertGreaterThanOrEqual($reward['xp'], $kid->xp);
    }

    /**
     * The rule the whole feature turns on: what a kid says about the day cannot
     * change what the day pays. If this ever fails, the app has started buying
     * good answers.
     */
    public function test_every_answer_pays_exactly_the_same(): void
    {
        $household = $this->household();
        $payouts = [];

        foreach ($this->day()['answers'] as $answer) {
            $kid = Profile::factory()->for($household)->create();

            $this->onTheDay($household);
            $this->celebrations()->answer($kid, self::KEY, $answer['key']);
            $this->celebrations()->open($kid->refresh(), self::KEY);

            $kid->refresh();
            $payouts[$answer['key']] = [$kid->points, $kid->bonus_tickets, $kid->xp];
        }

        $this->assertCount(count($this->day()['answers']), $payouts);
        $this->assertCount(1, array_unique($payouts, SORT_REGULAR));
    }

    public function test_an_answer_nobody_was_offered_is_refused(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        $this->assertNull($this->celebrations()->answer($kid, self::KEY, 'perfect'));
        $this->assertSame(0, CelebrationChest::where('profile_id', $kid->id)->count());
    }

    public function test_the_chest_only_ever_pays_once(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        $this->celebrations()->answer($kid, self::KEY, 'brilliant');
        $this->celebrations()->open($kid->refresh(), self::KEY);

        $banked = $kid->refresh()->points;

        $this->assertNull($this->celebrations()->open($kid, self::KEY));
        $this->assertSame($banked, $kid->refresh()->points);
        $this->assertSame(1, CelebrationChest::where('profile_id', $kid->id)->count());
    }

    /**
     * Changing your mind is free, and stays free after the chest is open —
     * there is nothing riding on the word, which is the point.
     */
    public function test_the_answer_can_be_changed_afterwards_without_paying_again(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        $this->celebrations()->answer($kid, self::KEY, 'brilliant');
        $this->celebrations()->open($kid->refresh(), self::KEY);

        $banked = $kid->refresh()->points;

        $entry = $this->celebrations()->answer($kid, self::KEY, 'hard', 'Actually it was hard.');

        $this->assertSame('hard', $entry->answer);
        $this->assertNotNull($entry->opened_at);
        $this->assertSame($banked, $kid->refresh()->points);
    }

    /**
     * The generic half: a celebration is a row in a map, and a row that asks
     * nothing — a birthday, the last day of term — hands the chest over on
     * sight. Stood up through a subclass rather than by editing the real
     * calendar.
     */
    public function test_a_day_that_asks_nothing_hands_the_chest_straight_over(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        $this->app->bind(CelebrationService::class, fn ($app) => new QuietCelebrationService(
            $app->make(TicketService::class),
            $app->make(LedgerService::class),
            $app->make(BadgeService::class),
        ));

        Carbon::setTestNow(Carbon::parse('2026-12-25', $household->timezone)->setTime(12, 0));

        $day = $this->celebrations()->activeFor($household);

        $this->assertNotNull($day);
        $this->assertFalse($this->celebrations()->asksAQuestion($day));
        $this->assertTrue($this->celebrations()->isOpenable($kid, $day['key']));

        $entry = $this->celebrations()->open($kid, $day['key']);

        $this->assertNotNull($entry);
        // The day's own reward, not the default — an override in the map has to
        // actually reach the payout.
        $this->assertSame(200, $kid->refresh()->points);
        $this->assertSame(200, (int) $entry->reward_points);
    }

    public function test_the_kid_home_page_asks_the_question_and_opens_the_chest(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee($this->day()['title'])
            ->assertSee($this->day()['question'])
            ->call('answerCelebration', 'up_and_down', 'Lunch was weird.')
            ->call('openCelebrationChest');

        $entry = $this->celebrations()->entryFor($kid->refresh(), self::KEY);

        $this->assertSame('up_and_down', $entry->answer);
        $this->assertSame('Lunch was weird.', $entry->note);
        $this->assertNotNull($entry->opened_at);
        $this->assertSame(
            $this->celebrations()->rewardFor($household, $this->day())['points'],
            $kid->refresh()->points,
        );
    }

    /**
     * The word and the note are one answer and go up together. Picking a word
     * used to save on the spot, which collapsed the card and took the box for
     * saying more off the screen before anybody had read it.
     */
    public function test_the_card_offers_a_send_button_and_keeps_the_note_box_up_until_it_is_pressed(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();
        $this->onTheDay($household);

        Auth::guard('profile')->login($kid);

        $page = Volt::test('kid.home')
            ->assertSee($this->day()['noteLabel'])
            ->assertSee($this->day()['submitLabel']);

        // Nothing is written until the send lands, so the chest is still shut
        // and there is no row for a kid who is halfway through typing.
        $this->assertSame(0, CelebrationChest::where('profile_id', $kid->id)->count());
        $this->assertFalse($this->celebrations()->isOpenable($kid, self::KEY));

        $page->call('answerCelebration', 'hard', 'The bus was loud.');

        $entry = $this->celebrations()->entryFor($kid, self::KEY);

        $this->assertSame('hard', $entry->answer);
        $this->assertSame('The bus was loud.', $entry->note);
    }

    public function test_the_balloons_are_up_on_the_day_and_down_after_it(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        $this->onTheDay($household);
        Volt::test('kid.home')->assertSee('fq-balloons');

        $this->onTheDay($household, CelebrationService::GRACE_DAYS);
        Volt::test('kid.home')->assertDontSee('fq-balloons');
    }

    /**
     * A first day that went badly is not something a sibling gets to read. The
     * kid's own page carries their row and nobody else's.
     */
    public function test_a_kid_never_sees_a_siblings_answer(): void
    {
        $household = $this->household();
        $kid = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Alex']);
        $this->onTheDay($household);

        $this->celebrations()->answer($sibling, self::KEY, 'hard', 'Nobody talked to me.');

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->assertDontSee('Nobody talked to me.');
    }

    public function test_a_parent_sees_what_everyone_said(): void
    {
        $household = $this->household();
        $parent = Profile::factory()->for($household)->parent()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Sam']);
        $this->onTheDay($household);

        $this->celebrations()->answer($kid, self::KEY, 'hard', 'Nobody talked to me.');

        Auth::guard('profile')->login($parent);

        Volt::test('parent.home')
            ->call('toggleRow', 'celebration')
            ->assertSee('Sam')
            ->assertSee('Nobody talked to me.')
            ->assertSee('Hard');
    }
}

/**
 * A celebration that asks nothing and pays its own amount. Here rather than in
 * the app because it exists only to prove the map is what decides — see
 * CelebrationService::days().
 */
class QuietCelebrationService extends CelebrationService
{
    public const DAYS = [
        'a-quiet-day' => [
            'date' => '2026-12-25',
            'kicker' => 'A Quiet Day',
            'title' => 'Happy day!',
            'blurb' => 'No question, no catch.',
            'chestTitle' => 'A chest',
            'chestText' => 'Just because.',
            'lockedText' => '',
            'accent' => 'var(--fq-gold)',
            'reward' => ['dollars' => 2],
        ],
    ];
}
