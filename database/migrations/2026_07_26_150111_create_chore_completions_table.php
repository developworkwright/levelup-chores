<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chore_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedInteger('points_awarded');
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'chore_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chore_completions');
    }
};
