<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('kind', ['earn', 'spend', 'cash_in', 'cash_out', 'adjustment']);
            $table->integer('amount');
            $table->string('description');
            $table->nullableMorphs('related');
            $table->timestamp('created_at');

            $table->index(['household_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
