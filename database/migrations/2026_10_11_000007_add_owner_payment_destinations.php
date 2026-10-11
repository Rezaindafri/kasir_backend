<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name', 100);
            $table->string('bank_name', 80)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->text('account_number')->nullable();
            $table->string('qris_image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['owner_id', 'kind', 'is_active']);
        });
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->foreignId('payment_destination_id')->nullable()
                ->constrained('payment_destinations')->restrictOnDelete();
            // Historical label only; no duplicate plaintext account number.
            $table->string('payment_destination_label', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_destination_id');
            $table->dropColumn('payment_destination_label');
        });
        Schema::dropIfExists('payment_destinations');
    }
};
