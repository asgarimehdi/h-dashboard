<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    use PersianNormalizer;

    public function index(Request $request): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

        $query = Person::whereIn('u_id', $ids)
            ->with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name']);

        if ($request->filled('search')) {
            $s = self::normalizeForSearch($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('n_code', 'LIKE', "%{$s}%")
                  ->orWhere('f_name', 'LIKE', "%{$s}%")
                  ->orWhere('l_name', 'LIKE', "%{$s}%");
            });
        }

        if ($request->filled('unit_id')) {
            $query->where('u_id', $request->unit_id);
        }

        if ($request->filled('semat_id')) {
            $query->where('s_id', $request->semat_id);
        }

        $sortBy = $request->get('sort_by', 'n_code');
        $sortDir = $request->get('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) $request->get('per_page', 20), 100);
        $persons = $query->paginate($perPage);

        return response()->json([
            'data' => $persons->items(),
            'meta' => [
                'current_page' => $persons->currentPage(),
                'last_page' => $persons->lastPage(),
                'per_page' => $persons->perPage(),
                'total' => $persons->total(),
            ],
        ]);
    }

    public function show(Person $person): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($person->u_id, $ids)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        return response()->json([
            'data' => $person->load(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'n_code' => 'required|string|size:10|unique:persons,n_code',
            'f_name' => 'required|string|max:255',
            'l_name' => 'required|string|max:255',
            't_id' => 'required|exists:tahsils,id',
            'e_id' => 'required|exists:estekhdams,id',
            's_id' => 'required|exists:semats,id',
            'r_id' => 'required|exists:radifs,id',
            'u_id' => 'required|exists:units,id',
        ]);

        $ids = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($validated['u_id'], $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $person = Person::create($validated);

        return response()->json([
            'success' => true,
            'data' => $person->load(['unit:id,name', 'semat:id,name']),
        ], 201);
    }

    public function update(Request $request, Person $person): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($person->u_id, $ids)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        $validated = $request->validate([
            'n_code' => 'sometimes|required|string|size:10|unique:persons,n_code,' . $person->n_code . ',n_code',
            'f_name' => 'sometimes|required|string|max:255',
            'l_name' => 'sometimes|required|string|max:255',
            't_id' => 'sometimes|required|exists:tahsils,id',
            'e_id' => 'sometimes|required|exists:estekhdams,id',
            's_id' => 'sometimes|required|exists:semats,id',
            'r_id' => 'sometimes|required|exists:radifs,id',
            'u_id' => 'sometimes|required|exists:units,id',
        ]);

        $person->update($validated);

        return response()->json([
            'success' => true,
            'data' => $person->fresh()->load(['unit:id,name', 'semat:id,name']),
        ]);
    }

    public function destroy(Person $person): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($person->u_id, $ids)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        $person->delete();

        return response()->json(['success' => true]);
    }
}