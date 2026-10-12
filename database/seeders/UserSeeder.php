<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AccountPolicyService;
use App\Services\AuditLogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Bootstrap only. Never overwrite accounts or recreate demo users.
        if (User::exists()) {
            $this->command?->warn('Sudah ada akun. Pembuatan akun awal dibatalkan.');
            return;
        }
        if (!$this->command || $this->command->option('no-interaction')) {
            throw new \RuntimeException('Pembuatan akun awal memerlukan terminal interaktif.');
        }
        $minimum = max(12, app(AccountPolicyService::class)->passwordMinLength());
        $name = trim((string) $this->command->ask('Nama Super Owner'));
        $username = trim((string) $this->command->ask('Username login'));
        $email = trim((string) $this->command->ask('Email (opsional, Enter untuk kosong)'));
        $data = [
            'name' => $name,
            'username' => $username,
            'email' => $email === '' ? null : $email,
            // No plaintext fallback when hidden input is unavailable.
            'password' => $this->command->secret('Password minimal ' . $minimum . ' karakter', false),
        ];
        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:' . $minimum, 'max:1024'],
        ])->validate();
        $confirmation = $this->command->secret('Ulangi password', false);
        if ($data['password'] !== $confirmation) {
            $this->command->error('Password tidak sama. Akun belum dibuat.');
            return;
        }
        $user = DB::transaction(function () use ($data): ?User {
            // Another administrator may have created an account while prompting.
            if (User::exists()) return null;
            // User model has a hashed cast; no plaintext credential is persisted.
            $user = User::create([...$data, 'role' => 'super_owner',
                'owner_id' => null, 'is_active' => true]);
            app(AuditLogService::class)->record(null, null, 'user', (int) $user->id,
                'user.created', ['role' => 'super_owner', 'owner_id' => null,
                    'is_active' => true, 'bootstrap' => true, 'source' => 'UserSeeder']);
            return $user;
        });
        unset($data, $confirmation);
        if ($user === null) {
            $this->command->warn('Sudah ada akun. Pembuatan akun awal dibatalkan.');
            return;
        }
        $this->command->info('Akun Super Owner berhasil dibuat.');
    }
}
