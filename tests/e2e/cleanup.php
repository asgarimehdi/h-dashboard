<?php

/**
 * Cleanup E2E test data.
 * Run after Playwright tests: php tests/e2e/cleanup.php
 *
 * Removes:
 * - Tickets with subjects starting with [E2E-TEST]
 * - Hardware records with pc_name starting with [E2E-TEST]
 * - Users with name starting with [E2E-TEST]
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Cleanup tickets
$deleted = DB::table('tickets')->where('subject', 'like', '[E2E-TEST%')->delete();
echo "Tickets cleaned: {$deleted}\n";

// Cleanup hardware (if any test creates hardware records)
$deleted = DB::table('hardwares')->where('pc_name', 'like', '[E2E-TEST%')->delete();
echo "Hardware cleaned: {$deleted}\n";

// Cleanup associated todos (created automatically with tickets)
$deleted = DB::table('todos')->where('title', 'like', '[E2E-TEST%')->delete();
echo "Todos cleaned: {$deleted}\n";

echo "E2E cleanup complete.\n";
