<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Services\TransactionStatusService;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TransactionStatusService $statusService,
        private readonly OwnerScopeService $ownerScope,
    )
    {
    }

    public function owner(Request $request)
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month,year,custom'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cashier_id' => ['nullable', 'integer'],
        ]);
        $ownerId = $this->ownerScope->idFor($request->user());
        // Request Owner hanya boleh menyinkronkan kelompoknya sendiri.
        // Sinkronisasi seluruh owner tetap dijalankan scheduler.
        $this->statusService->synchronize($ownerId);
        $now = now();
        $startOfDay = $now->copy()->startOfDay();
        $period = $validated['period'] ?? 'month';
        [$rangeStart, $rangeEnd] = $this->dashboardPeriodRange($period, $validated, $now);

        $cashierId = $validated['cashier_id'] ?? null;
        if ($cashierId && !$this->isOwnerCashier($ownerId, (int) $cashierId)) {
            abort(422, 'Kasir tidak berada di kelompok owner ini.');
        }

        $transactions = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->when($cashierId, fn ($query) => $query->where('cashier_id', $cashierId))
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->get();

        $todayRevenue = $this->revenueFor($ownerId, $startOfDay, $now, $cashierId);
        // Operational status cards are live queues, independent from the
        // financial/date period used by the dashboard. Match the cashier scope.
        $operationalQuery = Transaction::query()
            ->where('owner_id', $ownerId)
            ->when($cashierId, fn ($query) => $query->where('cashier_id', $cashierId))
            ->where('laundry_status', '!=', 'collected');
        $processingStatuses = ['received', 'washing', 'drying', 'ironing'];
        $processing = (clone $operationalQuery)
            ->whereIn('laundry_status', $processingStatuses)
            ->whereNotNull('estimated_finish_at')
            ->where('estimated_finish_at', '>', $now)
            ->count();
        $readyToPickup = (clone $operationalQuery)
            ->whereIn('laundry_status', ['completed', 'late'])
            ->count();
        $late = (clone $operationalQuery)
            ->where('laundry_status', 'late')
            ->count();
        $dueSoon = (clone $operationalQuery)
            ->whereIn('laundry_status', $processingStatuses)
            ->whereBetween('estimated_finish_at', [$now, $now->copy()->addHours(24)])
            ->count();
        $serviceDistribution = $transactions->flatMap(fn (Transaction $transaction) => $transaction->transactionDetails)
            ->groupBy('service_id')
            ->map(fn ($details) => [
                'label' => $details->first()->service?->name ?? 'Layanan',
                'count' => $details->count(),
            ])
            ->sortByDesc('count')
            ->take(4)
            ->values();
        $activeCustomers = $transactions->pluck('customer_id')->filter()->unique()->count();
        $customerNames = $transactions->pluck('customer.name')->filter()->unique()->take(3)->values();
        $activeCustomerItems = $transactions
            ->pluck('customer')
            ->filter()
            ->unique('id')
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
            ])
            ->values();
        $cashiers = User::query()
            ->where('role', 'kasir')
            ->where('owner_id', $ownerId)
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'is_active']);
        // Owner dapat mencatat transaksi dan pembayaran juga. Masukkan akun
        // pemilik ke opsi filter agar angka "Keseluruhan" dapat diurai tanpa
        // menyamaratakannya sebagai transaksi kasir.
        $accounts = $cashiers->prepend(
            User::query()->findOrFail($ownerId, ['id', 'name', 'username', 'is_active'])
        );

        $financial = $this->financialSummary($ownerId, $transactions, $rangeStart, $rangeEnd, $cashierId);
        $expenses = $this->expenseRowsFor($ownerId, $rangeStart, $rangeEnd, $cashierId);
        $attention = $this->attentionItems($transactions, $now);

        $summary = [
            'revenue' => $this->revenueFor($ownerId, $rangeStart, $rangeEnd, $cashierId),
            // revenue dipertahankan demi kompatibilitas klien lama. Untuk
            // tampilan baru gunakan nama eksplisit berikut.
            'order_value' => $financial['order_value'],
            'payments_received' => $financial['payments_received'],
            'payment_count' => $financial['payment_count'],
            'outstanding_amount' => $financial['outstanding_amount'],
            'expenses_total' => (int) $expenses->sum('amount'),
            'expense_count' => $expenses->count(),
            'transactions' => $transactions->count(),
            'customers' => $cashierId
                ? $transactions->pluck('customer_id')->filter()->unique()->count()
                : Customer::where('owner_id', $ownerId)->count(),
            'processing' => $processing,
            'ready_to_pickup' => $readyToPickup,
            'late' => $late,
            'today_revenue' => (int) $todayRevenue,
            'transaction_count' => $transactions->count(),
            'customer_count' => $cashierId
                ? $transactions->pluck('customer_id')->filter()->unique()->count()
                : Customer::where('owner_id', $ownerId)->count(),
            'processing_count' => $processing,
            'ready_to_pickup_count' => $readyToPickup,
            'late_count' => $late,
        ];

        $chart = $this->ownerChart($ownerId, $cashierId, $rangeStart, $rangeEnd);

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => $summary,
                'chart' => $chart,
                'insights' => [
                    'service_distribution' => $serviceDistribution,
                    'popular_services' => $serviceDistribution->take(3)->values(),
                    'monitoring' => [
                        'processing' => $processing,
                        'due_soon' => $dueSoon,
                        'late' => $late,
                        'ready_to_pickup' => $readyToPickup,
                    ],
                    'active_customers' => $activeCustomers,
                    'customer_names' => $customerNames,
                    'active_customer_items' => $activeCustomerItems,
                    'attention_items' => $attention,
                ],
                'payment_details' => $financial['payments'],
                'expense_details' => $this->expensePayloads($expenses),
                'order_details' => $financial['orders'],
                'outstanding_items' => $financial['outstanding_items'],
                'account_options' => ['Keseluruhan', ...$accounts->pluck('name')->all()],
                'accounts' => $accounts->map(fn (User $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'username' => $account->username,
                    'is_active' => (bool) $account->is_active,
                    'is_owner' => $account->id === $ownerId,
                ])->values(),
                'period_options' => ['Minggu ini', 'Bulan ini', 'Tahun ini'],
                'period' => $period,
                'account' => $cashierId ? (string) $cashierId : 'all',
            ],
        ], 200);
    }

    /**
     * Data keuangan yang kecil dan khusus kartu pembayaran/grafik Owner.
     * Endpoint ini memungkinkan Flutter menyiapkan beberapa periode tanpa
     * memuat ulang seluruh insight operasional dashboard.
     */
    public function ownerFinancial(Request $request)
    {
        $validated = $request->validate([
            'period' => ['required', 'in:today,week,month,year'],
            'cashier_id' => ['nullable', 'integer'],
        ]);
        $ownerId = $this->ownerScope->idFor($request->user());
        $cashierId = $validated['cashier_id'] ?? null;
        if ($cashierId && !$this->isOwnerCashier($ownerId, (int) $cashierId)) {
            abort(422, 'Kasir tidak berada di kelompok owner ini.');
        }

        $now = now();
        [$start, $end] = $this->dashboardPeriodRange($validated['period'], [], $now);
        $payments = $this->paymentRowsFor($ownerId, $start, $end, $cashierId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => $validated['period'],
                'summary' => [
                    'payments_received' => (int) $payments->sum('amount'),
                    'payment_count' => $payments->count(),
                ],
                'chart' => $this->ownerChart($ownerId, $cashierId, $start, $end),
                'payment_details' => $this->paymentPayloads($payments),
            ],
        ]);
    }

    public function kasir(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $this->statusService->synchronize($ownerId);
        $now = now();
        $todayStart = $now->copy()->startOfDay();

        $todayTransactions = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->whereBetween('created_at', [$todayStart, $now])
            ->orderByDesc('created_at')
            ->get();

        // Operasional kasir tidak dibatasi transaksi hari ini: cucian yang
        // belum diambil bisa dibuat pada hari sebelumnya dan tetap perlu tampil.
        $operationalTransactions = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->where('laundry_status', '!=', 'collected')
            ->orderBy('estimated_finish_at')
            ->get();

        $todayTransactionsPayload = $todayTransactions->map(fn ($transaction) => $this->transactionPayload($transaction))->values();

        $inProgress = $operationalTransactions->filter(function ($transaction) use ($now) {
            return !empty($transaction->estimated_finish_at) &&
                $transaction->estimated_finish_at->greaterThan($now) &&
                in_array($transaction->laundry_status ?? 'received', [
                'received', 'washing', 'drying', 'ironing',
            ], true);
        })->count();

        $completed = $operationalTransactions->filter(function ($transaction) {
            return ($transaction->laundry_status ?? 'received') === 'completed';
        })->count();

        $late = $operationalTransactions->filter(function ($transaction) {
            return ($transaction->laundry_status ?? 'received') === 'late';
        })->count();

        // "Belum Diambil" is a ready-to-handover queue, not every active
        // order. Laundry still being processed belongs only to Diproses.
        $readyToPickupItems = $operationalTransactions
            ->filter(fn ($transaction) => in_array(
                $transaction->laundry_status ?? 'received',
                ['completed', 'late'],
                true,
            ))
            ->values();

        $dueSoonItems = $operationalTransactions->filter(function ($transaction) use ($now) {
            if (empty($transaction->estimated_finish_at)) {
                return false;
            }

            $status = $transaction->laundry_status ?? 'received';
            if (!in_array($status, ['received', 'washing', 'drying', 'ironing'], true)) {
                return false;
            }

            return $transaction->estimated_finish_at->greaterThanOrEqualTo($now)
                && $transaction->estimated_finish_at->lessThanOrEqualTo($now->copy()->addHours(24));
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'today_transactions' => $todayTransactionsPayload,
                'summary' => [
                    'transactions_today' => $todayTransactions->count(),
                    'in_progress' => $inProgress,
                    'completed' => $completed,
                    'late' => $late,
                    'ready_to_pickup' => $readyToPickupItems->count(),
                    'due_soon' => $dueSoonItems->count(),
                ],
                'in_progress' => $inProgress,
                'completed' => $completed,
                'late' => $late,
                'ready_to_pickup' => $readyToPickupItems->count(),
                'due_soon' => $dueSoonItems->count(),
                'due_soon_items' => $dueSoonItems->map(fn ($transaction) => $this->transactionPayload($transaction))->values(),
                'ready_to_pickup_items' => $readyToPickupItems->map(fn ($transaction) => $this->transactionPayload($transaction))->values(),
                'queue' => $dueSoonItems->map(fn ($transaction) => $this->transactionPayload($transaction))->take(5)->values(),
            ],
        ], 200);
    }

    public function superOwner(Request $request)
    {
        $owners = User::query()
            ->where('role', 'owner')
            ->withCount('managedUsers')
            ->orderBy('name')
            ->get();

        $users = User::query()->where('role', 'kasir');

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'owners' => $owners->count(),
                    'users' => $users->count(),
                    'active_accounts' => User::where('role', '!=', 'super_owner')
                        ->where('is_active', true)
                        ->count(),
                    'inactive_accounts' => User::where('role', '!=', 'super_owner')
                        ->where('is_active', false)
                        ->count(),
                ],
                'owners' => $owners->map(fn (User $owner) => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'username' => $owner->username,
                    'is_active' => (bool) $owner->is_active,
                    'user_count' => $owner->managed_users_count,
                ])->values(),
            ],
        ]);
    }

    public function revenue(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cashier_id' => ['nullable', 'integer'],
        ]);
        $start = $validated['start_date'] ?? now()->subDays(30)->toDateString();
        $end = $validated['end_date'] ?? now()->toDateString();

        $startDate = now()->parse($start)->startOfDay();
        $endDate = now()->parse($end)->endOfDay();
        $cashierId = $validated['cashier_id'] ?? null;
        if ($cashierId && !$this->isOwnerCashier($ownerId, (int) $cashierId)) {
            abort(422, 'Kasir tidak berada di kelompok owner ini.');
        }

        $payments = $this->paymentRowsFor($ownerId, $startDate, $endDate, $cashierId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'revenue' => (int) $payments->sum('amount'),
                'transaction_count' => $payments->pluck('transaction_id')->unique()->count(),
                'items' => $payments->map(fn (TransactionPayment $payment) => [
                    ...$this->transactionPayload($payment->transaction),
                    'paid_amount' => (int) $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'payment_source' => $payment->source,
                ])->values(),
            ],
        ], 200);
    }

    public function statistics(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $this->statusService->synchronize($ownerId);
        $items = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->get();

        $serviceStats = $items->flatMap(fn ($transaction) => $transaction->transactionDetails)->groupBy('service_id')->map(function ($group) {
            $service = $group->first()->service;

            return [
                'service_name' => $service?->name ?? 'Layanan',
                'count' => $group->count(),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'total_revenue' => $this->revenueFor($ownerId),
                    'total_transactions' => $items->count(),
                    'total_customers' => Customer::where('owner_id', $ownerId)->count(),
                    'in_progress' => $items->filter(fn (Transaction $transaction) =>
                        in_array($transaction->laundry_status ?? 'received', ['received', 'washing', 'drying', 'ironing'], true) &&
                        $transaction->estimated_finish_at &&
                        $transaction->estimated_finish_at->greaterThan(now())
                    )->count(),
                    'ready_to_pickup' => $items->filter(fn ($transaction) => in_array(
                        $transaction->laundry_status ?? 'received',
                        ['completed', 'late'],
                        true,
                    ))->count(),
                ],
                'services' => $serviceStats,
            ],
        ], 200);
    }

    /**
     * Laporan periodik yang membedakan nilai pesanan (tanggal dibuat) dari
     * uang yang benar-benar diterima (tanggal pembayaran ledger).
     */
    public function overview(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $this->statusService->synchronize($ownerId);
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cashier_id' => ['nullable', 'integer'],
        ]);
        $start = now()->parse($validated['start_date'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $end = now()->parse($validated['end_date'] ?? now()->toDateString())->endOfDay();
        $cashierId = $validated['cashier_id'] ?? null;
        if ($cashierId && !$this->isOwnerCashier($ownerId, (int) $cashierId)) {
            abort(422, 'Kasir tidak berada di kelompok owner ini.');
        }
        $transactions = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->when($cashierId, fn ($query) => $query->where('cashier_id', $cashierId))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();
        $financial = $this->financialSummary($ownerId, $transactions, $start, $end, $cashierId);
        $expenses = $this->expenseRowsFor($ownerId, $start, $end, $cashierId);
        $now = now();
        $cashiers = User::query()
            ->where('role', 'kasir')
            ->where('owner_id', $ownerId)
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'is_active']);
        $accounts = $cashiers->prepend(
            User::query()->findOrFail($ownerId, ['id', 'name', 'username', 'is_active'])
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                'summary' => [
                    'order_value' => $financial['order_value'],
                    'payments_received' => $financial['payments_received'],
                    'payment_count' => $financial['payment_count'],
                    'outstanding_amount' => $financial['outstanding_amount'],
                    'order_count' => $transactions->count(),
                    'expenses_total' => (int) $expenses->sum('amount'),
                    'expense_count' => $expenses->count(),
                    'net_cash' => (int) $financial['payments_received'] - (int) $expenses->sum('amount'),
                ],
                'payments' => $financial['payments'],
                'expenses' => $this->expensePayloads($expenses),
                'outstanding_items' => $financial['outstanding_items'],
                'operational' => [
                    'orders_received' => $transactions->count(),
                    'completed' => $transactions->where('laundry_status', 'completed')->count(),
                    'late' => $transactions->where('laundry_status', 'late')->count(),
                    'ready_to_pickup' => $transactions->whereIn('laundry_status', ['completed', 'late'])->count(),
                    'in_progress' => $transactions->filter(fn (Transaction $transaction) =>
                        in_array($transaction->laundry_status, ['received', 'washing', 'drying', 'ironing'], true) &&
                        $transaction->estimated_finish_at &&
                        $transaction->estimated_finish_at->greaterThan($now)
                    )->count(),
                ],
                'attention_items' => $this->attentionItems($transactions, $now),
                'accounts' => $accounts->map(fn (User $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'username' => $account->username,
                    'is_active' => (bool) $account->is_active,
                    'is_owner' => $account->id === $ownerId,
                ])->values(),
                // Tren tujuh hari memberi konteks untuk monitoring Hari Ini;
                // tetap mengikuti filter kasir aktif di laporan.
                'chart' => $this->reportPaymentChart($ownerId, $cashierId, $end),
                'payment_methods' => [
                    'cash' => (int) collect($financial['payments'])->where('payment_method', 'cash')->sum('payment_amount'),
                    'qris' => (int) collect($financial['payments'])->where('payment_method', 'qris')->sum('payment_amount'),
                    'transfer' => (int) collect($financial['payments'])->where('payment_method', 'transfer')->sum('payment_amount'),
                ],
                'services' => $transactions->flatMap(fn (Transaction $transaction) => $transaction->transactionDetails)
                    ->groupBy('service_id')
                    ->map(fn ($details) => [
                        'service_name' => $details->first()->service?->name ?? 'Layanan',
                        'count' => $details->count(),
                    ])->sortByDesc('count')->values(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $validated = $request->validate([
            'type' => ['nullable', 'in:csv,json'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cashier_id' => ['nullable', 'integer'],
        ]);
        $type = $validated['type'] ?? 'csv';
        $start = $validated['start_date'] ?? now()->subDays(30)->toDateString();
        $end = $validated['end_date'] ?? now()->toDateString();

        $startDate = now()->parse($start)->startOfDay();
        $endDate = now()->parse($end)->endOfDay();
        $cashierId = $validated['cashier_id'] ?? null;
        if ($cashierId && !$this->isOwnerCashier($ownerId, (int) $cashierId)) {
            abort(422, 'Kasir tidak berada di kelompok owner ini.');
        }

        $payments = $this->paymentRowsFor($ownerId, $startDate, $endDate, $cashierId);

        if ($type !== 'csv') {
            return response()->json([
                'status' => 'success',
                'data' => $payments->map(fn (TransactionPayment $payment) => [
                    ...$this->transactionPayload($payment->transaction),
                    'paid_amount' => (int) $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'payment_source' => $payment->source,
                ])->values(),
            ], 200);
        }

        $columns = ['invoice_number', 'customer_name', 'payment_method', 'payment_amount', 'paid_at', 'source'];
        $rows = [implode(',', $columns)];

        foreach ($payments as $payment) {
            $transaction = $payment->transaction;
            $rows[] = implode(',', [
                $this->csvValue($transaction->invoice_number ?? ''),
                $this->csvValue($transaction->customer?->name ?? ''),
                $this->csvValue($payment->payment_method ?? 'cash'),
                $this->csvValue((string) $payment->amount),
                $this->csvValue($payment->paid_at?->toDateTimeString() ?? ''),
                $this->csvValue($payment->source ?? 'payment'),
            ]);
        }

        return response(implode(PHP_EOL, $rows), 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="laporan-pendapatan-' . now()->format('Ymd') . '.csv"');
    }

    private function csvValue(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /** @return array{0: mixed, 1: mixed} */
    private function dashboardPeriodRange(string $period, array $validated, $now): array
    {
        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'custom' => [
                now()->parse($validated['start_date'] ?? $now->copy()->startOfMonth()->toDateString())->startOfDay(),
                now()->parse($validated['end_date'] ?? $now->toDateString())->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
    }

    /**
     * Pengeluaran selalu dicatat Owner. Saat laporan dipersempit ke kasir,
     * angka pengeluaran sengaja nol agar tidak disalahartikan sebagai biaya
     * yang dibuat kasir tersebut. Filter "Me (Owner)" tetap menampilkannya.
     */
    private function expenseRowsFor(int $ownerId, $start, $end, ?int $cashierId = null)
    {
        if ($cashierId && $cashierId !== $ownerId) {
            return collect();
        }

        return Expense::query()
            ->with('recorder:id,name,username,role')
            ->where('owner_id', $ownerId)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderByDesc('occurred_at')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function expensePayloads($expenses): array
    {
        return $expenses->map(fn (Expense $expense) => [
            'id' => $expense->id,
            'amount' => (int) $expense->amount,
            'category' => $expense->category,
            'description' => $expense->description,
            'occurred_at' => $expense->occurred_at?->toIso8601String(),
            'recorded_by' => $expense->recorder ? [
                'id' => $expense->recorder->id,
                'name' => $expense->recorder->name,
                'username' => $expense->recorder->username,
                'role' => $expense->recorder->role,
            ] : null,
        ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentPayloads($payments): array
    {
        return $payments->map(fn (TransactionPayment $payment) => [
            ...$this->transactionPayload($payment->transaction),
            'payment_id' => $payment->id,
            'payment_amount' => (int) $payment->amount,
            'payment_method' => $payment->payment_method,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'payment_source' => $payment->source,
            'recorded_by' => $payment->recorder ? [
                'id' => $payment->recorder->id,
                'name' => $payment->recorder->name,
                'username' => $payment->recorder->username,
                'role' => $payment->recorder->role,
            ] : null,
        ])->values()->all();
    }

    private function transactionPayload(Transaction $transaction): array
    {
        $firstDetail = $transaction->transactionDetails->first();
        $serviceName = $firstDetail?->service?->name ?? 'Layanan';
        $total = (int) ($transaction->total_price ?? $transaction->total ?? 0);
        $paidAmount = (int) ($transaction->paid_amount ?? 0);
        $status = $transaction->laundry_status ?? $transaction->order_status ?? 'received';

        return [
            'id' => $transaction->id,
            'invoice' => $transaction->invoice_number ?? 'INV-' . str_pad((string) $transaction->id, 5, '0', STR_PAD_LEFT),
            'customer_id' => $transaction->customer_id,
            'customer_name' => $transaction->customer?->name ?? 'Pelanggan',
            'customer_phone' => $transaction->customer?->phone ?? null,
            'service_name' => $serviceName,
            'quantity' => (float) ($firstDetail?->qty ?? 1),
            'quantity_unit' => $firstDetail?->service?->unit ?? 'kg',
            'total' => $total,
            'paid_amount' => $paidAmount,
            'payment_method' => $transaction->payment_method ?? 'cash',
            'payment_status' => $transaction->payment_status ?? 'unpaid',
            'laundry_status' => $status,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'estimated_finish_at' => $transaction->estimated_finish_at?->toIso8601String(),
            'notes' => $transaction->notes,
        ];
    }

    private function isOwnerCashier(int $ownerId, int $cashierId): bool
    {
        return $cashierId === $ownerId || User::query()
            ->whereKey($cashierId)
            ->where('role', 'kasir')
            ->where('owner_id', $ownerId)
            ->exists();
    }

    private function revenueFor(int $ownerId, $start = null, $end = null, ?int $cashierId = null): int
    {
        return (int) $this->paymentRowsFor($ownerId, $start, $end, $cashierId)
            ->sum('amount');
    }

    /**
     * Includes a temporary fallback for transactions created before this
     * payment ledger migration. Newly recorded payments always use their real
     * paid_at timestamp.
     */
    private function paymentRowsFor(int $ownerId, $start = null, $end = null, ?int $cashierId = null)
    {
        $payments = TransactionPayment::query()
            ->with(['transaction.customer', 'transaction.transactionDetails.service', 'recorder:id,name,username,role'])
            ->where('owner_id', $ownerId)
            ->when($start && $end, fn ($query) => $query->whereBetween('paid_at', [$start, $end]))
            // Angka pembayaran milik akun yang benar-benar mencatat
            // pembayaran. Ini membuat filter "Me (Owner)" mencakup
            // pelunasan yang dicatat owner untuk pesanan kasir.
            ->when($cashierId, fn ($query) => $query->where('recorded_by', $cashierId))
            ->orderBy('paid_at')
            ->get();

        $legacyTransactions = Transaction::query()
            ->with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->where('paid_amount', '>', 0)
            ->whereDoesntHave('payments')
            ->when($start && $end, fn ($query) => $query->whereBetween('updated_at', [$start, $end]))
            // Data sebelum payment ledger tidak punya recorded_by. Untuk
            // fallback historis, satu-satunya identitas yang tersedia adalah
            // pembuat transaksi.
            ->when($cashierId, fn ($query) => $query->where('cashier_id', $cashierId))
            ->get()
            ->map(fn (Transaction $transaction) => (new TransactionPayment([
                'owner_id' => $transaction->owner_id,
                'transaction_id' => $transaction->id,
                'amount' => $transaction->paid_amount,
                'payment_method' => $transaction->payment_method,
                'paid_at' => $transaction->updated_at,
                'source' => 'legacy_balance',
            ]))->setRelation('transaction', $transaction));

        return $payments->concat($legacyTransactions)->sortBy('paid_at')->values();
    }

    /** @return array{order_value: int, payments_received: int, payment_count: int, outstanding_amount: int, orders: array, payments: array, outstanding_items: array} */
    private function financialSummary(int $ownerId, $transactions, $start, $end, ?int $cashierId = null): array
    {
        $payments = $this->paymentRowsFor($ownerId, $start, $end, $cashierId);
        $outstanding = $transactions
            ->filter(fn (Transaction $transaction) => (float) $transaction->total_price > (float) ($transaction->paid_amount ?? 0))
            ->map(fn (Transaction $transaction) => [
                ...$this->transactionPayload($transaction),
                'outstanding_amount' => max(0, (int) $transaction->total_price - (int) ($transaction->paid_amount ?? 0)),
            ])->values()->all();

        return [
            'order_value' => (int) $transactions->sum('total_price'),
            'payments_received' => (int) $payments->sum('amount'),
            'payment_count' => $payments->count(),
            'outstanding_amount' => (int) collect($outstanding)->sum('outstanding_amount'),
            'orders' => $transactions->map(fn (Transaction $transaction) =>
                $this->transactionPayload($transaction)
            )->values()->all(),
            'payments' => $this->paymentPayloads($payments),
            'outstanding_items' => $outstanding,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function attentionItems($transactions, $now): array
    {
        return $transactions->map(function (Transaction $transaction) use ($now) {
            $reasons = [];
            if ($transaction->laundry_status === 'late') {
                $reasons[] = 'Laundry terlambat';
            } elseif ($transaction->laundry_status === 'completed') {
                $reasons[] = 'Laundry siap diambil';
            }
            $outstanding = max(0, (int) $transaction->total_price - (int) ($transaction->paid_amount ?? 0));
            if ($outstanding > 0) {
                $reasons[] = 'Tagihan belum lunas';
            }
            if ($reasons === []) {
                return null;
            }
            return [
                ...$this->transactionPayload($transaction),
                'reasons' => $reasons,
                'outstanding_amount' => $outstanding,
                'priority' => $transaction->laundry_status === 'late' ? 0 : ($outstanding > 0 ? 1 : 2),
            ];
        })->filter()->sortBy('priority')->values()->all();
    }

    /** @return array<int, array{label: string, value: int}> */
    private function reportPaymentChart(int $ownerId, ?int $cashierId, $end): array
    {
        $chart = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $date = $end->copy()->subDays($offset);
            $chart[] = [
                'label' => $date->locale('id')->translatedFormat('D'),
                'value' => $this->revenueFor(
                    $ownerId,
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay(),
                    $cashierId,
                ),
            ];
        }
        return $chart;
    }

    /** @return array<int, array{label: string, value: int}> */
    private function ownerChart(int $ownerId, ?int $cashierId, $start, $end): array
    {
        $days = $start->diffInDays($end) + 1;
        $chart = [];
        if ($days <= 31) {
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $chart[] = [
                    // Untuk rentang Bulan ini, label hanya tanggal agar
                    // tidak berulang nama hari dan tetap terbaca di HP.
                    'label' => $days > 7 ? $date->format('d') : $date->locale('id')->translatedFormat('D'),
                    'value' => $this->revenueFor(
                        $ownerId,
                        $date->copy()->startOfDay(),
                        $date->copy()->endOfDay(),
                        $cashierId,
                    ),
                ];
            }
            return $chart;
        }

        for ($date = $start->copy()->startOfMonth(); $date->lte($end); $date->addMonth()) {
            $chart[] = [
                'label' => $date->locale('id')->translatedFormat('MMM'),
                'value' => $this->revenueFor(
                    $ownerId,
                    $date->copy()->startOfMonth(),
                    $date->copy()->endOfMonth(),
                    $cashierId,
                ),
            ];
        }
        return $chart;
    }
}
