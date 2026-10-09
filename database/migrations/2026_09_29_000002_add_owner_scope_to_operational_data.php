<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();
        });

        foreach (['customers', 'services', 'transactions', 'laundry_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('owner_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        // Semua data sebelum fitur kelompok owner hanya dimiliki oleh satu
        // usaha. Saat ada tepat satu owner, hubungan lama dapat dimigrasikan
        // tanpa tebakan atau pencampuran antar-owner.
        $owners = DB::table('users')->where('role', 'owner')->pluck('id');
        if ($owners->count() === 1) {
            $ownerId = $owners->first();

            DB::table('users')
                ->where('role', 'kasir')
                ->whereNull('owner_id')
                ->update(['owner_id' => $ownerId]);

            foreach (['customers', 'services', 'transactions', 'laundry_settings'] as $tableName) {
                DB::table($tableName)
                    ->whereNull('owner_id')
                    ->update(['owner_id' => $ownerId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['customers', 'services', 'transactions', 'laundry_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('owner_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
