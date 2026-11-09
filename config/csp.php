<?php

return [
    // #1281: CSP_ENFORCING flips the middleware from Report-Only to enforce.
    // Read through config — never env() directly (cached config returns null).
    'enforcing' => env('CSP_ENFORCING', false),
];
