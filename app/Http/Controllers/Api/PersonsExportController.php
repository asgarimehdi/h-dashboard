<?php

namespace App\Http\Controllers\Api;

use App\Exports\PersonsExport;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Person;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PersonsExportController extends Controller
{
    use PersianNormalizer;

    /**
     * @return BinaryFileResponse
     */
    public function export(UnitScopedRequest $request)
    {
        $accessibleIds = $request->accessibleIds();

        $query = Person::query()
            // Every lookup label in the sheet is a relation, so they are
            // eager-loaded here — otherwise the export issues one query per
            // person, exactly the N+1 the scope columns already avoid.
            ->with(['semat', 'tahsil', 'estekhdam', 'radif', 'unit'])
            ->when($accessibleIds === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->when($accessibleIds !== [], fn ($q) => $q->whereIn('u_id', $accessibleIds));

        $this->applySearch($query, $request);
        $this->applyFilters($query, $request);

        /** @var Collection<int, Person> $persons */
        $persons = $query->orderBy('id')->get();

        $filename = 'persons-'.now()->format('Ymd-His');

        return Excel::download(new PersonsExport($persons), "{$filename}.xlsx");
    }

    /**
     * The same multi-term search the personnel list runs, so the sheet and the
     * table on screen always agree about which rows match.
     *
     * Each whitespace-separated term must match (AND); within a term any of
     * n_code / "first last" / "last first" / unit name counts, so
     * "عسگری مهدی" finds «مهدی عسگری» too (#494).
     *
     * @param  Builder<Person>  $query
     */
    protected function applySearch(Builder $query, UnitScopedRequest $request): void
    {
        $search = trim((string) $request->input('search', ''));

        if ($search === '') {
            return;
        }

        // One list, one source string. normalizeForSearch() folds the Persian
        // char variants AND turns ZWNJ/ZWJ into a space, so a ZWNJ compound
        // yields MORE terms than the raw string has words (#940) — building a
        // second list from the raw string and indexing it by this one's
        // positions walked off the end and 500'd the export on «خانه‌بهداشت».
        // The list must be normalized FIRST and folded per term afterwards:
        // folding an already-escaped normalizeForQuery() term would escape LIKE
        // wildcards twice and match nothing. Same shape as the sibling list in
        // livewire/kargozini/person.blade.php.
        $terms = array_values(array_filter(
            explode(' ', self::normalizeForSearch($search)),
            fn (string $term) => $term !== ''
        ));

        if ($terms === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($terms): void {
            foreach ($terms as $term) {
                $foldedTerm = self::foldedTerm($term);
                $outer->where(function (Builder $termQuery) use ($foldedTerm): void {
                    // whereHas first: it is declared on the Eloquent builder and
                    // returns $this, so the OR group below keeps its type. The
                    // orWhereRaw() calls are forwarded to the query builder and
                    // would degrade the chain's type mid-expression.
                    $termQuery->whereHas('unit', function (Builder $unitQuery) use ($foldedTerm): void {
                        $unitQuery->whereRaw(self::foldSeparatorsSql('name').' LIKE ?', ["%{$foldedTerm}%"]);
                    });

                    // n_code is numeric only — a plain LIKE is enough. The name
                    // columns are folded the same way the unit column is, so a
                    // row written by any path that skipped the model's saving
                    // hook still matches (#815).
                    $termQuery->orWhere('n_code', 'LIKE', "%{$foldedTerm}%")
                        ->orWhereRaw(self::foldSeparatorsSql("CONCAT(f_name, ' ', l_name)").' LIKE ?', ["%{$foldedTerm}%"])
                        ->orWhereRaw(self::foldSeparatorsSql("CONCAT(l_name, ' ', f_name)").' LIKE ?', ["%{$foldedTerm}%"]);
                });
            }
        });
    }

    /**
     * The #494 list filters. They narrow the accessible scope, never widen it:
     * a unit id outside accessibleUnitIds() simply matches no row.
     *
     * @param  Builder<Person>  $query
     */
    protected function applyFilters(Builder $query, UnitScopedRequest $request): void
    {
        $filters = [
            'filter_u_id' => 'u_id',
            'filter_s_id' => 's_id',
            'filter_t_id' => 't_id',
            'filter_e_id' => 'e_id',
            'filter_r_id' => 'r_id',
        ];

        foreach ($filters as $parameter => $column) {
            $value = $request->input($parameter);

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }
    }
}
