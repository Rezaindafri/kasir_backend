<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Super Owner
        User::create([
            'name' => 'Super Owner Laundry',
            'username' => 'superowner',
            'email' => 'super@laundry.com',
            'password' => Hash::make('super123'),
            'role' => 'super_owner',
        ]);

        // 2. Owner
        $owner = User::create([
            'name' => 'Owner Laundry',
            'username' => 'owner',
            'email' => 'owner@laundry.com',
            'password' => Hash::make('owner123'),
            'role' => 'owner',
        ]);

        // 3. Kasir
        User::create([
            'name' => 'Kasir Utama',
            'username' => 'kasir',
            'email' => 'kasir@laundry.com',
            'password' => Hash::make('kasir123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
        ]);
    }
}
