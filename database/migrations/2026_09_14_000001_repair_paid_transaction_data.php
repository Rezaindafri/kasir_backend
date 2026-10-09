<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('transactions')
            ->where('payment_status', 'paid')
            ->whereRaw('paid_amount < total_price')
            ->update([
                'paid_amount' => DB::raw('total_price'),
            ]);

        DB::table('transactions')
            ->whereRaw('paid_amount >= total_price')
            ->where('payment_status', '!=', 'paid')
            ->update([
                'payment_status' => 'paid',
            ]);

        DB::table('transactions')
            ->where('payment_status', 'paid')
            ->where('paid_amount', '<=', 0)
            ->update([
                'paid_amount' => DB::raw('total_price'),
            ]);

        DB::table('transactions')
            ->where('payment_status', '!=', 'paid')
            ->where('paid_amount', '>', 0)
            ->whereRaw('paid_amount < total_price')
            ->update([
                'payment_status' => 'unpaid',
            ]);

        DB::table('transactions')
            ->whereNull('payment_method')
            ->update([
                'payment_method' => 'cash',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid accidental rollback of historical data.
    }
};
