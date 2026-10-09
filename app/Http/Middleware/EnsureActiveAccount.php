<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
            $authenticatedUser?->currentAccessToken()?->delete();

            return response()->json([
                'status' => 'error',
                'message' => 'Akun dinonaktifkan.',
            ], 403);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
