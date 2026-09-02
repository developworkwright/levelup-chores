<?php

namespace Tests\Feature;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Models\Chore;
use App\Models\FeelingEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Services\FeelingLock;
use App\Services\FeelingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * Locking a reason with the writer's own PIN.
 *
 * The promise being protected here is narrow and worth stating exactly: the
 * sealed text is not readable from anywhere in the app without the PIN. It is
 * *not* unreadable to someone who owns the database and the code — nothing
 * could make it so — and no test here pretends otherwise.
 */
class FeelingLockTest extends TestCase
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
        $this->kid = Profile::factory()->for($this->household)->create(['name' => 'Westin']);
        $this->sibling = Profile::factory()->for($this->household)->create(['name' => 'Ziggy']);
        $this->parent = Profile::factory()->for($this->household)->parent()->create(['name' => 'Mom']);

        $this->kid->setPin('4821');
        $this->kid->save();

        $this->travelTo(Carbon::parse('2026-05-01 09:00', $this->household->timezone));
    }

    private function service(): FeelingService
    {
        return app(FeelingService::class);
    }

    public function test_the_cipher_round_trips(): void
    {
        $lock = app(FeelingLock::class);

        $sealed = $lock->seal('4821', 'I did not want to go in today');

        $this->assertSame(
            'I did not want to go in today',
            $lock->open('4821', $sealed['salt'], $sealed['sealed']),
        );
    }

    public function test_a_wrong_pin_opens_nothing_rather_than_returning_rubbish(): void
    {
        $lock = app(FeelingLock::class);
        $sealed = $lock->seal('4821', 'something true');

        // Authenticated encryption, so this fails cleanly instead of handing
        // back plausible-looking nonsense.
        $this->assertNull($lock->open('0000', $sealed['salt'], $sealed['sealed']));
    }

    public function test_the_same_text_and_pin_seal_differently_every_time(): void
    {
        $lock = app(FeelingLock::class);

        $first = $lock->seal('4821', 'the same words');
        $second = $lock->seal('4821', 'the same words');

        // Per-entry salt and nonce. Two entries locked with one PIN must not be
        // comparable, or cracking either one would give away the other.
        $this->assertNotSame($first['salt'], $second['salt']);
        $this->assertNotSame($first['sealed'], $second['sealed']);
    }

    public function test_a_damaged_blob_throws_rather_than_failing_quietly(): void
    {
        $this->expectException(RuntimeException::class);

        app(FeelingLock::class)->open('4821', base64_encode(random_bytes(16)), 'v1:not-really-base64!!');
    }

    public function test_locking_seals_the_reason_and_leaves_no_plaintext(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'the math lesson', FeelingVisibility::House);

        $this->assertTrue($this->service()->lock($this->kid, '4821'));

        $entry = $this->service()->todayFor($this->kid);

        $this->assertTrue($entry->isLocked());
        $this->assertNull($entry->because);
        $this->assertNotNull($entry->because_locked);

        // The plaintext is nowhere in the row.
        $this->assertStringNotContainsString('math lesson', json_encode($entry->getAttributes()));
    }

    public function test_locking_forces_the_entry_private(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'a reason', FeelingVisibility::House);
        $this->service()->lock($this->kid, '4821');

        // "Everyone can read why" over text nobody but the writer can decrypt
        // would be a label that lies.
        $this->assertSame(FeelingVisibility::Private, $this->service()->todayFor($this->kid)->visibility);
    }

    public function test_a_locked_reason_is_hidden_from_everyone_including_its_writer(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'a reason', FeelingVisibility::House);
        $this->service()->lock($this->kid, '4821');

        $entry = $this->service()->todayFor($this->kid);

        // Not even the author reads it through the ordinary path — that is what
        // the PIN is for.
        $this->assertFalse($entry->becauseVisibleTo($this->kid));
        $this->assertFalse($entry->becauseVisibleTo($this->parent));
        $this->assertFalse($entry->becauseVisibleTo($this->sibling));

        $this->service()->record($this->sibling, Feeling::Okay);
        $row = $this->service()->houseToday($this->sibling)->firstWhere('profile.name', 'Westin');
        $this->assertNull($row['because']);
    }

    public function test_the_house_strip_still_says_a_reason_exists(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'a reason');
        $this->service()->lock($this->kid, '4821');

        // An entry that hid the fact it had been locked would make the lock
        // look like it had lost the text.
        $this->assertTrue($this->service()->todayFor($this->kid)->hasBecause());
    }

    public function test_the_writer_opens_it_with_their_pin(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'I did not want to go in');
        $this->service()->lock($this->kid, '4821');

        $entry = $this->service()->todayFor($this->kid);

        $this->assertSame(
            'I did not want to go in',
            $this->service()->openLocked($this->kid, $entry->id, '4821'),
        );
    }

    public function test_a_wrong_pin_does_not_open_it(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'a reason');
        $this->service()->lock($this->kid, '4821');

        $entry = $this->service()->todayFor($this->kid);

        $this->assertNull($this->service()->openLocked($this->kid, $entry->id, '1111'));
    }

    public function test_nobody_else_can_open_it_even_with_the_right_pin(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'a reason');
        $this->service()->lock($this->kid, '4821');

        $entry = $this->service()->todayFor($this->kid);

        // Scoped to the writer, so knowing the PIN is not enough on its own.
        $this->assertNull($this->service()->openLocked($this->parent, $entry->id, '4821'));
        $this->assertNull($this->service()->openLocked($this->sibling, $entry->id, '4821'));
    }

    public function test_opening_changes_nothing_on_the_row(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'a reason');
        $this->service()->lock($this->kid, '4821');

        $entry = $this->service()->todayFor($this->kid);
        $before = $entry->because_locked;

        $this->service()->openLocked($this->kid, $entry->id, '4821');

        $entry->refresh();

        // Opening is a look, not a state change. Close the page and it is
        // sealed again exactly as it was.
        $this->assertTrue($entry->isLocked());
        $this->assertNull($entry->because);
        $this->assertSame($before, $entry->because_locked);
    }

    public function test_locking_needs_the_writers_real_pin(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'a reason');

        // Sealing under a mistyped PIN would produce an entry they could never
        // open, and they would not find out until they tried.
        $this->assertFalse($this->service()->lock($this->kid, '9999'));
        $this->assertFalse($this->service()->todayFor($this->kid)->isLocked());
        $this->assertSame('a reason', $this->service()->todayFor($this->kid)->because);
    }

    public function test_there_is_nothing_to_lock_without_a_reason(): void
    {
        $this->service()->record($this->kid, Feeling::Flat);

        $this->assertFalse($this->service()->lock($this->kid, '4821'));
    }

    public function test_answering_again_takes_the_lock_off_with_the_old_reason(): void
    {
        $this->service()->record($this->kid, Feeling::Sad, 'the old reason');
        $this->service()->lock($this->kid, '4821');

        $this->service()->record($this->kid, Feeling::Okay, 'a new reason in the clear');

        $entry = $this->service()->todayFor($this->kid);

        // Left set, the row would carry plaintext behind a padlock — the card
        // trusts the flag and would draw a lock over text anybody could read.
        $this->assertFalse($entry->isLocked());
        $this->assertNull($entry->because_locked);
        $this->assertNull($entry->lock_salt);
        $this->assertSame('a new reason in the clear', $entry->because);
    }

    public function test_a_locked_reason_never_reaches_the_page(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'the secret sentence');
        $this->service()->lock($this->kid, '4821');

        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        $html = Volt::test('kid.home')->assertOk()->html();

        // Not rendered, not hidden with CSS, not sitting in the Livewire
        // payload — the only route to it is a PIN.
        $this->assertStringNotContainsString('the secret sentence', $html);
        $this->assertStringContainsString('Locked with your PIN', $html);
    }

    public function test_a_kid_locks_and_reopens_from_the_page(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        $component = Volt::test('kid.home')
            ->call('answerFeeling', 'worried', 'about tomorrow', 'private')
            ->call('lockFeeling', '4821');

        $this->assertTrue($this->service()->todayFor($this->kid)->isLocked());

        $entry = $this->service()->todayFor($this->kid);

        $component->call('openFeeling', $entry->id, '4821')
            ->assertSet('openedFeeling', 'about tomorrow')
            ->assertSee('about tomorrow');

        $component->call('openFeeling', $entry->id, '0000')
            ->assertSet('openedFeeling', null)
            ->assertSet('feelingLockMessage', 'That PIN did not open it.');
    }

    public function test_a_locked_entry_survives_being_serialised_by_livewire(): void
    {
        $this->service()->record($this->kid, Feeling::Worried, 'the secret sentence');
        $this->service()->lock($this->kid, '4821');

        $entry = FeelingEntry::where('profile_id', $this->kid->id)->sole();

        // Hidden on the model, so a payload, a log line or an API response
        // can't carry the sealed blob or its salt out by accident.
        $array = $entry->toArray();

        $this->assertArrayNotHasKey('because_locked', $array);
        $this->assertArrayNotHasKey('lock_salt', $array);
    }

    public function test_locking_at_entry_never_writes_the_plaintext(): void
    {
        // The whole reason locking moved into the save: the old order wrote the
        // words in the clear and offered to seal them afterwards, leaving a
        // window where the truest thing on the page sat there unlocked.
        $entry = $this->service()->record(
            $this->kid,
            Feeling::Worried,
            'the thing I have not told anyone',
            FeelingVisibility::House,
            '4821',
        );

        $this->assertNotNull($entry);
        $this->assertTrue($entry->isLocked());
        $this->assertNull($entry->because);

        // Not in the row, under any column.
        $this->assertStringNotContainsString(
            'have not told anyone',
            json_encode(FeelingEntry::sole()->getAttributes()),
        );

        $this->assertSame(
            'the thing I have not told anyone',
            $this->service()->openLocked($this->kid, $entry->id, '4821'),
        );
    }

    public function test_locking_at_entry_forces_it_private(): void
    {
        $entry = $this->service()->record(
            $this->kid,
            Feeling::Sad,
            'a reason',
            FeelingVisibility::House,
            '4821',
        );

        // Nobody else holds the key, so any other label would be a lie.
        $this->assertSame(FeelingVisibility::Private, $entry->visibility);
    }

    public function test_a_wrong_pin_at_entry_saves_nothing_at_all(): void
    {
        $this->assertNull($this->service()->record(
            $this->kid,
            Feeling::Sad,
            'a reason',
            FeelingVisibility::Private,
            '0000',
        ));

        // Falling back to saving it unlocked would put the text in the one
        // place they had just said to keep it out of.
        $this->assertSame(0, FeelingEntry::count());
    }

    public function test_a_wrong_pin_at_entry_does_not_damage_an_earlier_answer(): void
    {
        $this->service()->record($this->kid, Feeling::Okay, 'this morning was fine');

        $this->assertNull($this->service()->record(
            $this->kid,
            Feeling::Sad,
            'a new reason',
            FeelingVisibility::Private,
            '0000',
        ));

        $entry = $this->service()->todayFor($this->kid);

        $this->assertSame('this morning was fine', $entry->because);
        $this->assertFalse($entry->isLocked());
    }

    public function test_not_saying_ignores_a_lock_pin(): void
    {
        $entry = $this->service()->record(
            $this->kid,
            Feeling::NotSaying,
            'a leftover reason',
            FeelingVisibility::Private,
            '4821',
        );

        // There is nothing to seal, and an entry flagged locked with nothing
        // behind it would draw a padlock over an empty box.
        $this->assertFalse($entry->isLocked());
        $this->assertNull($entry->because);
    }

    public function test_a_kid_locks_it_on_the_way_in_from_the_page(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->call('answerFeeling', 'worried', 'about tomorrow', 'house', null, null, '4821')
            ->assertReturned(true);

        $entry = $this->service()->todayFor($this->kid);

        $this->assertTrue($entry->isLocked());
        $this->assertSame('about tomorrow', $this->service()->openLocked($this->kid, $entry->id, '4821'));
    }

    public function test_a_wrong_pin_from_the_page_keeps_the_form_open(): void
    {
        Chore::factory()->for($this->household)->create();
        Auth::guard('profile')->login($this->kid);

        Volt::test('kid.home')
            ->call('answerFeeling', 'worried', 'about tomorrow', 'private', null, null, '0000')
            // False is what tells the card to keep everything on screen.
            ->assertReturned(false)
            ->assertSet('feelingLockMessage', 'That PIN did not match. Nothing was saved — your words are still here.');

        $this->assertNull($this->service()->todayFor($this->kid));
    }
}
