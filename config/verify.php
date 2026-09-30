<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local verification (`composer verify`)
    |--------------------------------------------------------------------------
    |
    | `composer verify` refuses to run the suite against anything but the test
    | database, and it has to decide that BEFORE any destructive command. The
    | name below is the single source of truth for that name, so the wrapper,
    | the preflight command and the test suite cannot disagree.
    |
    | The database the suite uses is NOT read from this file: an exported shell
    | variable overrides phpunit.xml whenever the <env> entry lacks
    | force="true", and DB_URL overrides everything. Resolve the effective
    | value with `php artisan verify:preflight`, which replays those rules.
    |
    */

    'test_database' => env('VERIFY_TEST_DATABASE', 'h_dashboard_test'),

    /*
    |--------------------------------------------------------------------------
    | Git hooks
    |--------------------------------------------------------------------------
    |
    | `composer hooks:install` points core.hooksPath at the versioned .githooks
    | directory. The symlink installed by composer's post-install-cmd only
    | appears on a fresh `composer install`, so on an already-provisioned
    | machine the light pre-commit layer is silently absent — verify warns
    | about that instead of assuming it.
    |
    */

    'hooks' => [
        'path' => '.githooks',

        // Set to false in a test to assert the "hooks are missing" branch.
        'expect_installed' => true,
    ],

];
