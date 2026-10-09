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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            // Relasi ke tabel customers
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            
            $table->string('invoice_number')->unique(); // Contoh: INV-20260731-001
            $table->decimal('total_price', 15, 2)->default(0); // Total tagihan
            
            // Status pembayaran dan pesanan
            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
            $table->enum('order_status', ['new', 'processing', 'completed', 'delivered'])->default('new');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
