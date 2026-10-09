<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileProofAndExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_and_update_profile(): void
    {
        $user = User::create([
            'name' => 'Owner Baru',
            'username' => 'owner_baru',
            'email' => 'owner.baru@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $profileResponse = $this->getJson('/api/profile');
        $profileResponse->assertOk();
        $this->assertSame('owner_baru', $profileResponse->json('data.username'));

        $updateResponse = $this->patchJson('/api/profile', [
            'name' => 'Owner Terbaru',
            'email' => 'owner.update@example.com',
        ]);

        $updateResponse->assertOk();
        $this->assertSame('Owner Terbaru', $updateResponse->json('data.name'));
        $this->assertSame('owner.update@example.com', $updateResponse->json('data.email'));

        $passwordResponse = $this->patchJson('/api/profile/password', [
            'current_password' => 'secret123',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ]);

        $passwordResponse->assertOk();
        $this->assertSame('Password berhasil diperbarui', $passwordResponse->json('message'));
    }

    public function test_transaction_can_store_collection_proof_image(): void
    {
        $owner = User::create([
            'name' => 'Owner Proof',
            'username' => 'owner_proof',
            'email' => 'owner.proof@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Kasir Proof',
            'username' => 'kasir_proof',
            'email' => 'kasir.proof@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Budi',
            'phone' => '0812211223',
            'address' => 'Bandung',
            'gender' => 'male',
        ]);

        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PROOF-001',
            'total_price' => 50000,
            'paid_amount' => 50000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'completed',
            'order_status' => 'completed',
        ]);

        Storage::fake('public');

        $file = UploadedFile::fake()->image('proof.jpg', 600, 600);

        $response = $this->postJson('/api/transactions/' . $transaction->id . '/proof', [
            'proof_image' => $file,
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.proof_image_path'));
        $this->assertSame('collected', $response->json('data.laundry_status'));
    }

    public function test_owner_can_export_revenue_csv(): void
    {
        $owner = User::create([
            'name' => 'Owner Export',
            'username' => 'owner_export',
            'email' => 'owner.export@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Dina',
            'phone' => '0812333444',
            'address' => 'Jakarta',
            'gender' => 'female',
        ]);

        Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-EXPORT-001',
            'total_price' => 60000,
            'paid_amount' => 60000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'collected',
            'order_status' => 'completed',
        ]);

        $response = $this->get('/api/reports/export?type=csv&start_date=' . now()->subDay()->toDateString() . '&end_date=' . now()->toDateString());

        $response->assertOk();
        $this->assertStringContainsString('invoice_number', $response->content());
        $this->assertStringContainsString('INV-EXPORT-001', $response->content());
    }

    public function test_settings_backup_and_restore_are_role_protected(): void
    {
        $owner = User::create([
            'name' => 'Owner Settings',
            'username' => 'owner_settings',
            'email' => 'owner.settings@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $superOwner = User::create([
            'name' => 'Super Owner Settings',
            'username' => 'super_owner_settings',
            'email' => 'super.settings@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'super_owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/settings', [
            'business_name' => 'Laundry Server',
            'phone' => '08123456789',
            'address' => 'Bandung',
        ])->assertOk()->assertJsonPath('data.business_name', 'Laundry Server');

        Sanctum::actingAs($superOwner, ['*']);

        $backup = $this->getJson('/api/settings/backup');
        $backup->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('type', 'owner_configuration_backup')
            ->assertJsonPath('settings.0.owner_id', $owner->id);

        $this->postJson('/api/settings/restore', [
            'version' => 2,
            'type' => 'owner_configuration_backup',
            'settings' => [[
                'owner_id' => $owner->id,
                'business_name' => 'Laundry Dipulihkan',
                'phone' => '08129876543',
                'address' => 'Jakarta',
            ]],
        ])->assertOk()->assertJsonPath('data.0.business_name', 'Laundry Dipulihkan');

        $kasir = User::create([
            'name' => 'Kasir Settings',
            'username' => 'kasir_settings',
            'email' => 'kasir.settings@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($kasir, ['*']);

        $this->getJson('/api/settings')->assertOk();
        $this->patchJson('/api/settings', [
            'business_name' => 'Tidak Sah',
        ])->assertForbidden();
        $this->getJson('/api/settings/backup')->assertForbidden();
    }
}
