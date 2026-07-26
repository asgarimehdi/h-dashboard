<?php

namespace App\Http\Controllers\Api;

use App\Ai\Agents\HardwareAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareAgentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        try {
            $agent = new HardwareAgent();
            $response = $agent->prompt($validated['message']);

            return response()->json([
                'status' => 'ok',
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
