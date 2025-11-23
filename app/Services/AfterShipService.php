<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\Pool;

class AfterShipService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.aftership.api_key');
        $this->baseUrl = config('services.aftership.base_url', 'https://api.aftership.com/tracking/2025-07');
    }

    /**
     * Helper to guess carrier based on format patterns
     * Solves collision issues between Estafeta (MX) and DHL (Global)
     */
    private function predictSlug(string $trackingNumber): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $trackingNumber);

        // 1. UPS (Global) - Starts with 1Z
        if (preg_match('/^1Z[A-Z0-9]{16}$/i', $clean)) {
            return 'ups';
        }
        // 2. USPS (USA) - 22 digits, starts with 9
        elseif (preg_match('/^9\d{21}$/', $clean)) {
            return 'usps';
        }
        // 3. ESTAFETA (Mexico) - 10 digits (Collision with DHL)
        elseif (preg_match('/^\d{10}$/', $clean)) {
            return 'estafeta';
        }
        // 4. Estafeta Long Format - 22 digits, not starting with 9
        elseif (preg_match('/^\d{22}$/', $clean)) {
            return 'estafeta';
        }

        return null;
    }

    /**
     * Create/Register a tracking number
     * Returns true if successful or already exists. False if invalid for the carrier.
     */
    public function createTracking(string $trackingNumber, ?string $slug = null): bool
    {
        try {
            $payload = ['tracking_number' => $trackingNumber];
            
            if ($slug) {
                $payload['slug'] = $slug;
            }

            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/trackings", $payload);

            if ($response->successful()) {
                return true;
            }

            $data = $response->json();
            $metaCode = $data['meta']['code'] ?? 0;
            $errorType = $data['meta']['type'] ?? '';

            // Success if it already exists
            if ($metaCode === 4003 || $errorType === 'TrackingAlreadyExist') {
                return true;
            }

            // If we forced a slug but it was invalid, return false to trigger fallback
            if ($slug && ($metaCode === 4005 || str_contains(strtolower($data['meta']['message'] ?? ''), 'invalid'))) {
                 return false;
            }

            Log::error('AfterShip create tracking failed', [
                'tracking_number' => $trackingNumber,
                'response' => $data,
            ]);
            
            return false;

        } catch (\Exception $e) {
            Log::error('AfterShip create tracking exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get tracking information for a single tracking number
     */
    public function getTracking(string $trackingNumber): array
    {
        try {
            $cacheKey = "aftership_tracking_{$trackingNumber}";
            
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/trackings", [
                'tracking_numbers' => $trackingNumber,
            ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Failed to get tracking information',
                    'details' => $response->json(),
                ];
            }

            $data = $response->json();

            if (empty($data['data']['trackings'])) {
                return [
                    'success' => false,
                    'error' => 'No tracking information found',
                ];
            }

            $result = [
                'success' => true,
                'data' => $data['data']['trackings'][0],
                'meta' => $data['meta'] ?? [],
            ];

            // Cache for 15 minutes
            Cache::put($cacheKey, $result, now()->addMinutes(15));

            return $result;

        } catch (\Exception $e) {
            Log::error('AfterShip get tracking exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * MAIN TRACK METHOD
     * Uses prediction with auto-detect fallback
     */
    public function trackPackage(string $trackingNumber, ?string $inputSlug = null): array
    {
        // 1. Predict slug
        $predictedSlug = $inputSlug ?? $this->predictSlug($trackingNumber);

        // 2. Try to create with prediction
        $creationSuccess = false;
        if ($predictedSlug) {
            $creationSuccess = $this->createTracking($trackingNumber, $predictedSlug);
        }

        // 3. Fallback: If prediction failed (or was null), try Auto-Detect
        if (!$creationSuccess) {
            $this->createTracking($trackingNumber, null);
        }

        // 4. Retrieve Data
        return $this->getTracking($trackingNumber);
    }

    /**
     * Batch track multiple packages concurrently
     */
    public function getTrackingBatch(array $packages): array
    {
        $results = [];
        $toFetch = [];

        // Check Cache
        foreach ($packages as $pkg) {
            $num = $pkg['tracking_number'];
            $cacheKey = "aftership_tracking_{$num}";
            
            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                if ($cached['success'] ?? false) {
                    $results[$num] = $this->formatTrackingData($cached);
                }
            } else {
                $toFetch[] = $pkg;
            }
        }

        if (empty($toFetch)) {
            return $results;
        }

        // Fetch Missing
        try {
            $responses = Http::pool(function (Pool $pool) use ($toFetch) {
                foreach ($toFetch as $pkg) {
                    $pool->as($pkg['tracking_number'])->withHeaders([
                        'as-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])->get("{$this->baseUrl}/trackings", [
                        'tracking_numbers' => $pkg['tracking_number'],
                    ]);
                }
            });

            foreach ($responses as $trackingNumber => $response) {
                if ($response->ok()) {
                    $json = $response->json();
                    
                    if (!empty($json['data']['trackings'])) {
                        $rawData = $json['data']['trackings'][0];
                        $resultWrapper = ['success' => true, 'data' => $rawData];
                        
                        Cache::put("aftership_tracking_{$trackingNumber}", $resultWrapper, now()->addMinutes(15));
                        
                        $results[$trackingNumber] = $this->formatTrackingData($resultWrapper);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Batch tracking error', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Get list of supported carriers
     */
    public function getCouriers(bool $activeOnly = false): array
    {
        try {
            $cacheKey = 'aftership_couriers_' . ($activeOnly ? 'active' : 'all');
            
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $params = [];
            if ($activeOnly) {
                $params['active'] = 'true';
            }

            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/couriers", $params);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Failed to get couriers',
                ];
            }

            $data = $response->json();
            
            $result = [
                'success' => true,
                'data' => $data['data'],
                'meta' => $data['meta'] ?? [],
            ];

            Cache::put($cacheKey, $result, now()->addHours(24));

            return $result;

        } catch (\Exception $e) {
            Log::error('AfterShip get couriers exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search for a carrier
     */
    public function searchCourier(string $name): array
    {
        $couriers = $this->getCouriers();
        
        if (!$couriers['success']) {
            return $couriers;
        }

        $matching = array_filter($couriers['data']['couriers'], function ($courier) use ($name) {
            $searchName = strtolower($name);
            return str_contains(strtolower($courier['slug']), $searchName) ||
                   str_contains(strtolower($courier['name']), $searchName) ||
                   str_contains(strtolower($courier['other_name'] ?? ''), $searchName);
        });

        return [
            'success' => true,
            'data' => array_values($matching),
        ];
    }

    /**
     * Format tracking data for consistent API response
     */
    public function formatTrackingData(array $trackingData): array
    {
        if (!isset($trackingData['data'])) {
            return [];
        }

        $data = $trackingData['data'];

        return [
            'tracking_number' => $data['tracking_number'],
            'carrier' => [
                'slug' => $data['slug'] ?? null,
                'name' => isset($data['slug']) ? strtoupper($data['slug']) : null,
            ],
            'status' => [
                'tag' => $data['tag'] ?? 'Pending',
                'message' => $data['subtag_message'] ?? 'Status unavailable',
            ],
            'service_type' => $data['shipment_type'] ?? null,
            'origin' => [
                'country' => $data['origin_country_region'] ?? null,
                'location' => $data['origin_raw_location'] ?? null,
            ],
            'destination' => [
                'country' => $data['destination_country_region'] ?? null,
                'location' => $data['destination_raw_location'] ?? null,
            ],
            'estimated_delivery' => $data['expected_delivery'] ?? null, 
            'checkpoints' => array_map(function ($checkpoint) {
                return [
                    'time' => $checkpoint['checkpoint_time'],
                    'message' => $checkpoint['message'],
                    'location' => $checkpoint['location'] ?? null,
                    'city' => $checkpoint['city'] ?? null,
                    'state' => $checkpoint['state'] ?? null,
                    'country' => $checkpoint['country_region'] ?? null,
                ];
            }, $data['checkpoints'] ?? []),
            'last_update' => $data['updated_at'] ?? null,
        ];
    }
}