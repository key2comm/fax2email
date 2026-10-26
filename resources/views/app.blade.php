<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @php($brand = \App\Support\Branding::forPages())
        <title inertia>{{ $brand['name'] }}</title>

        <link rel="icon" type="image/png" href="{{ $brand['favicon'] ?? '/favicon.ico' }}">
        @if ($brand['color'])
            {{-- Brand colour from Settings > Branding, applied to Tabler's primary colour. --}}
            <style>
                :root, [data-bs-theme] {
                    --tblr-primary: {{ $brand['color'] }};
                    --tblr-primary-rgb: {{ implode(', ', \App\Support\Branding::rgb($brand['color'])) }};
                    --tblr-primary-fg: {{ \App\Support\Branding::foreground($brand['color']) }};
                }
            </style>
        @endif

        {{-- Apply the light/dark theme before the page paints; see resources/js/composables/useTheme.ts. --}}
        <script>
            (function () {
                var theme;
                try { theme = localStorage.getItem('theme'); } catch (e) {}
                if (theme !== 'light' && theme !== 'dark') {
                    theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-bs-theme', theme);
            })();
        </script>

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
