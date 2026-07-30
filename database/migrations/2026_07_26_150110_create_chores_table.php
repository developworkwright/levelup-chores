<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('points');
            $table->enum('cadence', ['daily', 'weekly']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chores');
    }
};
