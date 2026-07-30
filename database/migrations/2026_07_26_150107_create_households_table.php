<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('America/Chicago');
            $table->unsignedTinyInteger('day_boundary_hour')->default(4);
            $table->unsignedInteger('points_per_dollar')->default(100);
            $table->boolean('require_quest_first')->default(true);
            $table->boolean('spin_enabled')->default(true);
            $table->string('goal_name')->nullable();
            $table->unsignedInteger('goal_target')->default(0);
            $table->unsignedInteger('goal_now')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
