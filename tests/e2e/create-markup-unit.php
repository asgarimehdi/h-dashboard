<?php

/**
 * Create a unit with an HTML-markup name for the popup-escape E2E spec
 * (issue #1920).
 *
 * The web unit form has no lat/lng inputs, and POST /api/units rejects a child
 * of a typeless parent (`UnitTypeRelationship::allowsParentType(..., null)` is
 * always false), so neither UI nor API can build this fixture. A direct
 * Eloquent insert is enough: the write path's free-text name is already
 * verified by reading its validation rules; this spec only pins the render.
 *
 * Usage: php tests/e2e/create-markup-unit.php <n_code> <base64_name> [lat] [lng]
 * Outputs the new unit id.
 */

use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? null) === '--delete') {
    $id = (int) ($argv[2] ?? 0);
    $deleted = $id > 0 && Unit::query()->find($id)?->delete() ? 1 : 0;
    echo $deleted, PHP_EOL;
    exit($deleted > 0 ? 0 : 1);
}

$nCode = $argv[1] ?? null;
$encoded = $argv[2] ?? null;
$lat = (float) ($argv[3] ?? 36.6);
$lng = (float) ($argv[4] ?? 48.5);

if ($nCode === null || $encoded === null) {
    fwrite(STDERR, "Usage: php create-markup-unit.php <n_code> <base64_name> [lat] [lng]\n");
    exit(1);
}

$name = base64_decode($encoded, true);
if ($name === false || $name === '') {
    fwrite(STDERR, "Invalid base64 name\n");
    exit(1);
}

$user = User::query()->where('n_code', $nCode)->first();
if (! $user) {
    fwrite(STDERR, "User $nCode not found\n");
    exit(1);
}

// Parent = the unit the e2e admin is attached to. ValidateUnitContext sets
// `current_unit_id` from person.u_id, so the new unit lands inside the scope.
$parentId = $user->person?->u_id ?? $user->units()->value('units.id');
if (! $parentId) {
    fwrite(STDERR, "User $nCode has no base unit\n");
    exit(1);
}

$unit = Unit::create([
    'name' => $name,
    'parent_id' => $parentId,
    // A real type keeps the fixture harmless when another map spec clicks its
    // marker: the unit-details modal requires a non-empty type field (#1383).
    'unit_type_id' => 19,
    'lat' => $lat,
    'lng' => $lng,
    'boundary_id' => null,
    'is_active' => true,
]);

echo $unit->id, PHP_EOL;
