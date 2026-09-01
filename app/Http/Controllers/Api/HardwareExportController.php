<?php

namespace App\Http\Controllers\Api;

use App\Exports\HardwareExport;
use App\Models\Hardware;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;

class HardwareExportController extends Controller
{
    use PersianNormalizer;

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
        // Order: normalize → escape LIKE wildcards for all text inputs
        if ($request->filled('search')) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->search));
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
            $type = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($type));
            $query->where('type', 'LIKE', "%{$type}%");
        }
        if ($request->filled('os')) {
            $os = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->os));
            $query->where('os', 'LIKE', "%{$os}%");
        }
        if ($request->filled('cpu')) {
            $cpu = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->cpu));
            $query->where('cpu', 'LIKE', "%{$cpu}%");
        }
        if ($request->filled('ram')) {
            $ram = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->ram));
            $query->where('ram', 'LIKE', "%{$ram}%");
        }
        if ($request->filled('hdd')) {
            $hdd = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->hdd));
            $query->where('hdd', 'LIKE', "%{$hdd}%");
        }
        if ($request->filled('shutdown')) {
            $query->where('shutdown', $request->shutdown === '1');
        }
        if ($request->filled('net_type')) {
            $netType = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->net_type));
            $query->where('net_type', 'LIKE', "%{$netType}%");
        }
        if ($request->filled('mark')) {
            $query->where('mark', $request->mark === '1');
        }
        if ($request->filled('person')) {
            $normalized = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->person));
            $query->whereHas('person', function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('n_code', 'LIKE', "%{$normalized}%")
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$normalized}%"]);
            });
        }
        if ($request->filled('unit')) {
            $normalized = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->unit));
            $query->whereHas('person.unit', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }
        if ($request->filled('semat')) {
            $normalized = str_replace(['%', '_'], ['\\%', '\\_'], self::normalizeForSearch($request->semat));
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
