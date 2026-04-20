<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS: Izinkan request dari frontend (pentadosen.site & localhost dev)
        $middleware->append(function (Request $request, \Closure $next) {
            $allowedOrigins = [
                'https://pentadosen.site',
                'https://www.pentadosen.site',
                'http://localhost:5173',
                'http://localhost:3000',
            ];

            $origin = $request->headers->get('Origin');
            $response = $next($request);

            if (in_array($origin, $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }

            // Handle preflight OPTIONS request
            if ($request->getMethod() === 'OPTIONS') {
                $response->setStatusCode(200);
            }

            return $response;
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
