<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'service_id',
        'qty',
        'price',
        'subtotal',
        'service_name',
        'service_unit',
        'timing_configured',
        'estimated_finish_at',
        'late_at',
        'express_fee',
        'is_collected',
        'collected_at',
        'collected_by',
        'proof_image_path',
    ];

    protected $casts = [
        'timing_configured' => 'boolean',
        'is_collected' => 'boolean',
        'estimated_finish_at' => 'datetime',
        'late_at' => 'datetime',
        'collected_at' => 'datetime',
    ];

    protected $appends = ['laundry_status', 'is_ready', 'has_proof_image'];
    protected $hidden = ['transaction', 'proof_image_path'];

    public function getLaundryStatusAttribute(): string
    {
        if ($this->is_collected) return 'collected';
        // Preserve compatibility for old rows without a known line deadline.
        if (!$this->timing_configured) return $this->transaction->laundry_status ?? 'received';
        if ($this->late_at && now()->gt($this->late_at)) return 'late';
        if (!$this->estimated_finish_at || now()->gte($this->estimated_finish_at)) return 'completed';
        return 'received';
    }

    public function getIsReadyAttribute(): bool
    {
        return in_array($this->laundry_status, ['completed', 'late'], true);
    }

    public function getHasProofImageAttribute(): bool
    {
        return !empty($this->proof_image_path);
    }

    // Detail ini milik 1 transaksi
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    // Detail ini merujuk ke 1 jenis layanan
    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
