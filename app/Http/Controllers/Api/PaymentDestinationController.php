<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentDestination;
use App\Services\AuditLogService;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PaymentDestinationController extends Controller
{
    public function __construct(private readonly OwnerScopeService $scope,
        private readonly AuditLogService $audit)
    {
    }

    public function index(Request $request)
    {
        $query = PaymentDestination::where('owner_id', $this->scope->idFor($request->user()));
        if ($request->user()->role !== 'owner') $query->where('is_active', true);
        return response()->json(['status' => 'success', 'data' => $query
            ->orderBy('kind')->orderBy('name')->orderBy('id')->get()
            ->map(fn ($item) => $item->paymentData())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        return $this->save($request);
    }

    public function update(Request $request, string $id)
    {
        $item = PaymentDestination::where('owner_id', $this->scope->idFor($request->user()))
            ->findOrFail($id);
        return $this->save($request, $item);
    }

    private function save(Request $request, ?PaymentDestination $item = null)
    {
        $ownerId = $this->scope->idFor($request->user());
        // Keep the kind immutable; deactivation preserves past payment references.
        $kind = $item?->kind ?? $request->input('kind');
        $validated = $request->validate([
            'kind' => [$item ? 'sometimes' : 'required', Rule::in($item ? [$item->kind] : ['qris', 'transfer'])],
            'name' => [$item ? 'sometimes' : 'required', 'string', 'max:100'],
            'bank_name' => [$kind === 'transfer' && !$item ? 'required' : 'nullable', 'string', 'max:80'],
            'account_holder' => [$kind === 'transfer' && !$item ? 'required' : 'nullable', 'string', 'max:120'],
            'account_number' => [$kind === 'transfer' && !$item ? 'required' : 'nullable', 'string', 'regex:/^[0-9]{5,34}$/D'],
            'qris_image' => [$kind === 'qris' && !$item ? 'required' : 'nullable', 'image',
                'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($kind === 'qris' && $request->hasAny(['bank_name', 'account_holder', 'account_number'])) {
            abort(422, 'QRIS tidak menggunakan rekening transfer.');
        }
        if ($kind === 'transfer' && $request->hasFile('qris_image')) {
            abort(422, 'Rekening transfer tidak menggunakan gambar QRIS.');
        }
        if ($item && $kind === 'transfer') {
            foreach (['bank_name', 'account_holder', 'account_number'] as $field) {
                if ($request->has($field) && empty($validated[$field])) abort(422, 'Data rekening wajib diisi.');
            }
        }
        $path = null;
        try {
            $item = DB::transaction(function () use ($request, $ownerId, $kind, $validated, $item, &$path) {
                if ($item) $item = PaymentDestination::where('owner_id', $ownerId)->lockForUpdate()->findOrFail($item->id);
                $before = $item ? $this->auditData($item) : null;
                $values = collect($validated)->except(['qris_image', 'kind'])->all();
                if ($kind === 'qris') {
                    unset($values['bank_name'], $values['account_holder'], $values['account_number']);
                    if ($request->hasFile('qris_image')) {
                        $path = $request->file('qris_image')->store('payment-qris', 'proofs');
                        $values['qris_image_path'] = $path;
                    }
                }
                if ($item) $item->update($values);
                else $item = PaymentDestination::create([...$values, 'owner_id' => $ownerId, 'kind' => $kind]);
                $this->audit->record($request->user(), $ownerId, 'payment_destination', $item->id,
                    $before ? 'payment_destination.updated' : 'payment_destination.created',
                    ['before' => $before, 'after' => $this->auditData($item),
                        'account_number_changed' => $request->has('account_number'),
                        'qris_image_changed' => $request->hasFile('qris_image')]);
                return $item;
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('proofs')->delete($path);
            throw $error;
        }
        // Older QRIS files are intentionally not deleted or overwritten here.
        return response()->json(['status' => 'success', 'data' => $item->paymentData()],
            $request->route('id') ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    private function auditData(PaymentDestination $item): array
    {
        return $item->only(['id', 'kind', 'name', 'bank_name', 'is_active']);
    }

    public function image(Request $request, string $id)
    {
        $query = PaymentDestination::where('owner_id', $this->scope->idFor($request->user()));
        if ($request->user()->role !== 'owner') $query->where('is_active', true);
        $item = $query->findOrFail($id);
        $path = $item->qris_image_path;
        if ($item->kind !== 'qris' || !is_string($path) ||
            !preg_match('~^payment-qris/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$~iD', $path) ||
            !Storage::disk('proofs')->exists($path)) abort(404);
        return Storage::disk('proofs')->response($path, 'qris.' . pathinfo($path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
