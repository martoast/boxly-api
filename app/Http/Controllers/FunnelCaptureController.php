<?php

namespace App\Http\Controllers;

use App\Http\Requests\FunnelCaptureRequest;
use App\Jobs\SendFunnelCaptureWebhookJob;
use Illuminate\Http\JsonResponse;

class FunnelCaptureController extends Controller
{
    /**
     * Handle the incoming funnel capture request.
     *
     * @param FunnelCaptureRequest $request
     * @return JsonResponse
     */
    public function store(FunnelCaptureRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        
        // Dispatch the job to the queue
        SendFunnelCaptureWebhookJob::dispatch(
            $validatedData['name'],
            $validatedData['email'],
            $validatedData['phone']
        );
        
        // Return a response indicating acceptance (job queued)
        return response()->json([
            'success' => true,
            'message' => 'Thank you! Your information has been received.'
        ], 202); // 202 Accepted
    }
}