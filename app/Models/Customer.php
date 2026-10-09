<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    // Tambahkan baris ini
    protected $fillable = [
        'owner_id',
        'name',
        'phone',
        'address',
        'gender', 
    ];

    // 1 Pelanggan bisa punya banyak transaksi
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
