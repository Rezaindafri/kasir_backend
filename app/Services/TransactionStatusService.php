<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionStatusService
{
    public function __construct(private readonly AuditLogService $auditLog)
    {
    }

    /**
     * Centralizes time-based status changes so every client receives the same
     * completed/late status. The late grace period equals the service duration.
     */
    public function synchronize(?int $ownerId = null): void
    {
        Transaction::query()
            ->when($ownerId, fn ($query) => $query->where('owner_id', $ownerId))
            ->whereNotIn('laundry_status', ['collected', 'late'])
            ->whereNotNull('estimated_finish_at')
            ->get()
            ->each(fn (Transaction $transaction) => $this->synchronizeTransaction($transaction));
    }

    public function synchronizeTransaction(Transaction $transaction): void
    {
        if (in_array($transaction->laundry_status, ['collected', 'late'], true) ||
            !$transaction->estimated_finish_at) {
            return;
        }

        $now = now();
        $estimatedFinishAt = Carbon::parse($transaction->estimated_finish_at);
        if ($now->lt($estimatedFinishAt)) {
            return;
        }

        $createdAt = Carbon::parse($transaction->created_at);
        $serviceDurationSeconds = max(0, $estimatedFinishAt->diffInSeconds($createdAt, true));
        $lateAt = $estimatedFinishAt->copy()->addSeconds($serviceDurationSeconds);
        $status = $now->gt($lateAt) ? 'late' : 'completed';

        if ($transaction->laundry_status !== $status) {
            DB::transaction(function () use ($transaction, $status): void {
                $before = $transaction->only([
                    'laundry_status',
                    'order_status',
                    'estimated_finish_at',
                ]);
                $transaction->update([
                    'laundry_status' => $status,
                    'order_status' => $status === 'late' ? 'processing' : 'completed',
                ]);
                $this->auditLog->record(
                    null,
                    $transaction->owner_id,
                    'transaction',
                    $transaction->id,
                    'transaction.status_synchronized',
                    [
                        'before' => $before,
                        'after' => $transaction->fresh()->only([
                            'laundry_status',
                            'order_status',
                            'estimated_finish_at',
                        ]),
                    ],
                );
            });
        }
    }
}
