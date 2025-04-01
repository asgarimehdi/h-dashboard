import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js',
                'resources/js/chart/highcharts.js',
                'resources/js/chart/treemap.js',
                'resources/js/chart/treegraph.js',
                'resources/js/chart/exporting.js',
                'resources/js/chart/accessibility.js',],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
