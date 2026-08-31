<?php

namespace App\Http\Controllers\Api;

use App\Exports\HardwareExport;
use App\Models\Hardware;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;

class HardwareExportController extends Controller
{
    public function export(Request $request)
    {
        $columns = array_filter(explode(',', $request->input('columns', '')));
        if (empty($columns)) {
            $columns = ['n_code', 'pc_name'];
        }

        // Build query — same logic as Livewire component
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $query = Hardware::with('person.unit');

        // Org scope
        if (empty($accessibleIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds));
        }

        // Apply filters from query parameters
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('pc_name', 'LIKE', "%{$s}%")
                    ->orWhere('n_code', 'LIKE', "%{$s}%")
                    ->orWhere('ip_valid', 'LIKE', "%{$s}%")
                    ->orWhere('ip_local', 'LIKE', "%{$s}%")
                    ->orWhere('mac', 'LIKE', "%{$s}%")
                    ->orWhere('comments', 'LIKE', "%{$s}%")
                    ->orWhereHas('person', function ($pq) use ($s) {
                        $pq->where('f_name', 'LIKE', "%{$s}%")
                            ->orWhere('l_name', 'LIKE', "%{$s}%")
                            ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$s}%"]);
                    });
            });
        }

        if ($request->filled('type')) {
            $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
            $type = $typeAliases[$request->type] ?? $request->type;
            $query->where('type', 'LIKE', "%{$type}%");
        }
        if ($request->filled('os')) {
            $query->where('os', 'LIKE', "%{$request->os}%");
        }
        if ($request->filled('cpu')) {
            $query->where('cpu', 'LIKE', "%{$request->cpu}%");
        }
        if ($request->filled('ram')) {
            $query->where('ram', 'LIKE', "%{$request->ram}%");
        }
        if ($request->filled('hdd')) {
            $query->where('hdd', 'LIKE', "%{$request->hdd}%");
        }
        if ($request->filled('shutdown')) {
            $query->where('shutdown', $request->shutdown === '1');
        }
        if ($request->filled('net_type')) {
            $query->where('net_type', 'LIKE', "%{$request->net_type}%");
        }
        if ($request->filled('mark')) {
            $query->where('mark', $request->mark === '1');
        }
        if ($request->filled('person')) {
            $normalized = $request->person;
            $query->whereHas('person', function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('n_code', 'LIKE', "%{$normalized}%")
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$normalized}%"]);
            });
        }
        if ($request->filled('unit')) {
            $normalized = $request->unit;
            $query->whereHas('person.unit', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }
        if ($request->filled('semat')) {
            $normalized = $request->semat;
            $query->whereHas('person.semat', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }

        $query->orderByDesc('id');

        $filename = 'hardware-'.now()->format('Ymd-His');

        return Excel::download(
            new HardwareExport($query, $columns),
            "{$filename}.xlsx"
        );
    }
}
