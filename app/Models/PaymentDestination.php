<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentDestination extends Model
{
    protected $fillable = ['owner_id', 'kind', 'name', 'bank_name', 'account_holder',
        'account_number', 'qris_image_path', 'is_active'];
    protected $hidden = ['account_number', 'qris_image_path', 'owner_id'];
    protected $casts = ['account_number' => 'encrypted', 'is_active' => 'boolean'];

    // Call only after authenticating and resolving the operational owner scope.
    public function paymentData(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'name' => $this->name,
            'bank_name' => $this->bank_name, 'account_holder' => $this->account_holder,
            'account_number' => $this->account_number, 'is_active' => $this->is_active,
            'has_qris_image' => !empty($this->qris_image_path)];
    }

    public function label(): string
    {
        return $this->kind === 'transfer'
            ? $this->name . ' - ' . $this->bank_name . ' (' . $this->account_holder . ')'
            : $this->name . ' (QRIS)';
    }
}
