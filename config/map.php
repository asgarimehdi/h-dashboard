<?php

return [
    // ── Tile / basemap server (سرور تایل / نقشه) ──
    // Host may be an IP or hostname. Leave TILE_SERVER_PORT empty for the scheme default.
    // Scheme is https for OpenStreetMap; use http + port 8080 on the on-prem tile server.
    'tile_server_ip' => env('TILE_SERVER_IP', 'tile.openstreetmap.org'),
    'tile_server_port' => env('TILE_SERVER_PORT'),
    'tile_server_scheme' => env('TILE_SERVER_SCHEME', 'https'),
    'tile_url' => rtrim(
        env('TILE_SERVER_SCHEME', 'https').'://'.env('TILE_SERVER_IP', 'tile.openstreetmap.org')
        .(env('TILE_SERVER_PORT') ? ':'.env('TILE_SERVER_PORT') : ''),
        '/'
    ),
    // Full tile URL template (the on-prem server uses /tile/{z}/{x}/{y}.png, OSM uses
    // https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png). Keep the {z}/{x}/{y} tokens.
    'tile_url_template' => env(
        'TILE_URL_TEMPLATE',
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
    ),

    // ── Routing server (OSRM) (سرور مسیریابی) ──
    'routing_server_ip' => env('ROUTING_SERVER_IP', '127.0.0.1'),
    'routing_server_port' => env('ROUTING_SERVER_PORT', 5000),
    'routing_server_scheme' => env('ROUTING_SERVER_SCHEME', 'http'),
    'routing_url' => rtrim(
        env('ROUTING_SERVER_SCHEME', 'http').'://'.env('ROUTING_SERVER_IP', '127.0.0.1')
        .':'.env('ROUTING_SERVER_PORT', 5000),
        '/'
    ),

    // ── Geocoding server (Nominatim) (سرور آدرس‌یابی) ──
    'geocoding_server_ip' => env('GEOCODING_SERVER_IP', '127.0.0.1'),
    'geocoding_server_port' => env('GEOCODING_SERVER_PORT', 8088),
    'geocoding_server_scheme' => env('GEOCODING_SERVER_SCHEME', 'http'),
    'geocoding_url' => rtrim(
        env('GEOCODING_SERVER_SCHEME', 'http').'://'.env('GEOCODING_SERVER_IP', '127.0.0.1')
        .':'.env('GEOCODING_SERVER_PORT', 8088),
        '/'
    ),
];
