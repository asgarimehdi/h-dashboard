<?php

/**
 * Create a Sanctum personal access token for E2E API tests.
 * Usage: php tests/e2e/create-token.php <n_code> <ability1,ability2,...>
 * Outputs the plain-text token to stdout.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$nCode = $argv[1] ?? null;
$abilities = $argv[2] ?? 'hr:read,units:read,hardware:read,tickets:read,traffic:read';

if (! $nCode) {
    echo "Usage: php create-token.php <n_code> [abilities]\n";
    exit(1);
}

$user = User::where('n_code', $nCode)->first();
if (! $user) {
    echo "ERROR: User with n_code=$nCode not found\n";
    exit(1);
}

$token = $user->createToken('e2e-test', explode(',', $abilities))->plainTextToken;
echo $token."\n";
