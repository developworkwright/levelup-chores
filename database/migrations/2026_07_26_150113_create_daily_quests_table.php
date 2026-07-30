<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->date('quest_date');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'quest_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_quests');
    }
};
