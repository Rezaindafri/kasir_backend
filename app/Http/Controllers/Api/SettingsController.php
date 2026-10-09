<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaundrySetting;
use App\Models\User;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function __construct(private readonly OwnerScopeService $ownerScope)
    {
    }

    public function show(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->settingsFor($request),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
        ]);

        $settings = $this->settingsFor($request);
        $settings->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan usaha berhasil diperbarui',
            'data' => $settings->fresh(),
        ]);
    }

    /** Exports only configuration; customers and transactions are never included. */
    public function backup()
    {
        $settings = LaundrySetting::query()
            ->orderBy('owner_id')
            ->get(['owner_id', 'business_name', 'phone', 'address']);

        return response()->json([
            'version' => 2,
            'type' => 'owner_configuration_backup',
            'created_at' => now()->toIso8601String(),
            'settings' => $settings,
        ]);
    }

    public function restore(Request $request)
    {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'in:2'],
            'type' => ['required', 'in:owner_configuration_backup'],
            'settings' => ['required', 'array', 'min:1', 'max:1000'],
            'settings.*.owner_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'settings.*.business_name' => ['required', 'string', 'max:255'],
            'settings.*.phone' => ['nullable', 'string', 'max:30'],
            'settings.*.address' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($validated): void {
            $ownerIds = collect($validated['settings'])->pluck('owner_id')->sort()->values();
            $owners = User::query()->whereIn('id', $ownerIds)->where('role', 'owner')
                ->orderBy('id')->lockForUpdate()->get();
            // Validate every account before the first configuration write.
            if ($owners->count() !== $ownerIds->count()) {
                abort(422, 'Backup memuat owner yang tidak valid.');
            }

            foreach ($validated['settings'] as $item) {
                LaundrySetting::query()->updateOrCreate(
                    ['owner_id' => $item['owner_id']],
                    [
                        'business_name' => $item['business_name'],
                        'phone' => $item['phone'] ?? '',
                        'address' => $item['address'] ?? '',
                    ],
                );
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Konfigurasi usaha berhasil dipulihkan',
            'data' => LaundrySetting::query()->orderBy('owner_id')->get(),
        ]);
    }

    private function settingsFor(Request $request): LaundrySetting
    {
        $ownerId = $this->ownerScope->idFor($request->user());
        return LaundrySetting::query()->firstOrCreate(['owner_id' => $ownerId], [
            'business_name' => 'Clean Fresh Laundry',
            'phone' => '',
            'address' => '',
        ]);
    }
}
