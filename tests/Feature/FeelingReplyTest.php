<?php

namespace Tests\Feature;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Models\Chore;
use App\Models\FeelingReply;
use App\Models\Household;
use App\Models\Profile;
use App\Services\FeelingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A grown-up saying something back.
 *
 * The rules worth protecting are mostly about who *cannot* see one: a sibling
 * never does, and nothing anywhere notifies anybody.
 */
class FeelingReplyTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Profile $kid;

    private Profile $sibling;

    private Profile $mom;

    private Profile $dad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Westin']);
        $this->sibling = Profile::factory()->for($this->household)->create(['name' => 'Ziggy']);
        $this->mom = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mom']);
        $this->dad = Profile::factory()->for($this->household)->parent()->create(['name' => 'Dad']);

        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function service(): FeelingService
    {
        return app(FeelingService::class);
    }

    private function kidsEntry(): int
    {
        $this->service()->record($this->kid, Feeling::Sad, 'a hard one', FeelingVisibility::Parents);

        return $this->service()->todayFor($this->kid)->id;
    }

    public function test_a_parent_can_answer_a_kids_day(): void
    {
        $reply = $this->service()->reply($this->mom, $this->kidsEntry(), 'I saw that. I am here.');

        $this->assertNotNull($reply);
        $this->assertSame('I saw that. I am here.', $reply->body);
        $this->assertTrue($reply->author->is($this->mom));
    }

    public function test_both_parents_can_answer_the_same_day(): void
    {
        $entry = $this->kidsEntry();

        $this->service()->reply($this->mom, $entry, 'Thinking of you.');
        $this->service()->reply($this->dad, $entry, 'Me too.');

        // Mom and Dad are separate logins now, and either may want to say
        // something. Attribution is the whole reason that matters.
        $this->assertSame(
            ['Mom', 'Dad'],
            $this->service()->todayFor($this->kid)->replies->map(fn ($r) => $r->author->name)->all(),
        );
    }

    public function test_a_kid_cannot_reply(): void
    {
        // Grown-ups write, kids read. A thread between siblings on somebody's
        // bad day is exactly what this must never become.
        $this->assertNull($this->service()->reply($this->sibling, $this->kidsEntry(), 'lol'));
        $this->assertSame(0, FeelingReply::count());
    }

    public function test_a_parent_cannot_reply_to_their_own_entry(): void
    {
        $this->service()->record($this->mom, Feeling::Tired, 'long week');
        $entry = $this->service()->todayFor($this->mom)->id;

        $this->assertNull($this->service()->reply($this->mom, $entry, 'talking to myself'));
    }

    public function test_a_reply_never_crosses_households(): void
    {
        $outsider = Profile::factory()->for(Household::factory())->parent()->create();

        $this->assertNull($this->service()->reply($outsider, $this->kidsEntry(), 'hello'));
    }

    public function test_an_empty_reply_is_not_recorded(): void
    {
        $this->assertNull($this->service()->reply($this->mom, $this->kidsEntry(), '   '));
        $this->assertSame(0, FeelingReply::count());
    }

    public function test_a_reply_is_capped(): void
    {
        $reply = $this->service()->reply($this->mom, $this->kidsEntry(), str_repeat('a', FeelingService::MAX_REPLY + 200));

        $this->assertSame(FeelingService::MAX_REPLY, mb_strlen($reply->body));
    }

    public function test_a_sibling_never_sees_a_reply(): void
    {
        $entry = $this->kidsEntry();
        $this->service()->reply($this->mom, $entry, 'I am here whenever you want me.');

        $this->service()->record($this->sibling, Feeling::Okay);
        $this->service()->record($this->dad, Feeling::Okay);

        // Being answered in front of the whole house turns a private moment
        // into a scene.
        $siblingRow = $this->service()->houseToday($this->sibling)->firstWhere('profile.name', 'Westin');
        $this->assertCount(0, $siblingRow['replies']);

        // The person it is about, and the other grown-up, both see it.
        $dadRow = $this->service()->houseToday($this->dad)->firstWhere('profile.name', 'Westin');
        $this->assertCount(1, $dadRow['replies']);
        $this->assertCount(1, $this->service()->todayFor($this->kid)->replies);
    }

    public function test_a_sibling_never_sees_a_reply_on_the_page(): void
    {
        Chore::factory()->for($this->household)->create();

        $entry = $this->kidsEntry();
        $this->service()->reply($this->mom, $entry, 'a private thing between us');
        $this->service()->record($this->sibling, Feeling::Okay);

        Auth::guard('profile')->login($this->sibling);

        Volt::test('kid.home')
            ->assertOk()
            ->assertDontSee('a private thing between us');
    }

    public function test_only_the_author_can_take_a_reply_back(): void
    {
        $reply = $this->service()->reply($this->mom, $this->kidsEntry(), 'said in haste');

        // Not the other parent, and not the kid it was left for.
        $this->assertFalse($this->service()->deleteReply($this->dad, $reply->id));
        $this->assertFalse($this->service()->deleteReply($this->kid, $reply->id));
        $this->assertSame(1, FeelingReply::count());

        $this->assertTrue($this->service()->deleteReply($this->mom, $reply->id));
        $this->assertSame(0, FeelingReply::count());
    }

    public function test_a_parent_can_reply_even_when_the_reason_is_private(): void
    {
        // The feeling word is public to the house, so answering it is answering
        // something the kid chose to show. The hidden reason stays hidden.
        $this->service()->record($this->kid, Feeling::Worried, 'not telling', FeelingVisibility::Private);
        $entry = $this->service()->todayFor($this->kid)->id;

        $this->assertNotNull($this->service()->reply($this->mom, $entry, 'I am around if you want me.'));
    }

    public function test_the_kid_reads_it_on_their_own_card(): void
    {
        Chore::factory()->for($this->household)->create();

        $this->service()->reply($this->mom, $this->kidsEntry(), 'I am proud of you for saying so.');

        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->assertOk()
            ->assertSee('Mom said')
            ->assertSee('I am proud of you for saying so.');
    }

    public function test_a_parent_replies_from_their_own_page(): void
    {
        $entry = $this->kidsEntry();
        $this->service()->record($this->mom, Feeling::Okay);

        Auth::guard('profile')->login($this->mom);

        Volt::test('parent.home')
            ->assertOk()
            ->call('replyToFeeling', $entry, 'Saw your day. Here if you need me.');

        $this->assertSame(
            'Saw your day. Here if you need me.',
            $this->service()->todayFor($this->kid)->replies->first()->body,
        );
    }

    public function test_replying_notifies_nothing(): void
    {
        Notification::fake();

        $this->service()->reply($this->mom, $this->kidsEntry(), 'thinking of you');

        // The card only works while saying something has no consequence
        // attached. A push here would make a hard answer summon a parent.
        Notification::assertNothingSent();
    }
}
