<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->date('spin_date');
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('multiplier');
            $table->timestamps();

            $table->unique(['profile_id', 'spin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spins');
    }
};
