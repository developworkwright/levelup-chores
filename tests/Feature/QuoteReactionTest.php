<?php

namespace Tests\Feature;

use App\Enums\ReactionKind;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Quote;
use App\Models\QuoteReaction;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Reactions, and the celebration cards that tell a kid about them.
 *
 * The thing under test throughout is that a reaction stays an expression and
 * never becomes a score — nothing here ranks quotes against each other.
 */
class QuoteReactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tapping_a_face_adds_it_and_tapping_again_takes_it_back(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $quote = Quote::factory()->for($household)->create();

        $service = app(QuoteService::class);

        $this->assertTrue($service->react($kid, $quote->id, 'laugh'));
        $this->assertSame(1, QuoteReaction::count());

        $this->assertFalse($service->react($kid, $quote->id, 'laugh'));
        $this->assertSame(0, QuoteReaction::count());
    }

    public function test_a_kid_can_hold_more_than_one_face_on_the_same_quote(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $quote = Quote::factory()->for($household)->create();

        $service = app(QuoteService::class);
        $service->react($kid, $quote->id, 'laugh');
        $service->react($kid, $quote->id, 'heart');

        $this->assertSame(2, QuoteReaction::where('profile_id', $kid->id)->count());
    }

    public function test_an_unknown_face_is_refused(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $quote = Quote::factory()->for($household)->create();

        $this->assertFalse(app(QuoteService::class)->react($kid, $quote->id, 'thumbs_down'));
        $this->assertSame(0, QuoteReaction::count());
    }

    /** Reachable from two kid pages, so the scope check lives in the service. */
    public function test_a_kid_cannot_react_to_another_households_quote(): void
    {
        $kid = Profile::factory()->for(Household::factory())->create();
        $theirs = Quote::factory()->for(Household::factory())->create();

        $this->assertFalse(app(QuoteService::class)->react($kid, $theirs->id, 'laugh'));
        $this->assertSame(0, QuoteReaction::count());
    }

    /**
     * All four faces come back whether or not anyone has tapped them — the row
     * is the control as well as the readout.
     */
    public function test_the_summary_returns_every_face_with_counts_and_names(): void
    {
        $household = Household::factory()->create();
        $mabel = Profile::factory()->for($household)->create(['name' => 'Mabel']);
        $otto = Profile::factory()->for($household)->create(['name' => 'Otto']);
        $quote = Quote::factory()->for($household)->create();

        $service = app(QuoteService::class);
        $service->react($mabel, $quote->id, 'laugh');
        $service->react($otto, $quote->id, 'laugh');

        $summary = collect(QuoteService::reactionSummary($quote->fresh(['reactions.profile']), $mabel))
            ->keyBy(fn (array $row) => $row['kind']->value);

        $this->assertCount(count(ReactionKind::cases()), $summary);
        $this->assertSame(2, $summary['laugh']['count']);
        $this->assertTrue($summary['laugh']['mine']);
        $this->assertSame('Mabel, Otto', $summary['laugh']['who']);

        $this->assertSame(0, $summary['heart']['count']);
        $this->assertFalse($summary['heart']['mine']);
    }

    /**
     * Every face has to be reachable and none of them may crown anything —
     * 👑 and ⭐ are deliberately absent, since quotes are never ranked.
     */
    public function test_every_face_is_offered_and_none_of_them_is_a_crown(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $quote = Quote::factory()->for($household)->create();

        Auth::guard('profile')->login($kid);

        $page = Volt::test('kid.home');

        foreach (ReactionKind::cases() as $kind) {
            $page->assertSee($kind->emoji(), escape: false);
            $this->assertTrue(app(QuoteService::class)->react($kid, $quote->id, $kind->value));
        }

        $this->assertSame(count(ReactionKind::cases()), QuoteReaction::count());

        foreach (['👑', '⭐'] as $crown) {
            $this->assertNotContains(
                $crown,
                collect(ReactionKind::cases())->map(fn (ReactionKind $kind) => $kind->emoji())->all(),
            );
        }
    }

    public function test_a_kid_reacts_from_the_home_page(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $quote = Quote::factory()->for($household)->create(['text' => 'Angry milk']);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')->call('react', $quote->id, 'laugh');

        $this->assertDatabaseHas('quote_reactions', [
            'quote_id' => $quote->id,
            'profile_id' => $kid->id,
            'reaction' => 'laugh',
        ]);
    }

    public function test_a_kid_reacts_from_the_quote_wall(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();
        $quote = Quote::factory()->for($household)->daysAgo(3)->create();

        Auth::guard('profile')->login($kid);

        Volt::test('kid.journal')
            ->call('showTab', 'quotes')
            ->call('react', $quote->id, 'dead');

        $this->assertDatabaseHas('quote_reactions', [
            'quote_id' => $quote->id,
            'profile_id' => $kid->id,
            'reaction' => 'dead',
        ]);
    }

    /**
     * A household that has been running for months must not meet a kid with a
     * queue of every quote ever written down.
     */
    public function test_a_null_marker_seeds_itself_without_celebrating(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['quotes_seen_at' => null]);
        Quote::factory()->for($household)->create(['text' => 'Ancient history', 'said_by' => 'Granny']);

        Auth::guard('profile')->login($kid);

        // Asserted on the celebration's wording, not the quote text: the quote
        // itself is legitimately on the page in the Home card either way.
        Volt::test('kid.home')->assertDontSee('Granny said something!', escape: false);

        $this->assertNotNull($kid->refresh()->quotes_seen_at);
    }

    public function test_a_quote_added_since_the_last_look_is_celebrated_and_then_is_not(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['quotes_seen_at' => now()->subDay()]);
        Quote::factory()->for($household)->create(['text' => 'Dogs have no elbows', 'said_by' => 'Granny']);

        Auth::guard('profile')->login($kid);

        // The reward rides to the browser as JSON on the dispatching element.
        Volt::test('kid.home')->assertSee('Granny said something!', escape: false);

        // The marker moved, so the next visit is quiet — this is the half that
        // regresses if the shell ever stops writing it.
        Volt::test('kid.home')->assertDontSee('Granny said something!', escape: false);
    }

    /**
     * One quote, every kid in the house told — including the siblings it was
     * nothing to do with.
     *
     * The regression this guards is a live one: on the first real quote only
     * one of three kids saw the card, because the other two still had a null
     * `quotes_seen_at` and their first page load after the deploy burned the
     * marker silently. The seeding guard was right; the profiles predating it
     * should have been backfilled, which they now are.
     */
    public function test_every_kid_in_the_house_is_celebrated_at_not_just_the_first_one(): void
    {
        $household = Household::factory()->create();
        $kids = collect(['Nova', 'Scout', 'Ziggy'])->map(
            fn (string $name) => Profile::factory()->for($household)->create([
                'name' => $name,
                'quotes_seen_at' => now()->subHour(),
            ]),
        );

        Quote::factory()->for($household)->create([
            'text' => 'You bled on my chore!',
            'profile_id' => $kids->first()->id,
            'said_by' => null,
        ]);

        foreach ($kids as $kid) {
            Auth::guard('profile')->login($kid);

            Volt::test('kid.home')->assertSee(
                $kid->is($kids->first()) ? 'Your line got written down!' : 'Nova said something!',
                escape: false,
            );

            Auth::guard('profile')->logout();
        }
    }

    /** A profile created after the feature shipped still starts quiet. */
    public function test_a_brand_new_profile_does_not_inherit_the_backlog(): void
    {
        $household = Household::factory()->create();
        Quote::factory()->for($household)->daysAgo(30)->create(['text' => 'Old news', 'said_by' => 'Granny']);

        $newKid = Profile::factory()->for($household)->create(['quotes_seen_at' => null]);

        Auth::guard('profile')->login($newKid);

        Volt::test('kid.home')->assertDontSee('Granny said something!', escape: false);
        $this->assertNotNull($newKid->refresh()->quotes_seen_at);
    }

    /**
     * The toast changes for the kid who said it; the card's kicker does not.
     * That kicker names the feature, not who it happened to.
     */
    public function test_being_quoted_yourself_reads_differently_but_still_says_quote_of_the_day(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['quotes_seen_at' => now()->subDay()]);
        Quote::factory()->for($household)->create(['profile_id' => $kid->id, 'said_by' => null]);

        Auth::guard('profile')->login($kid);

        Volt::test('kid.home')
            ->assertSee('Your line got written down!', escape: false)
            ->assertSee('Quote of the Day', escape: false)
            ->assertDontSee('You said it', escape: false);
    }

    public function test_a_sibling_reacting_to_your_quote_is_celebrated(): void
    {
        Notification::fake();

        $household = Household::factory()->create();
        $mine = Profile::factory()->for($household)->create(['quotes_seen_at' => now()->subDay()]);
        $sibling = Profile::factory()->for($household)->create(['name' => 'Mabel']);
        $quote = Quote::factory()->for($household)->create(['profile_id' => $mine->id, 'said_by' => null]);

        app(QuoteService::class)->react($sibling, $quote->id, 'laugh');

        Auth::guard('profile')->login($mine);

        Volt::test('kid.home')->assertSee('Mabel reacted to your quote!', escape: false);
    }

    /** Three siblings piling onto one line is one card, not three. */
    public function test_several_reactions_on_one_quote_collapse_into_a_single_card(): void
    {
        $household = Household::factory()->create();
        $mine = Profile::factory()->for($household)->create(['quotes_seen_at' => now()->subDay()]);
        $quote = Quote::factory()->for($household)->create(['profile_id' => $mine->id, 'said_by' => null]);

        foreach (['laugh', 'dead'] as $index => $kind) {
            $sibling = Profile::factory()->for($household)->create(['name' => 'Sib'.$index]);
            app(QuoteService::class)->react($sibling, $quote->id, $kind);
        }

        Auth::guard('profile')->login($mine);

        Volt::test('kid.home')->assertSee('Your quote got 2 reactions!', escape: false);
    }

    /** Being told you laughed at your own quote is the app talking to itself. */
    public function test_your_own_reaction_is_not_news(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['quotes_seen_at' => now()->subDay()]);
        $quote = Quote::factory()->for($household)->daysAgo(2)->create([
            'profile_id' => $kid->id,
            'said_by' => null,
        ]);

        app(QuoteService::class)->react($kid, $quote->id, 'laugh');

        $news = app(QuoteService::class)->newsFor($kid->refresh(), $kid->quotes_seen_at);

        $this->assertCount(0, $news['reactions']);
    }

    /** News is scoped to the household and to quotes this kid actually said. */
    public function test_reaction_news_covers_only_your_own_quotes(): void
    {
        $household = Household::factory()->create();
        $mine = Profile::factory()->for($household)->create(['quotes_seen_at' => now()->subDay()]);
        $sibling = Profile::factory()->for($household)->create();
        $theirQuote = Quote::factory()->for($household)->create(['profile_id' => $sibling->id, 'said_by' => null]);

        app(QuoteService::class)->react($mine, $theirQuote->id, 'laugh');

        $news = app(QuoteService::class)->newsFor($mine, $mine->quotes_seen_at);

        $this->assertCount(0, $news['reactions']);
    }

    /**
     * The Quote Wall draws a page of twenty-five rows, each naming everyone who
     * reacted — lazy-loading that is a guaranteed N+1.
     */
    public function test_the_archive_loads_reactions_and_their_people_eagerly(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create();

        foreach (range(1, 3) as $i) {
            $quote = Quote::factory()->for($household)->create();
            app(QuoteService::class)->react($kid, $quote->id, 'laugh');
        }

        $archive = app(QuoteService::class)->archive($household);

        foreach ($archive as $quote) {
            $this->assertTrue($quote->relationLoaded('reactions'));
            $this->assertTrue($quote->reactions->first()->relationLoaded('profile'));
        }
    }
}
