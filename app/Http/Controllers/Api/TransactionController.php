<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionPayment;
use App\Models\PaymentDestination;
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
            'ready_for_collection' => ['nullable', 'boolean'],
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
            $query->activelyProcessing();
        }
        if (!empty($validated['ready_for_collection'])) {
            $query->readyForCollection();
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
            'payment_destination_id' => 'nullable|integer|min:1',
            'paid_amount' => 'sometimes|numeric|min:0',
            'services' => 'required|array|min:1|max:50',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.qty' => 'required|numeric|min:0.1|max:100000',
            'services.*.price' => 'required|numeric|min:0',
            'services.*.express' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:2000',
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
            $lineValues = [];
            $startedAt = now();
            $estimatedFinishAt = null;
            foreach ($request->services as $item) {
                $service = $services[$item['service_id']];
                $base = round($item['qty'] * $service->price);
                $expressFee = !empty($item['express']) ? round($base * .25) : 0;
                $duration = max(0, (int) $service->estimation_hours);
                $finish = $duration > 0 ? $startedAt->copy()->addHours($duration) : null;
                if ($finish && (!$estimatedFinishAt || $finish->gt($estimatedFinishAt))) $estimatedFinishAt = $finish;
                $lineValues[] = [
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'service_unit' => $service->unit,
                    'qty' => $item['qty'],
                    'price' => $service->price,
                    'express_fee' => $expressFee,
                    'subtotal' => $base + $expressFee,
                    'timing_configured' => true,
                    'estimated_finish_at' => $finish,
                    'late_at' => $duration > 0 ? $startedAt->copy()->addHours($duration * 2) : null,
                ];
                $totalPrice += $base + $expressFee;
            }
            // The deadline comes from the owner's service configuration, not
            // from a client payload. For multiple services, the longest
            // configured duration is the operational completion deadline.

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

            foreach ($lineValues as $line) {
                $transaction->transactionDetails()->create($line);
            }
            $this->statusService->synchronizeTransaction($transaction, false);

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
            'data' => $transaction->load(['payments.recorder:id,name'])
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
            ->readyForCollection()
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
            'paid_amount' => 'required_without:amount|prohibits:amount|numeric|min:0',
            'amount' => 'required_without:paid_amount|prohibits:paid_amount|numeric|min:1',
            'request_id' => 'required_with:amount|nullable|uuid',
            'payment_note' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:cash,qris,transfer',
            'payment_status' => 'nullable|in:unpaid,downPayment,paid',
            'payment_destination_id' => 'nullable|integer|min:1',
        ]);

        try {
            DB::transaction(function () use ($transaction, $request): void {
                $transaction = $this->lockedTransaction($request, (string) $transaction->id);
                if ($request->filled('request_id')) {
                    $existing = $transaction->payments()->where('request_id', $request->input('request_id'))->first();
                    if ($existing) {
                        if ((float) $existing->amount !== (float) $request->input('amount') ||
                            $existing->payment_method !== $request->input('payment_method', $existing->payment_method) ||
                            $existing->payment_destination_id != $request->input('payment_destination_id') ||
                            $existing->notes !== $request->input('payment_note')) {
                            throw ValidationException::withMessages(['request_id' => 'Permintaan pembayaran sudah digunakan dengan data berbeda.']);
                        }
                        return;
                    }
                }
                $paidAmount = $request->has('amount')
                    ? (float) ($transaction->paid_amount ?? 0) + (float) $request->input('amount')
                    : (float) $request->input('paid_amount');
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
     * Records a full or partial handover atomically with any extra payment.
     * Only selected ready lines are collected; the invoice closes after all lines.
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

        $validated = $request->validate([
            'settle_payment' => ['sometimes', 'boolean'],
            'payment_method' => ['nullable', 'in:cash,qris,transfer'],
            'proof_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'detail_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'detail_ids.*' => ['required', 'integer', 'distinct'],
            'payment_amount' => ['sometimes', 'numeric', 'min:0'],
            'payment_note' => ['nullable', 'string', 'max:1000'],
            'payment_destination_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $newProofPath = null;
        DB::beginTransaction();
        try {
            $transaction = $this->lockedTransaction($request, $id);
            $this->statusService->synchronizeTransaction($transaction);
            $this->assertNotCollected($transaction);
            $lines = $transaction->transactionDetails()->orderBy('id')->lockForUpdate()->get();
            $lines->each(fn ($line) => $line->setRelation('transaction', $transaction));
            $remainingLines = $lines->where('is_collected', false);
            $selected = isset($validated['detail_ids'])
                ? $remainingLines->whereIn('id', $validated['detail_ids'])
                : $remainingLines;
            if (isset($validated['detail_ids']) && $selected->count() !== count($validated['detail_ids'])) {
                throw ValidationException::withMessages(['detail_ids' => 'Layanan tidak tersedia pada transaksi ini atau sudah diambil.']);
            }
            if ($lines->isNotEmpty()) {
                if ($selected->isEmpty() || $selected->contains(fn ($line) => !$line->is_ready)) {
                    throw ValidationException::withMessages(['detail_ids' => 'Pilih hanya layanan yang sudah selesai dan belum diambil.']);
                }
                // Existing DP is credit for this invoice. Cover the cumulative
                // value handed over, rather than charging each line twice.
                $requiredPaid = $selected->count() === $remainingLines->count()
                    ? (float) $transaction->total_price
                    : min((float) $transaction->total_price, (float) $lines->where('is_collected', true)->sum('subtotal') + (float) $selected->sum('subtotal'));
            } else {
                $this->assertReadyAndPaid($transaction, true);
                $requiredPaid = (float) $transaction->total_price;
            }
            $balance = max(0, (float) $transaction->total_price - (float) ($transaction->paid_amount ?? 0));
            $additionalPayment = isset($validated['payment_amount'])
                ? (float) $validated['payment_amount']
                : ($request->boolean('settle_payment') ? $balance : 0);
            $paidAfter = (float) ($transaction->paid_amount ?? 0) + $additionalPayment;
            if ($additionalPayment > $balance || $paidAfter < $requiredPaid) {
                throw ValidationException::withMessages(['payment_amount' => $lines->isEmpty() && $paidAfter < $requiredPaid
                    ? 'Transaksi harus dilunasi sebelum laundry dapat diambil'
                    : 'Pembayaran harus mencukupi layanan yang diambil dan tidak melebihi sisa tagihan.']);
            }
            $before = $this->auditSnapshot($transaction);
            $updates = [
                'paid_amount' => $paidAfter,
                'payment_status' => $this->resolvePaymentStatus($paidAfter, (float) $transaction->total_price),
            ];
            if ($additionalPayment > 0) {
                $updates['payment_method'] = $validated['payment_method'] ?? $transaction->payment_method ?? 'cash';
            }

            if ($request->hasFile('proof_image')) {
                $newProofPath = $this->proofStorage->store($request->file('proof_image'));
                $updates['proof_image_path'] = $newProofPath;
            }
            if ($lines->isEmpty() || $selected->count() === $remainingLines->count()) {
                $updates['laundry_status'] = 'collected';
                $updates['order_status'] = 'delivered';
            }
            foreach ($selected as $line) {
                $line->update([
                    'is_collected' => true,
                    'collected_at' => now(),
                    'collected_by' => $request->user()->id,
                    'proof_image_path' => $newProofPath,
                ]);
            }
            $transaction->update($updates);
            if ($additionalPayment > 0) {
                $this->recordPayment($transaction, $request, $additionalPayment, 'collection_settlement');
            }
            $this->statusService->synchronizeTransaction($transaction, false);
            $isComplete = $transaction->laundry_status === 'collected';
            $this->auditLog->record(
                $request->user(),
                $transaction->owner_id,
                'transaction',
                $transaction->id,
                $isComplete ? 'transaction.collected' : 'transaction.partially_collected',
                ['before' => $before, 'after' => $this->auditSnapshot($transaction->fresh()),
                    'detail_ids' => $selected->pluck('id')->values()->all(), 'payment_note' => $request->input('payment_note')],
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
        $method = $request->input('payment_method', $transaction->payment_method ?? 'cash');
        $destination = null;
        if ($request->filled('payment_destination_id')) {
            $destination = PaymentDestination::where('owner_id', $transaction->owner_id)
                ->where('kind', $method)->where('is_active', true)
                ->lockForUpdate()->find($request->integer('payment_destination_id'));
            if (!$destination || $method === 'cash') {
                throw ValidationException::withMessages([
                    'payment_destination_id' => 'Tujuan pembayaran tidak tersedia untuk metode/kelompok ini.',
                ]);
            }
        } elseif ($method !== 'cash' && PaymentDestination::where('owner_id', $transaction->owner_id)
            ->where('kind', $method)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'payment_destination_id' => 'Pilih tujuan pembayaran yang digunakan.',
            ]);
        }
        TransactionPayment::create([
            'owner_id' => $transaction->owner_id,
            'transaction_id' => $transaction->id,
            'recorded_by' => $request->user()->id,
            'amount' => $amount,
            'payment_method' => $request->input('payment_method', $transaction->payment_method ?? 'cash'),
            'paid_at' => now(),
            'source' => $source,
            'notes' => $request->input('payment_note'),
            'request_id' => $source === 'payment' ? $request->input('request_id') : null,
            'payment_destination_id' => $destination?->id,
            'payment_destination_label' => $destination ? mb_substr($destination->label(), 0, 255) : null,
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
                $transaction->transactionDetails()->where('is_collected', false)->update([
                    'is_collected' => true, 'collected_at' => now(),
                    'collected_by' => $request->user()->id, 'proof_image_path' => $path,
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
        if (!$transaction) {
            abort(404, 'Bukti pengambilan tidak tersedia.');
        }
        $request->validate(['detail_id' => ['nullable', 'integer']]);
        $path = $request->filled('detail_id')
            ? $transaction->transactionDetails()->find($request->integer('detail_id'))?->proof_image_path
            : $transaction->proof_image_path;
        if (!$path) abort(404, 'Bukti pengambilan tidak tersedia.');
        return $this->proofStorage->response($path);
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
                if ((float) ($transaction->paid_amount ?? 0) > 0 || $transaction->payments()->exists() ||
                    $transaction->transactionDetails()->where('is_collected', true)->exists()) {
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
        $lines = $transaction->transactionDetails()->get();
        $lines->each(fn ($line) => $line->setRelation('transaction', $transaction));
        if ($lines->contains(fn ($line) => !$line->is_collected && !$line->is_ready)) {
            throw ValidationException::withMessages(['transaction' => 'Masih ada layanan yang belum selesai.']);
        }
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
        return [...$transaction->only([
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
        ]), 'details' => $transaction->transactionDetails()->get()->map(fn ($line) => $line->only([
            'id', 'service_id', 'service_name', 'service_unit', 'qty', 'price', 'express_fee',
            'subtotal', 'estimated_finish_at', 'is_collected', 'collected_at', 'collected_by',
        ]))->all()];
    }
}
