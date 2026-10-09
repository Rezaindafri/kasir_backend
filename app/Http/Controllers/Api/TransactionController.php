<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionPayment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\OwnerScopeService;
use App\Services\AuditLogService;
use App\Services\TransactionStatusService;
use App\Services\BusinessTime;
use App\Services\ProofStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    use RespondsToApiFailures;

    public function __construct(
        private readonly TransactionStatusService $statusService,
        private readonly OwnerScopeService $ownerScope,
        private readonly AuditLogService $auditLog,
        private readonly ProofStorage $proofStorage,
    )
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'invoice' => ['nullable', 'string', 'max:100'],
            'laundry_status' => ['nullable', 'array'],
            'laundry_status.*' => ['in:received,washing,drying,ironing,completed,collected,late'],
            'payment_status' => ['nullable', 'array'],
            'payment_status.*' => ['in:unpaid,downPayment,paid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'collected' => ['nullable', 'boolean'],
            'active_processing' => ['nullable', 'boolean'],
            'cashier_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:created_at,estimated_finish_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $ownerId = $this->ownerScope->idFor($request->user());
        $this->statusService->synchronize($ownerId);
        $cashierId = $validated['cashier_id'] ?? null;
        if ($cashierId && !$this->isOwnerCashier($ownerId, (int) $cashierId)) {
            abort(422, 'Kasir tidak berada di kelompok owner ini.');
        }
        $query = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId);

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        if (!empty($validated['invoice'])) {
            $query->where('invoice_number', $validated['invoice']);
        }

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    })
                    ->orWhereHas('transactionDetails.service', function ($serviceQuery) use ($search) {
                        $serviceQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($validated['laundry_status'])) {
            $query->whereIn('laundry_status', $validated['laundry_status']);
        }

        // The cashier's "Diproses" category is time-bound: active laundry
        // status and an estimated completion time that has not passed.
        if (!empty($validated['active_processing'])) {
            $query->whereIn('laundry_status', ['received', 'washing', 'drying', 'ironing'])
                ->whereNotNull('estimated_finish_at')
                ->where('estimated_finish_at', '>', now());
        }

        if (!empty($validated['payment_status'])) {
            $query->whereIn('payment_status', $validated['payment_status']);
        }

        if (!empty($validated['from'])) {
            $query->where('created_at', '>=', BusinessTime::startOfDay($validated['from']));
        }
        if (!empty($validated['to'])) {
            $query->where('created_at', '<=', BusinessTime::endOfDay($validated['to']));
        }

        if (array_key_exists('collected', $validated)) {
            if ($validated['collected']) {
                $query->where('laundry_status', 'collected');
            } else {
                $query->where('laundry_status', '!=', 'collected');
            }
        }

        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['direction'] ?? 'desc';
        if (!isset($validated['sort'])) {
            $query->orderByRaw("CASE WHEN laundry_status = 'collected' THEN 1 ELSE 0 END ASC");
        }
        $transactions = $query->orderBy($sort, $direction)
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'status' => 'success',
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ], 200);
    }

    public function store(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'payment_status' => 'sometimes|in:unpaid,downPayment,paid',
            'payment_method' => 'sometimes|in:cash,qris,transfer',
            'paid_amount' => 'sometimes|numeric|min:0',
            'services' => 'required|array|min:1',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.qty' => 'required|numeric|min:0.1',
            'services.*.price' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $customer = Customer::where('owner_id', $ownerId)->find($request->customer_id);
            if (!$customer) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Pelanggan tidak tersedia di kelompok owner ini.',
                ], 422);
            }

            $serviceIds = collect($request->services)->pluck('service_id')->unique();
            $services = Service::where('owner_id', $ownerId)
                ->whereIn('id', $serviceIds)
                ->get()
                ->keyBy('id');
            if ($services->count() !== $serviceIds->count()) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Salah satu layanan tidak tersedia di kelompok owner ini.',
                ], 422);
            }

            $invoiceNumber = 'INV-' . now()->format('YmdHisv') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $totalPrice = 0;
            foreach ($request->services as $item) {
                $totalPrice += ($item['qty'] * $services[$item['service_id']]->price);
            }
            // The deadline comes from the owner's service configuration, not
            // from a client payload. For multiple services, the longest
            // configured duration is the operational completion deadline.
            $estimatedFinishAt = now()->addHours(
                max(1, (int) $services->max('estimation_hours')),
            );

            $paidAmount = (float) $request->input(
                'paid_amount',
                $request->input('payment_status') === 'paid' ? $totalPrice : 0,
            );
            if ($paidAmount > $totalPrice) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Nominal pembayaran tidak boleh melebihi total tagihan.',
                ], 422);
            }
            $paymentStatus = $this->resolvePaymentStatus($paidAmount, $totalPrice);
            $paymentMethod = $request->input('payment_method', 'cash');

            $transaction = Transaction::create([
                'customer_id' => $request->customer_id,
                'owner_id' => $ownerId,
                'cashier_id' => $request->user()->id,
                'invoice_number' => $invoiceNumber,
                'total_price' => $totalPrice,
                'paid_amount' => $paidAmount,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                // Status akhir hanya boleh dicapai lewat proses server-side
                // (scheduler dan endpoint collection), bukan payload create.
                'laundry_status' => 'received',
                'order_status' => 'new',
                'estimated_finish_at' => $estimatedFinishAt,
                'notes' => $request->input('notes'),
            ]);

            foreach ($request->services as $item) {
                $price = $services[$item['service_id']]->price;
                $subtotal = $item['qty'] * $price;

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'service_id' => $item['service_id'],
                    'qty' => $item['qty'],
                    'price' => $price,
                    'subtotal' => $subtotal,
                ]);
            }

            if ($paidAmount > 0) {
                $this->recordPayment(
                    $transaction,
                    $request,
                    $paidAmount,
                    'initial_payment',
                );
            }

            $this->auditLog->record(
                $request->user(),
                $ownerId,
                'transaction',
                $transaction->id,
                'transaction.created',
                ['after' => $this->auditSnapshot($transaction)],
            );
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi berhasil dibuat',
                'data' => $transaction->load(['customer', 'transactionDetails.service'])
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->apiFailure($e, 'Gagal membuat transaksi. Coba lagi nanti.');
        }
    }

    public function show(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id, true);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan'
            ], 404);
        }

        $this->statusService->synchronizeTransaction($transaction);

        return response()->json([
            'status' => 'success',
            'data' => $transaction
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan'
            ], 404);
        }

        if ($request->hasAny(['payment_status', 'paid_amount', 'payment_method'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Perubahan pembayaran harus melalui endpoint pembayaran agar riwayat keuangan tercatat.',
            ], 422);
        }

        $request->validate([
            'order_status' => 'nullable|in:new,processing',
            'laundry_status' => 'nullable|in:received,washing,drying,ironing',
        ]);

        $updates = $request->only(['order_status', 'laundry_status']);

        try {
            DB::transaction(function () use ($transaction, $updates, $request): void {
                $transaction = $this->lockedTransaction($request, (string) $transaction->id);
                $this->assertNotCollected($transaction);
                $before = $this->auditSnapshot($transaction);
                $transaction->fill($updates);
                if (!$transaction->isDirty()) return;
                $transaction->update($updates);
                $this->auditLog->record(
                    $request->user(),
                    $transaction->owner_id,
                    'transaction',
                    $transaction->id,
                    'transaction.updated',
                    ['before' => $before, 'after' => $this->auditSnapshot($transaction->fresh())],
                );
            });
        } catch (\Throwable $e) {
            return $this->apiFailure($e, 'Gagal memperbarui transaksi. Coba lagi nanti.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Status transaksi berhasil diperbarui',
            'data' => $transaction->fresh()->load(['customer', 'transactionDetails.service'])
        ], 200);
    }

    public function today(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $this->statusService->synchronize($ownerId);
        $transactions = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->whereBetween('created_at', [BusinessTime::startOfDay(now()), BusinessTime::endOfDay(now())])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ], 200);
    }

    public function readyToCollect(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        $this->statusService->synchronize($ownerId);
        $transactions = Transaction::with(['customer', 'transactionDetails.service'])
            ->where('owner_id', $ownerId)
            ->whereIn('laundry_status', ['completed', 'late'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ], 200);
    }

    public function updateStatus(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan',
            ], 404);
        }

        $request->validate([
            // completed/late ditentukan scheduler; collected/delivered hanya
            // boleh terjadi melalui endpoint collection yang memeriksa lunas.
            'laundry_status' => 'required|in:received,washing,drying,ironing',
            'order_status' => 'nullable|in:new,processing',
        ]);

        $data = [
            'laundry_status' => $request->laundry_status,
            'order_status' => $request->input('order_status', $transaction->order_status),
        ];

        try {
            DB::transaction(function () use ($transaction, $data, $request): void {
                $transaction = $this->lockedTransaction($request, (string) $transaction->id);
                $this->assertNotCollected($transaction);
                $before = $this->auditSnapshot($transaction);
                $transaction->fill($data);
                if (!$transaction->isDirty()) return;
                $transaction->update($data);
                $this->auditLog->record(
                    $request->user(),
                    $transaction->owner_id,
                    'transaction',
                    $transaction->id,
                    'transaction.status_updated',
                    ['before' => $before, 'after' => $this->auditSnapshot($transaction->fresh())],
                );
            });
        } catch (\Throwable $e) {
            return $this->apiFailure($e, 'Gagal memperbarui status laundry. Coba lagi nanti.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Status laundry berhasil diperbarui',
            'data' => $transaction->fresh()->load(['customer', 'transactionDetails.service']),
        ], 200);
    }

    public function markPayment(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'nullable|in:cash,qris,transfer',
            'payment_status' => 'nullable|in:unpaid,downPayment,paid',
        ]);

        try {
            DB::transaction(function () use ($transaction, $request): void {
                $transaction = $this->lockedTransaction($request, (string) $transaction->id);
                $paidAmount = (float) $request->input('paid_amount');
                if ($paidAmount > (float) $transaction->total_price) {
                    throw ValidationException::withMessages([
                        'paid_amount' => 'Nominal pembayaran tidak boleh melebihi total tagihan.',
                    ]);
                }
                if ($paidAmount < (float) ($transaction->paid_amount ?? 0)) {
                    throw ValidationException::withMessages([
                        'paid_amount' => 'Nominal pembayaran tidak boleh dikurangi setelah dicatat.',
                    ]);
                }
                // Cumulative balance: retry cannot add a payment or audit row.
                if ($paidAmount === (float) ($transaction->paid_amount ?? 0)) return;
                $this->assertNotCollected($transaction);
                $paymentStatus = $this->resolvePaymentStatus($paidAmount, (float) $transaction->total_price);
                $before = $this->auditSnapshot($transaction);
                $transaction->update([
                    'paid_amount' => $paidAmount,
                    'payment_method' => $request->input('payment_method', $transaction->payment_method ?? 'cash'),
                    'payment_status' => $paymentStatus,
                    'laundry_status' => $transaction->laundry_status ?? 'received',
                ]);

                $additionalPayment = $paidAmount - (float) ($before['paid_amount'] ?? 0);
                if ($additionalPayment > 0) {
                    $this->recordPayment($transaction, $request, $additionalPayment, 'payment');
                }

                $this->auditLog->record(
                    $request->user(),
                    $transaction->owner_id,
                    'transaction',
                    $transaction->id,
                    'transaction.payment_recorded',
                    ['before' => $before, 'after' => $this->auditSnapshot($transaction->fresh())],
                );
            });
        } catch (\Throwable $e) {
            return $this->apiFailure($e, 'Gagal mencatat pembayaran. Coba lagi nanti.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pembayaran berhasil dicatat',
            'data' => $transaction->fresh()->load(['customer', 'transactionDetails.service']),
        ], 200);
    }

    /**
     * Completes a handover in one operation. Payment settlement and a proof image
     * are optional, but every successful handover is recorded as collected.
     */
    public function collect(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan',
            ], 404);
        }

        $this->statusService->synchronizeTransaction($transaction);

        if ($transaction->laundry_status === 'collected') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi sudah diambil',
            ], 422);
        }

        if (!in_array($transaction->laundry_status, ['completed', 'late'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Laundry hanya dapat diambil setelah selesai atau terlambat',
            ], 422);
        }

        $validated = $request->validate([
            'settle_payment' => ['sometimes', 'boolean'],
            'payment_method' => ['nullable', 'in:cash,qris,transfer'],
            'proof_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $isPaid = (float) ($transaction->paid_amount ?? 0) >= (float) $transaction->total_price ||
            $transaction->payment_status === 'paid';
        if (!$isPaid && !$request->boolean('settle_payment')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi harus dilunasi sebelum laundry dapat diambil',
            ], 422);
        }

        $newProofPath = null;
        DB::beginTransaction();
        try {
            $transaction = $this->lockedTransaction($request, $id);
            $this->statusService->synchronizeTransaction($transaction);
            $this->assertReadyAndPaid($transaction, $request->boolean('settle_payment'));
            $before = $this->auditSnapshot($transaction);
            $updates = [
                'laundry_status' => 'collected',
                'order_status' => 'delivered',
            ];

            if ($request->boolean('settle_payment')) {
                $remainingPayment = max(0, (float) $transaction->total_price - (float) ($transaction->paid_amount ?? 0));
                $updates['paid_amount'] = $transaction->total_price;
                $updates['payment_status'] = 'paid';
                $updates['payment_method'] = $validated['payment_method'] ?? $transaction->payment_method ?? 'cash';
            }

            if ($request->hasFile('proof_image')) {
                $newProofPath = $this->proofStorage->store($request->file('proof_image'));
                $updates['proof_image_path'] = $newProofPath;
            }

            $transaction->update($updates);
            if (isset($remainingPayment) && $remainingPayment > 0) {
                $this->recordPayment($transaction, $request, $remainingPayment, 'collection_settlement');
            }
            $this->auditLog->record(
                $request->user(),
                $transaction->owner_id,
                'transaction',
                $transaction->id,
                'transaction.collected',
                ['before' => $before, 'after' => $this->auditSnapshot($transaction->fresh())],
            );
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Pengambilan laundry berhasil dicatat',
                'data' => $transaction->fresh()->load(['customer', 'transactionDetails.service']),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->proofStorage->deleteNew($newProofPath);
            return $this->apiFailure($e, 'Gagal mencatat pengambilan. Coba lagi nanti.');
        }
    }

    private function resolvePaymentStatus(float $paidAmount, float $totalPrice): string
    {
        if ($paidAmount >= $totalPrice) {
            return 'paid';
        }

        if ($paidAmount > 0) {
            return 'downPayment';
        }

        return 'unpaid';
    }

    private function recordPayment(
        Transaction $transaction,
        Request $request,
        float $amount,
        string $source,
    ): void {
        TransactionPayment::create([
            'owner_id' => $transaction->owner_id,
            'transaction_id' => $transaction->id,
            'recorded_by' => $request->user()->id,
            'amount' => $amount,
            'payment_method' => $request->input('payment_method', $transaction->payment_method ?? 'cash'),
            'paid_at' => now(),
            'source' => $source,
        ]);
    }

    public function uploadProof(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan',
            ], 404);
        }

        if ($transaction->laundry_status === 'collected') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi sudah diambil',
            ], 422);
        }

        if (!in_array($transaction->laundry_status, ['completed', 'late'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bukti hanya dapat diunggah saat laundry siap diambil',
            ], 422);
        }

        // Endpoint lama ini tetap kompatibel untuk konsumen eksternal, tetapi
        // tidak boleh menjadi jalan pintas pengambilan transaksi yang belum
        // lunas. Flutter memakai endpoint collection untuk proof + pelunasan.
        $isPaid = (float) ($transaction->paid_amount ?? 0) >= (float) $transaction->total_price ||
            $transaction->payment_status === 'paid';
        if (!$isPaid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi harus dilunasi sebelum laundry dapat diambil.',
            ], 422);
        }

        $request->validate([
            'proof_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $path = null;
        try {
            DB::transaction(function () use ($transaction, &$path, $request): void {
                $transaction = $this->lockedTransaction($request, (string) $transaction->id);
                $this->assertReadyAndPaid($transaction, false);
                $path = $this->proofStorage->store($request->file('proof_image'));
                $before = $this->auditSnapshot($transaction);
                $transaction->update([
                    'proof_image_path' => $path,
                    'laundry_status' => 'collected',
                    'order_status' => 'delivered',
                ]);

                $this->auditLog->record(
                    $request->user(),
                    $transaction->owner_id,
                    'transaction',
                    $transaction->id,
                    'transaction.proof_uploaded_and_collected',
                    ['before' => $before, 'after' => $this->auditSnapshot($transaction->fresh())],
                );
            });
        } catch (\Throwable $e) {
            $this->proofStorage->deleteNew($path);
            return $this->apiFailure($e, 'Gagal mencatat bukti pengambilan. Coba lagi nanti.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bukti pengambilan berhasil diunggah',
            'data' => $transaction->fresh()->load(['customer', 'transactionDetails.service']),
        ], 200);
    }

    public function downloadProof(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);
        if (!$transaction || !$transaction->proof_image_path) {
            abort(404, 'Bukti pengambilan tidak tersedia.');
        }
        return $this->proofStorage->response($transaction->proof_image_path);
    }

    public function destroy(Request $request, string $id)
    {
        $transaction = $this->scopedTransaction($request, $id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan'
            ], 404);
        }

        if ($transaction->laundry_status === 'collected') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi yang sudah diambil tidak dapat dihapus',
            ], 422);
        }

        if ((float) ($transaction->paid_amount ?? 0) > 0 || $transaction->payments()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi yang sudah memiliki pembayaran tidak dapat dihapus untuk menjaga riwayat keuangan.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($transaction, $request, $id): void {
                $transaction = $this->lockedTransaction($request, $id);
                $this->assertNotCollected($transaction);
                if ((float) ($transaction->paid_amount ?? 0) > 0 || $transaction->payments()->exists()) {
                    throw ValidationException::withMessages([
                        'transaction' => 'Transaksi yang sudah memiliki pembayaran tidak dapat dihapus untuk menjaga riwayat keuangan.',
                    ]);
                }
                $snapshot = $this->auditSnapshot($transaction);
                $ownerId = $transaction->owner_id;
                $transaction->delete();
                $this->auditLog->record(
                    $request->user(),
                    $ownerId,
                    'transaction',
                    (int) $id,
                    'transaction.deleted',
                    ['before' => $snapshot],
                );
            });
        } catch (\Throwable $e) {
            return $this->apiFailure($e, 'Gagal menghapus transaksi. Coba lagi nanti.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi beserta detailnya berhasil dihapus'
        ], 200);
    }

    private function lockedTransaction(Request $request, string $id): Transaction
    {
        return Transaction::query()
            ->where('owner_id', $this->ownerScope->idFor($request->user()))
            ->lockForUpdate()->findOrFail($id);
    }

    private function assertNotCollected(Transaction $transaction): void
    {
        if ($transaction->laundry_status === 'collected') {
            throw ValidationException::withMessages([
                'transaction' => 'Transaksi yang sudah diambil tidak dapat diubah kembali.',
            ]);
        }
    }

    private function assertReadyAndPaid(Transaction $transaction, bool $settlePayment): void
    {
        $this->assertNotCollected($transaction);
        if (!in_array($transaction->laundry_status, ['completed', 'late'], true)) {
            throw ValidationException::withMessages([
                'transaction' => 'Laundry hanya dapat diambil setelah selesai atau terlambat.',
            ]);
        }
        // A status label is not proof of payment when the balance disagrees.
        if (!$settlePayment && (float) ($transaction->paid_amount ?? 0) < (float) $transaction->total_price) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Transaksi harus dilunasi sebelum laundry dapat diambil.',
            ]);
        }
    }

    private function scopedTransaction(Request $request, string $id, bool $withRelations = false): ?Transaction
    {
        $query = Transaction::where('owner_id', $this->ownerScope->idFor($request->user()));
        if ($withRelations) {
            $query->with(['customer', 'transactionDetails.service']);
        }

        return $query->find($id);
    }

    private function isOwnerCashier(int $ownerId, int $cashierId): bool
    {
        return $cashierId === $ownerId || User::query()
            ->whereKey($cashierId)
            ->where('role', 'kasir')
            ->where('owner_id', $ownerId)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(Transaction $transaction): array
    {
        return $transaction->only([
            'invoice_number',
            'customer_id',
            'cashier_id',
            'total_price',
            'paid_amount',
            'payment_status',
            'payment_method',
            'laundry_status',
            'order_status',
            'estimated_finish_at',
        ]);
    }
}
