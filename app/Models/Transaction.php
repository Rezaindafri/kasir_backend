<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $appends = ['has_proof_image', 'has_ready_items', 'is_processing'];

    public function getHasReadyItemsAttribute(): bool
    {
        if ($this->laundry_status === 'collected') return false;
        $lines = $this->transactionDetails;
        if ($lines->contains(fn ($line) => $line->timing_configured || $line->manually_completed_at)) {
            return $lines->contains(fn ($line) => !$line->is_collected && $line->is_ready);
        }
        return in_array($this->laundry_status, ['completed', 'late'], true);
    }

    public function getIsProcessingAttribute(): bool
    {
        if ($this->laundry_status === 'collected') return false;
        $lines = $this->transactionDetails;
        if ($lines->contains(fn ($line) => $line->timing_configured || $line->manually_completed_at)) {
            return $lines->contains(fn ($line) => !$line->is_collected && !$line->is_ready);
        }
        return in_array($this->laundry_status, ['received', 'washing', 'drying', 'ironing'], true)
            && $this->estimated_finish_at && $this->estimated_finish_at->gt(now());
    }

    public function hasDueSoonItems(): bool
    {
        if (!$this->is_processing) return false;
        $lines = $this->transactionDetails;
        if ($lines->contains(fn ($line) => $line->timing_configured || $line->manually_completed_at)) {
            return $lines->contains(fn ($line) => !$line->is_collected && !$line->is_ready && $line->estimated_finish_at
                && $line->estimated_finish_at->gt(now()) && $line->estimated_finish_at->lte(now()->addHours(24)));
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
        return $query->where('laundry_status', '!=', 'collected')->where(function ($query) {
            $query->whereHas('transactionDetails', fn ($line) => $line
                ->where('is_collected', false)
                ->where(fn ($ready) => $ready->whereNotNull('manually_completed_at')
                    ->orWhere(fn ($timed) => $timed->where('timing_configured', true)
                        ->where(fn ($date) => $date->whereNull('estimated_finish_at')
                            ->orWhere('estimated_finish_at', '<=', now())))))
                ->orWhere(fn ($legacy) => $legacy
                    ->whereDoesntHave('transactionDetails', fn ($line) => $line->where('timing_configured', true)
                        ->orWhereNotNull('manually_completed_at'))
                    ->whereIn('laundry_status', ['completed', 'late']));
        });
    }

    public function scopeActivelyProcessing($query)
    {
        return $query->where('laundry_status', '!=', 'collected')->where(function ($query) {
            $query->whereHas('transactionDetails', fn ($line) => $line
                ->where('timing_configured', true)->where('is_collected', false)
                ->whereNull('manually_completed_at')->where('estimated_finish_at', '>', now()))
                ->orWhere(fn ($legacy) => $legacy
                    ->whereDoesntHave('transactionDetails', fn ($line) => $line->where('timing_configured', true)
                        ->orWhereNotNull('manually_completed_at'))
                    ->whereIn('laundry_status', ['received', 'washing', 'drying', 'ironing'])
                    ->where('estimated_finish_at', '>', now()));
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
                        ->whereIn('transactions.laundry_status', ['received', 'washing', 'drying', 'ironing']))));
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
