<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Person;
use App\Rules\PerPage;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;

class PersonController extends Controller
{
    use PersianNormalizer;

    public function index(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        $query = Person::whereIn('u_id', $accessibleIds)
            ->with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name']);

        if ($request->filled('search')) {
            $s = self::normalizeForQuery($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('n_code', 'LIKE', "%{$s}%")
                    ->orWhere('f_name', 'LIKE', "%{$s}%")
                    ->orWhere('l_name', 'LIKE', "%{$s}%")
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$s}%"]);
            });
        }

        if ($request->filled('unit_id')) {
            $query->where('u_id', $request->unit_id);
        }

        if ($request->filled('semat_id')) {
            $query->where('s_id', $request->semat_id);
        }

        $allowedSortColumns = ['n_code', 'f_name', 'l_name', 'created_at', 'u_id', 's_id', 't_id', 'e_id', 'r_id'];
        $sortBy = $request->get('sort_by', 'n_code');
        if (! in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'n_code';
        }

        $sortDir = strtolower($request->get('sort_dir', 'asc'));
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        $query->orderBy($sortBy, $sortDir);

        // Issue #894: see UnitController::index(). 422 instead of a silent one-sided
        // clamp; `min()` kept as defence in depth.
        $request->validate(['per_page' => ['sometimes', new PerPage(100)]]);
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

    public function show(UnitScopedRequest $request, Person $person): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        return response()->json([
            'data' => $person->load(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name']),
        ]);
    }

    public function store(UnitScopedRequest $request): JsonResponse
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

        $accessibleIds = $request->accessibleIds();

        if (! in_array($validated['u_id'], $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $person = Person::create($validated);

        return response()->json([
            'success' => true,
            'data' => $person->load(['unit:id,name', 'semat:id,name']),
        ], 201);
    }

    public function update(UnitScopedRequest $request, Person $person): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        // Issue #959: n_code is immutable on the update path. hardwares.n_code
        // has no FK, so a rename would silently orphan every hardware row of
        // this person. Accept the field only when it is unchanged (a
        // full-payload PUT echoes it); any real change is a 422.
        if ($request->filled('n_code') && (string) $request->input('n_code') !== (string) $person->n_code) {
            return response()->json([
                'message' => 'n_code cannot be changed.',
                'errors' => ['n_code' => ['n_code cannot be changed.']],
            ], 422);
        }

        // Issue #532: validate FIRST, then check scope — prevents information
        // disclosure through differential error responses (403 vs 422).
        $validated = $request->validate([
            'f_name' => 'sometimes|required|string|max:255',
            'l_name' => 'sometimes|required|string|max:255',
            't_id' => 'sometimes|required|exists:tahsils,id',
            'e_id' => 'sometimes|required|exists:estekhdams,id',
            's_id' => 'sometimes|required|exists:semats,id',
            'r_id' => 'sometimes|required|exists:radifs,id',
            'u_id' => 'sometimes|required|exists:units,id',
        ]);

        if (isset($validated['u_id']) && ! in_array($validated['u_id'], $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $person->update($validated);

        return response()->json([
            'success' => true,
            'data' => $person->fresh()->load(['unit:id,name', 'semat:id,name']),
        ]);
    }

    public function destroy(UnitScopedRequest $request, Person $person): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        $person->delete();

        return response()->json(['success' => true]);
    }
}
