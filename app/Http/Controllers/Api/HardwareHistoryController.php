<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\HardwareHistory;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareHistoryController extends Controller
{
    /**
     * Display a listing of the hardware's change history.
     */
    public function index(Request $request, Hardware $hardware): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        
        // Get the hardware's unit through the person
        $hardware->loadMissing('person.unit');
        
        if (! $hardware->person || ! in_array($hardware->person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Hardware not accessible.'], 403);
        }

        $query = HardwareHistory::where('hardware_id', $hardware->id)
            ->with('user:id,n_code,name')
            ->orderBy('created_at', 'desc');

        // Filter by action if provided
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $perPage = min((int) $request->get('per_page', 20), 50);
        $histories = $query->paginate($perPage);

        return response()->json([
            'data' => $histories->items(),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'last_page' => $histories->lastPage(),
                'per_page' => $histories->perPage(),
                'total' => $histories->total(),
            ],
        ]);
    }
}
