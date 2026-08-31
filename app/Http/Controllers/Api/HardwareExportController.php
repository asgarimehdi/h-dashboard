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
        $sessionKey = 'hardware_export_state';
        $state = session($sessionKey);

        if (! $state) {
            abort(404, 'داده‌ای برای خروجی وجود ندارد.');
        }

        $columns = $state['columns'] ?? [];
        $filters = $state['filters'] ?? [];

        // Build query — same logic as Livewire component
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $query = Hardware::with('person.unit');

        // Org scope
        if (empty($accessibleIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds));
        }

        // Apply filters (same as Livewire hardwares() method)
        if (! empty($filters['search'])) {
            $s = $filters['search'];
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

        if (! empty($filters['filterType'])) {
            $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
            $type = $typeAliases[$filters['filterType']] ?? $filters['filterType'];
            $query->where('type', 'LIKE', "%{$type}%");
        }
        if (! empty($filters['filterOs'])) {
            $query->where('os', 'LIKE', "%{$filters['filterOs']}%");
        }
        if (! empty($filters['filterCpu'])) {
            $query->where('cpu', 'LIKE', "%{$filters['filterCpu']}%");
        }
        if (! empty($filters['filterRam'])) {
            $query->where('ram', 'LIKE', "%{$filters['filterRam']}%");
        }
        if (! empty($filters['filterHdd'])) {
            $query->where('hdd', 'LIKE', "%{$filters['filterHdd']}%");
        }
        if (isset($filters['filterShutdown']) && $filters['filterShutdown'] !== '') {
            $query->where('shutdown', $filters['filterShutdown'] === '1');
        }
        if (! empty($filters['filterNetType'])) {
            $query->where('net_type', 'LIKE', "%{$filters['filterNetType']}%");
        }
        if (isset($filters['filterMark']) && $filters['filterMark'] !== '') {
            $query->where('mark', $filters['filterMark'] === '1');
        }
        if (! empty($filters['filterPerson'])) {
            $normalized = $filters['filterPerson'];
            $query->whereHas('person', function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('n_code', 'LIKE', "%{$normalized}%")
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$normalized}%"]);
            });
        }
        if (! empty($filters['filterUnit'])) {
            $normalized = $filters['filterUnit'];
            $query->whereHas('person.unit', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }
        if (! empty($filters['filterSemat'])) {
            $normalized = $filters['filterSemat'];
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
