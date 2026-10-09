<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalIntegrityGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_with_transaction_history_cannot_be_deleted(): void
    {
        [$owner, $customer, $service] = $this->ownerCustomerAndService();
        $this->transaction($owner, $customer, $service);

        Sanctum::actingAs($owner, ['*']);

        $this->deleteJson('/api/customers/' . $customer->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pelanggan tidak dapat dihapus karena sudah memiliki riwayat transaksi.');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('transactions', ['customer_id' => $customer->id]);
    }

    public function test_service_used_by_a_transaction_cannot_be_deleted(): void
    {
        [$owner, $customer, $service] = $this->ownerCustomerAndService();
        $this->transaction($owner, $customer, $service);

        Sanctum::actingAs($owner, ['*']);

        $this->deleteJson('/api/services/' . $service->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Layanan tidak dapat dihapus karena sudah digunakan pada transaksi.');

        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertDatabaseHas('transaction_details', ['service_id' => $service->id]);
    }

    public function test_terminal_collection_status_cannot_bypass_the_collection_endpoint(): void
    {
        [$owner, $customer, $service] = $this->ownerCustomerAndService();
        $transaction = $this->transaction($owner, $customer, $service);

        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/transactions/' . $transaction->id . '/status', [
            'laundry_status' => 'collected',
            'order_status' => 'delivered',
        ])->assertStatus(422);

        $this->putJson('/api/transactions/' . $transaction->id, [
            'laundry_status' => 'collected',
        ])->assertStatus(422);

        $this->postJson('/api/transactions/' . $transaction->id . '/proof', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transaksi harus dilunasi sebelum laundry dapat diambil.');

        $this->assertSame('completed', $transaction->fresh()->laundry_status);
    }

    public function test_payment_cannot_exceed_total_and_new_transaction_cannot_start_as_collected(): void
    {
        [$owner, $customer, $service] = $this->ownerCustomerAndService();
        $transaction = $this->transaction($owner, $customer, $service);

        Sanctum::actingAs($owner, ['*']);

        $this->postJson('/api/transactions/' . $transaction->id . '/payment', [
            'paid_amount' => 100001,
            'payment_method' => 'cash',
        ])->assertStatus(422);
        $this->assertSame(0.0, (float) $transaction->fresh()->paid_amount);

        $response = $this->postJson('/api/transactions', [
            'customer_id' => $customer->id,
            'services' => [[
                'service_id' => $service->id,
                'qty' => 1,
                'price' => 1,
            ]],
            'laundry_status' => 'collected',
            'order_status' => 'delivered',
        ]);

        $response->assertCreated();
        $this->assertSame('received', $response->json('data.laundry_status'));
        $this->assertSame('new', $response->json('data.order_status'));
    }

    /** @return array{User, Customer, Service} */
    private function ownerCustomerAndService(): array
    {
        $owner = User::create([
            'name' => 'Owner Penjaga Data',
            'username' => 'owner_guard',
            'email' => 'owner.guard@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Pelanggan Penjaga',
        ]);
        $service = Service::create([
            'owner_id' => $owner->id,
            'name' => 'Cuci Penjaga',
            'price' => 100000,
            'unit' => 'kg',
            'estimation_hours' => 2,
        ]);

        return [$owner, $customer, $service];
    }

    private function transaction(User $owner, Customer $customer, Service $service): Transaction
    {
        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'cashier_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-GUARD-' . uniqid(),
            'total_price' => 100000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'completed',
            'order_status' => 'completed',
        ]);
        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'service_id' => $service->id,
            'qty' => 1,
            'price' => 100000,
            'subtotal' => 100000,
        ]);

        return $transaction;
    }
}
