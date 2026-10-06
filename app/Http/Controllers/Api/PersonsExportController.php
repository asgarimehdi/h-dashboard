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

        // normalizeForQuery normalizes Persian/Arabic chars + escapes LIKE wildcards.
        $normalized = self::normalizeForQuery($search);

        $terms = array_values(array_filter(explode(' ', $normalized), fn (string $term) => $term !== ''));
        // Persons/n_code columns are PersianNormalizer-hooked on save; the unit
        // name column is not, so its filter must fold the column (#815). Build
        // the unit term from the RAW term — re-escaping an already-escaped term
        // would double-escape LIKE wildcards.
        $rawTerms = array_values(array_filter(explode(' ', trim($search)), fn (string $t) => $t !== ''));
        $foldedTerms = array_map(static fn (string $term): string => self::foldedTerm($term), $rawTerms);

        if ($terms === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($terms, $foldedTerms): void {
            foreach ($terms as $i => $term) {
                $foldedTerm = $foldedTerms[$i];
                $outer->where(function (Builder $termQuery) use ($term, $foldedTerm): void {
                    // whereHas first: it is declared on the Eloquent builder and
                    // returns $this, so the OR group below keeps its type. The
                    // orWhereRaw() calls are forwarded to the query builder and
                    // would degrade the chain's type mid-expression.
                    $termQuery->whereHas('unit', function (Builder $unitQuery) use ($foldedTerm): void {
                        $unitQuery->whereRaw(self::foldSeparatorsSql('name').' LIKE ?', ["%{$foldedTerm}%"]);
                    });

                    $termQuery->orWhere('n_code', 'LIKE', "%{$term}%")
                        ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$term}%"])
                        ->orWhereRaw("CONCAT(l_name, ' ', f_name) LIKE ?", ["%{$term}%"]);
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
