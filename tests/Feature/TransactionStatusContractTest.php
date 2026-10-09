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

class TransactionStatusContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payment_can_be_recorded_as_down_payment(): void
    {
        $user = User::create([
            'name' => 'Kasir Owner',
            'username' => 'kasir_owner',
            'email' => 'kasir.owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Tono',
            'phone' => '0812345678',
            'address' => 'Bandung',
            'gender' => 'male',
        ]);

        $transaction = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-STATUS-001',
            'total_price' => 100000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'processing',
        ]);

        $response = $this->postJson('/api/transactions/' . $transaction->id . '/payment', [
            'paid_amount' => 50000,
            'payment_method' => 'cash',
            'payment_status' => 'downPayment',
        ]);

        $response->assertOk();
        $this->assertSame('downPayment', $response->json('data.payment_status'));
        $this->assertSame(50000.0, (float) $response->json('data.paid_amount'));
    }

    public function test_terminal_statuses_cannot_be_set_through_the_operational_status_endpoint(): void
    {
        $user = User::create([
            'name' => 'Kasir Late',
            'username' => 'kasir_late',
            'email' => 'kasir.late@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Sari',
            'phone' => '0811223344',
            'address' => 'Jakarta',
            'gender' => 'female',
        ]);

        $transaction = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-STATUS-002',
            'total_price' => 75000,
            'paid_amount' => 75000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'processing',
        ]);

        $response = $this->patchJson('/api/transactions/' . $transaction->id . '/status', [
            'laundry_status' => 'late',
            'order_status' => 'completed',
        ]);

        $response->assertStatus(422);
        $this->assertSame('received', $transaction->fresh()->laundry_status);
    }

    public function test_collection_settles_down_payment_marks_collected_and_stores_optional_proof(): void
    {
        $user = User::create([
            'name' => 'Kasir Collection',
            'username' => 'kasir_collection',
            'email' => 'kasir.collection@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);
        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Dewi',
            'phone' => '0812998877',
            'address' => 'Bogor',
            'gender' => 'female',
        ]);
        $transaction = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-COLLECTION-001',
            'total_price' => 100000,
            'paid_amount' => 25000,
            'payment_status' => 'downPayment',
            'payment_method' => 'cash',
            'laundry_status' => 'completed',
            'order_status' => 'completed',
        ]);
        Storage::fake('public');
        Storage::fake('proofs');

        $response = $this->postJson('/api/transactions/' . $transaction->id . '/collection', [
            'settle_payment' => true,
            'payment_method' => 'qris',
            'proof_image' => UploadedFile::fake()->image('proof.jpg'),
        ]);

        $response->assertOk();
        $this->assertSame('collected', $response->json('data.laundry_status'));
        $this->assertSame('delivered', $response->json('data.order_status'));
        $this->assertSame('paid', $response->json('data.payment_status'));
        $this->assertSame(100000.0, (float) $response->json('data.paid_amount'));
        $this->assertSame('qris', $response->json('data.payment_method'));
        $this->assertNotNull($response->json('data.proof_image_path'));
    }

    public function test_collection_rejects_laundry_that_is_not_ready(): void
    {
        $user = User::create([
            'name' => 'Kasir Collection Guard',
            'username' => 'kasir_collection_guard',
            'email' => 'kasir.collection.guard@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);
        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Rudi',
            'phone' => '0812334455',
            'address' => 'Depok',
            'gender' => 'male',
        ]);
        $transaction = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-COLLECTION-002',
            'total_price' => 50000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'washing',
            'order_status' => 'processing',
        ]);

        $this->postJson('/api/transactions/' . $transaction->id . '/collection', [
            'settle_payment' => false,
        ])->assertStatus(422);
    }

    public function test_transaction_index_filters_and_paginates_on_the_server(): void
    {
        $user = User::create([
            'name' => 'Kasir Filter',
            'username' => 'kasir_filter',
            'email' => 'kasir.filter@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);
        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Pelanggan Filter',
            'phone' => '0812556677',
            'address' => 'Bekasi',
            'gender' => 'female',
        ]);

        foreach ([
            ['INV-FILTER-001', 'completed', 'paid'],
            ['INV-FILTER-002', 'completed', 'unpaid'],
            ['INV-FILTER-003', 'washing', 'unpaid'],
        ] as [$invoice, $laundryStatus, $paymentStatus]) {
            Transaction::create([
                'owner_id' => $user->id,
                'customer_id' => $customer->id,
                'invoice_number' => $invoice,
                'total_price' => 50000,
                'paid_amount' => $paymentStatus === 'paid' ? 50000 : 0,
                'payment_status' => $paymentStatus,
                'payment_method' => 'cash',
                'laundry_status' => $laundryStatus,
                'order_status' => 'processing',
            ]);
        }

        $response = $this->getJson('/api/transactions?search=FILTER&laundry_status[]=completed&per_page=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame('completed', $response->json('data.0.laundry_status'));

        $invoiceResponse = $this->getJson('/api/transactions?invoice=INV-FILTER-003&per_page=1');
        $invoiceResponse->assertOk();
        $this->assertSame(1, $invoiceResponse->json('meta.total'));
        $this->assertSame('INV-FILTER-003', $invoiceResponse->json('data.0.invoice_number'));

        $uncollectedResponse = $this->getJson('/api/transactions?collected=0');
        $uncollectedResponse->assertOk();
        $this->assertSame(3, $uncollectedResponse->json('meta.total'));
    }

    public function test_server_synchronizes_completed_and_late_statuses_from_time(): void
    {
        $user = User::create([
            'name' => 'Kasir Status Waktu',
            'username' => 'kasir_status_waktu',
            'email' => 'kasir.status.waktu@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);
        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Waktu Laundry',
            'phone' => '0812000011',
            'address' => 'Jakarta',
            'gender' => 'female',
        ]);

        $completed = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TIME-COMPLETED',
            'total_price' => 25000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'washing',
            'order_status' => 'processing',
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
            'estimated_finish_at' => now()->subMinutes(30),
        ]);
        $late = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TIME-LATE',
            'total_price' => 25000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'drying',
            'order_status' => 'processing',
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
            'estimated_finish_at' => now()->subHours(3),
        ]);

        $completed->forceFill([
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ])->saveQuietly();
        $late->forceFill([
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ])->saveQuietly();

        $this->getJson('/api/dashboard/kasir')->assertOk();

        $this->assertSame('completed', $completed->fresh()->laundry_status);
        $this->assertSame('late', $late->fresh()->laundry_status);
    }

    public function test_collection_requires_payment_when_transaction_is_not_paid(): void
    {
        $user = User::create([
            'name' => 'Kasir Guard Pelunasan',
            'username' => 'kasir_guard_pelunasan',
            'email' => 'kasir.guard.pelunasan@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);
        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Pelanggan Belum Lunas',
            'phone' => '0812777888',
            'address' => 'Bogor',
            'gender' => 'female',
        ]);
        $transaction = Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-COLLECTION-UNPAID',
            'total_price' => 50000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'completed',
            'order_status' => 'completed',
        ]);

        $this->postJson('/api/transactions/' . $transaction->id . '/collection', [
            'settle_payment' => false,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Transaksi harus dilunasi sebelum laundry dapat diambil');

        $this->assertSame('completed', $transaction->fresh()->laundry_status);
        $this->assertSame('unpaid', $transaction->fresh()->payment_status);
    }
}
