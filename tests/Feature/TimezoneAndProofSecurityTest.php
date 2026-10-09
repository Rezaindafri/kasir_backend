<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TimezoneAndProofSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require SQLite :memory:, never the application DB.');
        }
        return $app;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $role = 'owner', ?int $ownerId = null): User
    {
        return User::create([
            'name' => 'WIB fixture', 'username' => 'wib_' . uniqid(),
            'password' => bcrypt('fixture-password'), 'role' => $role,
            'owner_id' => $ownerId, 'is_active' => true,
        ]);
    }

    private function order(User $owner, string $created = '2026-09-29 10:00:00', array $changes = []): Transaction
    {
        $customer = Customer::create(['owner_id' => $owner->id, 'name' => 'Fixture customer']);
        $order = Transaction::create(array_replace([
            'owner_id' => $owner->id, 'cashier_id' => $owner->id, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-WIB-' . uniqid(), 'total_price' => 100000, 'paid_amount' => 100000,
            'payment_status' => 'paid', 'payment_method' => 'cash', 'laundry_status' => 'collected',
            'order_status' => 'delivered', 'created_at' => $created, 'updated_at' => $created,
        ], $changes));
        $order->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();
        return $order;
    }

    private function payment(Transaction $order, int $amount, string $paidAt): void
    {
        TransactionPayment::create([
            'owner_id' => $order->owner_id, 'transaction_id' => $order->id,
            'recorded_by' => $order->cashier_id, 'amount' => $amount,
            'payment_method' => 'cash', 'paid_at' => $paidAt, 'source' => 'payment',
        ]);
    }

    public function test_wib_day_boundaries_match_summary_detail_csv_and_payment_date_not_order_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'UTC'));
        $owner = $this->account();
        $order = $this->order($owner);
        $before = $order->getRawOriginal('created_at');
        $this->payment($order, 5000, '2026-09-30 16:59:59');
        $this->payment($order, 30000, '2026-09-30 17:00:00');
        $this->payment($order, 60000, '2026-10-01 16:59:59');
        $this->payment($order, 5000, '2026-10-01 17:00:00');
        $foreign = $this->order($this->account());
        $this->payment($foreign, 99999, '2026-10-01 10:00:00');
        Sanctum::actingAs($owner);
        $query = '?start_date=2026-10-01&end_date=2026-10-01';
        $overview = $this->getJson('/api/reports/overview' . $query)->assertOk();
        $overview->assertJsonPath('data.period.timezone', 'Asia/Jakarta')
            ->assertJsonPath('data.period.start_date', '2026-10-01')
            ->assertJsonPath('data.summary.payments_received', 90000)
            ->assertJsonPath('data.summary.order_count', 0);
        $this->assertSame(90000, collect($overview->json('data.payments'))->sum('payment_amount'));
        $this->assertCount(2, $overview->json('data.payments'));
        $this->assertSame(90000, collect($overview->json('data.chart'))->last()['value']);
        $this->getJson('/api/reports/revenue' . $query)->assertOk()->assertJsonPath('data.revenue', 90000);
        foreach (['/api/dashboard/owner?period=today', '/api/dashboard/owner/financial?period=today'] as $uri) {
            $response = $this->getJson($uri)->assertOk()->assertJsonPath('data.summary.payments_received', 90000);
            $this->assertSame(90000, collect($response->json('data.payment_details'))->sum('payment_amount'));
            $this->assertSame(90000, collect($response->json('data.chart'))->sum('value'));
        }
        $csv = $this->get('/api/reports/export' . $query . '&type=csv')->assertOk()->getContent();
        $this->assertStringContainsString('source,timezone', $csv);
        $this->assertStringContainsString('2026-10-01 00:00:00', $csv);
        $this->assertStringContainsString('2026-10-01 23:59:59', $csv);
        $this->assertStringContainsString(',WIB', $csv);
        $this->assertStringNotContainsString($foreign->invoice_number, $csv);
        $this->assertSame($before, $order->fresh()->getRawOriginal('created_at'));
    }

    public function test_transaction_filters_and_cashier_today_use_wib_not_utc_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 16:59:59', 'UTC'));
        $owner = $this->account();
        $this->order($owner, '2026-09-30 16:59:59');
        $first = $this->order($owner, '2026-09-30 17:00:00');
        $last = $this->order($owner, '2026-10-01 16:59:59');
        $this->order($owner, '2026-10-01 17:00:00');
        Sanctum::actingAs($this->account('kasir', $owner->id));
        $filtered = $this->getJson('/api/transactions?from=2026-10-01&to=2026-10-01')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $last->id], array_column($filtered->json('data'), 'id'));
        // Compatibility: older Flutter builds sent a timezone-less ISO date.
        $oldFilter = $this->getJson('/api/transactions?from=2026-10-01T00:00:00&to=2026-10-01T00:00:00')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $last->id], array_column($oldFilter->json('data'), 'id'));
        $today = $this->getJson('/api/transactions/today')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $last->id], array_column($today->json('data'), 'id'));
        $this->getJson('/api/dashboard/kasir')->assertOk()->assertJsonPath('data.summary.transactions_today', 2);
    }

    public function test_month_chart_has_31_daily_points_and_year_chart_matches_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));
        $owner = $this->account();
        $order = $this->order($owner);
        $this->payment($order, 30000, '2026-09-30 17:00:00');
        $this->payment($order, 60000, '2026-10-31 16:59:59');
        $this->payment($order, 10000, '2026-10-31 17:00:00');
        Sanctum::actingAs($owner);
        $month = $this->getJson('/api/dashboard/owner/financial?period=month')->assertOk();
        $this->assertCount(31, $month->json('data.chart'));
        $month->assertJsonPath('data.chart.0.date', '2026-10-01')
            ->assertJsonPath('data.chart.30.date', '2026-10-31')
            ->assertJsonPath('data.summary.payments_received', 90000);
        $year = $this->getJson('/api/dashboard/owner/financial?period=year')->assertOk();
        $this->assertSame($year->json('data.summary.payments_received'), collect($year->json('data.chart'))->sum('value'));
    }

    public function test_expense_offset_and_legacy_wall_time_are_persisted_as_utc(): void
    {
        Sanctum::actingAs($this->account());
        foreach (['2026-10-01T00:30:00+07:00', '2026-09-30T17:30:00Z', '2026-10-01 00:30:00'] as $time) {
            $response = $this->postJson('/api/expenses', [
                'amount' => 1000, 'category' => 'Fixture', 'occurred_at' => $time,
            ])->assertCreated();
            $expense = Expense::findOrFail($response->json('data.id'));
            $this->assertSame('2026-09-30 17:30:00', $expense->getRawOriginal('occurred_at'));
        }
        $this->getJson('/api/expenses?start_date=2026-10-01&end_date=2026-10-01')
            ->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/expenses?start_date=2026-09-30&end_date=2026-09-30')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_upload_is_private_and_download_requires_auth_and_same_owner_for_both_roles(): void
    {
        Storage::fake('public');
        Storage::fake('proofs');
        $owner = $this->account();
        $order = $this->order($owner, changes: ['laundry_status' => 'completed', 'order_status' => 'completed']);
        Sanctum::actingAs($this->account('kasir', $owner->id));
        $response = $this->postJson('/api/transactions/' . $order->id . '/proof', [
            'proof_image' => UploadedFile::fake()->image('fixture.png'),
        ])->assertOk()->assertJsonPath('data.has_proof_image', true);
        $path = $response->json('data.proof_image_path');
        $this->assertStringStartsWith('proofs-private/', $path);
        Storage::disk('proofs')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->getJson('/api/transactions/' . $order->id . '/proof')->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        Sanctum::actingAs($owner);
        $download = $this->get('/api/transactions/' . $order->id . '/proof')->assertOk();
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $otherOwner = $this->account();
        foreach ([$otherOwner, $this->account('kasir', $otherOwner->id)] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson('/api/transactions/' . $order->id . '/proof')->assertNotFound();
        }
        Sanctum::actingAs($this->account('super_owner'));
        $this->getJson('/api/transactions/' . $order->id . '/proof')->assertForbidden();
        auth()->forgetGuards();
        $this->getJson('/api/transactions/' . $order->id . '/proof')->assertUnauthorized();
    }

    public function test_legacy_proof_can_only_be_read_via_authorized_endpoint_without_moving_it(): void
    {
        Storage::fake('public');
        Storage::fake('proofs');
        $owner = $this->account();
        $order = $this->order($owner, changes: ['proof_image_path' => 'proofs/legacy.png']);
        Storage::disk('public')->put('proofs/legacy.png', 'fixture-image');
        Sanctum::actingAs($owner);
        $this->assertSame('fixture-image', $this->get('/api/transactions/' . $order->id . '/proof')->assertOk()->streamedContent());
        $this->get('/storage/proofs/legacy.png')->assertForbidden();
        Storage::disk('public')->assertExists('proofs/legacy.png');
        $this->assertSame('proofs/legacy.png', $order->fresh()->proof_image_path);
        $order->update(['proof_image_path' => 'proofs/../../.env']);
        $this->get('/api/transactions/' . $order->id . '/proof')->assertNotFound();
    }

    public function test_artisan_router_denies_static_legacy_proofs_before_framework_routing(): void
    {
        foreach (['/storage/proofs/fixture.png', '/storage/%70roofs/fixture.png', '/storage/other/../proofs/fixture.png', '/STORAGE/PROOFS/fixture.png'] as $uri) {
            $code = 'register_shutdown_function(fn () => print(http_response_code())); '
                . '$_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . '; '
                . 'require ' . var_export(base_path('server.php'), true) . ';';
            $process = new Process([PHP_BINARY, '-r', $code], public_path());
            $process->mustRun();
            $this->assertSame('403', $process->getOutput());
        }
    }
}
