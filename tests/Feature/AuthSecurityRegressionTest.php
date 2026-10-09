<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthSecurityRegressionTest extends TestCase
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

    private function account(): User
    {
        return User::create([
            'name' => 'Security fixture', 'username' => 'security_fixture',
            'password' => Hash::make('fixture-password'), 'role' => 'owner', 'is_active' => true,
        ]);
    }

    private function loginToken(): string
    {
        Auth::forgetGuards();
        return $this->postJson('/api/auth/login', [
            'username' => 'security_fixture', 'password' => 'fixture-password',
        ])->assertOk()->json('data.token');
    }

    private function bearer(string $token): array
    {
        Auth::forgetGuards();
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_logout_deletes_only_the_current_real_token(): void
    {
        $user = $this->account();
        $current = $this->loginToken();
        $other = $this->loginToken();
        $this->postJson('/api/auth/logout', [], $this->bearer($current))->assertOk();
        $this->getJson('/api/auth/me', $this->bearer($current))->assertUnauthorized();
        $this->getJson('/api/auth/me', $this->bearer($other))->assertOk();
        $this->assertEquals(1, $user->tokens()->count());
    }

    public function test_new_token_has_expiry_and_legacy_tokens_expire_after_thirty_days(): void
    {
        $user = $this->account();
        $newToken = $this->loginToken();
        $model = PersonalAccessToken::findToken($newToken);
        $this->assertNotNull($model->expires_at);
        $this->assertTrue($model->expires_at->equalTo($model->created_at->copy()->addDays(30)));
        $legacy = $user->createToken('legacy-without-expiry')->plainTextToken;
        $this->travel(31)->days();
        $this->getJson('/api/auth/me', $this->bearer($newToken))->assertUnauthorized();
        $this->getJson('/api/auth/me', $this->bearer($legacy))->assertUnauthorized();
    }

    public function test_compromised_fingerprints_are_rejected_without_changing_credentials(): void
    {
        $user = $this->account();
        $blocked = $this->loginToken();
        $allowed = $this->loginToken();
        $model = PersonalAccessToken::findToken($blocked);
        config(['auth_security.compromised_token_hashes' => [$model->token]]);
        $this->getJson('/api/auth/me', $this->bearer($blocked))->assertUnauthorized();
        $this->getJson('/api/auth/me', $this->bearer($allowed))->assertOk();
        $this->assertTrue(Hash::check('fixture-password', $user->fresh()->password));
    }

    public function test_original_exposed_fingerprint_remains_denied(): void
    {
        $fingerprints = config('auth_security.compromised_token_hashes');
        $this->assertNotEmpty($fingerprints);
        $model = new PersonalAccessToken(['token' => $fingerprints[0]]);
        $this->assertFalse((Sanctum::$accessTokenAuthenticationCallback)($model, true));
        $this->assertFalse((Sanctum::$accessTokenAuthenticationCallback)($model, false));
    }

    public function test_login_has_identity_limit_retry_header_and_recovers_after_cooldown(): void
    {
        $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'security_fixture', 'password' => 'wrong-password',
            ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        }
        $this->postJson('/api/auth/login', [
            'username' => 'security_fixture', 'password' => 'fixture-password',
        ])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'login_rate_limited');
        $this->travel(61)->seconds();
        $this->loginToken();
    }

    public function test_login_ip_limit_cannot_be_bypassed_by_changing_username(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'unknown-' . $i, 'password' => 'wrong-password',
            ])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', [
            'username' => 'unknown-last', 'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_password_change_revokes_other_tokens_but_keeps_current_session(): void
    {
        $user = $this->account();
        $other = $this->loginToken();
        $current = $this->loginToken();
        $this->patchJson('/api/profile/password', [
            'current_password' => 'fixture-password', 'password' => 'new-fixture-password',
            'password_confirmation' => 'new-fixture-password',
        ], $this->bearer($current))->assertOk();
        $this->getJson('/api/auth/me', $this->bearer($other))->assertUnauthorized();
        $this->getJson('/api/auth/me', $this->bearer($current))->assertOk();
        $this->assertTrue(Hash::check('new-fixture-password', $user->fresh()->password));
        $this->assertEquals(1, $user->tokens()->count());
        $this->assertEquals(1, AuditLog::where('action', 'user.password_changed')->count());
        $metadata = AuditLog::where('action', 'user.password_changed')->first()->metadata;
        $this->assertStringNotContainsString('fixture-password', json_encode($metadata));
    }

    public function test_rejected_password_change_does_not_revoke_sessions_or_create_success_log(): void
    {
        $user = $this->account();
        $token = $this->loginToken();
        $this->patchJson('/api/profile/password', [
            'current_password' => 'wrong-password', 'password' => 'new-fixture-password',
            'password_confirmation' => 'new-fixture-password',
        ], $this->bearer($token))->assertUnprocessable();
        $this->assertTrue(Hash::check('fixture-password', $user->fresh()->password));
        $this->assertEquals(1, $user->tokens()->count());
        $this->assertEquals(0, AuditLog::where('action', 'user.password_changed')->count());
    }
}
