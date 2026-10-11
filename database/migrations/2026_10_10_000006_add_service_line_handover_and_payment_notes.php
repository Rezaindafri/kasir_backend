<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->string('service_name')->nullable();
            $table->string('service_unit', 50)->nullable();
            $table->boolean('timing_configured')->default(false);
            $table->timestamp('estimated_finish_at')->nullable();
            $table->dateTime('late_at')->nullable();
            $table->decimal('express_fee', 15, 2)->default(0);
            $table->boolean('is_collected')->default(false);
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proof_image_path')->nullable();
            $table->index(['transaction_id', 'is_collected', 'estimated_finish_at'], 'details_handover_index');
        });
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->uuid('request_id')->nullable();
            $table->unique(['transaction_id', 'request_id'], 'payments_request_unique');
        });

        // Keep historical prices and the ORIGINAL transaction deadline. The
        // current service configuration is not evidence of an old deadline.
        // Historical collection time/actor remain unknown (null), not guessed.
        DB::table('transaction_details')->orderBy('id')->chunkById(200, function ($details): void {
            foreach ($details as $detail) {
                $transaction = DB::table('transactions')->find($detail->transaction_id);
                $service = DB::table('services')->find($detail->service_id);
                $deadline = $transaction?->estimated_finish_at;
                $lateAt = null;
                if ($deadline && $transaction->created_at) {
                    $finish = \Illuminate\Support\Carbon::parse($deadline);
                    $seconds = max(0, $finish->diffInSeconds(\Illuminate\Support\Carbon::parse($transaction->created_at), true));
                    $lateAt = $finish->copy()->addSeconds($seconds);
                }
                DB::table('transaction_details')->where('id', $detail->id)->update([
                    'service_name' => $service?->name,
                    'service_unit' => $service?->unit,
                    'timing_configured' => $deadline !== null,
                    'estimated_finish_at' => $deadline,
                    'late_at' => $lateAt,
                    'is_collected' => $transaction?->laundry_status === 'collected',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->dropUnique('payments_request_unique');
            $table->dropColumn(['notes', 'request_id']);
        });
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropForeign(['collected_by']);
            $table->dropIndex('details_handover_index');
            $table->dropColumn(['service_name', 'service_unit', 'timing_configured', 'estimated_finish_at',
                'late_at', 'express_fee', 'is_collected', 'collected_at', 'collected_by', 'proof_image_path']);
        });
    }
};
