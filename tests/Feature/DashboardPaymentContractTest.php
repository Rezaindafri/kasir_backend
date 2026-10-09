<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use Tests\TestCase;

class DashboardPaymentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_transaction_includes_income_in_owner_dashboard(): void
    {
        $user = User::create([
            'name' => 'Owner User',
            'username' => 'owner1',
            'email' => 'owner1@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);

        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'owner_id' => $user->id,
            'name' => 'Fanny',
            'phone' => '08123456789',
            'address' => 'Bandung',
            'gender' => 'female',
        ]);
        $service = Service::create([
            'owner_id' => $user->id,
            'name' => 'Cuci Kiloan',
            'price' => 25000,
            'unit' => 'kg',
            'estimation_hours' => 6,
        ]);

        Transaction::create([
            'owner_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-001',
            'total_price' => 25000,
            'paid_amount' => 25000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'completed',
            'order_status' => 'completed',
            'estimated_finish_at' => now()->addHours(2),
        ]);

        $response = $this->getJson('/api/dashboard/owner');

        $response->assertOk();
        $this->assertSame(25000, $response->json('data.summary.revenue'));
        $this->assertSame(1, $response->json('data.summary.transactions'));
    }

    public function test_mark_payment_updates_paid_amount_and_dashboard_values(): void
    {
        $owner = User::create([
            'name' => 'Owner Pemeriksa',
            'username' => 'owner_pemeriksa',
            'email' => 'owner.pemeriksa@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Cashier User',
            'username' => 'cashier1',
            'email' => 'cashier1@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Budi',
            'phone' => '081122334455',
            'address' => 'Cirebon',
            'gender' => 'male',
        ]);
        $service = Service::create([
            'owner_id' => $owner->id,
            'name' => 'Setrika',
            'price' => 50000,
            'unit' => 'kg',
            'estimation_hours' => 5,
        ]);

        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-002',
            'total_price' => 50000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'processing',
        ]);

        $response = $this->postJson('/api/transactions/' . $transaction->id . '/payment', [
            'paid_amount' => 50000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        $response->assertOk();
        $this->assertSame(50000, $response->json('data.paid_amount'));
        $this->assertSame('paid', $response->json('data.payment_status'));

        Sanctum::actingAs($owner, ['*']);

        $dashboard = $this->getJson('/api/dashboard/owner');
        $dashboard->assertOk();
        $this->assertSame(50000, $dashboard->json('data.summary.revenue'));
    }

    public function test_cashier_dashboard_uses_the_official_status_definitions(): void
    {
        $owner = User::create([
            'name' => 'Owner Dashboard',
            'username' => 'owner_dashboard',
            'email' => 'owner.dashboard@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Cashier Dashboard',
            'username' => 'cashier_dashboard',
            'email' => 'cashier.dashboard@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
        ]);
        Sanctum::actingAs($user, ['*']);
        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Nina',
            'phone' => '0812778899',
            'address' => 'Bandung',
            'gender' => 'female',
        ]);

        $makeTransaction = function (string $invoice, string $status, $estimatedFinishAt) use ($customer, $owner): void {
            Transaction::create([
                'owner_id' => $owner->id,
                'customer_id' => $customer->id,
                'invoice_number' => $invoice,
                'total_price' => 25000,
                'paid_amount' => 0,
                'payment_status' => 'unpaid',
                'payment_method' => 'cash',
                'laundry_status' => $status,
                'order_status' => 'processing',
                'estimated_finish_at' => $estimatedFinishAt,
            ]);
        };

        $makeTransaction('INV-KASIR-001', 'washing', now()->addHours(4));
        $makeTransaction('INV-KASIR-002', 'completed', now()->addHour());
        $makeTransaction('INV-KASIR-003', 'late', now()->subHours(2));
        $makeTransaction('INV-KASIR-004', 'collected', now()->subHours(3));
        $makeTransaction('INV-KASIR-005', 'drying', now()->addHours(8));

        $response = $this->getJson('/api/dashboard/kasir');

        $response->assertOk();
        $this->assertSame(5, $response->json('data.summary.transactions_today'));
        $this->assertSame(2, $response->json('data.summary.in_progress'));
        $this->assertSame(1, $response->json('data.summary.completed'));
        $this->assertSame(1, $response->json('data.summary.late'));
        $this->assertSame(2, $response->json('data.summary.ready_to_pickup'));
        $this->assertSame(2, $response->json('data.summary.due_soon'));
        $this->assertCount(2, $response->json('data.ready_to_pickup_items'));
        $this->assertCount(2, $response->json('data.due_soon_items'));
    }
}
