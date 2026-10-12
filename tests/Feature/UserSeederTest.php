<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountPolicyService;
use App\Services\AuditLogService;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Seeder tests require SQLite :memory:.');
        }
        return $app;
    }

    private function command(array $values = []): Command
    {
        $data = [...['name' => 'Fixture Admin', 'username' => 'fixture_admin',
            'email' => '', 'password' => 'fixture-secure-password'], ...$values];
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('option')->with('no-interaction')->andReturn(false);
        $command->shouldReceive('ask')->with('Nama Super Owner')->andReturn($data['name']);
        $command->shouldReceive('ask')->with('Username login')->andReturn($data['username']);
        $command->shouldReceive('ask')->with('Email (opsional, Enter untuk kosong)')->andReturn($data['email']);
        $minimum = max(12, app(AccountPolicyService::class)->passwordMinLength());
        $command->shouldReceive('secret')->with('Password minimal ' . $minimum . ' karakter', false)->andReturn($data['password']);
        $command->shouldReceive('secret')->with('Ulangi password', false)->andReturn($data['confirmation'] ?? $data['password']);
        $command->shouldReceive('info', 'warn', 'error');
        return $command;
    }

    private function runSeeder(Command $command): void
    {
        (new UserSeeder())->setCommand($command)->run();
    }

    public function test_creates_only_active_super_owner_with_hash_and_safe_audit(): void
    {
        $this->runSeeder($this->command());
        $user = User::sole();
        $this->assertSame('super_owner', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->owner_id);
        $this->assertNull($user->email);
        $this->assertTrue(Hash::check('fixture-secure-password', $user->password));
        $this->assertNotSame('fixture-secure-password', $user->getRawOriginal('password'));
        $audit = AuditLog::sole();
        $this->assertSame('user.created', $audit->action);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertNull($audit->actor_id);
        $this->assertTrue($audit->metadata['bootstrap']);
        $this->assertArrayNotHasKey('password', $audit->metadata);
        $this->assertStringNotContainsString('fixture-secure-password', $audit->toJson());
    }

    public function test_existing_account_is_never_changed_and_no_questions_are_asked(): void
    {
        $user = User::create(['name' => 'Existing owner fixture', 'username' => 'existing_owner',
            'password' => 'existing-fixture-password', 'role' => 'owner', 'is_active' => true]);
        $before = $user->fresh()->getRawOriginal();
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('warn')->once();
        $command->shouldNotReceive('ask', 'secret');
        $this->runSeeder($command);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame(1, User::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_email_and_names_are_trimmed(): void
    {
        $this->runSeeder($this->command(['name' => ' Admin ', 'username' => ' fixture_admin ',
            'email' => ' admin@example.test ']));
        $user = User::sole();
        $this->assertSame('Admin', $user->name);
        $this->assertSame('fixture_admin', $user->username);
        $this->assertSame('admin@example.test', $user->email);
    }

    public function test_invalid_fields_create_neither_user_nor_audit(): void
    {
        foreach (['name' => '', 'username' => str_repeat('a', 101),
            'email' => 'invalid', 'password' => 'too-short'] as $field => $value) {
            try {
                $this->runSeeder($this->command([$field => $value]));
                $this->fail('Expected validation rejection.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($field, $error->errors());
            }
            $this->assertSame(0, User::count());
            $this->assertSame(0, AuditLog::count());
        }
    }

    public function test_password_confirmation_mismatch_creates_nothing(): void
    {
        $this->runSeeder($this->command(['confirmation' => 'different-fixture-password']));
        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_respects_stricter_configured_password_policy(): void
    {
        app(AccountPolicyService::class)->setPasswordMinLength(30);
        try {
            $this->runSeeder($this->command());
            $this->fail('Expected password policy rejection.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('password', $error->errors());
        }
        $this->assertSame(0, User::count());
    }

    public function test_noninteractive_bootstrap_is_refused(): void
    {
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('option')->with('no-interaction')->andReturn(true);
        $command->shouldNotReceive('ask', 'secret');
        try {
            $this->runSeeder($command);
            $this->fail('Expected noninteractive bootstrap rejection.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('interaktif', $error->getMessage());
        }
        $this->assertSame(0, User::count());
    }

    public function test_audit_failure_rolls_back_account_creation(): void
    {
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Fixture audit failure'));
        $this->app->instance(AuditLogService::class, $audit);
        try {
            $this->runSeeder($this->command());
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture audit failure', $error->getMessage());
        }
        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::count());
    }
}
