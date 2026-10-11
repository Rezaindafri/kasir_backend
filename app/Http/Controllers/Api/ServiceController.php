<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\TransactionDetail;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(private readonly OwnerScopeService $ownerScope)
    {
    }

    public function index(Request $request)
    {
        $services = Service::where('owner_id', $this->ownerScope->idFor($request->user()))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $services
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'estimation_hours' => 'nullable|integer|min:0|max:87600',
        ]);

        $service = Service::create([
            'owner_id' => $this->ownerScope->idFor($request->user()),
            'name' => $request->name,
            'price' => $request->price,
            'unit' => $request->unit,
            'estimation_hours' => $request->input('estimation_hours') ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Layanan berhasil ditambahkan',
            'data' => $service
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $service = Service::where('owner_id', $this->ownerScope->idFor($request->user()))
            ->find($id);

        if (!$service) {
            return response()->json([
                'status' => 'error',
                'message' => 'Layanan tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $service
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $service = Service::where('owner_id', $this->ownerScope->idFor($request->user()))
            ->find($id);

        if (!$service) {
            return response()->json([
                'status' => 'error',
                'message' => 'Layanan tidak ditemukan'
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'estimation_hours' => 'nullable|integer|min:0|max:87600',
        ]);

        $service->update([
            'name' => $request->name,
            'price' => $request->price,
            'unit' => $request->unit,
            'estimation_hours' => $request->input('estimation_hours') ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Layanan berhasil diperbarui',
            'data' => $service
        ], 200);
    }

    public function destroy(Request $request, string $id)
    {
        $service = Service::where('owner_id', $this->ownerScope->idFor($request->user()))
            ->find($id);

        if (!$service) {
            return response()->json([
                'status' => 'error',
                'message' => 'Layanan tidak ditemukan'
            ], 404);
        }

        // Detail transaksi menyimpan harga historis layanan. Menjaga layanan
        // yang sudah pernah dipakai mencegah cascade delete menghapus detail
        // tersebut dari bon dan laporan lama.
        if (TransactionDetail::query()->where('service_id', $service->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Layanan tidak dapat dihapus karena sudah digunakan pada transaksi.',
            ], 422);
        }

        $service->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Layanan berhasil dihapus'
        ], 200);
    }
}
