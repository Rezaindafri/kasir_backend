<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rejects_inactive_user(): void
    {
        User::create([
            'name' => 'Kasir Nonaktif',
            'username' => 'kasir_inactive',
            'email' => 'inactive@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'kasir_inactive',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403);
        $this->assertSame('Akun dinonaktifkan', $response->json('message'));
    }

    public function test_login_rejects_wrong_credentials_with_clear_message(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'akun_tidak_ada',
            'password' => 'secret123',
        ]);

        $response->assertUnauthorized();
        $this->assertSame('Username atau password salah', $response->json('message'));
    }

    public function test_cashier_login_returns_its_owner_scope(): void
    {
        $owner = User::create([
            'name' => 'Owner Scope',
            'username' => 'owner_scope_login',
            'email' => 'owner-scope-login@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Kasir Scope',
            'username' => 'kasir_scope_login',
            'email' => 'kasir-scope-login@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'kasir_scope_login',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('data.user.owner_id', $owner->id);
    }

    public function test_owner_can_create_user_and_list_users(): void
    {
        $owner = User::create([
            'name' => 'Owner Utama',
            'username' => 'owner_primary',
            'email' => 'owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson('/api/users', [
            'name' => 'Kasir Baru',
            'username' => 'kasir_baru',
            'email' => 'kasir@example.com',
            'password' => 'secret123',
            'role' => 'kasir',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Kasir Baru', $response->json('data.name'));
        $this->assertSame('kasir', $response->json('data.role'));
        $this->assertSame((string) $owner->id, (string) $response->json('data.owner_id'));
        $this->assertDatabaseHas('audit_logs', [
            'owner_id' => $owner->id,
            'actor_id' => $owner->id,
            'subject_type' => 'user',
            'subject_id' => $response->json('data.id'),
            'action' => 'user.created',
        ]);

        // Akun kasir yang baru dibuat Owner harus langsung dapat login dengan
        // kredensial yang dikirim pada saat pembuatan.
        $this->postJson('/api/auth/login', [
            'username' => 'kasir_baru',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('data.user.owner_id', $owner->id);

        $list = $this->getJson('/api/users');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('kasir_baru', $list->json('data.0.username'));
    }

    public function test_kasir_cannot_access_user_management(): void
    {
        $kasir = User::create([
            'name' => 'Kasir Biasa',
            'username' => 'kasir_biasa',
            'email' => 'kasirbiasa@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'is_active' => true,
        ]);

        Sanctum::actingAs($kasir, ['*']);

        $response = $this->getJson('/api/users');
        $response->assertStatus(403);
        $this->assertSame('Akses ditolak', $response->json('message'));
    }

    public function test_kasir_cannot_access_owner_dashboard_or_reports(): void
    {
        $kasir = User::create([
            'name' => 'Kasir Dashboard',
            'username' => 'kasir_dashboard',
            'email' => 'kasir.dashboard@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'is_active' => true,
        ]);

        Sanctum::actingAs($kasir, ['*']);

        $this->getJson('/api/dashboard/owner')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses ditolak');
        $this->getJson('/api/reports/revenue')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses ditolak');
        $this->getJson('/api/reports/statistics')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses ditolak');
        $this->get('/api/reports/export?type=csv')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses ditolak');
    }

    public function test_owner_can_only_manage_cashier_accounts(): void
    {
        $owner = User::create([
            'name' => 'Owner Pembatasan',
            'username' => 'owner_limited',
            'email' => 'owner.limited@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->postJson('/api/users', [
            'name' => 'Owner Tidak Sah',
            'username' => 'owner_tidak_sah',
            'password' => 'secret123',
            'role' => 'owner',
        ])->assertUnprocessable();
    }

    public function test_kasir_can_read_master_data_but_cannot_change_it(): void
    {
        $owner = User::create([
            'name' => 'Owner Master Data',
            'username' => 'owner_master_data',
            'email' => 'owner.master.data@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $kasir = User::create([
            'name' => 'Kasir Master Data',
            'username' => 'kasir_master_data',
            'email' => 'kasir.master.data@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'kasir',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $service = Service::create([
            'owner_id' => $owner->id,
            'name' => 'Cuci Kering',
            'price' => 15000,
            'unit' => 'kg',
            'estimation_hours' => 2,
        ]);
        $customer = Customer::create([
            'owner_id' => $owner->id,
            'name' => 'Pelanggan Lama',
            'phone' => '0812345678',
        ]);

        Sanctum::actingAs($kasir, ['*']);

        $this->getJson('/api/services')->assertOk();
        $this->getJson('/api/customers')->assertOk();
        $this->postJson('/api/customers', [
            'name' => 'Pelanggan Baru',
        ])->assertCreated();

        $this->postJson('/api/services', [
            'name' => 'Layanan Tidak Sah',
            'price' => 10000,
            'unit' => 'kg',
            'estimation_hours' => 1,
        ])->assertForbidden();
        $this->putJson('/api/services/' . $service->id, [
            'name' => 'Harga Diubah',
            'price' => 1,
            'unit' => 'kg',
            'estimation_hours' => 1,
        ])->assertForbidden();
        $this->deleteJson('/api/services/' . $service->id)->assertForbidden();
        $this->putJson('/api/customers/' . $customer->id, [
            'name' => 'Nama Diubah',
        ])->assertForbidden();
        $this->deleteJson('/api/customers/' . $customer->id)->assertForbidden();
    }
}
