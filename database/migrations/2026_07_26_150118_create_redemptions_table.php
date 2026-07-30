<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cost_snapshot');
            $table->enum('status', ['pending', 'fulfilled'])->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by_profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
