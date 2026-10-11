<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Services\TransactionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiServicePaymentAndCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Multi-service tests require SQLite :memory:.');
        }
        return $app;
    }

    private function account(string $role = 'owner', ?int $ownerId = null): User
    {
        return User::create(['name' => 'Fixture', 'username' => 'multi_' . uniqid(),
            'password' => bcrypt('fixture-password'), 'role' => $role,
            'owner_id' => $ownerId, 'is_active' => true]);
    }

    private function order(User $owner, int $paid = 0, bool $withGoods = false): Transaction
    {
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Fixture customer']);
        $services = collect([[30000, 2], [50000, 4], ...($withGoods ? [[10000, 0]] : [])])
            ->map(fn ($values) => Service::create(['owner_id' => $owner->id, 'name' => 'Service ' . $values[1],
                'price' => $values[0], 'unit' => 'item', 'estimation_hours' => $values[1]]));
        $response = $this->postJson('/api/transactions', ['customer_id' => $customer->id,
            'paid_amount' => $paid, 'services' => $services->map(fn ($service) => [
                'service_id' => $service->id, 'qty' => 1, 'price' => 1,
            ])->all()])->assertCreated();
        return Transaction::findOrFail($response->json('data.id'));
    }

    public function test_service_snapshots_and_individual_deadlines_are_authoritative(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
        $owner = $this->account(); Sanctum::actingAs($owner);
        $order = $this->order($owner, 0, true);
        $lines = $order->transactionDetails()->orderBy('id')->get();
        $this->assertSame(90000, (int) $order->total_price);
        $this->assertTrue($order->estimated_finish_at->equalTo(now()->addHours(4)));
        $this->assertTrue($lines[0]->estimated_finish_at->equalTo(now()->addHours(2)));
        $this->assertNull($lines[2]->estimated_finish_at);
        $this->assertTrue($lines[2]->is_ready);
        $lines[0]->service->update(['name' => 'Renamed', 'price' => 999999, 'estimation_hours' => 24]);
        $this->getJson('/api/transactions/' . $order->id)->assertOk()
            ->assertJsonPath('data.transaction_details.0.service_name', 'Service 2');
        $this->assertSame(30000, (int) $lines[0]->fresh()->subtotal);
        $this->travelBack();
    }

    public function test_partial_handover_applies_existing_dp_and_keeps_remaining_services_open(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
        $owner = $this->account(); $cashier = $this->account('kasir', $owner->id);
        Sanctum::actingAs($cashier);
        $order = $this->order($owner, 20000);
        $lines = $order->transactionDetails()->orderBy('id')->get();
        $this->travel(2)->hours();
        $url = '/api/transactions/' . $order->id . '/collection';
        $this->postJson($url, ['detail_ids' => [$lines[0]->id], 'payment_amount' => 9999])->assertUnprocessable();
        $this->assertSame(20000, (int) $order->fresh()->paid_amount);
        $this->postJson($url, ['detail_ids' => [$lines[0]->id], 'payment_amount' => 10000,
            'payment_method' => 'qris', 'payment_note' => 'Ambil layanan cepat'])->assertOk()
            ->assertJsonPath('data.payment_status', 'downPayment')
            ->assertJsonPath('data.laundry_status', 'received');
        $this->assertTrue($lines[0]->fresh()->is_collected);
        $this->assertFalse($lines[1]->fresh()->is_collected);
        $this->assertSame($cashier->id, $lines[0]->fresh()->collected_by);
        $this->assertSame(1, AuditLog::where('action', 'transaction.partially_collected')->count());
        $this->postJson($url, ['detail_ids' => [$lines[0]->id], 'payment_amount' => 10000])->assertUnprocessable();
        $this->assertSame(30000, (int) TransactionPayment::sum('amount'));
        $this->travel(2)->hours();
        Sanctum::actingAs($owner);
        $this->postJson('/api/transactions/' . $order->id . '/payment', [
            'amount' => 50000, 'request_id' => 'fc7b7934-2e5d-4aa7-b25f-c7243d55e309',
            'payment_note' => 'Pelunasan owner', 'payment_method' => 'transfer',
        ])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertNotSame('collected', $order->fresh()->laundry_status);
        $this->postJson($url, ['detail_ids' => [$lines[1]->id], 'payment_amount' => 0])->assertOk()
            ->assertJsonPath('data.laundry_status', 'collected');
        $this->assertSame(80000, (int) TransactionPayment::sum('amount'));
        $this->assertSame(3, TransactionPayment::count());
        $this->travelBack();
    }

    public function test_incremental_payment_retry_is_idempotent_and_does_not_collect(): void
    {
        $owner = $this->account(); Sanctum::actingAs($owner);
        $order = $this->order($owner);
        $payload = ['amount' => 15000, 'payment_method' => 'cash', 'payment_note' => 'DP tambahan',
            'request_id' => '95c5a0e3-8d1f-43c5-a1d4-578be0863c47'];
        $url = '/api/transactions/' . $order->id . '/payment';
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, TransactionPayment::count());
        $this->assertSame(15000, (int) $order->fresh()->paid_amount);
        $this->assertSame('received', $order->fresh()->laundry_status);
        $this->assertSame('DP tambahan', TransactionPayment::first()->notes);
        $this->postJson($url, [...$payload, 'amount' => 10000])->assertUnprocessable();
        $this->postJson($url, ['amount' => 1000])->assertUnprocessable();
        $this->postJson($url, ['paid_amount' => 15000, 'amount' => 1000])->assertUnprocessable();
    }

    public function test_mixed_ready_and_processing_counts_and_filters_match(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
        $owner = $this->account(); Sanctum::actingAs($owner);
        $order = $this->order($owner);
        $this->travel(2)->hours();
        $this->getJson('/api/dashboard/owner')->assertOk()
            ->assertJsonPath('data.summary.processing_count', 1)->assertJsonPath('data.summary.ready_to_pickup_count', 1);
        $this->getJson('/api/dashboard/kasir')->assertOk()
            ->assertJsonPath('data.summary.in_progress', 1)->assertJsonPath('data.summary.ready_to_pickup', 1);
        foreach (['active_processing=1', 'ready_for_collection=1'] as $query) {
            $this->getJson('/api/transactions?' . $query)->assertOk()->assertJsonPath('meta.total', 1);
        }
        $this->travelBack();
    }

    public function test_collection_cannot_bypass_deadlines_or_other_owner_scope(): void
    {
        $owner = $this->account(); Sanctum::actingAs($owner);
        $order = $this->order($owner, 80000);
        $line = $order->transactionDetails()->first();
        $this->postJson('/api/transactions/' . $order->id . '/collection', [
            'detail_ids' => [$line->id], 'payment_amount' => 0,
        ])->assertUnprocessable();
        $this->postJson('/api/transactions/' . $order->id . '/collection', [
            'detail_ids' => [99999], 'payment_amount' => 0,
        ])->assertUnprocessable();
        $other = $this->account(); Sanctum::actingAs($other);
        $this->getJson('/api/transactions/' . $order->id)->assertNotFound();
        $this->postJson('/api/transactions/' . $order->id . '/payment', [
            'paid_amount' => 80000,
        ])->assertNotFound();
        $this->postJson('/api/transactions/' . $order->id . '/collection', [
            'detail_ids' => [$line->id], 'payment_amount' => 0,
        ])->assertNotFound();
    }

    public function test_zero_or_missing_duration_is_accepted_and_goods_never_become_late(): void
    {
        $owner = $this->account(); Sanctum::actingAs($owner);
        foreach ([0, null] as $duration) {
            $this->postJson('/api/services', ['name' => 'Barang', 'unit' => 'item', 'price' => 10000,
                'estimation_hours' => $duration])->assertCreated()->assertJsonPath('data.estimation_hours', 0);
        }
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Fixture']);
        $service = Service::first();
        $response = $this->postJson('/api/transactions', ['customer_id' => $customer->id,
            'services' => [['service_id' => $service->id, 'qty' => 1, 'price' => 1, 'express' => true]],
        ])->assertCreated()->assertJsonPath('data.laundry_status', 'completed');
        $order = Transaction::findOrFail($response->json('data.id'));
        $this->assertSame(12500, (int) $order->total_price);
        $this->assertNull($order->estimated_finish_at);
        $this->travel(30)->days();
        app(TransactionStatusService::class)->synchronize($owner->id);
        $this->assertSame('completed', $order->fresh()->laundry_status);
        $this->travelBack();
    }

    public function test_private_proof_is_kept_per_handover_and_owner_scoped(): void
    {
        Storage::fake('proofs');
        $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
        $owner = $this->account(); Sanctum::actingAs($owner);
        $order = $this->order($owner, 80000);
        $lines = $order->transactionDetails;
        $this->travel(2)->hours();
        $url = '/api/transactions/' . $order->id;
        $this->post($url . '/collection', ['detail_ids' => [$lines[0]->id], 'payment_amount' => 0,
            'proof_image' => UploadedFile::fake()->image('first.png')])->assertOk();
        $firstPath = $lines[0]->fresh()->proof_image_path;
        $this->travel(2)->hours();
        $this->post($url . '/collection', ['detail_ids' => [$lines[1]->id], 'payment_amount' => 0,
            'proof_image' => UploadedFile::fake()->image('second.png')])->assertOk();
        $this->assertNotSame($firstPath, $lines[1]->fresh()->proof_image_path);
        $this->assertSame($firstPath, $lines[0]->fresh()->proof_image_path);
        Storage::disk('proofs')->assertExists($firstPath);
        foreach ($lines as $line) $this->get($url . '/proof?detail_id=' . $line->id)->assertOk();
        Sanctum::actingAs($this->account());
        $this->getJson($url . '/proof?detail_id=' . $lines[0]->id)->assertNotFound();
        $this->travelBack();
    }

    public function test_legacy_proof_endpoint_preserves_earlier_partial_handover(): void
    {
        Storage::fake('proofs');
        $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
        $owner = $this->account(); $cashier = $this->account('kasir', $owner->id);
        Sanctum::actingAs($cashier);
        $order = $this->order($owner, 80000);
        $lines = $order->transactionDetails;
        $this->travel(2)->hours();
        $url = '/api/transactions/' . $order->id;
        $this->post($url . '/collection', ['detail_ids' => [$lines[0]->id], 'payment_amount' => 0,
            'proof_image' => UploadedFile::fake()->image('earlier.png')])->assertOk();
        $earlier = $lines[0]->fresh();
        $this->travel(2)->hours();
        Sanctum::actingAs($owner);
        $this->getJson($url)->assertOk()->assertJsonPath('data.laundry_status', 'completed');
        $this->post($url . '/proof', ['proof_image' => UploadedFile::fake()->image('later.png')])
            ->assertOk()->assertJsonPath('data.laundry_status', 'collected');
        $preserved = $lines[0]->fresh();
        $this->assertSame($earlier->proof_image_path, $preserved->proof_image_path);
        $this->assertSame($cashier->id, $preserved->collected_by);
        $this->assertTrue($earlier->collected_at->equalTo($preserved->collected_at));
        $this->assertSame($owner->id, $lines[1]->fresh()->collected_by);
        $this->assertNotSame($earlier->proof_image_path, $lines[1]->fresh()->proof_image_path);
        $this->get($url . '/proof?detail_id=' . $lines[0]->id)->assertOk();
        $this->travelBack();
    }

    public function test_migration_preserves_old_money_deadline_and_unknown_collection_actor(): void
    {
        $migration = require database_path('migrations/2026_10_10_000006_add_service_line_handover_and_payment_notes.php');
        // Only this isolated SQLite fixture is rolled back, NEVER Laragon DB.
        $migration->down();
        $owner = $this->account();
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Legacy fixture']);
        $service = Service::create(['owner_id' => $owner->id, 'name' => 'Legacy service',
            'unit' => 'kg', 'price' => 90000, 'estimation_hours' => 99]);
        $created = '2026-10-01 07:00:00'; $finish = '2026-10-01 09:00:00';
        $id = DB::table('transactions')->insertGetId(['owner_id' => $owner->id, 'cashier_id' => $owner->id,
            'customer_id' => $customer->id, 'invoice_number' => 'INV-LEGACY-MIGRATION',
            'total_price' => 30000, 'paid_amount' => 30000, 'payment_status' => 'paid',
            'payment_method' => 'cash', 'laundry_status' => 'collected', 'order_status' => 'delivered',
            'estimated_finish_at' => $finish, 'created_at' => $created, 'updated_at' => $created]);
        DB::table('transaction_details')->insert(['transaction_id' => $id, 'service_id' => $service->id,
            'qty' => 3, 'price' => 10000, 'subtotal' => 30000, 'created_at' => $created, 'updated_at' => $created]);
        $migration->up();
        $order = Transaction::findOrFail($id);
        $line = $order->transactionDetails->first();
        $this->assertSame(30000, (int) $order->total_price);
        $this->assertSame(10000, (int) $line->price);
        $this->assertSame($finish, $line->estimated_finish_at->toDateTimeString());
        $this->assertTrue($line->is_collected);
        $this->assertNull($line->collected_at);
        $this->assertNull($line->collected_by);
        $this->assertSame(0, AuditLog::count());
    }
}
