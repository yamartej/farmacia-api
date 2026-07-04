<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    private const DEFAULT_ALLOWED_ORIGINS = 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:3001,http://127.0.0.1:3001';

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            $response = response()->noContent();
        } else {
            $response = $next($request);
        }

        $origin = $request->headers->get('Origin');
        $allowedOrigins = $this->allowedOrigins();

        if ($origin && in_array($origin, $allowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function allowedOrigins(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', self::DEFAULT_ALLOWED_ORIGINS))
        )));
    }
}
