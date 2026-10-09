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
        DB::transaction(function () use ($transaction): void {
            // A scheduler/model loaded before collection must not overwrite
            // the terminal status committed by another request.
            $current = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            if (!$current) return;
            if (in_array($current->laundry_status, ['collected', 'late'], true) ||
                !$current->estimated_finish_at) {
                $transaction->setRawAttributes($current->getAttributes(), true);
                return;
            }

            $now = now();
            $estimatedFinishAt = Carbon::parse($current->estimated_finish_at);
            if ($now->lt($estimatedFinishAt)) {
                $transaction->setRawAttributes($current->getAttributes(), true);
                return;
            }
            $createdAt = Carbon::parse($current->created_at);
            $serviceDurationSeconds = max(0, $estimatedFinishAt->diffInSeconds($createdAt, true));
            $lateAt = $estimatedFinishAt->copy()->addSeconds($serviceDurationSeconds);
            $status = $now->gt($lateAt) ? 'late' : 'completed';

            if ($current->laundry_status !== $status) {
                $before = $current->only([
                    'laundry_status',
                    'order_status',
                    'estimated_finish_at',
                ]);
                $current->update([
                    'laundry_status' => $status,
                    'order_status' => $status === 'late' ? 'processing' : 'completed',
                ]);
                $this->auditLog->record(
                    null,
                    $current->owner_id,
                    'transaction',
                    $current->id,
                    'transaction.status_synchronized',
                    [
                        'before' => $before,
                        'after' => $current->only([
                            'laundry_status',
                            'order_status',
                            'estimated_finish_at',
                        ]),
                    ],
                );
            }
            $transaction->setRawAttributes($current->getAttributes(), true);
        });
    }
}
