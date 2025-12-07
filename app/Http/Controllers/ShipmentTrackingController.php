<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrackPackageRequest;
use App\Services\AfterShipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $carrier = $request->input('carrier'); // Optional, defaults to estafeta

        $result = $this->afterShip->trackPackage($trackingNumber, $carrier);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Unable to track package',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->afterShip->formatTrackingData($result),
        ]);
    }

    /**
     * Get list of supported carriers
     */
    public function carriers(): JsonResponse
    {
        $result = $this->afterShip->getCouriers();

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch carriers',
            ], 500);
        }

        $couriers = array_map(fn($c) => [
            'slug' => $c['slug'],
            'name' => $c['name'],
        ], $result['data']['couriers'] ?? []);

        return response()->json([
            'success' => true,
            'data' => $couriers,
            'total' => count($couriers),
        ]);
    }

    /**
     * Search carriers by name
     */
    public function searchCarrier(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|min:2']);

        $result = $this->afterShip->searchCourier($request->input('query'));

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to search carriers',
            ], 500);
        }

        $couriers = array_map(fn($c) => [
            'slug' => $c['slug'],
            'name' => $c['name'],
        ], $result['data']);

        return response()->json([
            'success' => true,
            'data' => $couriers,
            'total' => count($couriers),
        ]);
    }
}
