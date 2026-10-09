<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->timestamp('paid_at')->index();
            $table->string('source', 30)->default('payment');
            $table->timestamps();

            $table->index(['owner_id', 'paid_at']);
        });

        // Existing installations only have a cumulative paid_amount. Preserve
        // it as one legacy event; future payments receive their actual time.
        DB::table('transactions')
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    DB::table('transaction_payments')->insert([
                        'owner_id' => $transaction->owner_id,
                        'transaction_id' => $transaction->id,
                        'recorded_by' => $transaction->cashier_id,
                        'amount' => $transaction->paid_amount,
                        'payment_method' => $transaction->payment_method ?? 'cash',
                        // The precise historical payment time was not stored.
                        'paid_at' => $transaction->updated_at ?? $transaction->created_at ?? now(),
                        'source' => 'legacy_balance',
                        'created_at' => $transaction->updated_at ?? $transaction->created_at ?? now(),
                        'updated_at' => $transaction->updated_at ?? $transaction->created_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_payments');
    }
};
