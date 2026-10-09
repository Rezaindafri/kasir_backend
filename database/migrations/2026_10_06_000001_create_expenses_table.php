<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('category', 80);
            $table->string('description', 500)->nullable();
            // Waktu kejadian disengaja terpisah dari created_at supaya Owner
            // dapat mencatat pengeluaran yang terjadi sebelumnya.
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['owner_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
