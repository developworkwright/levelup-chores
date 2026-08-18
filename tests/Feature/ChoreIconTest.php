<?php

namespace Tests\Feature;

use App\Enums\ChoreIcon;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Services\ChoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The face a quest card wears, for the kids who can't read the name.
 *
 * @see ChoreIcon
 */
class ChoreIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_icon_in_the_set_actually_draws(): void
    {
        // A preset with nothing behind it renders nothing at all, which on a
        // card is a blank face — the one outcome the fallback chain exists to
        // stop.
        foreach (ChoreIcon::cases() as $icon) {
            $rendered = view('components.chore-icon', ['icon' => $icon, 'class' => 'text-[24px]'])->render();

            $this->assertStringContainsString('<i', $rendered, "{$icon->value} renders nothing.");
            $this->assertStringContainsString($icon->faClass(), $rendered);
        }
    }

    public function test_every_preset_names_an_icon_font_awesome_free_actually_ships(): void
    {
        // A Pro-only name is worse than a wrong picture: the class resolves,
        // the glyph never ships, and the card comes out blank. The free
        // package's stylesheet is the list of what exists.
        $stylesheet = file_get_contents(base_path('node_modules/@fortawesome/fontawesome-free/css/fontawesome.css'));

        if ($stylesheet === false) {
            $this->markTestSkipped('Font Awesome is not installed — run npm install.');
        }

        foreach (ChoreIcon::cases() as $icon) {
            $name = str_replace('fa-solid ', '', $icon->faClass());

            $this->assertStringContainsString(
                "\n.{$name} {",
                $stylesheet,
                "{$icon->value} points at {$name}, which is not in the free set.",
            );
        }
    }

    public function test_an_unknown_key_draws_nothing_rather_than_a_broken_shape(): void
    {
        $this->assertSame('', trim(view('components.chore-icon', ['icon' => 'wombat'])->render()));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function customClassCases(): array
    {
        return [
            'a plain class' => ['fa-solid fa-rocket', 'fa-solid fa-rocket'],
            'a bare name gets a style' => ['fa-rocket', 'fa-solid fa-rocket'],
            'the short style alias' => ['fas fa-rocket', 'fas fa-rocket'],
            'a whole pasted tag' => ['<i class="fa-brands fa-github"></i>', 'fa-brands fa-github'],
            'stray whitespace and case' => ['  FA-Solid   FA-Rocket ', 'fa-solid fa-rocket'],
            'a style on its own has no glyph' => ['fa-solid', null],
            'nothing font-awesome about it' => ['rocket', null],
            'blank' => ['   ', null],
            'null' => [null, null],
            // The result lands in a `class` attribute, so this is the gate
            // that matters most: nothing outside `fa-[a-z0-9-]` gets through.
            'an injection attempt' => ['fa-solid" onload="alert(1)', null],
            'a script tag' => ['<script>alert(1)</script>', null],
        ];
    }

    #[DataProvider('customClassCases')]
    public function test_a_typed_class_is_normalised_or_refused(?string $typed, ?string $expected): void
    {
        $this->assertSame($expected, ChoreIcon::normalizeClass($typed));
    }

    public function test_a_class_too_long_for_the_column_is_refused_rather_than_truncated(): void
    {
        // A truncated class renders a face nobody chose — or nothing at all.
        $this->assertNull(ChoreIcon::normalizeClass('fa-solid fa-'.str_repeat('x', 80)));
    }

    /** @return array<int, array{0: string, 1: ChoreIcon}> */
    public static function nameCases(): array
    {
        return [
            ['Mow the lawn', ChoreIcon::Lawn],
            ['Put away dishes', ChoreIcon::Dishes],
            ['Fold the laundry', ChoreIcon::Laundry],
            ['Make your bed', ChoreIcon::Bed],
            ['Sweep the porch', ChoreIcon::Sweep],
            ['Feed the dog', ChoreIcon::Pet],
            ['Take the bins out', ChoreIcon::Trash],
            ['Vacuum the stairs', ChoreIcon::Vacuum],
            ['Water the plants', ChoreIcon::Water],
            ['Set the table', ChoreIcon::Table],
            ['Sort the recycling', ChoreIcon::Recycle],
            ['Brush your teeth', ChoreIcon::Teeth],
            ['Wash Living Room Windows', ChoreIcon::Window],
            ['Tidy your toys', ChoreIcon::Toys],
            ['Clean the car', ChoreIcon::Car],
            ['Bring in the post', ChoreIcon::Mail],
        ];
    }

    #[DataProvider('nameCases')]
    public function test_a_chore_name_picks_its_own_icon(string $name, ChoreIcon $expected): void
    {
        $this->assertSame($expected, ChoreIcon::forName($name));
    }

    public function test_the_narrower_word_wins_where_two_lists_overlap(): void
    {
        // 'wash' belongs to laundry and would swallow half a board, so
        // everything more literal gets first refusal.
        $this->assertSame(ChoreIcon::Window, ChoreIcon::forName('Wash the windows'));
        $this->assertSame(ChoreIcon::Car, ChoreIcon::forName('Wash the car'));
        $this->assertSame(ChoreIcon::Dishes, ChoreIcon::forName('Wash up the plates'));
        // With nothing more specific in it, laundry still gets its word.
        $this->assertSame(ChoreIcon::Laundry, ChoreIcon::forName('Wash and fold'));
    }

    public function test_a_keyword_buried_inside_a_longer_word_does_not_match(): void
    {
        // 'carpet' contains both 'pet' and 'car'. A plain substring test put a
        // paw print on this, and the card exists to be chosen by a kid who
        // can't read the name and has nothing to check the picture against.
        $this->assertNotSame(ChoreIcon::Pet, ChoreIcon::forName('Clean the carpet'));
        $this->assertNotSame(ChoreIcon::Car, ChoreIcon::forName('Clean the carpet'));
        $this->assertNotSame(ChoreIcon::Car, ChoreIcon::forName('Sort the cards'));

        // Still open at the right-hand end, so plurals and stems keep working.
        $this->assertSame(ChoreIcon::Recycle, ChoreIcon::forName('Sort the recycling'));
        $this->assertSame(ChoreIcon::Trash, ChoreIcon::forName('Take the bins out'));
        $this->assertSame(ChoreIcon::Toys, ChoreIcon::forName('Put the toys away'));
    }

    public function test_a_name_that_matches_nothing_gets_no_icon(): void
    {
        // A wrong picture is worse than none: the kid this is for chooses *by*
        // the picture and has nothing to check it against.
        $this->assertNull(ChoreIcon::forName('Practise piano'));
        $this->assertNull(ChoreIcon::forName('Homework'));
    }

    public function test_a_new_chore_gets_a_face_guessed_from_its_name(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->set('newChoreName', 'Mow the lawn')
            ->set('newChorePoints', '250')
            ->call('addChore');

        $this->assertSame(ChoreIcon::Lawn->faClass(), Chore::firstWhere('name', 'Mow the lawn')->icon);
    }

    public function test_a_parent_can_set_and_clear_a_chores_face(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Homework', 'icon' => null]);

        Auth::guard('profile')->login($parent);

        $page = Volt::test('parent.chores')->call('setIcon', $chore->id, 'toys');
        $this->assertSame(ChoreIcon::Toys->faClass(), $chore->fresh()->icon);

        // Tapping the current one again clears it — the only way back to the
        // typographic face once a guess has put something there.
        $page->call('setIcon', $chore->id, 'toys');
        $this->assertNull($chore->fresh()->icon);
    }

    public function test_a_parent_can_type_any_font_awesome_class(): void
    {
        // The sixteen presets are a shortlist, not the vocabulary — this is
        // the only way to reach the other two thousand icons.
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['name' => 'Practise piano', 'icon' => null]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->set("customIcon.{$chore->id}", 'fa-solid fa-music')
            ->call('setCustomIcon', $chore->id);

        $this->assertSame('fa-solid fa-music', $chore->fresh()->icon);
    }

    public function test_a_typed_class_is_normalised_before_it_is_stored(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['icon' => null]);

        Auth::guard('profile')->login($parent);

        $page = Volt::test('parent.chores')
            ->set("customIcon.{$chore->id}", '<i class="fa-solid fa-guitar"></i>')
            ->call('setCustomIcon', $chore->id);

        $this->assertSame('fa-solid fa-guitar', $chore->fresh()->icon);
        // Echoed back normalised, so a parent can see what actually landed.
        $page->assertSet("customIcon.{$chore->id}", 'fa-solid fa-guitar');
    }

    public function test_a_typed_class_that_makes_no_sense_is_refused_out_loud(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['icon' => ChoreIcon::Toys->faClass()]);

        Auth::guard('profile')->login($parent);

        $page = Volt::test('parent.chores')
            ->set("customIcon.{$chore->id}", 'alert(1)')
            ->call('setCustomIcon', $chore->id);

        // The face it already had is left alone — a rejected class must not
        // cost a parent the icon they'd already chosen.
        $this->assertSame(ChoreIcon::Toys->faClass(), $chore->fresh()->icon);
        // And a control that silently eats what you type reads as broken.
        $page->assertSet("customIconMessage.{$chore->id}", fn ($message) => is_string($message) && $message !== '');
    }

    public function test_an_empty_custom_class_clears_the_face(): void
    {
        $household = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($household)->create();
        $chore = Chore::factory()->for($household)->create(['icon' => ChoreIcon::Toys->faClass()]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->set("customIcon.{$chore->id}", '')
            ->call('setCustomIcon', $chore->id);

        $this->assertNull($chore->fresh()->icon);
    }

    public function test_a_parent_cannot_type_a_face_onto_another_households_chore(): void
    {
        $mine = Household::factory()->create();
        $theirs = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($mine)->create();
        $chore = Chore::factory()->for($theirs)->create(['icon' => null]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')
            ->set("customIcon.{$chore->id}", 'fa-solid fa-music')
            ->call('setCustomIcon', $chore->id);

        $this->assertNull($chore->fresh()->icon);
    }

    public function test_a_parent_cannot_set_a_face_on_another_households_chore(): void
    {
        $mine = Household::factory()->create();
        $theirs = Household::factory()->create();
        $parent = Profile::factory()->parent()->for($mine)->create();
        $chore = Chore::factory()->for($theirs)->create(['icon' => null]);

        Auth::guard('profile')->login($parent);

        Volt::test('parent.chores')->call('setIcon', $chore->id, 'toys');

        $this->assertNull($chore->fresh()->icon);
    }

    public function test_the_quest_card_draws_the_icon_when_a_chore_has_one(): void
    {
        $household = Household::factory()->create(['require_quest_first' => true]);
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        Chore::factory()->for($household)->create([
            'name' => 'Mow the lawn',
            'icon' => ChoreIcon::Lawn->faClass(),
            'points' => 300,
            'min_age' => null,
            'quest_eligible' => true,
        ]);

        Auth::guard('profile')->login($kid);

        $html = Volt::test('kid.quests')->assertOk()->html();

        $this->assertStringContainsString('fq-card-glyph', $html);
        $this->assertStringContainsString(ChoreIcon::Lawn->faClass(), $html);
        // The suit corners carry the ladder the row is sorted by.
        $this->assertStringContainsString('♦', $html);
    }

    public function test_the_side_quest_board_wears_the_same_faces_as_the_cards(): void
    {
        // A board of identical text rows is unusable to a kid who can't read
        // them; the picture is the only thing that makes it scannable, and it
        // used to stop existing the moment the hand burned.
        $household = Household::factory()->create(['require_quest_first' => true]);
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        // Three faceless chores to fill the hand, so the only icon anywhere on
        // the page has to be the one drawn on the board below it.
        foreach (range(1, 3) as $i) {
            Chore::factory()->for($household)->create([
                'name' => "Faceless chore {$i}",
                'icon' => null,
                'min_age' => null,
                'quest_eligible' => true,
            ]);
        }

        Chore::factory()->for($household)->create([
            'name' => 'Feed the dog',
            'icon' => ChoreIcon::Pet->faClass(),
            'min_age' => null,
            'quest_eligible' => false,
        ]);

        Auth::guard('profile')->login($kid);

        $html = Volt::test('kid.quests')->assertOk()->html();

        $this->assertStringContainsString(ChoreIcon::Pet->faClass(), $html);
        // Drawn on the board, not on a card — the hand is all faceless.
        $this->assertStringNotContainsString('fq-card-glyph', $html);
    }

    public function test_the_main_quest_keeps_the_face_its_card_was_picked_by(): void
    {
        // The hand burns after the pick. Without the face on the quest itself
        // the picture a pre-reader actually chose from is simply gone.
        $household = Household::factory()->create(['require_quest_first' => true]);
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        $chore = Chore::factory()->for($household)->create([
            'name' => 'Mow the lawn',
            'icon' => ChoreIcon::Lawn->faClass(),
            'min_age' => null,
            'quest_eligible' => true,
        ]);

        Auth::guard('profile')->login($kid);

        $html = Volt::test('kid.quests')
            ->call('chooseQuest', $chore->id)
            ->assertOk()
            ->html();

        $this->assertStringContainsString(ChoreIcon::Lawn->faClass(), $html);
        // The hero, not a card: the hand is gone by now.
        $this->assertStringNotContainsString('fq-card-glyph', $html);
    }

    public function test_a_chore_with_no_icon_falls_back_to_the_typographic_face(): void
    {
        $household = Household::factory()->create(['require_quest_first' => true]);
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        Chore::factory()->for($household)->create([
            'name' => 'Practise piano',
            'icon' => null,
            'points' => 300,
            'min_age' => null,
            'quest_eligible' => true,
        ]);

        Auth::guard('profile')->login($kid);

        $html = Volt::test('kid.quests')->assertOk()->html();

        // No card is ever blank: with no picture, the points become the face.
        $this->assertStringContainsString('fq-card-facenum', $html);
        $this->assertStringNotContainsString('fq-card-glyph', $html);
    }

    public function test_the_hand_is_a_row_of_fixed_cards_not_a_stretched_grid(): void
    {
        $household = Household::factory()->create(['require_quest_first' => true]);
        $kid = Profile::factory()->for($household)->create(['age' => 10]);

        foreach ([50, 150, 400] as $points) {
            Chore::factory()->for($household)->create([
                'points' => $points,
                'min_age' => null,
                'quest_eligible' => true,
            ]);
        }

        Auth::guard('profile')->login($kid);

        $html = Volt::test('kid.quests')->assertOk()->html();

        $this->assertStringContainsString('fq-hand', $html);
        // The row is the choice — it scales on a phone, it never reflows into
        // a column, so there is no grid class left to stretch it.
        $this->assertStringNotContainsString('grid-cols-3 gap-2', $html);

        $service = app(ChoreService::class);
        $this->assertCount(3, $service->offeredChoresFor($kid->refresh()));
    }
}
