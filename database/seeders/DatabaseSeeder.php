<?php

namespace Database\Seeders;

use App\Enums\PerkEffect;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Household;
use App\Models\LuckyPrize;
use App\Models\Profile;
use App\Models\StoreItem;
use App\Services\MonsterService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds a single demo household: profiles, chores, and store items.
     * Badges are not seeded here — migrations own the full set, so they exist
     * after a plain `php artisan migrate` with no seeding step.
     *
     * These are placeholder demo names and PINs, not a real family — rotate
     * every profile's PIN from the Kids & Points screen after first login
     * regardless, since anything shipped in a seeder is effectively public.
     */
    public function run(): void
    {
        $household = Household::create(['name' => 'Home Base']);

        $this->seedProfiles($household);
        $this->seedChores($household);
        $this->seedStoreItems($household);
        $this->seedBonusPerks($household);
        // Same three-way split as the perks: the migration seeds households
        // that already exist, this seeds the demo one, the factory seeds the
        // ones tests build.
        LuckyPrize::seedDefaults($household);
        $this->seedSavingGoals($household);
        $this->seedMonster($household);
    }

    /** The one monster the house is fighting, with a reward priced against it. */
    private function seedMonster(Household $household): void
    {
        app(MonsterService::class)->spawn($household, 'Pizza + movie night, everyone picks a snack', 1300, 4000);
    }

    /**
     * Give each kid something to save toward so the Loot Shop's goal card has
     * something to show on a fresh install. Kids can repoint it themselves from
     * any reward card, so these are only opening suggestions — each gets a
     * different big-ticket item rather than all three chasing the same thing.
     */
    private function seedSavingGoals(Household $household): void
    {
        $aspirational = StoreItem::where('household_id', $household->id)
            ->where('cost', '>=', 500)
            ->orderByDesc('cost')
            ->get();

        if ($aspirational->isEmpty()) {
            return;
        }

        $kids = Profile::where('household_id', $household->id)
            ->where('role', 'kid')
            ->orderByDesc('age')
            ->get();

        foreach ($kids as $index => $kid) {
            $kid->saving_for_store_item_id = $aspirational[$index % $aspirational->count()]->id;
            $kid->save();
        }
    }

    /**
     * The perk catalogue is per household, and the migration that creates the
     * table can only seed households that already exist — so a household born
     * here needs its own copy.
     */
    private function seedBonusPerks(Household $household): void
    {
        foreach (PerkEffect::cases() as $effect) {
            BonusPerk::firstOrCreate(
                ['household_id' => $household->id, 'effect' => $effect],
                $effect->defaults(),
            );
        }
    }

    private function seedProfiles(Household $household): void
    {
        $profiles = [
            ['name' => 'Nova', 'role' => 'kid', 'age' => 12, 'color' => 'cyan', 'pin' => '1111'],
            ['name' => 'Scout', 'role' => 'kid', 'age' => 9, 'color' => 'lime', 'pin' => '2222'],
            ['name' => 'Ziggy', 'role' => 'kid', 'age' => 6, 'color' => 'gold', 'pin' => '3333'],
            ['name' => 'Parent', 'role' => 'parent', 'age' => null, 'color' => 'parent', 'pin' => '4444'],
        ];

        foreach ($profiles as $data) {
            $profile = new Profile([
                'household_id' => $household->id,
                'name' => $data['name'],
                'role' => $data['role'],
                'age' => $data['age'],
                'color' => $data['color'],
            ]);
            $profile->setPin($data['pin']);
            $profile->save();
        }
    }

    private function seedChores(Household $household): void
    {
        $chores = [
            ['name' => 'Put away dishes', 'cadence' => 'daily'],
            ['name' => 'Pick up living room floor', 'cadence' => 'daily'],
            ['name' => 'Water indoor plants', 'cadence' => 'weekly'],
            ['name' => 'Sweep living room floor', 'cadence' => 'daily'],
            ['name' => 'Feed animals', 'cadence' => 'daily'],
        ];

        foreach ($chores as $chore) {
            Chore::create([
                'household_id' => $household->id,
                'name' => $chore['name'],
                'points' => 100,
                'cadence' => $chore['cadence'],
            ]);
        }
    }

    private function seedStoreItems(Household $household): void
    {
        $items = [
            ['name' => '1 hour of YouTube', 'description' => 'An extra hour of screen time, YouTube edition.', 'cost' => 100, 'color_tag' => 'coral'],
            ['name' => 'Robux', 'description' => 'Robux added to your account.', 'cost' => 100, 'color_tag' => 'lime'],
            ['name' => 'Steam wallet', 'description' => 'Steam wallet credit for your next game.', 'cost' => 100, 'color_tag' => 'cyan'],
            ['name' => 'Dessert pick', 'description' => 'You choose dessert for the whole family tonight.', 'cost' => 150, 'color_tag' => 'magenta'],
            ['name' => 'Extra family game time', 'description' => '30 extra minutes of family game night.', 'cost' => 200, 'color_tag' => 'gold'],
            ['name' => 'Trip of your choice', 'description' => 'Pick an outing for the family to take together.', 'cost' => 500, 'color_tag' => 'violet'],
            // Priced out of reach on purpose — the saving-up goal and the "Big
            // ticket" shelf both need something to aim at.
            ['name' => 'Movie night out', 'description' => 'A trip to the cinema, your pick of film.', 'cost' => 800, 'color_tag' => 'cyan'],
            ['name' => 'Lego set', 'description' => 'A Lego set of your choice.', 'cost' => 2000, 'color_tag' => 'violet'],
        ];

        foreach ($items as $item) {
            StoreItem::create([
                'household_id' => $household->id,
                ...$item,
            ]);
        }
    }
}
