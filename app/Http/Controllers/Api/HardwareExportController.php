<?php

namespace App\Http\Controllers\Api;

use App\Exports\HardwareExport;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Hardware;
use App\Support\HardwareFilters;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;

class HardwareExportController extends Controller
{
    public function export(UnitScopedRequest $request)
    {
        $columns = array_filter(explode(',', $request->input('columns', '')));
        if (empty($columns)) {
            $columns = ['n_code', 'pc_name'];
        }

        $accessibleIds = $request->accessibleIds();
        $query = Hardware::with('person.unit');

        // Org scope
        if (empty($accessibleIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds));
        }

        // Issue #1050: one shared filter implementation for the model scopes,
        // this export and the Livewire component.
        HardwareFilters::apply($query, $request->only([
            'search', 'type', 'os', 'cpu', 'ram', 'hdd',
            'shutdown', 'net_type', 'mark', 'person', 'unit', 'semat',
        ]));

        $query->orderByDesc('id');

        $filename = 'hardware-'.now()->format('Ymd-His');

        return Excel::download(
            new HardwareExport($query, $columns),
            "{$filename}.xlsx"
        );
    }
}
