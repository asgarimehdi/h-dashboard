<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Help sections
    |--------------------------------------------------------------------------
    |
    | The single source of truth for the in-app help system (#1535). Every
    | entry must have a matching `resources/views/components/help/content/
    | {section}.blade.php`; `HelpSystemTest` enforces both directions.
    |
    | Add a section here and its content file in the same change — the modal
    | iterates this list, so a registered section with no file fails loudly
    | (Blade `@include`, not `@includeIf`).
    |
    */
    'sections' => [
        'dashboard',
        'hardware',
        'hardware-import',
        'persons-import',
        'personnel',
        'units',
        'tickets',
        'todos',
        'reports',
        'maps',
        'settings',
        'roles',
        'permissions',
        'users',
        'activity-log',
        'networks',
        'wireless',
        'tools',
        'search',
        'profile',
        'hr-dashboard',
        'zabbix-devices',
        'select-context',
        'change-password',
    ],
];
