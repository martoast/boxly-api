<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Track a package - simple flow:
     * 1. Try to get existing tracking
     * 2. If carrier mismatch, delete and recreate
     * 3. If not found, create it
     */
    public function trackPackage(string $trackingNumber, ?string $slug = null): array
    {
        $slug = $slug ?? 'estafeta'; // Default to estafeta

        // 1. Try to get existing tracking
        $existing = $this->getTracking($trackingNumber);

        if ($existing['success']) {
            $existingSlug = $existing['data']['slug'] ?? null;

            // If carrier matches, return existing
            if ($existingSlug === $slug) {
                return $existing;
            }

            // Carrier mismatch - delete and recreate
            $this->deleteTracking($existing['data']['id']);
        }

        // 2. Create new tracking with correct carrier
        return $this->createTracking($trackingNumber, $slug);
    }

    /**
     * Delete a tracking by ID
     */
    public function deleteTracking(string $trackingId): bool
    {
        try {
            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->delete("{$this->baseUrl}/trackings/{$trackingId}");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('AfterShip delete exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create a tracking - POST /trackings
     * Returns the tracking data from AfterShip's response
     */
    public function createTracking(string $trackingNumber, string $slug = 'estafeta'): array
    {
        try {
            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/trackings", [
                'tracking_number' => $trackingNumber,
                'slug' => $slug,
            ]);

            $data = $response->json();

            // Success - return tracking data from response
            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $data['data']['tracking'] ?? $data['data'] ?? [],
                ];
            }

            // Already exists - that's fine, just get it
            $metaCode = $data['meta']['code'] ?? 0;
            if ($metaCode === 4003) {
                return $this->getTracking($trackingNumber);
            }

            Log::error('AfterShip create tracking failed', [
                'tracking_number' => $trackingNumber,
                'slug' => $slug,
                'response' => $data,
            ]);

            return [
                'success' => false,
                'error' => $data['meta']['message'] ?? 'Failed to create tracking',
            ];

        } catch (\Exception $e) {
            Log::error('AfterShip exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get tracking - GET /trackings?tracking_numbers=xxx
     */
    public function getTracking(string $trackingNumber): array
    {
        try {
            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/trackings", [
                'tracking_numbers' => $trackingNumber,
            ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Failed to get tracking',
                ];
            }

            $data = $response->json();

            if (empty($data['data']['trackings'])) {
                return [
                    'success' => false,
                    'error' => 'Tracking not found',
                ];
            }

            return [
                'success' => true,
                'data' => $data['data']['trackings'][0],
            ];

        } catch (\Exception $e) {
            Log::error('AfterShip exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get list of supported carriers
     */
    public function getCouriers(): array
    {
        try {
            $response = Http::withHeaders([
                'as-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/couriers");

            if ($response->failed()) {
                return ['success' => false, 'error' => 'Failed to get couriers'];
            }

            return [
                'success' => true,
                'data' => $response->json()['data'] ?? [],
            ];

        } catch (\Exception $e) {
            Log::error('AfterShip exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Search for a carrier by name
     */
    public function searchCourier(string $query): array
    {
        $couriers = $this->getCouriers();

        if (!$couriers['success']) {
            return $couriers;
        }

        $query = strtolower($query);
        $matching = array_filter($couriers['data']['couriers'] ?? [], function ($courier) use ($query) {
            return str_contains(strtolower($courier['slug'] ?? ''), $query) ||
                   str_contains(strtolower($courier['name'] ?? ''), $query);
        });

        return [
            'success' => true,
            'data' => array_values($matching),
        ];
    }

    /**
     * Format tracking data for API response
     */
    public function formatTrackingData(array $result): array
    {
        if (!$result['success'] || empty($result['data'])) {
            return [];
        }

        $data = $result['data'];

        return [
            'tracking_number' => $data['tracking_number'] ?? null,
            'carrier' => [
                'slug' => $data['slug'] ?? null,
                'name' => isset($data['slug']) ? strtoupper($data['slug']) : null,
            ],
            'status' => [
                'tag' => $data['tag'] ?? 'Pending',
                'message' => $data['subtag_message'] ?? 'Awaiting carrier update',
            ],
            'origin' => [
                'country' => $data['origin_country_region'] ?? null,
            ],
            'destination' => [
                'country' => $data['destination_country_region'] ?? null,
            ],
            'estimated_delivery' => $data['expected_delivery'] ?? null,
            'checkpoints' => array_map(fn($cp) => [
                'time' => $cp['checkpoint_time'] ?? null,
                'message' => $cp['message'] ?? null,
                'location' => $cp['location'] ?? null,
            ], $data['checkpoints'] ?? []),
            'last_update' => $data['updated_at'] ?? null,
        ];
    }
}
