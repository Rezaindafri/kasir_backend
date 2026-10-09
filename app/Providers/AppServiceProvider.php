<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $valid): bool => $valid &&
                !in_array($token->token, config('auth_security.compromised_token_hashes', []), true),
        );

        RateLimiter::for('login', function (Request $request): array {
            $identity = hash('sha256', Str::lower((string) $request->input('username')));
            $ip = (string) $request->ip();
            $response = fn (Request $request, array $headers) => response()->json([
                'status' => 'error',
                'code' => 'login_rate_limited',
                'message' => 'Terlalu banyak percobaan login. Coba lagi sebentar.',
            ], 429, $headers);

            return [
                Limit::perMinute(5)->by('login:identity:' . $identity . ':' . $ip)->response($response),
                Limit::perMinute(30)->by('login:ip:' . $ip)->response($response),
            ];
        });
    }
}
