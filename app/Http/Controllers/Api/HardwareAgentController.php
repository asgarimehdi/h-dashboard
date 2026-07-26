<?php

namespace App\Http\Controllers\Api;

use App\Ai\Agents\HardwareAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareAgentController extends Controller
{
    /**
     * Prompt the hardware AI agent.
     *
     * The agent can search hardware, get stats, look up person devices,
     * and update records (with human approval).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        try {
            $response = HardwareAgent::make()
                ->prompt($validated['message']);

            return response()->json([
                'status' => 'ok',
                'response' => (string) $response,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
