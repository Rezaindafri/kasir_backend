<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly OwnerScopeService $ownerScope)
    {
    }

    /**
     * Tampilkan semua data pelanggan (Read)
     */
    public function index(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        // Menambahkan withCount('transactions') untuk menghitung total nota
        $customers = Customer::withCount('transactions')
            ->where('owner_id', $ownerId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $customers
        ], 200);
    }

    /**
     * Simpan data pelanggan baru (Create)
     */
    public function store(Request $request)
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        // 1. Validasi input dari Flutter
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'gender' => 'nullable|in:male,female',
            
        ]);

        // 2. Simpan ke database
        $customer = Customer::create([
            'owner_id' => $ownerId,
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'gender' => $request->gender,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Data pelanggan berhasil ditambahkan',
            'data' => $customer
        ], 201);
    }

    /**
     * Tampilkan satu data pelanggan spesifik (Read One)
     */
    public function show(Request $request, string $id)
    {
        // Menambahkan withCount agar di halaman detail pelanggan juga muncul totalnya
        $customer = Customer::withCount('transactions')
            ->where('owner_id', $this->ownerScope->idFor($request->user()))
            ->find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pelanggan tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $customer
        ], 200);
    }

    /**
     * Ubah data pelanggan (Update)
     */
    public function update(Request $request, string $id)
    {
        $customer = Customer::where('owner_id', $this->ownerScope->idFor($request->user()))
            ->find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pelanggan tidak ditemukan'
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'gender' => 'nullable|in:male,female',
        ]);

        // Update data
        $customer->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'gender' => $request->gender
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Data pelanggan berhasil diperbarui',
            'data' => $customer
        ], 200);
    }

    /**
     * Hapus data pelanggan (Delete)
     */
    public function destroy(Request $request, string $id)
    {
        $customer = Customer::where('owner_id', $this->ownerScope->idFor($request->user()))
            ->find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data pelanggan tidak ditemukan'
            ], 404);
        }

        // Riwayat transaksi merupakan catatan keuangan. Foreign key lama
        // memakai cascade delete, sehingga pelanggan yang sudah bertransaksi
        // tidak boleh dihapus dari endpoint operasional.
        if ($customer->transactions()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan tidak dapat dihapus karena sudah memiliki riwayat transaksi.',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Data pelanggan berhasil dihapus'
        ], 200);
    }
}
