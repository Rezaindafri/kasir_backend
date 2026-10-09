<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\AuditLogService;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly OwnerScopeService $ownerScope,
        private readonly AuditLogService $auditLog,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ownerId = $this->ownerScope->idFor($request->user());
        $expenses = Expense::query()
            ->with('recorder:id,name,username')
            ->where('owner_id', $ownerId)
            ->when(
                $validated['start_date'] ?? null,
                fn ($query, $start) => $query->where('occurred_at', '>=', now()->parse($start)->startOfDay()),
            )
            ->when(
                $validated['end_date'] ?? null,
                fn ($query, $end) => $query->where('occurred_at', '<=', now()->parse($end)->endOfDay()),
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 30);

        return response()->json([
            'status' => 'success',
            'data' => $expenses->items(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'total' => $expenses->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);
        $ownerId = $this->ownerScope->idFor($request->user());

        $expense = DB::transaction(function () use ($validated, $ownerId, $request): Expense {
            $expense = Expense::create([
                ...$validated,
                'owner_id' => $ownerId,
                'recorded_by' => $request->user()->id,
            ]);
            $this->auditLog->record(
                $request->user(),
                $ownerId,
                'expense',
                $expense->id,
                'expense.created',
                ['after' => $this->auditSnapshot($expense)],
            );

            return $expense;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Pengeluaran berhasil dicatat.',
            'data' => $expense->load('recorder:id,name,username'),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $expense = $this->expenseFor($request, $id);
        $validated = $this->validatedPayload($request);

        $updated = DB::transaction(function () use ($expense, $validated, $request): Expense {
            $before = $this->auditSnapshot($expense);
            $expense->update($validated);
            $fresh = $expense->fresh();
            $this->auditLog->record(
                $request->user(),
                $fresh->owner_id,
                'expense',
                $fresh->id,
                'expense.updated',
                [
                    'before' => $before,
                    'after' => $this->auditSnapshot($fresh),
                ],
            );

            return $fresh;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Pengeluaran berhasil diperbarui.',
            'data' => $updated->load('recorder:id,name,username'),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $expense = $this->expenseFor($request, $id);

        DB::transaction(function () use ($expense, $request): void {
            $snapshot = $this->auditSnapshot($expense);
            $this->auditLog->record(
                $request->user(),
                $expense->owner_id,
                'expense',
                $expense->id,
                'expense.deleted',
                ['before' => $snapshot],
            );
            $expense->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Pengeluaran berhasil dihapus.',
        ]);
    }

    private function expenseFor(Request $request, string $id): Expense
    {
        return Expense::query()
            ->where('owner_id', $this->ownerScope->idFor($request->user()))
            ->findOrFail($id);
    }

    /** @return array{amount: float, category: string, description: ?string, occurred_at: string} */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999999.99'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['required', 'date'],
        ]);
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(Expense $expense): array
    {
        return [
            'amount' => (int) $expense->amount,
            'category' => $expense->category,
            'description' => $expense->description,
            'occurred_at' => $expense->occurred_at?->toIso8601String(),
        ];
    }
}
