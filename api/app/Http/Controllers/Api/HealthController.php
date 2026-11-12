<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $db = 'ok';
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $db = 'error';
        }

        return response()->json([
            'app' => config('app.name'),
            'version' => config('app.version', '1.0.0'),
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'database' => $db,
            'google_configured' => (bool) config('sovera.google.client_id') && (bool) config('sovera.google.client_secret'),
            'api_key_required' => (string) config('sovera.api_key') !== '',
            'time' => now()->toIso8601String(),
        ], $db === 'ok' ? 200 : 503);
    }
}
