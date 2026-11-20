<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Transaction extends Model
{
    use HasFactory;

    protected $appends = ['has_proof_image', 'has_ready_items', 'is_processing'];

    // Old detail rows inherit their invoice deadline, not its aggregate status
    // once another line is completed manually. This avoids circular readiness.
    public function legacyLaundryStatus(): string
    {
        if (in_array($this->laundry_status, ['collected', 'late'], true) ||
            !$this->estimated_finish_at || now()->lt($this->estimated_finish_at)) {
            return $this->laundry_status ?? 'received';
        }
        $finish = Carbon::parse($this->estimated_finish_at);
        $duration = max(0, $finish->diffInSeconds(Carbon::parse($this->created_at), true));
        return now()->gt($finish->copy()->addSeconds($duration)) ? 'late' : 'completed';
    }

    public function getHasReadyItemsAttribute(): bool
    {
        if ($this->laundry_status === 'collected') return false;
        $lines = $this->transactionDetails;
        if ($lines->contains(fn ($line) => $line->timing_configured || $line->manually_completed_at)) {
            return $lines->contains(fn ($line) => !$line->is_collected && $line->is_ready);
        }
        return in_array($this->legacyLaundryStatus(), ['completed', 'late'], true);
    }

    public function getIsProcessingAttribute(): bool
    {
        if ($this->laundry_status === 'collected') return false;
        $lines = $this->transactionDetails;
        if ($lines->isNotEmpty()) {
            return $lines->contains(fn ($line) => !$line->is_collected && !$line->is_ready &&
                ($line->timing_configured ? $line->estimated_finish_at : $this->estimated_finish_at)?->gt(now()));
        }
        return in_array($this->legacyLaundryStatus(), ['received', 'washing', 'drying', 'ironing'], true)
            && $this->estimated_finish_at && $this->estimated_finish_at->gt(now());
    }

    public function hasDueSoonItems(): bool
    {
        if (!$this->is_processing) return false;
        $lines = $this->transactionDetails;
        if ($lines->isNotEmpty()) {
            return $lines->contains(function ($line): bool {
                $finish = $line->timing_configured ? $line->estimated_finish_at : $this->estimated_finish_at;
                return !$line->is_collected && !$line->is_ready && $finish &&
                    $finish->gt(now()) && $finish->lte(now()->addHours(24));
            });
        }
        return $this->estimated_finish_at && $this->estimated_finish_at->lte(now()->addHours(24));
    }

    public function getHasProofImageAttribute(): bool
    {
        return !empty($this->proof_image_path);
    }

    // Mixed orders can have both a processing line and a ready line. Counts
    // remain counts of distinct invoices, never joins that duplicate money.
    public function scopeReadyForCollection($query)
    {
        $now = now();
        $legacyReady = fn ($parent) => $parent->whereIn('transactions.laundry_status', ['completed', 'late'])
            ->orWhere('transactions.estimated_finish_at', '<=', $now);
        return $query->where('laundry_status', '!=', 'collected')->where(function ($query) use ($now, $legacyReady) {
            $query->whereHas('transactionDetails', fn ($line) => $line
                ->where('is_collected', false)
                ->where(fn ($ready) => $ready->whereNotNull('manually_completed_at')
                    ->orWhere(fn ($timed) => $timed->where('timing_configured', true)
                        ->where(fn ($date) => $date->whereNull('estimated_finish_at')
                            ->orWhere('estimated_finish_at', '<=', $now)->orWhere('late_at', '<', $now)))
                    ->orWhere(fn ($legacy) => $legacy->where('timing_configured', false)->where($legacyReady))))
                ->orWhere(fn ($legacy) => $legacy
                    ->doesntHave('transactionDetails')->where($legacyReady));
        });
    }

    public function scopeActivelyProcessing($query)
    {
        $now = now();
        $legacyProcessing = fn ($parent) => $parent
            ->whereIn('transactions.laundry_status', ['received', 'washing', 'drying', 'ironing'])
            ->where('transactions.estimated_finish_at', '>', $now);
        return $query->where('laundry_status', '!=', 'collected')->where(function ($query) use ($now, $legacyProcessing) {
            $query->whereHas('transactionDetails', fn ($line) => $line
                ->where('is_collected', false)->whereNull('manually_completed_at')
                ->where(fn ($pending) => $pending
                    ->where(fn ($timed) => $timed->where('timing_configured', true)
                        ->where('estimated_finish_at', '>', $now)
                        ->where(fn ($late) => $late->whereNull('late_at')->orWhere('late_at', '>=', $now)))
                    ->orWhere(fn ($legacy) => $legacy->where('timing_configured', false)->where($legacyProcessing))))
                ->orWhere(fn ($legacy) => $legacy
                    ->doesntHave('transactionDetails')->where($legacyProcessing));
        });
    }

    public function scopePendingServiceCompletion($query)
    {
        $now = now();

        return $query->where('laundry_status', '!=', 'collected')
            ->whereHas('transactionDetails', fn ($line) => $line
                ->where('is_collected', false)
                ->whereNull('manually_completed_at')
                ->where(fn ($pending) => $pending
                    ->where(fn ($timed) => $timed->where('timing_configured', true)
                        ->where('estimated_finish_at', '>', $now)
                        ->where(fn ($late) => $late->whereNull('late_at')
                            ->orWhere('late_at', '>=', $now)))
                    ->orWhere(fn ($legacy) => $legacy->where('timing_configured', false)
                        ->whereIn('transactions.laundry_status', ['received', 'washing', 'drying', 'ironing'])
                        ->where(fn ($date) => $date->whereNull('transactions.estimated_finish_at')
                            ->orWhere('transactions.estimated_finish_at', '>', $now)))));
    }

    protected $fillable = [
        'customer_id',
        'owner_id',
        'cashier_id',
        'invoice_number',
        'total_price',
        'paid_amount',
        'payment_method',
        'payment_status',
        'order_status',
        'laundry_status',
        'estimated_finish_at',
        'notes',
        'proof_image_path',
    ];

    protected $casts = [
        'estimated_finish_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function transactionDetails()
    {
        return $this->hasMany(TransactionDetail::class)->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(TransactionPayment::class);
    }
}
