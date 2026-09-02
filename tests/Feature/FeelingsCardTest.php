<?php

namespace Tests\Feature;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Models\Chore;
use App\Models\FeelingEntry;
use App\Models\FeelingWord;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use App\Services\FeelingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The feelings card. The rules being protected here are mostly *absences* —
 * nothing paid, nothing counted, nothing leaked — so most of these tests assert
 * that something did not happen.
 */
class FeelingsCardTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $sibling;

    private Profile $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Westin', 'points' => 0]);
        $this->sibling = Profile::factory()->for($this->household)->create(['name' => 'Ziggy', 'points' => 0]);
        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Dad']);

        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function service(): FeelingService
    {
        return app(FeelingService::class);
    }

    public function test_an_answer_is_recorded_against_the_household_day(): void
    {
        $entry = $this->service()->record($this->kid, Feeling::Nervous, 'school tomorrow');

        $this->assertSame(Feeling::Nervous, $entry->feeling);
        $this->assertSame('school tomorrow', $entry->because);
        // Private unless the writer says otherwise, every time.
        $this->assertSame(FeelingVisibility::Private, $entry->visibility);
        $this->assertTrue($entry->felt_on->isSameDay(Carbon::parse('2026-05-01')));
    }

    public function test_answering_pays_absolutely_nothing(): void
    {
        $this->service()->record($this->kid, Feeling::Happy, 'good day');

        $this->kid->refresh();

        // The whole reason this card is safe to be honest on. If any of these
        // ever move, the fastest answer starts beating the true one.
        $this->assertSame(0, $this->kid->points);
        $this->assertSame(0, $this->kid->bonus_tickets);
        $this->assertSame(0, $this->kid->xp);
        $this->assertSame(0, LedgerEntry::where('profile_id', $this->kid->id)->count());
    }

    public function test_a_second_answer_replaces_the_first_rather_than_stacking(): void
    {
        $this->service()->record($this->kid, Feeling::Okay, 'nothing much');
        $this->service()->record($this->kid, Feeling::Sad, 'it got worse');

        // Feelings move during a day, and being allowed to say so is the point.
        $this->assertSame(1, FeelingEntry::where('profile_id', $this->kid->id)->count());

        $entry = $this->service()->todayFor($this->kid);
        $this->assertSame(Feeling::Sad, $entry->feeling);
        $this->assertSame('it got worse', $entry->because);
    }

    public function test_not_saying_keeps_no_reason_and_stays_private(): void
    {
        $this->service()->record($this->kid, Feeling::NotSaying, 'a leftover reason', FeelingVisibility::House);

        $entry = $this->service()->todayFor($this->kid);

        // The answer is that they would rather not go into it; storing a reason
        // under that would contradict the answer.
        $this->assertNull($entry->because);
        $this->assertSame(FeelingVisibility::Private, $entry->visibility);
    }

    public function test_the_house_stays_covered_until_you_have_answered(): void
    {
        $this->service()->record($this->sibling, Feeling::Happy);

        // Read the room first and you answer the room instead of the question.
        $this->assertNull($this->service()->houseToday($this->kid));

        $this->service()->record($this->kid, Feeling::Flat);

        $this->assertNotNull($this->service()->houseToday($this->kid));
    }

    public function test_the_house_lists_everyone_including_those_who_have_not_answered(): void
    {
        $this->service()->record($this->kid, Feeling::Tired);

        $house = $this->service()->houseToday($this->kid);

        $this->assertCount(3, $house);
        // An absence is a person who hasn't got to it, not a person missing.
        $this->assertNull($house->firstWhere('profile.name', 'Ziggy')['entry']);
        $this->assertSame(2, $this->service()->cardFor($this->kid)['waiting']);
    }

    public function test_a_private_reason_reaches_nobody_else(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'the math test', FeelingVisibility::Private);
        $this->service()->record($this->sibling, Feeling::Happy);
        $this->service()->record($this->parent, Feeling::Okay);

        $entry = $this->service()->todayFor($this->kid);

        $this->assertTrue($entry->becauseVisibleTo($this->kid));
        $this->assertFalse($entry->becauseVisibleTo($this->sibling));
        $this->assertFalse($entry->becauseVisibleTo($this->parent));

        // And the strip a sibling reads carries no trace of the text.
        $row = $this->service()->houseToday($this->sibling)->firstWhere('profile.name', 'Westin');
        $this->assertNull($row['because']);
        // The feeling itself is never gated — that half is always public.
        $this->assertSame(Feeling::Worried, $row['entry']->feeling);
    }

    public function test_a_parents_only_reason_reaches_parents_and_not_siblings(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'had a fight with Sam', FeelingVisibility::Parents);
        $this->service()->record($this->sibling, Feeling::Happy);
        $this->service()->record($this->parent, Feeling::Okay);

        $this->assertSame(
            'had a fight with Sam',
            $this->service()->houseToday($this->parent)->firstWhere('profile.name', 'Westin')['because'],
        );

        $this->assertNull(
            $this->service()->houseToday($this->sibling)->firstWhere('profile.name', 'Westin')['because'],
        );
    }

    public function test_a_house_reason_reaches_everyone_at_home(): void
    {
        $this->service()->record($this->kid, Feeling::Excited, 'we got out early for break', FeelingVisibility::House);
        $this->service()->record($this->sibling, Feeling::Happy);

        $this->assertSame(
            'we got out early for break',
            $this->service()->houseToday($this->sibling)->firstWhere('profile.name', 'Westin')['because'],
        );
    }

    public function test_a_reason_never_crosses_households(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'private thing', FeelingVisibility::House);

        $outsider = Profile::factory()->for(Household::factory())->parent()->create();

        // Even "everyone" means everyone *here*.
        $this->assertFalse($this->service()->todayFor($this->kid)->becauseVisibleTo($outsider));
    }

    public function test_yesterdays_answer_does_not_count_as_todays(): void
    {
        FeelingEntry::factory()->for($this->household)->for($this->kid)->daysAgo(1)->create();

        $this->assertFalse($this->service()->hasAnswered($this->kid));
        $this->assertNull($this->service()->houseToday($this->kid));
    }

    public function test_a_reason_is_trimmed_to_the_limit(): void
    {
        $this->service()->record($this->kid, Feeling::Okay, str_repeat('a', FeelingService::MAX_BECAUSE + 200));

        $this->assertSame(
            FeelingService::MAX_BECAUSE,
            mb_strlen($this->service()->todayFor($this->kid)->because),
        );
    }

    public function test_a_blank_reason_is_stored_as_nothing_at_all(): void
    {
        $this->service()->record($this->kid, Feeling::Calm, '   ');

        $entry = $this->service()->todayFor($this->kid);

        $this->assertNull($entry->because);
        $this->assertFalse($entry->hasBecause());
    }

    public function test_a_kid_answers_from_home_and_the_house_opens_up(): void
    {
        Chore::factory()->for($this->household)->create();
        $this->service()->record($this->sibling, Feeling::Proud, 'won my race', FeelingVisibility::House);

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('How are you feeling today?')
            // Covered before answering — the sibling's reason must not be on
            // the page at all, not merely hidden with CSS.
            ->assertDontSee('won my race')
            ->call('answerFeeling', 'nervous', 'school tomorrow', 'parents')
            ->assertSee('won my race');

        $entry = $this->service()->todayFor($this->kid);
        $this->assertSame(Feeling::Nervous, $entry->feeling);
        $this->assertSame(FeelingVisibility::Parents, $entry->visibility);
    }

    public function test_a_parent_answers_from_their_own_landing_page(): void
    {
        Auth::guard('profile')->login($this->parent);

        Volt::test('parent.home')
            ->assertOk()
            ->assertSee('How are you feeling today?')
            ->call('answerFeeling', 'flat', 'long week', 'house');

        // The grown-ups answering is the mechanism, not a courtesy.
        $this->assertSame(Feeling::Flat, $this->service()->todayFor($this->parent)->feeling);
    }

    public function test_a_feeling_nobody_picked_is_never_recorded(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')->call('answerFeeling', 'ecstatic', 'made up', 'house');

        $this->assertNull($this->service()->todayFor($this->kid));
    }

    public function test_an_unknown_visibility_falls_back_to_private(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        // The safe direction to be wrong in is the one where nothing was shared
        // that the writer didn't mean to share.
        Volt::test('kid.home')->call('answerFeeling', 'sad', 'a reason', 'everyone-in-the-world');

        $this->assertSame(FeelingVisibility::Private, $this->service()->todayFor($this->kid)->visibility);
    }

    public function test_the_opt_out_is_offered_as_an_answer(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        // Declining must not look like a blank, or a kid picks a feeling to
        // avoid the awkwardness — which is the mask by a different door.
        Volt::test('kid.home')->assertOk()->assertSee('Not saying today');
    }

    public function test_a_kid_adds_a_word_of_their_own(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly', '🌧️');

        $this->assertSame('Wobbly', $word->label);
        $this->assertSame('🌧️', $word->displayGlyph());
        $this->assertTrue($this->service()->wordsFor($this->household)->contains($word));
    }

    public function test_a_word_added_by_anyone_is_on_everyones_card(): void
    {
        $this->service()->addWord($this->parent, 'Anxious');

        // The word this whole card exists for was added by a parent. A list
        // only its author could use would put it out of reach of the kid who
        // needs it, which is what per-profile words actually did.
        $this->assertCount(1, $this->service()->wordsFor($this->household));
        $this->assertNotNull($this->service()->resolveAnswer($this->kid, (string) FeelingWord::sole()->id));
        $this->assertNotNull($this->service()->resolveAnswer($this->sibling, (string) FeelingWord::sole()->id));
    }

    public function test_the_card_never_says_who_added_a_word(): void
    {
        Chore::factory()->for($this->household)->create();

        // A distinctive name, because "Dad" turns up legitimately elsewhere on
        // the page and would make this pass or fail for unrelated reasons.
        $author = Profile::factory()->for($this->household)->parent()->create(['name' => 'Bartholomew']);
        $this->service()->addWord($author, 'Anxious');

        Auth::guard('profile')->login($this->kid);

        // A word is the house's the moment it exists. Naming its author turns
        // "somebody here needed this word" into a fact about one person, which
        // is what stops the next word being added.
        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Anxious')
            ->assertDontSee('Bartholomew');
    }

    public function test_a_word_is_tidied_and_capped(): void
    {
        $word = $this->service()->addWord($this->kid, '   all   over    the place with a great deal more besides   ');

        $this->assertSame('all over the place with', $word->label);
        $this->assertLessThanOrEqual(FeelingWord::MAX_LABEL, mb_strlen($word->label));
    }

    public function test_a_blank_word_is_not_added(): void
    {
        $this->assertNull($this->service()->addWord($this->kid, '   '));
        $this->assertCount(0, $this->service()->wordsFor($this->household));
    }

    public function test_a_word_that_duplicates_a_built_in_is_refused(): void
    {
        // Two buttons meaning the same thing is worse than no button.
        $this->assertNull($this->service()->addWord($this->kid, 'happy'));
        $this->assertNull($this->service()->addWord($this->kid, 'Fed up'));
        $this->assertCount(0, $this->service()->wordsFor($this->household));
    }

    public function test_adding_the_same_word_twice_does_not_make_two(): void
    {
        $first = $this->service()->addWord($this->kid, 'Wobbly');
        $second = $this->service()->addWord($this->kid, 'wobbly');

        $this->assertTrue($first->is($second));
        $this->assertCount(1, $this->service()->wordsFor($this->household));
    }

    public function test_a_retired_word_leaves_the_card_but_keeps_its_old_days(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly');
        $this->service()->record($this->kid, $word, 'a strange day');

        $this->assertTrue($this->service()->retireWord($this->parent, $word->id));

        // Off the card...
        $this->assertCount(0, $this->service()->wordsFor($this->household));
        // ...but the day it was used on still reads back as itself.
        $this->assertSame('Wobbly', $this->service()->todayFor($this->kid)->label());
    }

    public function test_a_retired_word_comes_back_rather_than_being_duplicated(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly');
        $this->service()->retireWord($this->parent, $word->id);

        $again = $this->service()->addWord($this->kid, 'Wobbly');

        $this->assertTrue($word->is($again));
        $this->assertCount(1, FeelingWord::where('profile_id', $this->kid->id)->get());
    }

    public function test_only_a_grown_up_can_take_a_word_off_the_card(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly');

        // Adding is everyone's; taking away is not. The list is shared and
        // unattributed, so a kid tapping the cross would be removing a word
        // somebody else uses — with nothing on screen to say it wasn't theirs.
        // Not even by the kid who added it — nothing on the card says they did.
        $this->assertFalse($this->service()->retireWord($this->kid, $word->id));
        $this->assertFalse($this->service()->retireWord($this->sibling, $word->id));
        $this->assertTrue($word->fresh()->active);

        $this->assertTrue($this->service()->retireWord($this->parent, $word->id));
        $this->assertFalse($word->fresh()->active);
    }

    public function test_a_word_cannot_be_retired_from_another_household(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly');
        $outsider = Profile::factory()->for(Household::factory())->parent()->create();

        $this->assertFalse($this->service()->retireWord($outsider, $word->id));
        $this->assertTrue($word->fresh()->active);
    }

    public function test_a_custom_word_answers_the_card_like_any_other(): void
    {
        $word = $this->service()->addWord($this->kid, 'Homesick', '🌧️');

        $entry = $this->service()->record($this->kid, $word, 'camp starts tomorrow', FeelingVisibility::House);

        $this->assertNull($entry->feeling);
        $this->assertSame($word->id, $entry->feeling_word_id);
        $this->assertSame('Homesick', $entry->label());
        $this->assertSame('🌧️', $entry->glyph());
        $this->assertSame('Today I felt homesick', $entry->stem());

        // And it reads in the house strip exactly like a built-in.
        $this->service()->record($this->sibling, Feeling::Okay);
        $row = $this->service()->houseToday($this->sibling)->firstWhere('profile.name', 'Westin');
        $this->assertSame('Homesick', $row['entry']->label());
        $this->assertSame('camp starts tomorrow', $row['because']);
    }

    public function test_switching_back_to_a_built_in_clears_the_custom_word(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly');
        $this->service()->record($this->kid, $word);
        $this->service()->record($this->kid, Feeling::Calm);

        $entry = $this->service()->todayFor($this->kid)->fresh();

        // Both set would leave the model rendering whichever it checked first.
        $this->assertNull($entry->feeling_word_id);
        $this->assertSame(Feeling::Calm, $entry->feeling);
        $this->assertSame('Calm', $entry->label());
    }

    public function test_a_word_cannot_be_borrowed_from_another_household(): void
    {
        $theirs = $this->service()->addWord($this->sibling, 'Wobbly');
        $outsider = Profile::factory()->for(Household::factory())->create();

        // Shared within a house, never across one. Resolution is scoped to the
        // answering profile's household, so a hand-edited request can't post a
        // word off somebody else's family's card.
        $this->assertNull($this->service()->resolveAnswer($outsider, (string) $theirs->id));
        $this->assertNotNull($this->service()->resolveAnswer($this->kid, (string) $theirs->id));
    }

    public function test_a_retired_word_can_no_longer_be_answered_with(): void
    {
        $word = $this->service()->addWord($this->kid, 'Wobbly');
        $this->service()->retireWord($this->parent, $word->id);

        $this->assertNull($this->service()->resolveAnswer($this->kid, (string) $word->id));
    }

    public function test_typing_a_word_and_pressing_the_one_button_creates_it_and_answers_with_it(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        // The trap this replaced: fill the whole card in, then find the answer
        // button dead because of an "Add" step nobody thinks to press. Typing
        // the word *is* choosing it now.
        Volt::test('kid.home')
            ->call('answerFeeling', null, 'first day back', 'parents', 'Buzzing', '🎈')
            ->assertOk();

        $entry = $this->service()->todayFor($this->kid);

        $this->assertSame('Buzzing', $entry->label());
        $this->assertSame('🎈', $entry->glyph());
        $this->assertSame('first day back', $entry->because);
        $this->assertSame(FeelingVisibility::Parents, $entry->visibility);

        // And the word is on the house's card from now on.
        $this->assertCount(1, $this->service()->wordsFor($this->household));
    }

    public function test_typing_the_name_of_a_built_in_answers_with_the_built_in(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        // Typing "happy" means happy. The difference between typing it and
        // tapping it is not one anybody should have to care about, and it must
        // not leave a duplicate chip behind either.
        Volt::test('kid.home')
            ->call('answerFeeling', null, null, 'private', 'happy');

        $this->assertSame(Feeling::Happy, $this->service()->todayFor($this->kid)->feeling);
        $this->assertCount(0, $this->service()->wordsFor($this->household));
    }

    public function test_a_typed_word_wins_over_a_chip_that_was_left_selected(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        // The card clears one when the other is set, so this shouldn't arise —
        // but if it ever does, the word somebody typed is the more deliberate
        // of the two and must not be silently discarded.
        Volt::test('kid.home')
            ->call('answerFeeling', 'proud', null, 'private', 'Wobbly');

        $this->assertSame('Wobbly', $this->service()->todayFor($this->kid)->label());
    }

    public function test_an_empty_typed_word_falls_through_to_the_chip(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->call('answerFeeling', 'proud', null, 'private', '   ');

        $this->assertSame(Feeling::Proud, $this->service()->todayFor($this->kid)->feeling);
        $this->assertCount(0, $this->service()->wordsFor($this->household));
    }

    public function test_answering_with_neither_a_chip_nor_a_word_records_nothing(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')->call('answerFeeling', null, 'a reason', 'house', null);

        $this->assertNull($this->service()->todayFor($this->kid));
    }

    public function test_a_word_the_house_already_has_is_reused_rather_than_duplicated(): void
    {
        Chore::factory()->for($this->household)->create();
        $existing = $this->service()->addWord($this->parent, 'Anxious');

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')->call('answerFeeling', null, null, 'private', 'anxious');

        $this->assertSame($existing->id, $this->service()->todayFor($this->kid)->feeling_word_id);
        $this->assertCount(1, $this->service()->wordsFor($this->household));
    }

    public function test_the_cards_alpine_state_is_not_cut_short_by_a_stray_quote(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        $html = Volt::test('kid.home')->html();

        // x-data is delimited by double quotes, so one literal `"` anywhere
        // inside it — in a comment as readily as in code — closes the attribute
        // early. Alpine then gets a fragment of an object, the component never
        // initialises, and because both halves of the card carry x-cloak the
        // whole thing renders as an empty box. It cost an afternoon once.
        //
        // `[^"]*` below stops at the first quote, so a truncated attribute
        // simply won't contain the tail of the expression.
        $card = mb_substr($html, mb_strpos($html, 'wire:key="feelings-card"'));

        $this->assertSame(1, preg_match('/x-data="([^"]*)"/', $card, $matches));

        $state = $matches[1];

        // Properties from the top, the middle and the very bottom of x-data.
        $this->assertStringContainsString('visibility:', $state);
        $this->assertStringContainsString('stemLine()', $state);
        $this->assertStringContainsString('answerFeeling(', $state);
        $this->assertStringContainsString('editing = false', $state);
    }

    public function test_the_answer_button_renders_as_a_real_button(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        $html = Volt::test('kid.home')->assertOk()->html();

        // `[^<>]*` is the whole point: it requires the opening tag to contain no
        // markup of its own. A stray element landing *inside* it — which is how
        // this button once vanished from the page entirely — fails here, where
        // an assertSee on the label would happily still pass.
        $this->assertMatchesRegularExpression(
            '/<button[^<>]*>That\'s me today<\/button>/',
            $html,
        );
    }

    public function test_the_lock_offer_renders_once_there_is_a_reason_to_lock(): void
    {
        Chore::factory()->for($this->household)->create();
        $this->service()->record($this->kid, Feeling::Worried, 'because my app is not working');

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->assertOk()
            // Offered after saving, never before: there is nothing to seal until
            // something has been written down.
            ->assertSee('Lock this with my PIN');
    }
}
