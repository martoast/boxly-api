<?php

namespace App\Http\Controllers;

use App\Http\Requests\FunnelCaptureRequest;
use App\Jobs\SendFunnelCaptureWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FunnelCaptureController extends Controller
{
    /**
     * Handle the incoming funnel capture request.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,12}$/'
            ],
            'user_type' => 'nullable|string|in:expat,business,shopper',
            'registration_source' => 'nullable|string|max:255',
        ]);
        
        // Dispatch the job to the queue with user type and registration source
        SendFunnelCaptureWebhookJob::dispatch(
            $validatedData['name'],
            $validatedData['email'],
            $validatedData['phone'],
            $validatedData['user_type'] ?? null,
            $validatedData['registration_source'] ?? null
        );
        
        // Return a response indicating acceptance (job queued)
        return response()->json([
            'success' => true,
            'message' => 'Thank you! Your information has been received.'
        ], 202); // 202 Accepted
    }
}