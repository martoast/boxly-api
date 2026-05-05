<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live USD → MXN exchange-rate lookup, used at quote-generation time.
 *
 * Source: api.frankfurter.dev (free, no API key, ECB-based daily rate).
 * Cached for 10 minutes server-side so a burst of quotes doesn't hammer
 * the upstream and so all items in the same PR see a consistent rate.
 *
 * Falls back to the configured default rate
 * (services.exchange_rate.usd_to_mxn) if the upstream is unreachable.
 */
class FxRateController extends Controller
{
    public function show(): JsonResponse
    {
        $rate = Cache::remember('fx:usd-mxn', now()->addMinutes(10), function () {
            try {
                $res = Http::timeout(5)->get('https://api.frankfurter.dev/v1/latest', [
                    'base'    => 'USD',
                    'symbols' => 'MXN',
                ]);
                $rate = (float) ($res->json('rates.MXN') ?? 0);
                if ($rate > 0) return $rate;
            } catch (\Throwable $e) {
                Log::warning('FX rate fetch failed', ['error' => $e->getMessage()]);
            }
            return (float) config('services.exchange_rate.usd_to_mxn', 17.5);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'base' => 'USD',
                'quote' => 'MXN',
                'rate' => round($rate, 4),
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
