<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountAndFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_account_loses_existing_tokens_and_cannot_use_an_active_session(): void
    {
        $superOwner = $this->user('super_owner', 'super_guard');
        $owner = $this->user('owner', 'owner_guard');
        $cashier = $this->user('kasir', 'cashier_guard', $owner->id);
        $cashier->createToken('existing-session');

        Sanctum::actingAs($superOwner, ['*']);
        $this->patchJson('/api/users/' . $cashier->id . '/status')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $cashier->id]);

        Sanctum::actingAs($cashier->fresh(), ['*']);
        $this->getJson('/api/customers')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun dinonaktifkan.');
    }

    public function test_cashier_cannot_change_its_own_username_or_password(): void
    {
        $owner = $this->user('owner', 'credential_owner');
        $cashier = $this->user('kasir', 'credential_cashier', $owner->id);
        Sanctum::actingAs($cashier, ['*']);

        $this->patchJson('/api/profile', [
            'name' => 'Nama Baru',
            'username' => 'tidak_diizinkan',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Kasir tidak berhak mengubah username.');

        $this->patchJson('/api/profile/password', [
            'current_password' => 'secret123',
            'password' => 'passwordBaru',
            'password_confirmation' => 'passwordBaru',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Kasir tidak berhak mengubah password.');
    }

    public function test_owner_monitoring_keeps_inactive_cashier_and_its_data(): void
    {
        $owner = $this->user('owner', 'monitor_owner');
        $cashier = $this->user('kasir', 'monitor_cashier', $owner->id);
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Monitoring']);
        Transaction::create([
            'owner_id' => $owner->id,
            'cashier_id' => $cashier->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-INACTIVE-MONITOR',
            'total_price' => 12000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
            'estimated_finish_at' => now()->addHours(2),
        ]);
        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/users/' . $cashier->id . '/status')->assertOk();

        $this->getJson('/api/dashboard/owner?period=month&cashier_id=' . $cashier->id)
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 1)
            ->assertJsonPath('data.accounts.0.id', $owner->id)
            ->assertJsonPath('data.accounts.0.is_owner', true)
            ->assertJsonPath('data.accounts.1.id', $cashier->id)
            ->assertJsonPath('data.accounts.1.is_active', false);

        $this->patchJson('/api/users/' . $cashier->id . '/status')->assertOk();

        $this->getJson('/api/dashboard/owner?period=month&cashier_id=' . $cashier->id)
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 1)
            ->assertJsonPath('data.accounts.1.is_active', true);
    }

    public function test_only_super_owner_can_set_and_enforce_account_password_policy(): void
    {
        $superOwner = $this->user('super_owner', 'policy_super');
        $owner = $this->user('owner', 'policy_owner');
        Sanctum::actingAs($superOwner, ['*']);

        $this->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonPath('data.system.api_status', 'online')
            ->assertJsonPath('data.account_policy.password_min_length', 6);

        $this->patchJson('/api/system-settings', ['password_min_length' => 10])
            ->assertOk()
            ->assertJsonPath('data.password_min_length', 10);

        Sanctum::actingAs($owner, ['*']);
        $this->getJson('/api/system-settings')->assertForbidden();
        $this->getJson('/api/account-policy')
            ->assertOk()
            ->assertJsonPath('data.password_min_length', 10);
        $this->postJson('/api/users', [
            'name' => 'Kasir Password Lemah',
            'username' => 'weak_policy_cashier',
            'password' => 'secret12',
            'role' => 'kasir',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/users', [
            'name' => 'Kasir Password Kuat',
            'username' => 'strong_policy_cashier',
            'password' => 'secret1234',
            'role' => 'kasir',
        ])->assertCreated();
    }

    public function test_super_owner_settings_never_returns_operational_audit_entries(): void
    {
        $superOwner = $this->user('super_owner', 'settings_audit_super');
        AuditLog::create([
            'subject_type' => 'transaction',
            'subject_id' => 99,
            'action' => 'transaction.created',
            'metadata' => ['invoice' => 'INV-PRIVATE'],
        ]);
        Sanctum::actingAs($superOwner, ['*']);

        $this->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonCount(0, 'data.recent_account_changes')
            ->assertJsonMissing(['action' => 'transaction.created']);
    }

    public function test_server_derives_deadline_from_service_instead_of_client_payload(): void
    {
        $owner = $this->user('owner', 'estimate_guard');
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Estimasi']);
        $service = Service::create([
            'owner_id' => $owner->id,
            'name' => 'Cuci Dua Jam',
            'price' => 15000,
            'unit' => 'kg',
            'estimation_hours' => 2,
        ]);
        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson('/api/transactions', [
            'customer_id' => $customer->id,
            'estimated_finish_at' => now()->subYear()->toIso8601String(),
            'services' => [[
                'service_id' => $service->id,
                'qty' => 1,
                'price' => 1,
            ]],
        ]);

        $response->assertCreated();
        $deadline = $response->json('data.estimated_finish_at');
        $this->assertNotNull($deadline);
        $this->assertTrue(now()->addHour()->lt(now()->parse($deadline)));
    }

    public function test_payment_is_recorded_at_payment_time_and_paid_transaction_cannot_be_deleted(): void
    {
        $owner = $this->user('owner', 'payment_guard');
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Bayar']);
        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'cashier_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PAYMENT-GUARD',
            'total_price' => 50000,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
        ]);
        $transaction->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();
        Sanctum::actingAs($owner, ['*']);

        $this->postJson('/api/transactions/' . $transaction->id . '/payment', [
            'paid_amount' => 50000,
            'payment_method' => 'qris',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'owner_id' => $owner->id,
            'actor_id' => $owner->id,
            'subject_type' => 'transaction',
            'subject_id' => $transaction->id,
            'action' => 'transaction.payment_recorded',
        ]);

        $today = now('Asia/Jakarta')->toDateString();
        $this->getJson('/api/reports/revenue?start_date=' . $today . '&end_date=' . $today)
            ->assertOk()
            ->assertJsonPath('data.revenue', 50000)
            ->assertJsonPath('data.transaction_count', 1);

        $this->deleteJson('/api/transactions/' . $transaction->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transaksi yang sudah memiliki pembayaran tidak dapat dihapus untuk menjaga riwayat keuangan.');
    }

    public function test_report_overview_separates_order_value_payment_events_and_outstanding_balance(): void
    {
        $owner = $this->user('owner', 'report_owner');
        $otherOwner = $this->user('owner', 'report_other');
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Laporan']);
        $transaction = Transaction::create([
            'owner_id' => $owner->id,
            'cashier_id' => $owner->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-REPORT-DP',
            'total_price' => 100000,
            'paid_amount' => 30000,
            'payment_status' => 'downPayment',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
        ]);
        TransactionPayment::create([
            'owner_id' => $owner->id,
            'transaction_id' => $transaction->id,
            'recorded_by' => $owner->id,
            'amount' => 30000,
            'payment_method' => 'cash',
            'paid_at' => now(),
            'source' => 'initial_payment',
        ]);
        $otherCustomer = Customer::create(['owner_id' => $otherOwner->id, 'name' => 'Pelanggan Lain']);
        Transaction::create([
            'owner_id' => $otherOwner->id,
            'cashier_id' => $otherOwner->id,
            'customer_id' => $otherCustomer->id,
            'invoice_number' => 'INV-OWNER-LAIN',
            'total_price' => 900000,
            'paid_amount' => 900000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'laundry_status' => 'received',
            'order_status' => 'new',
        ]);

        Sanctum::actingAs($owner, ['*']);
        $today = now('Asia/Jakarta')->toDateString();
        $response = $this->getJson('/api/reports/overview?start_date=' . $today . '&end_date=' . $today);

        $response->assertOk()
            ->assertJsonPath('data.summary.order_value', 100000)
            ->assertJsonPath('data.summary.payments_received', 30000)
            ->assertJsonPath('data.summary.outstanding_amount', 70000)
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.payment_amount', 30000)
            ->assertJsonPath('data.payments.0.recorded_by.id', $owner->id)
            ->assertJsonCount(1, 'data.outstanding_items')
            ->assertJsonPath('data.accounts.0.id', $owner->id)
            ->assertJsonPath('data.accounts.0.is_owner', true);

        // Owner juga boleh menjadi pencatat transaksi/pembayaran miliknya.
        // Filter diri sendiri harus diterima, sedangkan akun owner lain tetap
        // ditolak oleh batas kelompok data.
        $this->getJson('/api/reports/overview?start_date=' . $today
            . '&end_date=' . $today . '&cashier_id=' . $owner->id)
            ->assertOk()
            ->assertJsonPath('data.summary.payments_received', 30000);
        $this->getJson('/api/reports/overview?cashier_id=' . $otherOwner->id)
            ->assertUnprocessable();

        $this->getJson('/api/dashboard/owner?period=custom&start_date='
            . $today . '&end_date=' . $today
            . '&cashier_id=' . $owner->id)
            ->assertOk()
            ->assertJsonPath('data.accounts.0.id', $owner->id)
            ->assertJsonPath('data.accounts.0.is_owner', true)
            ->assertJsonPath('data.summary.payments_received', 30000);
    }

    public function test_super_owner_cannot_delete_owner_with_operational_data(): void
    {
        $superOwner = $this->user('super_owner', 'super_delete_guard');
        $owner = $this->user('owner', 'owner_delete_guard');
        Customer::create(['owner_id' => $owner->id, 'name' => 'Pelanggan Owner']);
        Sanctum::actingAs($superOwner, ['*']);

        $this->deleteJson('/api/users/' . $owner->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Akun yang memiliki data operasional atau riwayat audit tidak dapat dihapus. Nonaktifkan akun atau pindahkan tanggung jawabnya terlebih dahulu.');

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    private function user(string $role, string $suffix, ?int $ownerId = null): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . $suffix,
            'username' => $suffix,
            'email' => $suffix . '@example.com',
            'password' => bcrypt('secret123'),
            'role' => $role,
            'owner_id' => $ownerId,
            'is_active' => true,
        ]);
    }
}
