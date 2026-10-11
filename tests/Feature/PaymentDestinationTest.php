<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PaymentDestination;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Payment destination tests require SQLite :memory:.');
        }
        return $app;
    }

    private function account(string $role = 'owner', ?int $ownerId = null): User
    {
        return User::create(['name' => 'Fixture actor', 'username' => 'destination_' . uniqid(),
            'password' => bcrypt('fixture-password'), 'role' => $role,
            'owner_id' => $ownerId, 'is_active' => true]);
    }

    private function bank(string $name = 'Utama', string $number = '00123456789'): int
    {
        return $this->postJson('/api/settings/payment-destinations', ['kind' => 'transfer',
            'name' => $name, 'bank_name' => 'Bank Fixture', 'account_holder' => 'Usaha Fixture',
            'account_number' => $number])->assertCreated()->json('data.id');
    }

    private function order(User $owner, array $payment = []): Transaction
    {
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Fixture customer']);
        $service = Service::create(['owner_id' => $owner->id, 'name' => 'Goods', 'unit' => 'item',
            'price' => 10000, 'estimation_hours' => 0]);
        $result = $this->postJson('/api/transactions', ['customer_id' => $customer->id,
            'services' => [['service_id' => $service->id, 'qty' => 1, 'price' => 0]], ...$payment])->assertCreated();
        return Transaction::findOrFail($result->json('data.id'));
    }

    public function test_multiple_banks_encrypt_numbers_and_never_log_plaintext(): void
    {
        $owner = $this->account(); Sanctum::actingAs($owner);
        $id = $this->bank(); $this->bank('Cadangan', '00987654321');
        $raw = DB::table('payment_destinations')->where('id', $id)->value('account_number');
        $this->assertNotSame('00123456789', $raw);
        $this->assertStringNotContainsString('00123456789', $raw);
        $this->assertSame('00123456789', Crypt::decryptString($raw));
        $this->getJson('/api/settings/payment-destinations')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['account_number' => '00123456789']);
        $this->assertArrayNotHasKey('account_number', PaymentDestination::find($id)->toArray());
        $this->assertStringNotContainsString('00123456789', AuditLog::all()->toJson());
        $this->assertSame(2, AuditLog::where('action', 'payment_destination.created')->count());
    }

    public function test_cashier_can_read_own_destinations_but_cannot_manage_or_read_other_owner(): void
    {
        $owner = $this->account(); $other = $this->account();
        Sanctum::actingAs($owner); $id = $this->bank();
        Sanctum::actingAs($this->account('kasir', $owner->id));
        $this->getJson('/api/settings/payment-destinations')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/settings/payment-destinations', [])->assertForbidden();
        $this->patchJson('/api/settings/payment-destinations/' . $id, ['is_active' => false])->assertForbidden();
        Sanctum::actingAs($other);
        $this->getJson('/api/settings/payment-destinations')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/settings/payment-destinations/' . $id, ['name' => 'Hijack'])->assertNotFound();
        Sanctum::actingAs($this->account('super_owner'));
        $this->getJson('/api/settings/payment-destinations')->assertForbidden();
    }

    public function test_qris_image_is_private_and_owner_scoped(): void
    {
        Storage::fake('proofs'); Storage::fake('public');
        $owner = $this->account(); Sanctum::actingAs($owner);
        $id = $this->post('/api/settings/payment-destinations', ['kind' => 'qris', 'name' => 'QRIS usaha',
            'qris_image' => UploadedFile::fake()->image('qris.png', 400, 400)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_qris_image', true)->json('data.id');
        $path = PaymentDestination::find($id)->qris_image_path;
        Storage::disk('proofs')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $url = '/api/settings/payment-destinations/' . $id . '/image';
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Sanctum::actingAs($this->account('kasir', $owner->id)); $this->get($url)->assertOk();
        Sanctum::actingAs($this->account()); $this->getJson($url)->assertNotFound();
    }

    public function test_validation_rejects_invalid_accounts_and_unsafe_images(): void
    {
        Storage::fake('proofs'); Sanctum::actingAs($this->account());
        $base = ['kind' => 'transfer', 'name' => 'Bank', 'bank_name' => 'Fixture', 'account_holder' => 'Fixture'];
        foreach (['abc123', '1234', str_repeat('1', 35)] as $number) {
            $this->postJson('/api/settings/payment-destinations', [...$base, 'account_number' => $number])
                ->assertUnprocessable();
        }
        $qris = ['kind' => 'qris', 'name' => 'QRIS'];
        $this->postJson('/api/settings/payment-destinations', $qris)->assertUnprocessable();
        foreach ([UploadedFile::fake()->create('fake.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('large.png', 2049, 50),
            UploadedFile::fake()->image('huge.jpg')->size(2049)] as $image) {
            $this->post('/api/settings/payment-destinations', [...$qris, 'qris_image' => $image],
                ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertSame(0, PaymentDestination::count());
        $this->assertSame(0, AuditLog::where('subject_type', 'payment_destination')->count());
        $this->assertSame([], Storage::disk('proofs')->allFiles());
    }

    public function test_creation_payment_and_followup_record_selected_bank_and_safe_snapshot(): void
    {
        $owner = $this->account(); Sanctum::actingAs($owner); $id = $this->bank();
        $order = $this->order($owner, ['paid_amount' => 3000, 'payment_method' => 'transfer',
            'payment_destination_id' => $id]);
        $this->assertSame($id, TransactionPayment::first()->payment_destination_id);
        $this->postJson('/api/transactions/' . $order->id . '/payment', ['amount' => 7000,
            'payment_method' => 'transfer', 'payment_destination_id' => $id,
            'request_id' => 'fc7b7934-2e5d-4aa7-b25f-c7243d55e309'])->assertOk();
        $this->assertSame(10000, (int) TransactionPayment::sum('amount'));
        $this->assertSame(2, TransactionPayment::count());
        $this->getJson('/api/transactions/' . $order->id)->assertOk()
            ->assertJsonPath('data.payments.0.payment_destination_id', $id)
            ->assertJsonPath('data.payments.0.recorder.name', 'Fixture actor');
        $this->assertStringNotContainsString('00123456789', TransactionPayment::all()->toJson());
    }

    public function test_wrong_group_kind_and_inactive_destinations_rollback_payment_and_handover(): void
    {
        Storage::fake('proofs'); $owner = $this->account(); $other = $this->account();
        Sanctum::actingAs($other); $foreign = $this->bank();
        Sanctum::actingAs($owner); $own = $this->bank(); $order = $this->order($owner);
        $url = '/api/transactions/' . $order->id;
        foreach ([['payment_method' => 'transfer', 'payment_destination_id' => $foreign],
            ['payment_method' => 'qris', 'payment_destination_id' => $own],
            ['payment_method' => 'cash', 'payment_destination_id' => $own],
            ['payment_method' => 'transfer']] as $fields) {
            $this->postJson($url . '/payment', ['amount' => 1000,
                'request_id' => 'fc7b7934-2e5d-4aa7-b25f-c7243d55e309', ...$fields])->assertUnprocessable();
        }
        $this->patchJson('/api/settings/payment-destinations/' . $own, ['is_active' => false])->assertOk();
        $this->post($url . '/collection', ['payment_amount' => 10000, 'payment_method' => 'transfer',
            'payment_destination_id' => $own, 'proof_image' => UploadedFile::fake()->image('proof.jpg')],
            ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, (int) $order->fresh()->paid_amount);
        $this->assertNotSame('collected', $order->fresh()->laundry_status);
        $this->assertFalse($order->transactionDetails()->first()->is_collected);
        $this->assertSame(0, TransactionPayment::count());
        $this->assertSame([], Storage::disk('proofs')->allFiles());
    }

    public function test_retry_and_deactivation_preserve_history_without_double_counting(): void
    {
        $owner = $this->account(); Sanctum::actingAs($owner);
        $id = $this->bank(); $second = $this->bank('Second', '1234567890'); $order = $this->order($owner);
        $payload = ['amount' => 3000, 'payment_method' => 'transfer', 'payment_destination_id' => $id,
            'request_id' => 'fc7b7934-2e5d-4aa7-b25f-c7243d55e309'];
        $url = '/api/transactions/' . $order->id . '/payment';
        $this->postJson($url, $payload)->assertOk();
        $label = TransactionPayment::first()->payment_destination_label;
        $this->patchJson('/api/settings/payment-destinations/' . $id,
            ['is_active' => false, 'bank_name' => 'Renamed', 'account_number' => '00111111111'])->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, [...$payload, 'payment_destination_id' => $second])->assertUnprocessable();
        $this->assertSame(1, TransactionPayment::count());
        $this->assertSame($label, TransactionPayment::first()->payment_destination_label);
        $this->assertSame(3000, (int) $order->fresh()->paid_amount);
        Sanctum::actingAs($this->account('kasir', $owner->id));
        $this->getJson('/api/settings/payment-destinations')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, PaymentDestination::count());
    }

    public function test_cash_is_unchanged_and_collection_proofs_remain_in_detail_and_scoped(): void
    {
        Storage::fake('proofs'); $owner = $this->account(); Sanctum::actingAs($owner); $this->bank();
        $order = $this->order($owner, ['paid_amount' => 1000, 'payment_method' => 'cash']);
        $this->post('/api/transactions/' . $order->id . '/collection', ['payment_amount' => 9000,
            'payment_method' => 'cash', 'proof_image' => UploadedFile::fake()->image('proof.jpg')],
            ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/transactions/' . $order->id)->assertOk()
            ->assertJsonPath('data.has_proof_image', true)
            ->assertJsonPath('data.transaction_details.0.has_proof_image', true);
        $url = '/api/transactions/' . $order->id . '/proof?detail_id=' . $order->transactionDetails()->first()->id;
        $this->get($url)->assertOk();
        Sanctum::actingAs($this->account()); $this->getJson($url)->assertNotFound();
        $this->assertSame(10000, (int) TransactionPayment::sum('amount'));
        $this->assertNull(TransactionPayment::first()->payment_destination_id);
    }
}
