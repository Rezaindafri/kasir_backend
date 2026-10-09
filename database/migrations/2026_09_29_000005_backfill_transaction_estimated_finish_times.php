<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Old transactions can lack a deadline because it was previously
        // supplied by Flutter. Derive it from the longest linked service so
        // scheduler calculations are server-owned for historical data too.
        DB::table('transactions')
            ->select(['id', 'created_at'])
            ->whereNull('estimated_finish_at')
            ->orderBy('id')
            ->chunkById(200, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $duration = DB::table('transaction_details')
                        ->join('services', 'services.id', '=', 'transaction_details.service_id')
                        ->where('transaction_details.transaction_id', $transaction->id)
                        ->max('services.estimation_hours');

                    if ($duration === null) {
                        continue;
                    }

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'estimated_finish_at' => Carbon::parse($transaction->created_at)
                                ->addHours(max(1, (int) $duration)),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Backfilled values are deliberately retained: they are derived from
        // existing service data and deleting them would regress status logic.
    }
};
