<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrackPackageRequest;
use App\Services\AfterShipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShipmentTrackingController extends Controller
{
    protected AfterShipService $afterShip;

    public function __construct(AfterShipService $afterShip)
    {
        $this->afterShip = $afterShip;
    }

    /**
     * Track a package by tracking number
     */
    public function track(TrackPackageRequest $request): JsonResponse
    {
        $trackingNumber = $request->input('tracking_number');
        $carrier = $request->input('carrier');

        Log::info('Package tracking requested', [
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
            'ip' => $request->ip(),
        ]);

        // Track the package
        $result = $this->afterShip->trackPackage($trackingNumber, $carrier);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Unable to track package',
            ], 404);
        }

        // Format the tracking data
        $formattedData = $this->afterShip->formatTrackingData($result);

        return response()->json([
            'success' => true,
            'data' => $formattedData,
        ]);
    }

    /**
     * Get list of supported carriers
     */
    public function carriers(Request $request): JsonResponse
    {
        $activeOnly = $request->boolean('active_only', false);
        $country = $request->input('country'); // e.g., 'MEX', 'USA'

        $result = $this->afterShip->getCouriers($activeOnly);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch carriers',
            ], 500);
        }

        $couriers = $result['data']['couriers'];

        // Filter by country if provided
        if ($country) {
            $couriers = array_filter($couriers, function ($courier) use ($country) {
                return in_array($country, $courier['service_from_country_regions'] ?? []);
            });
        }

        // Format courier data
        $formattedCouriers = array_map(function ($courier) {
            return [
                'slug' => $courier['slug'],
                'name' => $courier['name'],
                'phone' => $courier['phone'] ?? null,
                'website' => $courier['web_url'] ?? null,
                'countries' => $courier['service_from_country_regions'] ?? [],
            ];
        }, $couriers);

        return response()->json([
            'success' => true,
            'data' => array_values($formattedCouriers),
            'total' => count($formattedCouriers),
        ]);
    }

    /**
     * Search for a carrier
     */
    public function searchCarrier(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = $request->input('query');

        $result = $this->afterShip->searchCourier($query);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to search carriers',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'total' => count($result['data']),
        ]);
    }
}