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
    ];

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