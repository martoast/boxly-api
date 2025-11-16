<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
     * Create a new tracking
     */
    public function createTracking(string $trackingNumber, ?string $slug = null): array
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

            if ($response->failed()) {
                Log::error('AfterShip create tracking failed', [
                    'tracking_number' => $trackingNumber,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Failed to create tracking',
                    'details' => $response->json(),
                ];
            }

            $data = $response->json();

            Log::info('AfterShip tracking created', [
                'tracking_number' => $trackingNumber,
                'slug' => $data['data']['slug'] ?? null,
                'status_code' => $data['meta']['code'],
            ]);

            return [
                'success' => true,
                'data' => $data['data'],
                'meta' => $data['meta'],
            ];

        } catch (\Exception $e) {
            Log::error('AfterShip create tracking exception', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get tracking information
     */
    public function getTracking(string $trackingNumber): array
    {
        try {
            // Try cache first (cache for 5 minutes)
            $cacheKey = "aftership_tracking_{$trackingNumber}";
            
            if (Cache::has($cacheKey)) {
                Log::info('AfterShip tracking retrieved from cache', [
                    'tracking_number' => $trackingNumber,
                ]);
                return Cache::get($cacheKey);
            }

            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/trackings", [
                'tracking_numbers' => $trackingNumber,
            ]);

            if ($response->failed()) {
                Log::error('AfterShip get tracking failed', [
                    'tracking_number' => $trackingNumber,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

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
                'meta' => $data['meta'],
            ];

            // Cache the result
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            Log::info('AfterShip tracking retrieved', [
                'tracking_number' => $trackingNumber,
                'status' => $result['data']['tag'] ?? null,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('AfterShip get tracking exception', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Track a package (create if needed, then get info)
     */
    public function trackPackage(string $trackingNumber, ?string $slug = null): array
    {
        // First, try to create the tracking (will return existing if already tracked)
        $createResult = $this->createTracking($trackingNumber, $slug);

        // Wait a moment for AfterShip to fetch data
        sleep(2);

        // Get the tracking information
        return $this->getTracking($trackingNumber);
    }

    /**
     * Get list of available couriers
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
                'meta' => $data['meta'],
            ];

            // Cache for 24 hours
            Cache::put($cacheKey, $result, now()->addHours(24));

            return $result;

        } catch (\Exception $e) {
            Log::error('AfterShip get couriers exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search for courier by name
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
     * Format tracking data for response
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
                'slug' => $data['slug'],
                'name' => strtoupper($data['slug']),
            ],
            'status' => [
                'tag' => $data['tag'],
                'message' => $data['subtag_message'] ?? 'N/A',
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
            'estimated_delivery' => $data['courier_estimated_delivery_date']['estimated_delivery_date'] ?? null,
            'tracking_url' => $data['courier_tracking_link'] ?? null,
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