<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly OwnerScopeService $ownerScope)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'subject_type' => ['nullable', 'in:transaction,user,expense'],
            'cashier_activity' => ['nullable', 'boolean'],
            'action' => ['nullable', 'in:transaction.deleted,transaction.service_completed_manually'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $logs = AuditLog::query()
            ->with('actor:id,name,username,role')
            ->where('owner_id', $this->ownerScope->idFor($request->user()))
            ->when($validated['subject_type'] ?? null, fn ($query, $type) => $query->where('subject_type', $type))
            ->when($validated['cashier_activity'] ?? false, fn ($query) => $query
                ->where('subject_type', 'transaction')
                ->where(fn ($actor) => $actor->where('metadata->actor_snapshot->role', 'kasir')
                    ->orWhere(fn ($legacy) => $legacy->whereNull('metadata->actor_snapshot->role')
                        ->whereHas('actor', fn ($user) => $user->where('role', 'kasir')))))
            ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->latest()
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 30);

        return response()->json([
            'status' => 'success',
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
