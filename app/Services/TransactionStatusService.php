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
            ->where('laundry_status', '!=', 'collected')
            ->get()
            ->each(fn (Transaction $transaction) => $this->synchronizeTransaction($transaction));
    }

    public function synchronizeTransaction(Transaction $transaction, bool $recordAudit = true): void
    {
        DB::transaction(function () use ($transaction, $recordAudit): void {
            // A scheduler/model loaded before collection must not overwrite
            // the terminal status committed by another request.
            $current = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            if (!$current) return;
            if ($current->laundry_status === 'collected') {
                $transaction->setRawAttributes($current->getAttributes(), true);
                return;
            }
            $lines = $current->transactionDetails()->get();
            $lines->each(fn ($line) => $line->setRelation('transaction', $current));
            if ($lines->contains(fn ($line) => $line->timing_configured)) {
                $remaining = $lines->where('is_collected', false);
                $status = match (true) {
                    $remaining->isEmpty() => 'collected',
                    $remaining->contains(fn ($line) => $line->laundry_status === 'late') => 'late',
                    $remaining->every(fn ($line) => $line->is_ready) => 'completed',
                    in_array($current->laundry_status, ['received', 'washing', 'drying', 'ironing'], true) => $current->laundry_status,
                    default => 'received',
                };
            } else {
                // Legacy transactions without line timing retain the old
                // deadline/status contract, including unknown/null estimates.
                if ($current->laundry_status === 'late' || !$current->estimated_finish_at || now()->lt($current->estimated_finish_at)) {
                    $transaction->setRawAttributes($current->getAttributes(), true);
                    return;
                }
                $finish = Carbon::parse($current->estimated_finish_at);
                $seconds = max(0, $finish->diffInSeconds(Carbon::parse($current->created_at), true));
                $status = now()->gt($finish->copy()->addSeconds($seconds)) ? 'late' : 'completed';
            }

            if ($current->laundry_status !== $status) {
                $before = $current->only([
                    'laundry_status',
                    'order_status',
                    'estimated_finish_at',
                ]);
                $current->update([
                    'laundry_status' => $status,
                    'order_status' => match ($status) {
                        'collected' => 'delivered',
                        'completed' => 'completed',
                        'received' => 'new',
                        default => 'processing',
                    },
                ]);
                if ($recordAudit) $this->auditLog->record(
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
            $transaction->unsetRelation('transactionDetails');
        });
    }
}
