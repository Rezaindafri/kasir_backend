<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaundrySetting extends Model
{
    protected $fillable = [
        'owner_id',
        'business_name',
        'phone',
        'address',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
