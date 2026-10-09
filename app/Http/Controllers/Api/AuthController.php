<?php
//login
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountPolicyService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly AccountPolicyService $accountPolicy,
        private readonly AuditLogService $auditLog,
    )
    {
    }

    public function login(Request $request)
    {
        // 1. Validasi input dari Flutter
        $request->validate([
            'username' => 'required|string|max:100',
            'password' => 'required|string|max:1024',
        ]);

        // 2. Cari user berdasarkan username
        $user = User::where('username', $request->username)->first();

        // 3. Cek ketersediaan user dan kecocokan password
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'code' => 'invalid_credentials',
                'message' => 'Username atau password salah',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'status' => 'error',
                'code' => 'account_inactive',
                'message' => 'Akun dinonaktifkan',
            ], 403);
        }

        // 4. Buat token autentikasi baru menggunakan Sanctum
        $expiresAt = now()->addDays(30);
        $token = $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken;

        // 5. Kembalikan respons JSON ke Flutter
        return response()->json([
            'status' => 'success',
            'message' => 'Login berhasil',
            'data' => [
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'owner_id' => $user->owner_id,
                    'is_active' => $user->is_active,
                ]
            ]
        ], 200);
    }

    public function logout(Request $request)
    {
        // Hapus token yang sedang digunakan saat logout
        $token = $request->user()->currentAccessToken();
        if (!$token instanceof PersonalAccessToken) {
            abort(401, 'Sesi tidak berlaku.');
        }
        $token->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout berhasil',
        ], 200);
    }

    public function me(Request $request)
    {
        // Mengembalikan data user yang sedang login
        return response()->json([
            'status' => 'success',
            'data' => $request->user(),
        ], 200);
    }

    public function profile(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->fresh(),
        ], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Kasir boleh memperbarui nama tampilan, tetapi kredensialnya dikelola
        // oleh owner/super owner agar identitas login operasional tetap stabil.
        if ($user->role === 'kasir' && $request->has('username')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kasir tidak berhak mengubah username.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:100', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ]);

        $fresh = DB::transaction(function () use ($user, $validated): User {
            $before = $user->only(['name', 'username', 'email']);
            $user->update($validated);
            $fresh = $user->fresh();
            $this->auditLog->record(
                $fresh,
                $this->operationalOwnerId($fresh),
                'user',
                $fresh->id,
                'user.profile_updated',
                [
                    'before' => $before,
                    'after' => $fresh->only(['name', 'username', 'email']),
                ],
            );

            return $fresh;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui',
            'data' => $fresh,
        ], 200);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'kasir') {
            return response()->json([
                'status' => 'error',
                'message' => 'Kasir tidak berhak mengubah password.',
            ], 403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:' . $this->accountPolicy->passwordMinLength(), 'confirmed'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password saat ini salah',
            ], 422);
        }

        $currentToken = $user->currentAccessToken();
        DB::transaction(function () use ($user, $validated, $currentToken): void {
            $user->update([
                'password' => Hash::make($validated['password']),
            ]);
            $fresh = $user->fresh();
            $this->auditLog->record(
                $fresh,
                $this->operationalOwnerId($fresh),
                'user',
                $fresh->id,
                'user.password_changed',
            );
            // Keep only this already-authenticated session. For a stateful
            // session without a personal token, revoke every bearer token.
            $user->tokens()
                ->when($currentToken instanceof PersonalAccessToken,
                    fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                ->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Password berhasil diperbarui',
            'data' => $user->fresh(),
        ], 200);
    }

    private function operationalOwnerId(User $user): ?int
    {
        if ($user->role === 'owner') {
            return $user->id;
        }

        return $user->role === 'kasir' ? $user->owner_id : null;
    }
}
