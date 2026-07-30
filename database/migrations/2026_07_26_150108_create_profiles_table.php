<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('role', ['kid', 'parent']);
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('color');
            $table->string('pin_hash');
            $table->unsignedTinyInteger('failed_pin_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('streak')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
