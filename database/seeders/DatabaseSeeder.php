<?php

namespace Database\Seeders;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Profile;
use App\Models\StoreItem;
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
        $household = Household::create([
            'name' => 'Home Base',
            'goal_name' => 'Pizza + movie night, everyone picks a snack',
            'goal_target' => 4000,
            'goal_now' => 0,
        ]);

        $this->seedProfiles($household);
        $this->seedChores($household);
        $this->seedStoreItems($household);
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
        ];

        foreach ($items as $item) {
            StoreItem::create([
                'household_id' => $household->id,
                ...$item,
            ]);
        }
    }
}
