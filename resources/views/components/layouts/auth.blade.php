<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .auth-layout { min-height: 100vh; }
    </style>
</head>

<body class="auth-layout min-h-screen font-sans antialiased stitch-bg">
    <!-- Stitch-style animated background JavaScript -->
    <script>
        // Create floating gradient blobs
        function createBlobs() {
            const container = document.querySelector('.stitch-blobs');
            if (!container || container.children.length > 0) return; // Already created

            const blobConfigs = [
                { class: 'blob-1', size: Math.random() * 200 + 300, top: '10%', left: '5%' },
                { class: 'blob-2', size: Math.random() * 150 + 250, top: '60%', right: '10%' },
                { class: 'blob-3', size: Math.random() * 180 + 280, bottom: '20%', left: '50%' }
            ];

            blobConfigs.forEach(config => {
                const blob = document.createElement('div');
                blob.className = `stitch-blob ${config.class}`;
                blob.style.width = `${config.size}px`;
                blob.style.height = `${config.size}px`;

                if (config.top) blob.style.top = config.top;
                if (config.bottom) blob.style.bottom = config.bottom;
                if (config.left) blob.style.left = config.left;
                if (config.right) blob.style.right = config.right;

                container.appendChild(blob);
            });
        }

        // Initialize theme from localStorage on load
        const savedTheme = localStorage.getItem('mary-theme')?.replaceAll('"', '');
        const savedClass = localStorage.getItem('mary-class')?.replaceAll('"', '');
        if (savedTheme) {
            document.documentElement.setAttribute('data-theme', savedTheme);
        }
        if (savedClass) {
            document.documentElement.setAttribute('class', savedClass);
        }

        // Create blobs
        createBlobs();

        // Listen for theme changes to update blobs
        window.addEventListener('theme-changed', (e) => {
            const blobs = document.querySelectorAll('.stitch-blob');
            blobs.forEach(blob => {
                blob.style.display = 'none';
                blob.offsetHeight;
                blob.style.display = '';
            });
        });
    </script>

    <!-- Animated floating blobs (stitched background) -->
    <div class="stitch-blobs fixed inset-0 pointer-events-none -z-10" aria-hidden="true"></div>

    <!-- Stitch-style woven grid background -->
    <div class="stitch-bg-fixed fixed inset-0 -z-20 pointer-events-none" aria-hidden="true"></div>

    {{ $slot }}
</body>
</html>

