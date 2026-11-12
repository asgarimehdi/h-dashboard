import js from '@eslint/js';
import globals from 'globals';
import prettier from 'eslint-config-prettier';

export default [
    js.configs.recommended,
    {
        files: ['resources/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // Vendor globals loaded by Blade/@assets <script> tags before this module runs.
                L: 'readonly', // Leaflet — 41 uses
                Highcharts: 'readonly', // 13 uses
                Livewire: 'readonly', // 6 uses
                jalaliDatepicker: 'readonly', // 5 uses
                Cropper: 'readonly', // 1 use
                FullCalendar: 'readonly', // 1 use
            },
        },
    },
    prettier,
];
