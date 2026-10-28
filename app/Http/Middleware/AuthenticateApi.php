<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    /**
     * Handle an incoming request.
     *
     * Authenticates via Sanctum bearer token or Web session (cookie).
     * If a Bearer token is sent, it is strictly validated against the database.
     * If session-based, the web session guard is used.
     * If neither is valid, returns 401 JSON.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = null;

        // 1. If Bearer token is present, strictly validate the token
        if ($bearerToken = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($bearerToken);
            if ($accessToken && $accessToken->tokenable) {
                $expiration = config('sanctum.expiration');
                $isExpired = $expiration && $accessToken->created_at->lte(now()->subMinutes($expiration));
                
                if (!$isExpired) {
                    $user = $accessToken->tokenable->withAccessToken($accessToken);
                    $accessToken->forceFill(['last_used_at' => now()])->save();
                }
            }

            // If a token was provided but is invalid/revoked/expired, reject immediately (no session fallback)
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Token tidak valid atau sudah dicabut.'
                ], 401);
            }
        } else {
            // 2. Fallback to Web session cookie (SPA)
            $user = Auth::guard('web')->user();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
            ], 401);
        }

        // Set the authenticated user for the current request
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        Auth::setUser($user);

        return $next($request);
    }
}
