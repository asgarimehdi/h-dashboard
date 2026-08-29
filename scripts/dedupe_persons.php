<?php
// One-off processor: dedupe person_users_from_devices.php by (f_name + l_name) ONLY
// — a real person (e.g. مهدی عسگری) may work across multiple units, so unit is
// intentionally NOT part of the key.
//
// The 3 hand-seeded persons in PersonsTableSeeder are REAL and must be preserved
// as canonical: their n_codes win over any duplicate found in the device data.
// Every later same-name person in the device data gets its n_code remapped to the
// canonical one (the hand-seeded code when the name matches, otherwise the first
// occurrence in the file). Hardware n_codes follow the same remap so nothing is
// left orphaned.

require __DIR__.'/../vendor/autoload.php';

$personsFile = __DIR__.'/../database/seeders/data/person_users_from_devices.php';
$hwFile = __DIR__.'/../database/seeders/data/hardwares_data.php';

$records = require $personsFile;
$hardwares = require $hwFile;

// Real persons seeded by PersonsTableSeeder — these n_codes are canonical.
$handSeeded = [
    'صادق بیگلر' => '4400176134',
    'مهدی عسگری' => '4411015056',
    'شهاب عباسی' => '4400176143',
];

$canonicalByName = [];   // "f_name l_name" => n_code (kept)
$remap = [];             // dropped/source n_code => canonical n_code
$merged = [];            // kept records (order preserved, n_code remapped in place)

$fieldsToFold = ['semat', 'radif', 'role', 'unit'];

foreach ($records as $record) {
    $name = trim(($record['f_name'] ?? '').' '.($record['l_name'] ?? ''));
    $nCode = $record['n_code'];

    if (! isset($canonicalByName[$name])) {
        // First time we see this name. Prefer the hand-seeded canonical code.
        $canonical = $handSeeded[$name] ?? $nCode;
        $canonicalByName[$name] = $canonical;

        // The kept record uses the canonical n_code.
        $kept = $record;
        $kept['n_code'] = $canonical;
        $merged[$canonical] = $kept;

        // If this file record's own code differs from the canonical, remap it.
        if ($nCode !== $canonical) {
            $remap[$nCode] = $canonical;
        }
        continue;
    }

    $canonical = $canonicalByName[$name];
    if ($nCode !== $canonical) {
        $remap[$nCode] = $canonical;
    }

    // Fold differing fields into the kept record (if kept is empty/blank).
    $keptCode = $canonical;
    foreach ($fieldsToFold as $f) {
        if (! isset($merged[$keptCode][$f]) || $merged[$keptCode][$f] === '' || $merged[$keptCode][$f] === null) {
            if (isset($record[$f]) && $record[$f] !== '' && $record[$f] !== null) {
                $merged[$keptCode][$f] = $record[$f];
            }
        }
    }
}

// Re-index merged keeping original order of first appearance.
$mergedList = array_values($merged);

// Remap hardware n_codes.
$hwRemapped = 0;
foreach ($hardwares as &$hw) {
    if (isset($remap[$hw['n_code']])) {
        $hw['n_code'] = $remap[$hw['n_code']];
        $hwRemapped++;
    }
}
unset($hw);

// Write persons file (preserve PHP array-return format).
$export = "<?php\n\nreturn ".var_export($mergedList, true).";\n";
file_put_contents($personsFile, $export);

// Write hardware file.
$exportHw = "<?php\n\nreturn ".var_export($hardwares, true).";\n";
file_put_contents($hwFile, $exportHw);

echo 'Persons before: '.count($records).'  after: '.count($mergedList).'  (remapped '.count($remap).")\n";
echo 'Hardware rows remapped: '.$hwRemapped."\n";
echo 'Distinct canonical names: '.count($canonicalByName)."\n";
echo "Hand-seeded canonical codes used:\n";
foreach ($handSeeded as $nm => $code) {
    echo "  $nm => $code (kept: ".(isset($merged[$code]) ? 'yes' : 'NO').")\n";
}
