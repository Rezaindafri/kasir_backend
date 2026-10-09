<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'price',
        'unit', // 'kg' atau 'item'
        'estimation_hours',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
