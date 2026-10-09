<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Contoh: Cuci Komplit, Setrika
            $table->decimal('price', 12, 2); // Harga per unit/kg
            $table->string('unit', 50); // Satuan harga
            $table->integer('estimation_hours')->default(24); // Estimasi pengerjaan
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
