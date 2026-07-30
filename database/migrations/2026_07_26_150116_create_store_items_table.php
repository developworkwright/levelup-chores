<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description');
            $table->unsignedInteger('cost');
            $table->string('color_tag');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_items');
    }
};
