<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AccessService;

class UnitController extends Controller
{
    public function __invoke(): array
    {
        $ids = app(AccessService::class)->accessibleUnitIds();
        $units = Unit::whereIn('id', $ids)->paginate();

        return [
            'data' => $units->items(),
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
            ],
        ];
    }
}
