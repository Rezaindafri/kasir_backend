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
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'cashier_id')) {
                $table->unsignedBigInteger('cashier_id')->nullable()->after('customer_id');
            }

            if (!Schema::hasColumn('transactions', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('total_price');
            }

            if (!Schema::hasColumn('transactions', 'payment_method')) {
                $table->string('payment_method')->nullable()->default('cash')->after('paid_amount');
            }

            if (!Schema::hasColumn('transactions', 'laundry_status')) {
                $table->enum('laundry_status', [
                    'received',
                    'washing',
                    'drying',
                    'completed',
                    'collected',
                    'late',
                ])->default('received')->after('payment_status');
            }

            if (!Schema::hasColumn('transactions', 'estimated_finish_at')) {
                $table->timestamp('estimated_finish_at')->nullable()->after('laundry_status');
            }

            if (!Schema::hasColumn('transactions', 'notes')) {
                $table->text('notes')->nullable()->after('estimated_finish_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'cashier_id',
                'paid_amount',
                'payment_method',
                'laundry_status',
                'estimated_finish_at',
                'notes',
            ]);
        });
    }
};
