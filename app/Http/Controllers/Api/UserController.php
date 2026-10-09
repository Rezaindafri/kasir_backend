<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LaundrySetting;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountPolicyService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AccountPolicyService $accountPolicy,
    )
    {
    }

    public function index(Request $request)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak',
            ], 403);
        }

        $users = User::query()
            ->when(
                $request->user()->role === 'owner',
                fn ($query) => $query
                    ->where('role', 'kasir')
                    ->where('owner_id', $request->user()->id),
                fn ($query) => $query->whereIn('role', ['owner', 'kasir']),
            )
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ], 200);
    }

    public function store(Request $request)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:' . $this->accountPolicy->passwordMinLength()],
            'role' => ['required', Rule::in($this->manageableRoles($request))],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $ownerId = $this->resolvedOwnerIdForNewUser($request, $validated);

        try {
            $user = DB::transaction(function () use ($validated, $ownerId, $request): User {
                $user = User::create([
                    'name' => $validated['name'],
                    'username' => $validated['username'],
                    'email' => $validated['email'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'role' => $validated['role'],
                    'owner_id' => $ownerId,
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                $this->auditLog->record(
                    $request->user(),
                    $user->role === 'kasir' ? $user->owner_id : null,
                    'user',
                    $user->id,
                    'user.created',
                    ['role' => $user->role, 'owner_id' => $user->owner_id, 'is_active' => (bool) $user->is_active],
                );

                return $user;
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal membuat akun: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Akun berhasil dibuat',
            'data' => $user,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun tidak ditemukan',
            ], 404);
        }

        if (!$this->canManage($request, $user)) {
            return $this->accessDenied();
        }

        return response()->json([
            'status' => 'success',
            'data' => $user,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun tidak ditemukan',
            ], 404);
        }

        if (!$this->canManage($request, $user)) {
            return $this->accessDenied();
        }

        if ($request->hasAny(['username', 'password'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username dan password kasir hanya dapat diubah melalui verifikasi kredensial owner.',
            ], 422);
        }

        $allowedRoles = $user->role === 'super_owner'
            ? ['super_owner']
            : $this->manageableRoles($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:' . $this->accountPolicy->passwordMinLength()],
            'role' => ['sometimes', 'required', Rule::in($allowedRoles)],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $resultRole = $validated['role'] ?? $user->role;

        if ($request->user()->role === 'owner') {
            unset($validated['owner_id']);
            $validated['owner_id'] = $user->owner_id;
        } elseif ($resultRole === 'kasir') {
            $targetOwnerId = $validated['owner_id'] ?? $user->owner_id;
            $this->assertOwnerAccount($targetOwnerId);
            $validated['owner_id'] = $targetOwnerId;
        } else {
            $validated['owner_id'] = null;
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        try {
            $fresh = DB::transaction(function () use ($user, $validated, $request): User {
                $before = $this->auditSnapshot($user);
                $user->update($validated);
                $fresh = $user->fresh();
                $this->auditLog->record(
                    $request->user(),
                    $fresh->role === 'kasir' ? $fresh->owner_id : null,
                    'user',
                    $fresh->id,
                    'user.updated',
                    ['before' => $before, 'after' => $this->auditSnapshot($fresh)],
                );

                return $fresh;
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui akun: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Akun berhasil diperbarui',
            'data' => $fresh,
        ], 200);
    }

    public function updateCredentials(Request $request, string $id)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return $this->accessDenied();
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun tidak ditemukan',
            ], 404);
        }
        if (!$this->canManage($request, $user)) {
            return $this->accessDenied();
        }

        $validated = $request->validate([
            'manager_username' => ['required', 'string'],
            'manager_password' => ['required', 'string'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:' . $this->accountPolicy->passwordMinLength()],
        ]);

        $manager = $request->user();
        if (!hash_equals($manager->username, $validated['manager_username']) ||
            !Hash::check($validated['manager_password'], $manager->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password owner tidak sesuai.',
            ], 422);
        }

        $before = $this->auditSnapshot($user);
        $changes = collect($validated)->only(['name', 'username'])->all();
        if (array_key_exists('password', $validated)) {
            $changes['password'] = Hash::make($validated['password']);
        }
        if ($changes === []) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak ada kredensial yang diubah.',
            ], 422);
        }

        try {
            $fresh = DB::transaction(function () use ($user, $changes, $manager, $before): User {
                $user->update($changes);
                // Username/password baru harus langsung menggantikan sesi
                // lama kasir; token lama tidak boleh tetap berlaku.
                if (array_key_exists('username', $changes) || array_key_exists('password', $changes)) {
                    $user->tokens()->delete();
                }
                $fresh = $user->fresh();
                $this->auditLog->record(
                    $manager,
                    $fresh->role === 'kasir' ? $fresh->owner_id : null,
                    'user',
                    $fresh->id,
                    'user.credentials_updated',
                    ['before' => $before, 'after' => $this->auditSnapshot($fresh)],
                );

                return $fresh;
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui kredensial akun: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Kredensial akun berhasil diperbarui.',
            'data' => $fresh,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun tidak ditemukan',
            ], 404);
        }

        if (!$this->canManage($request, $user)) {
            return $this->accessDenied();
        }

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak dapat menghapus akun sendiri',
            ], 422);
        }

        if ($this->hasHistoricalResponsibilities($user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun yang memiliki data operasional atau riwayat audit tidak dapat dihapus. Nonaktifkan akun atau pindahkan tanggung jawabnya terlebih dahulu.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($user, $request, $id): void {
                $snapshot = $this->auditSnapshot($user);
                $ownerId = $user->role === 'kasir' ? $user->owner_id : null;
                $user->delete();
                $this->auditLog->record(
                    $request->user(),
                    $ownerId,
                    'user',
                    (int) $id,
                    'user.deleted',
                    ['before' => $snapshot],
                );
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus akun: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Akun berhasil dihapus',
        ], 200);
    }

    public function toggleStatus(Request $request, string $id)
    {
        if (!$request->user() || !in_array($request->user()->role, ['owner', 'super_owner'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun tidak ditemukan',
            ], 404);
        }

        if (!$this->canManage($request, $user)) {
            return $this->accessDenied();
        }

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak dapat menonaktifkan akun sendiri',
            ], 422);
        }

        $willBeActive = !(bool) $user->is_active;
        try {
            $fresh = DB::transaction(function () use ($user, $willBeActive, $request): User {
                $before = $this->auditSnapshot($user);
                if (!$willBeActive) {
                    // Status nonaktif harus langsung menghentikan seluruh sesi,
                    // bukan hanya mencegah login berikutnya.
                    $user->tokens()->delete();
                }
                $user->update([
                    'is_active' => $willBeActive,
                ]);
                $fresh = $user->fresh();
                $this->auditLog->record(
                    $request->user(),
                    $fresh->role === 'kasir' ? $fresh->owner_id : null,
                    'user',
                    $fresh->id,
                    'user.status_toggled',
                    ['before' => $before, 'after' => $this->auditSnapshot($fresh)],
                );

                return $fresh;
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah status akun: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Status akun berhasil diperbarui',
            'data' => $fresh,
        ], 200);
    }

    /** @return array<int, string> */
    private function manageableRoles(Request $request): array
    {
        return $request->user()->role === 'super_owner'
            ? ['owner', 'kasir']
            : ['kasir'];
    }

    private function canManage(Request $request, User $target): bool
    {
        if ($request->user()->role === 'super_owner') {
            return in_array($target->role, ['owner', 'kasir'], true);
        }

        return $target->role === 'kasir' &&
            (int) $target->owner_id === (int) $request->user()->id;
    }

    /** @param array<string, mixed> $validated */
    private function resolvedOwnerIdForNewUser(Request $request, array $validated): ?int
    {
        if ($request->user()->role === 'owner') {
            return (int) $request->user()->id;
        }

        if ($validated['role'] === 'owner') {
            return null;
        }

        $ownerId = isset($validated['owner_id']) ? (int) $validated['owner_id'] : null;
        $this->assertOwnerAccount($ownerId);
        return $ownerId;
    }

    private function assertOwnerAccount(?int $ownerId): void
    {
        if (!$ownerId || !User::query()
            ->whereKey($ownerId)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->exists()) {
            abort(422, 'User operasional wajib ditetapkan di bawah akun owner yang aktif.');
        }
    }

    private function accessDenied()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Akses ditolak',
        ], 403);
    }

    private function hasHistoricalResponsibilities(User $user): bool
    {
        if ($user->role === 'owner') {
            return $user->managedUsers()->exists()
                || Customer::where('owner_id', $user->id)->exists()
                || Service::where('owner_id', $user->id)->exists()
                || Transaction::where('owner_id', $user->id)->exists()
                || LaundrySetting::where('owner_id', $user->id)->exists()
                || AuditLog::where('owner_id', $user->id)->exists()
                || AuditLog::where('actor_id', $user->id)->exists();
        }

        return Transaction::where('cashier_id', $user->id)->exists()
            || AuditLog::where('actor_id', $user->id)->exists();
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(User $user): array
    {
        return $user->only(['name', 'username', 'email', 'role', 'owner_id', 'is_active']);
    }
}
