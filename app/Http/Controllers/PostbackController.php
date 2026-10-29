<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PostbackRequest;
use App\Models\Postback;
use App\Services\AcceptPostback;
use App\Services\DeliverPostback;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PostbackController extends Controller
{
    public function store(PostbackRequest $request, AcceptPostback $accept, DeliverPostback $deliver): JsonResponse
    {
        try {
            $postback = $accept->accept($request->validated());
        } catch (Throwable) {
            Log::error('postback_persistence_failed');

            return response()->json(['status' => 'error', 'message' => 'Service unavailable'], 503);
        }

        if ($postback === null) {
            return response()->json(['status' => 'duplicate', 'message' => 'Event already received']);
        }

        $this->deliverSafely($postback, $deliver);

        return response()->json(['status' => 'accepted', 'message' => 'Event received']);
    }

    public function index(): JsonResponse
    {
        return response()->json(Postback::query()->latest('id')->paginate(50));
    }

    private function deliverSafely(Postback $postback, DeliverPostback $deliver): void
    {
        try {
            $deliver->deliver($postback);
        } catch (Throwable) {
            // Durable acceptance still succeeds if delivery/persistence is interrupted.
            Log::error('postback_delivery_interrupted', ['postback_id' => $postback->id]);
        }
    }
}
