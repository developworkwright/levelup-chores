<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_mysteries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('mystery_date');
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['household_id', 'mystery_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_mysteries');
    }
};
