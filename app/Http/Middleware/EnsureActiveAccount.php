<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureActiveAccount
{
    /** Rejects revoked sessions immediately after an account is deactivated. */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();
        // Resolve the current database value. This makes a role/status change
        // effective even when the request started from an older token model.
        $user = $authenticatedUser?->fresh();

        if (!$user || !$user->is_active) {
            $token = $authenticatedUser?->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'status' => 'error',
                'code' => 'account_inactive',
                'message' => 'Akun dinonaktifkan.',
            ], 403);
        }

        // fresh() refreshes account policy/role, but drops Sanctum's token.
        // Preserve the validated token so logout and session revocation work.
        $user->withAccessToken($authenticatedUser->currentAccessToken());
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
