<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerScopeAndSuperOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_only_sees_and_uses_operational_data_from_its_owner_group(): void
    {
        $ownerA = $this->owner('a');
        $ownerB = $this->owner('b');
        $cashierA = $this->cashier($ownerA, 'a');

        $customerA = Customer::create(['owner_id' => $ownerA->id, 'name' => 'Pelanggan A']);
        $customerB = Customer::create(['owner_id' => $ownerB->id, 'name' => 'Pelanggan B']);
        $serviceA = Service::create([
            'owner_id' => $ownerA->id,
            'name' => 'Cuci A',
            'price' => 10000,
            'unit' => 'kg',
            'estimation_hours' => 2,
        ]);

        Sanctum::actingAs($cashierA, ['*']);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customerA->id);
        $this->getJson('/api/customers/' . $customerB->id)->assertNotFound();

        $this->postJson('/api/transactions', [
            'customer_id' => $customerB->id,
            'services' => [['service_id' => $serviceA->id, 'qty' => 1, 'price' => 1]],
        ])->assertUnprocessable();

        $created = $this->postJson('/api/transactions', [
            'customer_id' => $customerA->id,
            'services' => [['service_id' => $serviceA->id, 'qty' => 1, 'price' => 1]],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.owner_id', $ownerA->id)
            ->assertJsonPath('data.total_price', 10000);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_owner_only_manages_its_own_cashiers_while_super_owner_can_assign_and_move_them(): void
    {
        $ownerA = $this->owner('a');
        $ownerB = $this->owner('b');
        $cashierA = $this->cashier($ownerA, 'a');
        $cashierB = $this->cashier($ownerB, 'b');
        $superOwner = User::create([
            'name' => 'Super Owner',
            'username' => 'super_owner',
            'email' => 'super@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'super_owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($ownerA, ['*']);
        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cashierA->id);
        $this->patchJson('/api/users/' . $cashierB->id, ['name' => 'Tidak Boleh'])
            ->assertForbidden();

        Sanctum::actingAs($superOwner, ['*']);
        $this->postJson('/api/users', [
            'name' => 'Kasir Tanpa Owner',
            'username' => 'kasir_tanpa_owner',
            'password' => 'secret123',
            'role' => 'kasir',
        ])->assertUnprocessable();

        $this->patchJson('/api/users/' . $cashierA->id, [
            'owner_id' => $ownerB->id,
        ])->assertOk()->assertJsonPath('data.owner_id', $ownerB->id);

        $this->assertDatabaseHas('users', [
            'id' => $cashierA->id,
            'owner_id' => $ownerB->id,
        ]);
    }

    public function test_inactive_owner_prevents_its_cashier_from_accessing_operational_endpoints(): void
    {
        $owner = $this->owner('inactive', false);
        $cashier = $this->cashier($owner, 'inactive');

        Sanctum::actingAs($cashier, ['*']);

        $this->getJson('/api/customers')->assertForbidden();
        $this->getJson('/api/transactions')->assertForbidden();
    }

    public function test_owner_can_reset_only_its_cashier_credentials_after_verification(): void
    {
        $ownerA = $this->owner('credential_a');
        $ownerB = $this->owner('credential_b');
        $cashierA = $this->cashier($ownerA, 'credential_a');
        $cashierB = $this->cashier($ownerB, 'credential_b');
        $oldCashierToken = $cashierA->createToken('before-credential-update');
        Sanctum::actingAs($ownerA, ['*']);

        $this->postJson('/api/users/' . $cashierA->id . '/credentials', [
            'manager_username' => $ownerA->username,
            'manager_password' => 'secret123',
            'name' => 'Kasir A Diperbarui',
            'username' => 'kasir_a_baru',
            'password' => 'password-baru-aman',
        ])->assertOk()
            ->assertJsonPath('data.username', 'kasir_a_baru');
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $oldCashierToken->accessToken->id,
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'kasir_a_baru',
            'password' => 'password-baru-aman',
        ])->assertOk();

        Sanctum::actingAs($ownerA, ['*']);
        $this->postJson('/api/users/' . $cashierB->id . '/credentials', [
            'manager_username' => $ownerA->username,
            'manager_password' => 'secret123',
            'password' => 'password-baru-aman',
        ])->assertForbidden();

        $this->postJson('/api/users/' . $cashierA->id . '/credentials', [
            'manager_username' => $ownerA->username,
            'manager_password' => 'password-salah',
            'password' => 'password-lain-aman',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Username atau password owner tidak sesuai.');
    }

    public function test_only_owner_can_delete_uncollected_transactions_and_changes_are_audited(): void
    {
        $owner = $this->owner('transaction');
        $cashier = $this->cashier($owner, 'transaction');
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Transaksi']);
        $active = Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'invoice_number' => 'INV-ACTIVE',
            'total_price' => 10000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'washing',
            'order_status' => 'processing',
        ]);
        $collected = Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'invoice_number' => 'INV-COLLECTED',
            'total_price' => 10000,
            'paid_amount' => 10000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'collected',
            'order_status' => 'delivered',
        ]);

        Sanctum::actingAs($cashier, ['*']);
        $this->putJson('/api/transactions/' . $active->id, ['order_status' => 'completed'])
            ->assertForbidden();
        $this->deleteJson('/api/transactions/' . $active->id)->assertForbidden();

        Sanctum::actingAs($owner, ['*']);
        $this->deleteJson('/api/transactions/' . $collected->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Transaksi yang sudah diambil tidak dapat dihapus');
        $this->deleteJson('/api/transactions/' . $active->id)->assertOk();

        $this->assertDatabaseMissing('transactions', ['id' => $active->id]);
        $this->assertDatabaseHas('audit_logs', [
            'owner_id' => $owner->id,
            'actor_id' => $owner->id,
            'subject_type' => 'transaction',
            'subject_id' => $active->id,
            'action' => 'transaction.deleted',
        ]);
    }

    public function test_owner_dashboard_uses_scope_filter_and_official_status_categories(): void
    {
        $owner = $this->owner('dashboard');
        $cashier = $this->cashier($owner, 'dashboard');
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Dashboard']);
        foreach ([
            ['INV-PROCESSING', 'washing'],
            ['INV-COMPLETED', 'completed'],
            ['INV-LATE', 'late'],
            ['INV-COLLECTED', 'collected'],
        ] as [$invoice, $status]) {
            Transaction::create([
                'owner_id' => $owner->id,
                'customer_id' => $customer->id,
                'cashier_id' => $cashier->id,
                'invoice_number' => $invoice,
                'total_price' => 10000,
                'paid_amount' => 10000,
                'payment_status' => 'paid',
                'payment_method' => 'cash',
                'laundry_status' => $status,
                'order_status' => 'processing',
                'estimated_finish_at' => now()->addHour(),
            ]);
        }

        Sanctum::actingAs($owner, ['*']);
        $this->getJson('/api/dashboard/owner?period=month&cashier_id=' . $cashier->id)
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 4)
            ->assertJsonPath('data.summary.processing', 1)
            ->assertJsonPath('data.summary.ready_to_pickup', 2)
            ->assertJsonPath('data.summary.late', 1)
            ->assertJsonPath('data.summary.revenue', 40000);
    }

    public function test_owner_operational_counts_include_orders_outside_selected_financial_period(): void
    {
        $owner = $this->owner('dashboard_live_queue');
        $cashier = $this->cashier($owner, 'dashboard_live_queue');
        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Pelanggan Pesanan Lama',
        ]);
        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'invoice_number' => 'INV-OLD-ACTIVE',
            'total_price' => 12000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'washing',
            'order_status' => 'processing',
            'estimated_finish_at' => now()->addHours(2),
        ]);
        $transaction->created_at = now()->subMonth()->startOfMonth()->addDay();
        $transaction->save();

        Sanctum::actingAs($owner, ['*']);
        $this->getJson('/api/dashboard/owner?period=month&cashier_id=' . $cashier->id)
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 0)
            ->assertJsonPath('data.summary.processing', 1);
    }

    public function test_owner_report_filters_payments_by_recorder_but_orders_by_creator(): void
    {
        $owner = $this->owner('payment_filter');
        $cashier = $this->cashier($owner, 'payment_filter');
        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Pelanggan Pembayaran',
        ]);
        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'invoice_number' => 'INV-RECORDER-FILTER',
            'total_price' => 10000,
            'paid_amount' => 10000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
        ]);
        TransactionPayment::create([
            'owner_id' => $owner->id,
            'transaction_id' => $transaction->id,
            'recorded_by' => $owner->id,
            'amount' => 10000,
            'payment_method' => 'cash',
            'paid_at' => now(),
            'source' => 'payment',
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/reports/overview?cashier_id=' . $owner->id)
            ->assertOk()
            ->assertJsonPath('data.summary.payments_received', 10000)
            ->assertJsonPath('data.summary.order_count', 0);
        $this->getJson('/api/reports/overview?cashier_id=' . $cashier->id)
            ->assertOk()
            ->assertJsonPath('data.summary.payments_received', 0)
            ->assertJsonPath('data.summary.order_count', 1);
        $this->get('/api/reports/export?type=csv&cashier_id=' . $owner->id)
            ->assertOk()
            ->assertSee('INV-RECORDER-FILTER');
        $this->get('/api/reports/export?type=csv&cashier_id=' . $cashier->id)
            ->assertOk()
            ->assertDontSee('INV-RECORDER-FILTER');
    }

    public function test_owner_request_only_synchronizes_its_own_group(): void
    {
        $ownerA = $this->owner('sync_a');
        $ownerB = $this->owner('sync_b');
        $customerA = Customer::create(['owner_id' => $ownerA->id, 'name' => 'Pelanggan A']);
        $customerB = Customer::create(['owner_id' => $ownerB->id, 'name' => 'Pelanggan B']);
        Transaction::create([
            'owner_id' => $ownerA->id,
            'customer_id' => $customerA->id,
            'invoice_number' => 'INV-SYNC-A',
            'total_price' => 10000,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
            'estimated_finish_at' => now()->subHours(2),
        ]);
        $otherTransaction = Transaction::create([
            'owner_id' => $ownerB->id,
            'customer_id' => $customerB->id,
            'invoice_number' => 'INV-SYNC-B',
            'total_price' => 10000,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
            'estimated_finish_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($ownerA, ['*']);

        $this->getJson('/api/dashboard/owner')->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $otherTransaction->id,
            'laundry_status' => 'received',
        ]);
    }

    public function test_owner_cannot_filter_transaction_list_with_another_owner_cashier(): void
    {
        $ownerA = $this->owner('filter_a');
        $ownerB = $this->owner('filter_b');
        $cashierB = $this->cashier($ownerB, 'filter_b');

        Sanctum::actingAs($ownerA, ['*']);

        $this->getJson('/api/transactions?cashier_id=' . $cashierB->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kasir tidak berada di kelompok owner ini.');
    }

    public function test_super_owner_dashboard_contains_account_structure_without_operational_metrics(): void
    {
        $ownerA = $this->owner('a');
        $ownerB = $this->owner('b', false);
        $this->cashier($ownerA, 'a1');
        $this->cashier($ownerA, 'a2', false);
        $this->cashier($ownerB, 'b1');
        $superOwner = User::create([
            'name' => 'Super Owner',
            'username' => 'super_dashboard',
            'email' => 'super.dashboard@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'super_owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($superOwner, ['*']);

        $this->getJson('/api/dashboard/owner')->assertForbidden();
        $this->getJson('/api/reports/revenue')->assertForbidden();
        $this->getJson('/api/customers')->assertForbidden();
        $this->getJson('/api/transactions')->assertForbidden();
        $this->getJson('/api/dashboard/super-owner')
            ->assertOk()
            ->assertJsonPath('data.summary.owners', 2)
            ->assertJsonPath('data.summary.users', 3)
            ->assertJsonPath('data.summary.active_accounts', 3)
            ->assertJsonPath('data.summary.inactive_accounts', 2)
            ->assertJsonPath('data.owners.0.user_count', 2)
            ->assertJsonMissing(['revenue' => 0])
            ->assertJsonMissing(['transactions' => 0]);
    }

    public function test_owner_can_only_read_audit_logs_from_its_group(): void
    {
        $ownerA = $this->owner('audit_a');
        $ownerB = $this->owner('audit_b');
        AuditLog::create([
            'owner_id' => $ownerA->id,
            'subject_type' => 'transaction',
            'subject_id' => 101,
            'action' => 'transaction.created',
        ]);
        AuditLog::create([
            'owner_id' => $ownerB->id,
            'subject_type' => 'transaction',
            'subject_id' => 202,
            'action' => 'transaction.deleted',
        ]);

        Sanctum::actingAs($ownerA, ['*']);
        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', 101);
    }

    public function test_owner_manages_its_expenses_and_report_does_not_mix_owner_groups(): void
    {
        $ownerA = $this->owner('expense_a');
        $ownerB = $this->owner('expense_b');
        $cashierA = $this->cashier($ownerA, 'expense_a');
        Expense::create([
            'owner_id' => $ownerB->id,
            'recorded_by' => $ownerB->id,
            'amount' => 99000,
            'category' => 'Milik owner lain',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($cashierA, ['*']);
        $this->getJson('/api/expenses')->assertForbidden();

        Sanctum::actingAs($ownerA, ['*']);
        $created = $this->postJson('/api/expenses', [
            'amount' => 12500,
            'category' => 'Perlengkapan',
            'description' => 'Detergen',
            'occurred_at' => now()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.amount', '12500.00')
            ->assertJsonPath('data.category', 'Perlengkapan');

        $expenseId = $created->json('data.id');
        $this->getJson('/api/expenses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expenseId);
        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.summary.expenses_total', 12500)
            ->assertJsonPath('data.summary.net_cash', -12500)
            ->assertJsonPath('data.expenses.0.category', 'Perlengkapan');
        $this->getJson('/api/dashboard/owner/financial?period=week')
            ->assertOk()
            ->assertJsonPath('data.period', 'week')
            ->assertJsonPath('data.summary.payments_received', 0);
        $this->getJson('/api/dashboard/owner?cashier_id=' . $cashierA->id)
            ->assertOk()
            ->assertJsonPath('data.summary.expenses_total', 0);
        $this->getJson('/api/audit-logs?subject_type=expense')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'expense.created');

        Sanctum::actingAs($ownerB, ['*']);
        $this->patchJson('/api/expenses/' . $expenseId, [
            'amount' => 20000,
            'category' => 'Tidak boleh',
            'occurred_at' => now()->toIso8601String(),
        ])->assertNotFound();
    }

    private function owner(string $suffix, bool $active = true): User
    {
        return User::create([
            'name' => 'Owner ' . $suffix,
            'username' => 'owner_' . $suffix,
            'email' => 'owner.' . $suffix . '@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => $active,
        ]);
    }

    private function cashier(User $owner, string $suffix, bool $active = true): User
    {
        return User::create([
            'name' => 'Kasir ' . $suffix,
            'username' => 'kasir_' . $suffix,
            'email' => 'kasir.' . $suffix . '@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
            'is_active' => $active,
        ]);
    }
}
