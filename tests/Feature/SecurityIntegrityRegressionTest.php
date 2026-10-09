<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\LaundrySetting;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TransactionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Security tests require SQLite :memory:.');
        }
        return $app;
    }

    private function account(string $role = 'owner', ?int $ownerId = null): User
    {
        return User::create([
            'name' => 'Security fixture', 'username' => 'fixture_' . uniqid(),
            'password' => bcrypt('fixture-password'), 'role' => $role,
            'owner_id' => $ownerId, 'is_active' => true,
        ]);
    }

    private function order(User $owner, array $changes = []): Transaction
    {
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Fixture customer']);
        return Transaction::create(array_replace([
            'owner_id' => $owner->id, 'cashier_id' => $owner->id, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-SECURITY-' . uniqid(), 'total_price' => 100000, 'paid_amount' => 0,
            'payment_status' => 'unpaid', 'payment_method' => 'cash', 'laundry_status' => 'completed',
            'order_status' => 'completed', 'estimated_finish_at' => null,
        ], $changes));
    }

    public function test_collected_is_terminal_for_both_roles_and_general_update(): void
    {
        $owner = $this->account();
        $cashier = $this->account('kasir', $owner->id);
        $order = $this->order($owner, [
            'laundry_status' => 'collected', 'order_status' => 'delivered',
            'paid_amount' => 100000, 'payment_status' => 'paid',
        ]);
        foreach ([$owner, $cashier] as $actor) {
            Sanctum::actingAs($actor, ['*']);
            $this->patchJson('/api/transactions/' . $order->id . '/status', [
                'laundry_status' => 'received',
            ])->assertUnprocessable();
            $this->postJson('/api/transactions/' . $order->id . '/collection', [
                'settle_payment' => true,
            ])->assertUnprocessable();
        }
        Sanctum::actingAs($owner, ['*']);
        $this->putJson('/api/transactions/' . $order->id, ['order_status' => 'new'])
            ->assertUnprocessable();
        $this->deleteJson('/api/transactions/' . $order->id)->assertUnprocessable();
        $this->assertSame('collected', $order->fresh()->laundry_status);
        $this->assertSame('delivered', $order->fresh()->order_status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_repeated_cumulative_payments_do_not_duplicate_ledger_or_audit(): void
    {
        $owner = $this->account();
        $cashier = $this->account('kasir', $owner->id);
        $order = $this->order($owner);
        Sanctum::actingAs($cashier, ['*']);
        foreach (['cash', 'transfer'] as $method) {
            $this->postJson('/api/transactions/' . $order->id . '/payment', [
                'paid_amount' => 30000, 'payment_method' => $method,
            ])->assertOk();
        }
        $this->assertSame(1, TransactionPayment::count());
        $this->assertSame('cash', $order->fresh()->payment_method);
        $this->assertSame(1, AuditLog::where('action', 'transaction.payment_recorded')->count());
        Sanctum::actingAs($owner, ['*']);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/transactions/' . $order->id . '/payment', [
                'paid_amount' => 100000, 'payment_method' => 'qris',
            ])->assertOk();
        }
        $this->assertSame(2, TransactionPayment::count());
        $this->assertEquals(100000, TransactionPayment::sum('amount'));
        $this->assertSame(2, AuditLog::where('action', 'transaction.payment_recorded')->count());
        $this->postJson('/api/transactions/' . $order->id . '/collection', ['settle_payment' => false])
            ->assertOk();
        $this->postJson('/api/transactions/' . $order->id . '/collection', ['settle_payment' => true])
            ->assertUnprocessable();
        $this->assertSame(2, TransactionPayment::count());
        $this->assertSame(1, AuditLog::where('action', 'transaction.collected')->count());
        $this->getJson('/api/reports/overview')->assertOk()
            ->assertJsonPath('data.summary.payments_received', 100000)
            ->assertJsonPath('data.summary.outstanding_amount', 0);
    }

    public function test_stale_lower_payment_is_rejected_without_changing_the_ledger(): void
    {
        $owner = $this->account();
        $order = $this->order($owner);
        Sanctum::actingAs($owner, ['*']);
        $this->postJson('/api/transactions/' . $order->id . '/payment', ['paid_amount' => 70000])->assertOk();
        $this->postJson('/api/transactions/' . $order->id . '/payment', ['paid_amount' => 30000])
            ->assertUnprocessable();
        $this->assertEquals(70000, $order->fresh()->paid_amount);
        $this->assertEquals(70000, TransactionPayment::sum('amount'));
        $this->assertSame(1, AuditLog::where('action', 'transaction.payment_recorded')->count());
    }

    public function test_paid_label_alone_cannot_bypass_unpaid_balance_on_collection(): void
    {
        $owner = $this->account();
        $order = $this->order($owner, ['payment_status' => 'paid']);
        Sanctum::actingAs($owner, ['*']);
        $this->postJson('/api/transactions/' . $order->id . '/collection', ['settle_payment' => false])
            ->assertUnprocessable();
        $this->assertSame('completed', $order->fresh()->laundry_status);
        $this->postJson('/api/transactions/' . $order->id . '/collection', ['settle_payment' => true])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertEquals(100000, TransactionPayment::sum('amount'));
    }

    public function test_scheduler_uses_current_row_not_a_model_loaded_before_collection(): void
    {
        $owner = $this->account();
        $stale = $this->order($owner, ['estimated_finish_at' => now()->subHours(4)]);
        Transaction::whereKey($stale->id)->update(['laundry_status' => 'collected', 'order_status' => 'delivered']);
        $this->app->make(TransactionStatusService::class)->synchronizeTransaction($stale);
        $this->assertSame('collected', $stale->laundry_status);
        $this->assertSame('collected', $stale->fresh()->laundry_status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_payment_rollback_and_generic_error_also_handle_non_exception_throwables(): void
    {
        $owner = $this->account();
        $order = $this->order($owner);
        Sanctum::actingAs($owner, ['*']);
        config(['app.debug' => false]);
        $audit = \Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->andThrow(new \Error('INTERNAL_FIXTURE_DETAIL'));
        $this->app->instance(AuditLogService::class, $audit);
        $response = $this->postJson('/api/transactions/' . $order->id . '/payment', ['paid_amount' => 30000])
            ->assertStatus(500)->assertJsonPath('message', 'Gagal mencatat pembayaran. Coba lagi nanti.');
        $this->assertNotEmpty($response->json('error_id'));
        $this->assertStringNotContainsString('INTERNAL_FIXTURE_DETAIL', $response->getContent());
        $this->assertEquals(0, $order->fresh()->paid_amount);
        $this->assertSame(0, TransactionPayment::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_failed_collection_removes_only_new_proof_and_rolls_back_payment(): void
    {
        $owner = $this->account();
        $order = $this->order($owner, ['proof_image_path' => 'proofs/existing.png']);
        Sanctum::actingAs($owner, ['*']);
        Storage::fake('public');
        Storage::fake('proofs');
        Storage::disk('public')->put('proofs/existing.png', 'fixture');
        $audit = \Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('Fixture audit failure'));
        $this->app->instance(AuditLogService::class, $audit);
        $this->postJson('/api/transactions/' . $order->id . '/collection', [
            'settle_payment' => true, 'proof_image' => UploadedFile::fake()->image('fixture.png'),
        ])->assertStatus(500);
        $this->assertSame('completed', $order->fresh()->laundry_status);
        $this->assertEquals(0, $order->fresh()->paid_amount);
        $this->assertSame('proofs/existing.png', $order->fresh()->proof_image_path);
        $this->assertSame(0, TransactionPayment::count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
        Storage::disk('public')->assertExists('proofs/existing.png');
        $this->assertSame([], Storage::disk('proofs')->allFiles());
    }

    public function test_valid_restore_changes_both_settings_and_no_operational_or_account_data(): void
    {
        $super = $this->account('super_owner');
        $owner = $this->account();
        $other = $this->account();
        $order = $this->order($owner);
        $passwordHash = $owner->password;
        LaundrySetting::create(['owner_id' => $owner->id, 'business_name' => 'Before']);
        LaundrySetting::create(['owner_id' => $other->id, 'business_name' => 'Before other']);
        Sanctum::actingAs($super, ['*']);
        $this->postJson('/api/settings/restore', [
            'version' => 2, 'type' => 'owner_configuration_backup', 'settings' => [
                ['owner_id' => $owner->id, 'business_name' => 'After'],
                ['owner_id' => $other->id, 'business_name' => 'After other'],
            ],
        ])->assertOk();
        $this->assertDatabaseHas('laundry_settings', ['owner_id' => $owner->id, 'business_name' => 'After']);
        $this->assertDatabaseHas('laundry_settings', ['owner_id' => $other->id, 'business_name' => 'After other']);
        $this->assertSame($passwordHash, $owner->fresh()->password);
        $this->assertDatabaseHas('transactions', ['id' => $order->id, 'owner_id' => $owner->id]);
        $this->getJson('/api/reports/overview')->assertForbidden();
    }

    public function test_restore_rolls_back_an_earlier_write_if_a_later_write_fails(): void
    {
        $super = $this->account('super_owner');
        $owner = $this->account();
        $other = $this->account();
        LaundrySetting::create(['owner_id' => $owner->id, 'business_name' => 'Original']);
        LaundrySetting::create(['owner_id' => $other->id, 'business_name' => 'Original other']);
        Sanctum::actingAs($super, ['*']);
        config(['app.debug' => false]);

        // Use an isolated dispatcher so the failure hook cannot leak to other
        // tests or replace real registered application listeners.
        $originalDispatcher = LaundrySetting::getEventDispatcher();
        LaundrySetting::setEventDispatcher(clone $originalDispatcher);
        LaundrySetting::updating(function (LaundrySetting $settings) use ($other): void {
            if ($settings->owner_id === $other->id) {
                throw new \RuntimeException('Fixture second-row write failed');
            }
        });
        try {
            $this->postJson('/api/settings/restore', [
                'version' => 2, 'type' => 'owner_configuration_backup', 'settings' => [
                    ['owner_id' => $owner->id, 'business_name' => 'Must roll back'],
                    ['owner_id' => $other->id, 'business_name' => 'Must fail'],
                ],
            ])->assertStatus(500);
            $this->assertDatabaseHas('laundry_settings', ['owner_id' => $owner->id, 'business_name' => 'Original']);
            $this->assertDatabaseHas('laundry_settings', ['owner_id' => $other->id, 'business_name' => 'Original other']);
        } finally {
            LaundrySetting::setEventDispatcher($originalDispatcher);
        }
    }

    public function test_duplicate_owner_in_restore_is_rejected_without_writes(): void
    {
        $super = $this->account('super_owner');
        $owner = $this->account();
        LaundrySetting::create(['owner_id' => $owner->id, 'business_name' => 'Original']);
        Sanctum::actingAs($super, ['*']);
        $this->postJson('/api/settings/restore', [
            'version' => 2, 'type' => 'owner_configuration_backup', 'settings' => [
                ['owner_id' => $owner->id, 'business_name' => 'First'],
                ['owner_id' => $owner->id, 'business_name' => 'Second'],
            ],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('laundry_settings', ['owner_id' => $owner->id, 'business_name' => 'Original']);
    }

    public function test_owner_with_only_an_expense_cannot_be_demoted(): void
    {
        $super = $this->account('super_owner');
        $owner = $this->account();
        $other = $this->account();
        Expense::create([
            'owner_id' => $owner->id, 'recorded_by' => $owner->id,
            'amount' => 10000, 'category' => 'other', 'description' => 'Fixture', 'occurred_at' => now(),
        ]);
        Sanctum::actingAs($super, ['*']);
        $this->patchJson('/api/users/' . $owner->id, ['role' => 'kasir', 'owner_id' => $other->id])
            ->assertUnprocessable();
        $this->assertSame('owner', $owner->fresh()->role);
        $this->assertDatabaseHas('expenses', ['owner_id' => $owner->id, 'amount' => 10000]);
    }

    public function test_empty_owner_can_still_be_assigned_as_cashier_without_data_migration(): void
    {
        $super = $this->account('super_owner');
        $owner = $this->account();
        $other = $this->account();
        Sanctum::actingAs($super, ['*']);
        $this->patchJson('/api/users/' . $owner->id, ['role' => 'kasir', 'owner_id' => $other->id])
            ->assertOk()->assertJsonPath('data.role', 'kasir')->assertJsonPath('data.owner_id', $other->id);
    }

    public function test_csv_escapes_formula_prefixes_but_keeps_money_numeric(): void
    {
        $owner = $this->account();
        $order = $this->order($owner);
        Sanctum::actingAs($owner, ['*']);
        $this->postJson('/api/transactions/' . $order->id . '/payment', ['paid_amount' => 30000])->assertOk();
        foreach (['=1+1', '+1+1', '-1+1', '@SUM(1)', '  =1+1', "\t=1+1", "\r=1+1"] as $name) {
            $order->customer->update(['name' => $name]);
            $csv = $this->get('/api/reports/export?type=csv')->assertOk()->getContent();
            $this->assertStringContainsString("'" . $name, $csv);
            $this->assertStringContainsString(',cash,30000.00,', $csv);
        }
    }
}
