<?php

namespace Database\Factories;

use App\Models\FeelingEntry;
use App\Models\FeelingReply;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeelingReplyFactory extends Factory
{
    protected $model = FeelingReply::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'feeling_entry_id' => FeelingEntry::factory(),
            'profile_id' => Profile::factory()->parent(),
            'body' => 'I saw that. I am around whenever you want me.',
        ];
    }
}
