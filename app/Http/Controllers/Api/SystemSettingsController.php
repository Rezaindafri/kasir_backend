<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AccountPolicyService;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    public function __construct(private readonly AccountPolicyService $accountPolicy)
    {
    }

    public function show()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'system' => [
                    'application_name' => config('app.name'),
                    'api_status' => 'online',
                    'server_time' => now()->toIso8601String(),
                ],
                'account_policy' => [
                    'password_min_length' => $this->accountPolicy->passwordMinLength(),
                ],
                // Intentionally restricted to account-management events. No
                // transaction, revenue, or operational activity is returned.
                'recent_account_changes' => AuditLog::query()
                    ->with('actor:id,name,username,role')
                    ->where('subject_type', 'user')
                    ->latest()
                    ->limit(20)
                    ->get()
                    ->map(function (AuditLog $log) {
                        $snapshot = ($log->metadata['after'] ?? null)
                            ?: ($log->metadata['before'] ?? null)
                            ?: [];

                        return [
                            'id' => $log->id,
                            'action' => $log->action,
                            'actor_name' => $log->actor?->name ?? 'Sistem',
                            'actor_username' => $log->actor?->username,
                            'subject_id' => $log->subject_id,
                            'subject_name' => $snapshot['name'] ?? ('Akun #' . $log->subject_id),
                            'subject_username' => $snapshot['username'] ?? null,
                            'created_at' => $log->created_at?->toIso8601String(),
                        ];
                    })->values(),
            ],
        ]);
    }

    public function accountPolicy()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'password_min_length' => $this->accountPolicy->passwordMinLength(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'password_min_length' => ['required', 'integer', 'min:6', 'max:128'],
        ]);
        $length = $this->accountPolicy->setPasswordMinLength(
            (int) $validated['password_min_length'],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Kebijakan password berhasil diperbarui.',
            'data' => ['password_min_length' => $length],
        ]);
    }
}
