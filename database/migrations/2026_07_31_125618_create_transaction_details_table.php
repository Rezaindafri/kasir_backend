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
        Schema::create('transaction_details', function (Blueprint $table) {
            $table->id();
            // Relasi ke transaksi dan layanan
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            
            // Menggunakan decimal (8,2) agar bisa menyimpan angka koma (misal 1.5 kg)
            $table->decimal('qty', 8, 2); 
            
            // Harga saat transaksi terjadi (untuk riwayat jika harga berubah di masa depan)
            $table->decimal('price', 15, 2); 
            $table->decimal('subtotal', 15, 2);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_details');
    }
};
