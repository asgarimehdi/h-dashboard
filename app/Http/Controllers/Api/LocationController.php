<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationLog;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LocationLog::with('user.person');

        if ($request->filled('unit_id')) {
            $unitIds = Unit::descendantIds($request->unit_id);
            $userIds = User::join('user_units', 'users.id', '=', 'user_units.user_id')
                ->whereIn('user_units.unit_id', $unitIds)
                ->pluck('users.id');
            $query->whereIn('user_id', $userIds);
        }

        if ($request->filled('live')) {
            $query->where('created_at', '>=', now()->subMinutes((int) $request->get('live', 30)));
        } else {
            if ($request->filled('from')) {
                $query->where('created_at', '>=', $request->from);
            }

            if ($request->filled('to')) {
                $query->where('created_at', '<=', $request->to);
            }
        }

        $logs = $query->latest()->limit(5000)->get(['user_id', 'latitude', 'longitude', 'created_at']);

        return response()->json($logs->map(fn($l) => [
            'lat' => $l->latitude,
            'lng' => $l->longitude,
            'userId' => $l->user_id,
            'userName' => $l->user?->person?->f_name ?? 'نامشخص',
            'time' => $l->created_at->toISOString(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $unitId = session('current_unit_id', $request->user()->person?->u_id);
        if (! $unitId) {
            return response()->json(['message' => 'Unit context required.'], 422);
        }

        LocationLog::create([
            'user_id' => $request->user()->id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json(['message' => 'Location saved successfully.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $log = LocationLog::where('user_id', $id)->latest()->first();

        if (! $log) {
            return response()->json(['message' => 'No location found'], 404);
        }

        return response()->json([
            'latitude' => $log->latitude,
            'longitude' => $log->longitude,
            'logged_at' => $log->created_at->toISOString(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
