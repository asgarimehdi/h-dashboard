<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateUnitContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $currentUnitId = session('current_unit_id');

        if ($currentUnitId) {
            $hasAccess = $user->units()->where('units.id', $currentUnitId)->exists();

            if ($hasAccess) {
                return $next($request);
            }

            session()->forget('current_unit_id');
            session()->forget('current_unit_name');
            $currentUnitId = null;
        }

        // Only load units relationship when current_unit_id is not set
        $userUnits = $user->units;

        if ($userUnits->isEmpty()) {
            $person = $user->person;
            if ($person?->u_id) {
                session(['current_unit_id' => $person->u_id]);
                session(['current_unit_name' => $person->unit?->name ?? '-']);
            }

            return $next($request);
        }

        if ($userUnits->count() === 1) {
            $unit = $userUnits->first();
            session(['current_unit_id' => $unit->id]);
            session(['current_unit_name' => $unit->name]);

            return $next($request);
        }

        return redirect('/select-context');
    }
}
