<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $appends = ['has_proof_image'];

    public function getHasProofImageAttribute(): bool
    {
        return !empty($this->proof_image_path);
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
        return $this->hasMany(TransactionDetail::class);
    }

    public function payments()
    {
        return $this->hasMany(TransactionPayment::class);
    }
}
