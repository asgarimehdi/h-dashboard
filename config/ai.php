<?php

return [

    'default' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'code'),

    'providers' => [
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],
    ],

];
