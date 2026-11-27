<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\User;
use App\Rules\PerPage;
use App\Support\PersonRules;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;

class PersonController extends Controller
{
    use PersianNormalizer;

    /**
     * Issue #1880: explicit allowlist for every Person payload this controller
     * returns (index/show/update). The four `*_folded` search columns are
     * generated (`2026_11_14_000001_...`) and internal to query-time folding —
     * never part of the API contract. Same convention as
     * HrStatsController::personColumns() (#1802) and
     * TicketCommentController::REPLY_AUTHOR_PERSON_COLUMNS (#1689), which chose
     * a select allowlist over a model `$hidden` for exactly these columns.
     *
     * FK columns are MANDATORY here: a select without them silently nulls the
     * eager-loaded relations (#1689). A new legitimate persons column must be
     * added here — `PersonApiTest::test_person_column_allowlist_matches_...`
     * fails until it is.
     *
     * @var list<string>
     */
    public const PERSON_COLUMNS = [
        'id', 'n_code', 'f_name', 'l_name',
        't_id', 'e_id', 's_id', 'r_id', 'u_id',
        'phone', 'birth_date', 'hire_date', 'status',
        'created_at', 'updated_at',
    ];

    public function index(UnitScopedRequest $request): JsonResponse
    {
        // Issue #1777: `search[]=x` used to blow up inside normalizeForQuery()
        // and `sort_dir[]=x` inside strtolower() (TypeError), while `unit_id=abc`
        // and `semat_id=abc` raised 22P02 — all 500s. `sort_by` stays unvalidated
        // on purpose: an unknown value already falls back to `n_code` and never
        // reaches orderBy(), so rejecting it would be a contract change.
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string'],
            'unit_id' => ['sometimes', 'nullable', 'integer'],
            'semat_id' => ['sometimes', 'nullable', 'integer'],
            'sort_dir' => ['sometimes', 'nullable', 'string'],
        ]);

        $accessibleIds = $request->accessibleIds();

        $query = Person::query()
            ->select(self::PERSON_COLUMNS)
            ->whereIn('u_id', $accessibleIds)
            ->with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name']);

        if ($request->filled('search')) {
            $s = self::normalizeForQuery((string) $validated['search']);
            $query->where(function ($q) use ($s) {
                $q->where('n_code', 'LIKE', "%{$s}%")
                    ->orWhere('f_name', 'LIKE', "%{$s}%")
                    ->orWhere('l_name', 'LIKE', "%{$s}%")
                    // Issue #1009: the `||` operator (NOT `concat()`) so the
                    // `persons_fullname_trgm_idx` expression index applies.
                    ->orWhereRaw("(f_name || ' ' || l_name) LIKE ?", ["%{$s}%"]);
            });
        }

        if ($request->filled('unit_id')) {
            $query->where('u_id', $validated['unit_id']);
        }

        if ($request->filled('semat_id')) {
            $query->where('s_id', $validated['semat_id']);
        }

        $allowedSortColumns = ['n_code', 'f_name', 'l_name', 'created_at', 'u_id', 's_id', 't_id', 'e_id', 'r_id'];
        $sortBy = $request->get('sort_by', 'n_code');
        if (! in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'n_code';
        }

        $sortDir = strtolower((string) ($validated['sort_dir'] ?? 'asc'));
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

        $data = Person::query()
            ->select(self::PERSON_COLUMNS)
            ->with(['unit:id,name', 'semat:id,name', 'tahsil:id,name', 'estekhdam:id,name', 'radif:id,name'])
            ->whereKey($person->getKey())
            ->firstOrFail();

        return response()->json([
            'data' => $data,
        ]);
    }

    public function store(UnitScopedRequest $request): JsonResponse
    {
        $validated = $request->validate(PersonRules::apiRules());

        $accessibleIds = $request->accessibleIds();

        if (! in_array($validated['u_id'], $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        // Issue #1880: deliberately NOT projected. `create()` only carries the
        // submitted attributes, so DB-generated columns (`*_folded`) cannot
        // appear here — verified against the live model. The regression test
        // pins this so a future `->refresh()`/`fresh()` on this path is caught.
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
        $validated = $request->validate(PersonRules::apiRules(required: false));

        if (isset($validated['u_id']) && ! in_array($validated['u_id'], $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $person->update($validated);

        $data = Person::query()
            ->select(self::PERSON_COLUMNS)
            ->with(['unit:id,name', 'semat:id,name'])
            ->whereKey($person->getKey())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function destroy(UnitScopedRequest $request, Person $person): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        // Issue #959: hardwares.n_code has no FK, so deleting the person
        // would silently orphan every hardware row it owns. Refuse while any
        // hardware exists — this is the only app-level guard for that hole.
        if (Hardware::where('n_code', $person->n_code)->exists()) {
            return response()->json([
                'message' => 'Cannot delete person: hardware records exist for this n_code.',
            ], 422);
        }

        // Issue #1720: users.n_code is a RESTRICT FK. Deactivated accounts keep
        // their row (User uses SoftDeletes), so they still block the delete —
        // hence withTrashed.
        if (User::withTrashed()->where('n_code', $person->n_code)->exists()) {
            return response()->json([
                'message' => 'Cannot delete person: a user account is linked to this n_code (deactivated accounts included).',
            ], 422);
        }

        try {
            $person->delete();
        } catch (\Throwable $e) {
            // Issue #1720: an account can be created between the check above
            // and this DELETE. Mirror UnitController: 422 with the same reason,
            // never a 500.
            report($e);

            return response()->json([
                'message' => 'Cannot delete person: a user account is linked to this n_code (deactivated accounts included).',
            ], 422);
        }

        return response()->json(['success' => true]);
    }
}
